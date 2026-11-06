<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $outlet }} · {{ $hotel->name }}</title>
    <style>
        :root { color-scheme: light dark; --fg:#1f2937; --muted:#6b7280; --bg:#fafaf9; --card:#fff; --line:#e5e7eb; --accent:#312e81; }
        @media (prefers-color-scheme: dark) { :root { --fg:#f3f4f6; --muted:#9ca3af; --bg:#111827; --card:#1f2937; --line:#374151; --accent:#a5b4fc; } }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--fg); line-height:1.4; }
        main { max-width: 680px; margin: 0 auto; padding: 20px 16px 48px; }
        h1 { margin:0; font-size:1.6rem; color:var(--accent); }
        .sub { color:var(--muted); margin:4px 0 16px; }
        nav a { color:var(--accent); margin-right:12px; font-size:.9rem; }
        h2 { font-size:1.1rem; margin:28px 0 8px; border-bottom:1px solid var(--line); padding-bottom:4px; }
        .item { display:flex; gap:12px; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px; margin:8px 0; }
        .item img { width:72px; height:72px; object-fit:cover; border-radius:8px; flex:none; }
        .row { display:flex; justify-content:space-between; gap:12px; align-items:baseline; }
        .name { font-weight:600; }
        .price { white-space:nowrap; font-weight:600; }
        .desc { color:var(--muted); font-size:.92rem; margin-top:2px; }
        .tags { margin-top:6px; font-size:.78rem; color:var(--muted); }
        .tag { display:inline-block; border:1px solid var(--line); border-radius:999px; padding:1px 8px; margin:2px 4px 0 0; }
        .empty { color:var(--muted); margin-top:24px; }
        footer { color:var(--muted); font-size:.8rem; margin-top:32px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $outlet }}</h1>
    <p class="sub">{{ $hotel->name }}@if ($menu['period']) · {{ $menu['period'] }}@endif</p>
    <nav>
        @foreach ($locales as $code)
            @if ($code !== $locale)<a href="?lang={{ $code }}{{ $all ? '&all=1' : '' }}">{{ strtoupper($code) }}</a>@endif
        @endforeach
        @if ($all)
            <a href="?lang={{ $locale }}">{{ __('Now') }}</a>
        @else
            <a href="?lang={{ $locale }}&all=1">{{ __('Whole menu') }}</a>
        @endif
    </nav>

    @forelse ($menu['sections'] as $section)
        <h2>{{ $section['name'] }}</h2>
        @foreach ($section['items'] as $item)
            <div class="item">
                @if ($item['photo'])
                    <img src="{{ route('menu.public.photo', [$hotel->slug, $outletSlug, $item['id']]) }}" alt="" loading="lazy">
                @endif
                <div style="flex:1">
                    <div class="row"><span class="name">{{ $item['name'] }}</span><span class="price">{{ $item['price_text'] }}</span></div>
                    @if ($item['description'])<div class="desc">{{ $item['description'] }}</div>@endif
                    @if ($item['labels'] || $item['allergens'])
                        <div class="tags">
                            @foreach ($item['labels'] as $label)<span class="tag">{{ $label }}</span>@endforeach
                            @if ($item['allergens'])<span>{{ __('Contains:') }} {{ implode(', ', $item['allergens']) }}</span>@endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    @empty
        <p class="empty">{{ __('The menu is not available at the moment.') }}</p>
    @endforelse

    <footer>{{ __('Prices include taxes. Ask our team about allergens.') }}</footer>
</main>
</body>
</html>
