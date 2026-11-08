<?php

declare(strict_types=1);

namespace App\Domains\Help\Http\Controllers;

use App\Domains\Help\HelpSearch;
use App\Domains\Help\Models\HelpArticle;
use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Site\Models\BrandSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The help centre: public pages (French at /help, English at /en/help) and
 * the in-app help panel (JSON for the "?" button and support form).
 */
final class HelpController extends Controller
{
    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $q = trim((string) $request->query('q', ''));
        $results = $q === '' ? null : HelpSearch::find($q, $locale, 'site');

        return view('help.index', [
            ...$this->layout($locale, 'help', __('Help centre'), __('Answers to common questions about the hotel assistant.')),
            'q' => $q,
            'results' => $results,
            'categories' => HelpArticle::query()->where('published', true)->orderBy('sort_order')->get()->groupBy('category'),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $locale = $this->locale($request);
        $article = HelpArticle::query()->where('slug', $slug)->where('published', true)->firstOrFail();

        return view('help.show', [
            ...$this->layout($locale, 'help/'.$slug, $article->text('title', $locale), $article->excerpt($locale)),
            'article' => $article,
            'html' => $article->html($locale),
            'related' => HelpArticle::query()->where('published', true)->where('category', $article->category)->whereKeyNot($article->id)->orderBy('sort_order')->get(),
        ]);
    }

    public function feedback(Request $request, string $slug): RedirectResponse|JsonResponse
    {
        $article = HelpArticle::query()->where('slug', $slug)->where('published', true)->firstOrFail();
        $data = $request->validate(['helpful' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:500']]);

        DB::table('help_feedback')->insert(['help_article_id' => $article->id, 'helpful' => (bool) $data['helpful'], 'comment' => $data['comment'] ?? null, 'locale' => $this->locale($request), 'created_at' => now()]);
        $article->increment((bool) $data['helpful'] ? 'helpful' : 'not_helpful');

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('status', 'thanks');
    }

    /** In the app: the article for the current screen, or search results. */
    public function panel(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            return response()->json(['results' => HelpSearch::find($q, $locale, (string) $request->query('source', 'app') === 'support' ? 'support' : 'app', 5)
                ->map(fn (HelpArticle $a): array => $this->summary($a, $locale))->values()]);
        }

        $screen = (string) $request->query('screen', '');
        $slug = (string) $request->query('slug', '');
        $article = $slug !== ''
            ? HelpArticle::query()->where('slug', $slug)->where('published', true)->first()
            : HelpArticle::query()->where('published', true)->get()->first(fn (HelpArticle $a): bool => in_array($screen, $a->screens ?? [], true));

        return response()->json([
            'article' => $article === null ? null : [...$this->summary($article, $locale), 'html' => $article->html($locale)],
            'others' => HelpArticle::query()->where('published', true)->orderBy('sort_order')->get()->map(fn (HelpArticle $a): array => $this->summary($a, $locale))->values(),
        ]);
    }

    public function media(string $file): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]+\.(png|jpg|jpeg|gif|webp)$/', $file) === 1 && Storage::disk('local')->exists('help/'.$file), 404);

        return Storage::disk('local')->response('help/'.$file, null, ['Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    /**
     * @return array{slug: string, title: string, excerpt: string, url: string}
     */
    private function summary(HelpArticle $a, string $locale): array
    {
        return ['slug' => $a->slug, 'title' => $a->text('title', $locale), 'excerpt' => $a->excerpt($locale), 'url' => ($locale === 'en' ? '/en/help/' : '/help/').$a->slug];
    }

    private function locale(Request $request): string
    {
        $locale = str_starts_with($request->path(), 'en') ? 'en' : 'fr';
        App::setLocale($locale);

        return $locale;
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(string $locale, string $page, string $title, string $description): array
    {
        return [
            'locale' => $locale, 'page' => $page, 'title' => $title, 'description' => $description,
            'brand' => BrandSettings::cached() ?? ['name' => config('app.name'), 'tagline' => [], 'color' => '#4f46e5', 'logo' => null, 'support_email' => null, 'support_whatsapp' => null, 'analytics_domain' => null, 'analytics_script' => null],
            'canSignUp' => SignupSettings::current()->mode !== 'closed',
            'countries' => CountryTemplate::query()->limit(0)->get(),
        ];
    }
}
