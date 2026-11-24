@php
    $frHome = \App\Domains\Site\Models\BrandSettings::defaultLocale() === 'en' ? '/fr' : '/';
    // 0.37.7: pages outside the site's own routes (the legal texts) give their language links.
    $alt = isset($alternates) ? fn (string $p) => $alternates : fn (string $p) => $p === 'home' ? ['fr' => url($frHome), 'en' => url('/en')] : ['fr' => url('/'.$p), 'en' => url('/en/'.$p)];
    $link = fn (string $p) => $locale === 'en' ? ($p === 'home' ? '/en' : '/en/'.$p) : ($p === 'home' ? (\App\Domains\Site\Models\BrandSettings::defaultLocale() === 'en' ? '/fr' : '/') : '/'.$p);
    $nav = ['features' => __('Features'), 'pricing' => __('Pricing'), 'countries' => __('Countries'), 'faq' => __('FAQ'), 'help' => __('Help')];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page === 'home' ? $brand['name'].' — '.$title : $title.' · '.$brand['name'] }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $alt($page)[$locale] }}">
    <link rel="alternate" hreflang="fr" href="{{ $alt($page)['fr'] }}">
    <link rel="alternate" hreflang="en" href="{{ $alt($page)['en'] }}">
    <link rel="alternate" hreflang="x-default" href="{{ $alt($page)[\App\Domains\Site\Models\BrandSettings::defaultLocale() ?? 'fr'] }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $alt($page)[$locale] }}">
    <meta property="og:locale" content="{{ $locale === 'en' ? 'en_GB' : 'fr_FR' }}">
    <link rel="icon" href="{{ $brand['logo'] ?? route('brand.logo') }}">
    @if ($brand['analytics_script'] && $brand['analytics_domain'])
        <script defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" data-domain="{{ $brand['analytics_domain'] }}" src="{{ $brand['analytics_script'] }}"></script>
    @endif
    @php
        $palette = \App\Support\BrandPalette::for($brand['color'] ?? \App\Support\BrandPalette::DEFAULT);
    @endphp
    @vite('resources/css/site.css')
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        :root { --brand: {{ $palette['light']['--brand'] }}; --brand-ink: {{ $palette['light']['--brand-ink'] }}; --brand-soft: {{ $palette['light']['--brand-soft'] }}; }
        @media (prefers-color-scheme: dark) { :root { --brand: {{ $palette['light']['--brand'] }}; --brand-ink: {{ $palette['dark']['--brand-ink'] }}; --brand-soft: {{ $palette['dark']['--brand-soft'] }}; } }
    </style>
    @stack('head')
</head>
@php
    $langs = ['fr' => 'Français', 'en' => 'English'];
    $wa = preg_replace('/\D+/', '', (string) ($brand['support_whatsapp'] ?? ''));
    $onDark = $page === 'home';
