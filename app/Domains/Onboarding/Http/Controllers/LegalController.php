<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Legal;
use App\Domains\Onboarding\LegalPlaceholders;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Domains\Site\Models\BrandSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

final class LegalController extends Controller
{
    public function show(string $kind, Request $request): View
    {
        abort_unless(in_array($kind, LegalDocument::KINDS, true), 404);
        $document = LegalDocument::current($kind) ?? abort(404);
        $locale = in_array($request->query('lang'), ['fr', 'en'], true)
            ? (string) $request->query('lang')
            : (BrandSettings::defaultLocale() ?? (str_starts_with((string) $request->getPreferredLanguage(['fr', 'en']), 'en') ? 'en' : 'fr'));
        App::setLocale($locale);

        // 0.37.7: the site's own page, with a contents list built from the "## " headings.
        $markdown = LegalPlaceholders::fill($document->text($locale), $locale);
        $markdown = (string) preg_replace('/\A\s*#\s+[^\n]*\n/u', '', $markdown); // the page header shows the title
        $sections = [];
        $html = Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/u', function (array $m) use (&$sections): string {
            $id = 's'.(count($sections) + 1);
            $sections[] = ['id' => $id, 'title' => html_entity_decode(strip_tags($m[1]))];

            return '<h2 id="'.$id.'">'.$m[1].'</h2>';
        }, $html);

        return view('legal.show', [
            'locale' => $locale,
            'page' => 'legal-'.$kind,
            'kind' => $kind,
            'title' => LegalDocument::label($kind),
            'description' => LegalDocument::label($kind).' · '.(BrandSettings::cached()['name'] ?? config('app.name')),
            'version' => $document->version,
            'updated' => $document->published_at?->settings(['locale' => $locale])->isoFormat('LL'),
            'html' => $html,
            'sections' => $sections,
            'others' => array_values(array_filter(LegalDocument::KINDS, fn (string $k): bool => $k !== $kind)),
            'brand' => BrandSettings::cached() ?? ['name' => config('app.name'), 'tagline' => [], 'color' => '#4f46e5', 'logo' => null, 'support_email' => null, 'support_whatsapp' => null, 'analytics_domain' => null, 'analytics_script' => null],
            'alternates' => ['fr' => url('/legal/'.$kind.'?lang=fr'), 'en' => url('/legal/'.$kind.'?lang=en')],
        ]);
    }

    public function acceptPage(#[CurrentUser] User $user, Legal $legal): Response|RedirectResponse
    {
        $missing = $legal->missing($user);

        if ($missing === []) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/accept-legal', [
            'documents' => array_map(fn (LegalDocument $d): array => ['id' => $d->id, 'kind' => $d->kind, 'label' => LegalDocument::label($d->kind), 'version' => $d->version, 'url' => route('legal.show', $d->kind)], $missing),
        ]);
    }

    public function accept(Request $request, #[CurrentUser] User $user, Legal $legal): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']]);
        $missing = $legal->missing($user);
        $legal->accept($user, $missing, $request->ip());
        Audit::record('legal.accepted', 'Legal texts accepted', causer: $user, properties: ['documents' => array_map(fn (LegalDocument $d): string => $d->kind.' v'.$d->version, $missing)]);

        return redirect()->intended(route('dashboard'));
    }
}
