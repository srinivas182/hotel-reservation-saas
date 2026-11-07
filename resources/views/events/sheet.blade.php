<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Event sheet') }} · {{ $event->reference }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color:#111827; margin:0; }
        main { max-width: 760px; margin:0 auto; padding:24px 16px; }
        h1 { font-size:1.3rem; margin:0; color:#312e81; }
        h2 { font-size:1rem; margin:20px 0 6px; border-bottom:1px solid #e5e7eb; }
        .muted { color:#6b7280; font-size:.9rem; }
        .updated { background:#fef3c7; padding:6px 10px; border-radius:6px; display:inline-block; margin-top:8px; }
        table td { padding:3px 8px 3px 0; vertical-align:top; }
        pre { white-space:pre-wrap; font-family:inherit; margin:0; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('Event sheet') }} · {{ $event->reference }}</h1>
    <p class="muted">{{ $hotel->name }} · {{ $event->company ?: $event->client_name }} · {{ $event->date->toDateString() }} · {{ $space }} · {{ $block }} · {{ __(':people people', ['people' => $event->people]) }}</p>
    @if ($sheet)
        <p class="muted">{{ __('Version :version, :date, :by', ['version' => $sheet->version, 'date' => $sheet->created_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'), 'by' => $sheet->author?->name]) }}</p>
        @if ($sheet->after_confirmation)<p class="updated">{{ __('Updated after confirmation (:count change(s)). Check the details.', ['count' => $changes]) }}</p>@endif

        <h2>{{ __('Timings') }}</h2>
        <table>
            @foreach ($sheet->content['timings'] ?? [] as $t)<tr><td><strong>{{ $t['time'] }}</strong></td><td>{{ $t['what'] }}</td></tr>@endforeach
        </table>
        @foreach (['menu' => __('Menu'), 'equipment' => __('Equipment'), 'kitchen' => __('Kitchen'), 'setup' => __('Setup'), 'reception' => __('Reception')] as $key => $label)
            @if (! empty($sheet->content[$key]))<h2>{{ $label }}</h2><pre>{{ $sheet->content[$key] }}</pre>@endif
        @endforeach
    @else
        <p>{{ __('No event sheet yet.') }}</p>
    @endif
</main>
</body>
</html>