@endphp
<body>
<a href="#main" class="skip">{{ __('Skip to content') }}</a>
<header class="top {{ $onDark ? 'on-dark' : '' }}">
    <div class="wrap bar">
        <a class="logo" href="{{ $link('home') }}"><img src="{{ $brand['logo'] ?? route('brand.logo') }}" alt="" width="34" height="34"><span>{{ $brand['name'] }}</span></a>
        <nav class="menu" aria-label="{{ __('Main') }}">
            @foreach ($nav as $key => $label)
                <a href="{{ $link($key) }}" @if ($page === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <span class="sep" aria-hidden="true"></span>
            <details class="lang" data-close>
                <summary>
                    <span class="sr">{{ __('Language') }}: {{ $langs[$locale] }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>
                    {{ strtoupper($locale) }}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <ul>
                    @foreach ($langs as $code => $name)
                        <li><a href="{{ $alt($page)[$code] }}" hreflang="{{ $code }}" lang="{{ $code }}" @if ($code === $locale) aria-current="true" @endif>{{ $name }} <span class="code">{{ $code === $locale ? '✓' : strtoupper($code) }}</span></a></li>
                    @endforeach
                </ul>
            </details>
            <a href="/login">{{ __('Log in') }}</a>
            <a class="btn sm" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a>
        </nav>
        <details class="burger" data-close>
            <summary aria-label="{{ __('Menu') }}"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></summary>
            <nav aria-label="{{ __('Main') }}">
                @foreach ($nav as $key => $label)
                    <a href="{{ $link($key) }}" @if ($page === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                <a href="/login">{{ __('Log in') }}</a>
                <a class="btn" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a>
                <div class="langs" role="group" aria-label="{{ __('Language') }}">
                    @foreach ($langs as $code => $name)
                        <a href="{{ $alt($page)[$code] }}" hreflang="{{ $code }}" lang="{{ $code }}" @if ($code === $locale) aria-current="true" @endif>{{ $name }}</a>
                    @endforeach
                </div>
            </nav>
        </details>
    </div>
</header>
<main id="main">
    @yield('main')
</main>
<footer class="site">
    <div class="wrap">
        <div class="foot">
            <div>
                <a class="logo" href="{{ $link('home') }}"><img src="{{ $brand['logo'] ?? route('brand.logo') }}" alt="" width="34" height="34"><span>{{ $brand['name'] }}</span></a>
                <p>{{ ($brand['tagline'][$locale] ?? '') ?: __('Your hotel answers on WhatsApp, day and night.') }}</p>
            </div>
            <div>
                <h3>{{ __('Product') }}</h3>
                <ul>
                    <li><a href="{{ $link('features') }}">{{ __('Features') }}</a></li>
                    <li><a href="{{ $link('pricing') }}">{{ __('Pricing') }}</a></li>
                    <li><a href="{{ $link('countries') }}">{{ __('Countries') }}</a></li>
                    <li><a href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a></li>
                </ul>
            </div>
            <div>
                <h3>{{ __('Resources') }}</h3>
                <ul>
                    <li><a href="{{ $link('faq') }}">{{ __('FAQ') }}</a></li>
                    <li><a href="{{ $link('help') }}">{{ __('Help') }}</a></li>
                    <li><a href="{{ $locale === 'en' ? '/en/status' : '/status' }}">{{ __('Service status') }}</a></li>
                    @if ($brand['support_email'])<li><a href="mailto:{{ $brand['support_email'] }}">{{ $brand['support_email'] }}</a></li>@endif
                </ul>
            </div>
            <div>
                <h3>{{ __('Legal') }}</h3>
                <ul>
                    <li><a href="/legal/terms?lang={{ $locale }}">{{ __('Terms of service') }}</a></li>
                    <li><a href="/legal/privacy?lang={{ $locale }}">{{ __('Privacy notice') }}</a></li>
                    <li><a href="/legal/dpa?lang={{ $locale }}">{{ __('Data processing agreement') }}</a></li>
                </ul>
            </div>
        </div>
        <div class="legal">
            <span>© {{ date('Y') }} {{ $brand['name'] }}</span>
            <span>
                @foreach ($langs as $code => $name)
                    <a href="{{ $alt($page)[$code] }}" hreflang="{{ $code }}" lang="{{ $code }}" @if ($code === $locale) aria-current="true" style="font-weight:700" @endif>{{ $name }}</a>@if (! $loop->last) · @endif
                @endforeach
            </span>
        </div>
    </div>
</footer>
@if ($page !== 'demo')
    {{-- The same "chat with us" button hotels get (Sprint H), for the sales team. --}}
    <a class="chat-fab" aria-label="{{ __('Chat with us') }}" href="{{ $wa !== '' ? 'https://wa.me/'.$wa.'?text='.rawurlencode(__('Hello, I would like to know more about :name for my hotel.', ['name' => $brand['name']])) : $link('demo') }}" @if ($wa !== '') target="_blank" rel="noopener" @endif>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 20.5l1.6-4.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.3 3.5h.5a8.5 8.5 0 0 1 8.2 8z"/></svg>
        <span>{{ __('Chat with us') }}</span>
    </a>
@endif
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    // Over the dark opening section the header is dark; below it, light.
    (() => {
        const top = document.querySelector('.top'); const hero = document.querySelector('.hero-dark');
        if (!top || !hero) return;
        const sync = () => top.classList.toggle('on-dark', hero.getBoundingClientRect().bottom > 70);
        addEventListener('scroll', sync, { passive: true }); sync();
    })();
    // Menus close on a click outside or on Escape.
    document.addEventListener('click', (e) => document.querySelectorAll('details[data-close][open]').forEach((d) => { if (!d.contains(e.target)) d.removeAttribute('open'); }));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') document.querySelectorAll('details[data-close][open]').forEach((d) => { d.removeAttribute('open'); d.querySelector('summary')?.focus(); }); });
</script>
@stack('scripts')
</body>
</html>
