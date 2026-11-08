@php
    $alt = fn (string $p) => $p === 'home' ? ['fr' => url('/'), 'en' => url('/en')] : ['fr' => url('/'.$p), 'en' => url('/en/'.$p)];
    $link = fn (string $p) => $locale === 'en' ? ($p === 'home' ? '/en' : '/en/'.$p) : ($p === 'home' ? '/' : '/'.$p);
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
    <link rel="alternate" hreflang="x-default" href="{{ $alt($page)['fr'] }}">
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
    <style>
        :root { color-scheme: light dark; --brand: {{ $brand['color'] }}; --bg: #ffffff; --soft: #f6f7fb; --fg: #111827; --muted: #4b5563; --line: #e5e7eb; --card: #ffffff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1020; --soft: #111827; --fg: #f3f4f6; --muted: #cbd5e1; --line: #1f2937; --card: #111827; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.6; }
        a { color: inherit; }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 20px; }
        header { border-bottom: 1px solid var(--line); background: var(--bg); position: sticky; top: 0; z-index: 2; }
        .bar { display: flex; align-items: center; gap: 16px; min-height: 64px; flex-wrap: wrap; }
        .logo { display: flex; align-items: center; gap: 10px; font-weight: 700; text-decoration: none; }
        .logo img { width: 32px; height: 32px; border-radius: 8px; }
        nav { display: flex; gap: 14px; flex-wrap: wrap; margin-left: auto; align-items: center; font-size: 15px; }
        nav a { text-decoration: none; padding: 6px 2px; }
        nav a[aria-current="page"] { text-decoration: underline; text-underline-offset: 6px; }
        .btn { display: inline-block; background: var(--brand); color: #fff !important; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-weight: 600; border: 0; cursor: pointer; font-size: 15px; }
        .btn.ghost { background: transparent; color: var(--fg) !important; border: 1px solid var(--line); }
        a:focus-visible, .btn:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        .hero { padding: 56px 0 32px; }
        .hero h1 { font-size: clamp(28px, 6vw, 44px); line-height: 1.15; margin: 0 0 16px; }
        .hero p { font-size: 18px; color: var(--muted); max-width: 640px; margin: 0 0 24px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .content { padding: 24px 0 56px; }
        .content h2 { margin-top: 36px; } .content h3 { margin-top: 24px; }
        .content p, .content li { color: var(--muted); }
        .chat { background: var(--soft); border: 1px solid var(--line); border-radius: 16px; padding: 16px; max-width: 420px; display: grid; gap: 8px; margin: 8px 0 24px; }
        .msg { padding: 8px 12px; border-radius: 14px; max-width: 85%; font-size: 15px; }
        .msg.in { background: var(--card); border: 1px solid var(--line); }
        .msg.out { background: var(--brand); color: #fff; justify-self: end; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 20px; }
        .card.featured { border: 2px solid var(--brand); }
        .price { font-size: 26px; font-weight: 700; margin: 8px 0 0; color: var(--fg); }
        .muted { color: var(--muted); font-size: 14px; }
        ul.ticks { padding-left: 18px; } ul.ticks li { margin: 4px 0; }
        form.demo { display: grid; gap: 12px; max-width: 560px; }
        label { display: grid; gap: 4px; font-weight: 600; font-size: 14px; }
        input, select, textarea { font: inherit; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--fg); }
        .two { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .notice { background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px; }
        .error { color: #b91c1c; font-size: 14px; font-weight: 600; }
        @media (prefers-color-scheme: dark) { .error { color: #fca5a5; } }
        .hp { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        footer { border-top: 1px solid var(--line); padding: 24px 0 40px; font-size: 14px; color: var(--muted); }
        footer .links { display: flex; flex-wrap: wrap; gap: 14px; }
        .countries { columns: 2 220px; padding-left: 18px; }
    </style>
</head>
<body>
<a href="#main" class="hp">{{ __('Skip to content') }}</a>
<header>
    <div class="wrap bar">
        <a class="logo" href="{{ $link('home') }}"><img src="{{ $brand['logo'] ?? route('brand.logo') }}" alt="" width="32" height="32"><span>{{ $brand['name'] }}</span></a>
        <nav aria-label="{{ __('Main') }}">
            @foreach ($nav as $key => $label)
                <a href="{{ $link($key) }}" @if ($page === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <a href="{{ $locale === 'en' ? $alt($page)['fr'] : $alt($page)['en'] }}" hreflang="{{ $locale === 'en' ? 'fr' : 'en' }}" lang="{{ $locale === 'en' ? 'fr' : 'en' }}">{{ $locale === 'en' ? 'Français' : 'English' }}</a>
            <a href="/login">{{ __('Log in') }}</a>
            <a class="btn" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a>
        </nav>
    </div>
</header>
<main id="main">
    @yield('main')
</main>
<footer>
    <div class="wrap">
        <div class="links">
            <span>© {{ date('Y') }} {{ $brand['name'] }}</span>
            <a href="/legal/terms?lang={{ $locale }}">{{ __('Terms of service') }}</a>
            <a href="/legal/privacy?lang={{ $locale }}">{{ __('Privacy notice') }}</a>
            <a href="/legal/dpa?lang={{ $locale }}">{{ __('Data processing agreement') }}</a>
            <a href="{{ $locale === 'en' ? '/en/status' : '/status' }}">{{ __('Service status') }}</a>
            @if ($brand['support_email'])<a href="mailto:{{ $brand['support_email'] }}">{{ $brand['support_email'] }}</a>@endif
        </div>
    </div>
</footer>
</body>
</html>
