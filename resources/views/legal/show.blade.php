<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.55; margin: 0; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 1.5rem; } .muted { color: #6b7280; font-size: .85rem; }
    </style>
</head>
<body>
<main>
    <p class="muted">{{ $title }} · {{ __('Version :version', ['version' => $version]) }} · <a href="?lang=fr">FR</a> · <a href="?lang=en">EN</a></p>
    {!! $html !!}
</main>
</body>
</html>
