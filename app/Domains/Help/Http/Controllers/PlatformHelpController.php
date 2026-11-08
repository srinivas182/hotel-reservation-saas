<?php

declare(strict_types=1);

namespace App\Domains\Help\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Help\Models\HelpArticle;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Help: articles, pictures and what hotels could not find.
 */
final class PlatformHelpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/help', [
            'articles' => HelpArticle::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (HelpArticle $a): array => [
                'id' => $a->id, 'slug' => $a->slug, 'category' => $a->category, 'title' => $a->title, 'body' => $a->body, 'screens' => implode(', ', $a->screens ?? []),
                'published' => $a->published, 'helpful' => $a->helpful, 'not_helpful' => $a->not_helpful,
            ])->values(),
            'categories' => collect(HelpArticle::CATEGORIES)->map(fn (string $c): array => ['value' => $c, 'label' => HelpArticle::categoryLabel($c)])->values(),
            'gaps' => DB::table('help_searches')->where('results', 0)->where('created_at', '>=', now()->subDays(60))
                ->selectRaw('lower(query) as q, count(*) as n, max(created_at) as last')->groupBy('q')->orderByDesc('n')->limit(30)->get(),
            'comments' => DB::table('help_feedback')->join('help_articles', 'help_articles.id', '=', 'help_feedback.help_article_id')
                ->where('help_feedback.helpful', false)->whereNotNull('help_feedback.comment')->orderByDesc('help_feedback.id')->limit(20)
                ->get(['help_articles.slug', 'help_feedback.comment', 'help_feedback.created_at']),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->save($request, $user, null);
    }

    public function update(Request $request, HelpArticle $article, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->save($request, $user, $article);
    }

    /**
     * Pictures and short animations (GIF) for articles.
     */
    public function media(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:5120']]);
        $file = $request->file('file');
        abort_unless($file instanceof \Illuminate\Http\UploadedFile, 422);
        $name = Str::uuid().'.'.strtolower((string) $file->extension());
        Storage::disk('local')->putFileAs('help', $file, $name);
        Audit::record('help.media_uploaded', 'Help picture uploaded', causer: $user, properties: ['file' => $name]);

        return response()->json(['markdown' => '![]('.route('help.media', $name).')']);
    }

    private function save(Request $request, User $user, ?HelpArticle $article): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('help_articles', 'slug')->ignore($article?->id)],
            'category' => ['required', Rule::in(HelpArticle::CATEGORIES)],
            'title' => ['required', 'array'], 'title.fr' => ['required', 'string', 'max:150'], 'title.en' => ['required', 'string', 'max:150'],
            'body' => ['required', 'array'], 'body.fr' => ['required', 'string', 'max:30000'], 'body.en' => ['required', 'string', 'max:30000'],
            'screens' => ['nullable', 'string', 'max:1000'],
            'published' => ['required', 'boolean'],
        ]);

        $values = [
            'slug' => Str::lower((string) $data['slug']), 'category' => $data['category'], 'title' => $data['title'], 'body' => $data['body'],
            'screens' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['screens'] ?? ''))))), 'published' => (bool) $data['published'],
        ];

        $article === null ? $article = HelpArticle::query()->create([...$values, 'sort_order' => (int) HelpArticle::query()->max('sort_order') + 1]) : $article->update($values);
        Audit::record('help.article_saved', 'Help article saved', causer: $user, properties: ['slug' => $article->slug]);

        return back();
    }
}
