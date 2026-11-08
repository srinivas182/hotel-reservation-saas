<?php

declare(strict_types=1);

namespace App\Domains\Site\Http\Controllers;

use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Site\Models\Lead;
use App\Domains\Site\Models\SitePage;
use App\Domains\Site\Pricing;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public website: French at /, English at /en.
 */
final class SiteController extends Controller
{
    public const PAGES = ['features', 'pricing', 'countries', 'faq', 'demo'];

    public function home(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null && $request->route()?->getName() === 'home') {
            return to_route('dashboard');
        }

        return $this->page($request, 'home');
    }

    public function page(Request $request, string $page): View
    {
        $locale = str_starts_with($request->path(), 'en') ? 'en' : 'fr';
        App::setLocale($locale);
        $content = in_array($page, ['pricing', 'demo'], true) ? null : SitePage::query()->where('key', $page)->where('published', true)->first();
        abort_if($content === null && ! in_array($page, ['pricing', 'demo'], true), 404);

        $data = [
            'locale' => $locale,
            'page' => $page,
            'content' => $content,
            'html' => $content === null ? '' : Str::markdown($content->text('body', $locale), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'title' => $content?->text('title', $locale) ?? ($page === 'pricing' ? __('Pricing') : __('Ask for a demo')),
            'description' => $content?->text('description', $locale) ?? ($page === 'pricing' ? __('Plans and prices in your currency, with a 14-day free trial.') : __('See the assistant on a demo hotel with our team.')),
            'brand' => BrandSettings::cached() ?? ['name' => config('app.name'), 'tagline' => [], 'color' => '#4f46e5', 'logo' => null, 'support_email' => null, 'support_whatsapp' => null, 'analytics_domain' => null, 'analytics_script' => null],
            'canSignUp' => SignupSettings::current()->mode !== 'closed',
            'countries' => CountryTemplate::query()->where('active', true)->get()->map(fn (CountryTemplate $c): array => ['code' => $c->country_code, 'name' => $c->localizedName($locale), 'currency' => $c->currency])->sortBy('name')->values(),
        ];

        if ($page === 'pricing') {
            $currency = Pricing::currencyFor($request->query('currency') === null ? null : (string) $request->query('currency'), $request->header('CF-IPCountry'));
            $data['pricing'] = Pricing::build($currency, $locale);
            $data['currencies'] = Pricing::currencies();
        }

        return view('site.page', $data);
    }

    public function demo(Request $request): RedirectResponse
    {
        $locale = str_starts_with($request->path(), 'en') ? 'en' : 'fr';
        App::setLocale($locale);
        $back = $locale === 'en' ? '/en/demo' : '/demo';

        // Spam trap: people never fill this hidden field.
        if (trim((string) $request->input('website', '')) !== '') {
            return redirect($back)->with('status', 'sent');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hotel_name' => ['required', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2', Rule::exists('country_templates', 'country_code')],
            'city' => ['nullable', 'string', 'max:120'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'whatsapp' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9 ().-]{8,25}$/'],
            'email' => ['nullable', 'email', 'max:191'],
            'message' => ['nullable', 'string', 'max:2000'],
            'contact_time' => ['nullable', 'string', 'max:60'],
        ]);

        Lead::query()->create([...$data, 'locale' => $locale, 'status' => 'new', 'ip' => $request->ip()]);

        return redirect($back)->with('status', 'sent');
    }

    public function logo(): StreamedResponse|Response
    {
        $path = BrandSettings::current()->logo_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return response((string) file_get_contents(resource_path('svg/default-logo.svg')), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']);
        }

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sitemap(): Response
    {
        $paths = ['', ...self::PAGES];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        foreach ($paths as $p) {
            $fr = url($p === '' ? '/' : '/'.$p);
            $en = url($p === '' ? '/en' : '/en/'.$p);
            foreach ([$fr, $en] as $loc) {
                $xml .= "\n<url><loc>{$loc}</loc><xhtml:link rel=\"alternate\" hreflang=\"fr\" href=\"{$fr}\"/><xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$en}\"/></url>";
            }
        }

        return response($xml."\n</urlset>\n", 200, ['Content-Type' => 'application/xml']);
    }
}
