<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --fg: #0f172a; --muted: #64748b; --accent: #4f46e5; --card: #ffffff; --border: #e2e8f0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1020; --fg: #e2e8f0; --muted: #94a3b8; --accent: #818cf8; --card: #111827; --border: #1f2937; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 24px; }
        main { max-width: 440px; width: 100%; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; text-align: center; }
        .code { font-size: 14px; letter-spacing: .08em; color: var(--muted); }
        h1 { font-size: 22px; margin: 8px 0 12px; }
        p { color: var(--muted); line-height: 1.55; margin: 0 0 20px; }
        a { display: inline-block; background: var(--accent); color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 10px; font-weight: 600; }
        a:focus-visible { outline: 3px solid var(--fg); outline-offset: 2px; }
        .ref { margin-top: 18px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<main role="main">
    <div class="code">{{ config('app.name') }} · {{ $code }}</div>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    <a href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
    @if ($requestId = \Illuminate\Support\Facades\Context::get('request_id'))
        <div class="ref">{{ __('Reference: :id', ['id' => $requestId]) }}</div>
    @endif
</main>
</body>
</html>
