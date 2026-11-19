<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    @if ($url)
        <meta http-equiv="refresh" content="0;url={{ $url }}">
    @endif
    <title>{{ $hotel->name }} · WhatsApp</title>
    <style>
        :root { color-scheme: light dark; --fg:#111827; --muted:#4b5563; --bg:#f7f7f5; --card:#fff; --line:#e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --fg:#f3f4f6; --muted:#9ca3af; --bg:#0b1220; --card:#111827; --line:#1f2937; } }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--fg); }
        main { max-width: 420px; margin: 0 auto; padding: 40px 16px; text-align:center; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:24px 18px; }
        h1 { font-size:1.3rem; margin:0 0 6px; }
        p { color:var(--muted); line-height:1.45; }
        a.btn { display:block; margin-top:14px; padding:14px; border-radius:12px; text-decoration:none; font-weight:700; font-size:1.05rem; }
        a.wa { background: {{ $color }}; color:#fff; }
        a.call { border:2px solid var(--line); color:inherit; }
        .number { font-size:1.4rem; font-weight:700; letter-spacing:.02em; color:var(--fg); }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>{{ $hotel->name }}</h1>
        @if ($url)
            <p>{{ __('Opening WhatsApp… If nothing happens, tap the button.') }}</p>
            <a class="btn wa" href="{{ $url }}">{{ __('Chat on WhatsApp') }}</a>
            <p class="number">+{{ $number }}</p>
            <p>{{ __('No WhatsApp? Add this number, or call us.') }}</p>
        @else
            <p>{{ __('The hotel’s WhatsApp number is not set yet.') }}</p>
        @endif
        @if ($phone)
            <a class="btn call" href="tel:{{ $phone }}">{{ __('Call the hotel') }}</a>
        @endif
    </div>
</main>
</body>
</html>
