<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Quote') }} {{ $event->reference }} · {{ $hotel->name }}</title>
    <style>
        :root { color-scheme: light dark; --fg:#111827; --muted:#6b7280; --bg:#fafaf9; --card:#fff; --line:#e5e7eb; --accent:#312e81; }
        @media (prefers-color-scheme: dark) { :root { --fg:#f3f4f6; --muted:#9ca3af; --bg:#111827; --card:#1f2937; --line:#374151; --accent:#a5b4fc; } }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--fg); }
        main { max-width: 760px; margin: 0 auto; padding: 20px 16px 48px; }
        h1 { color:var(--accent); font-size:1.4rem; margin:0; }
        .muted { color:var(--muted); font-size:.9rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; margin-top:16px; }
        table { width:100%; border-collapse:collapse; font-size:.92rem; }
        td, th { padding:6px 4px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        td.num, th.num { text-align:right; white-space:nowrap; }
        .total td { font-weight:700; border-bottom:none; }
        .banner { border-radius:10px; padding:10px 12px; margin-top:16px; }
        .ok { background:#dcfce7; color:#14532d; } .warn { background:#fef3c7; color:#78350f; }
        label { display:block; margin-top:10px; font-size:.9rem; }
        input[type=text] { width:100%; padding:8px; border:1px solid var(--line); border-radius:8px; background:transparent; color:inherit; box-sizing:border-box; }
        button { margin-top:14px; background:var(--accent); color:#fff; border:0; border-radius:8px; padding:10px 16px; font-size:1rem; }
        pre { white-space:pre-wrap; font-family:inherit; font-size:.85rem; color:var(--muted); }
        @media print { form, .no-print { display:none; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $hotel->name }}</h1>
    <p class="muted">{{ trim(implode(', ', array_filter([$settings->address_line1, $settings->city]))) }} {{ $settings->phone_e164 }}</p>

    <div class="card">
        <strong>{{ __('Quote') }} {{ $event->reference }} · V{{ $quote->version }}</strong><br>
        <span class="muted">{{ $event->company ?: $event->client_name }} · {{ $event->date->toDateString() }} · {{ __(':people people', ['people' => $event->people]) }}</span><br>
        <span class="muted">{{ __('Valid until :date', ['date' => $quote->valid_until->toDateString()]) }}</span>

        <table style="margin-top:12px">
            <thead><tr><th>{{ __('Description') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @foreach ($quote->lines as $line)
                <tr><td>{{ $line->description }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ $money($line->unit_price_minor) }}</td><td class="num">{{ $money($line->total_minor) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="3">{{ __('Subtotal') }}</td><td class="num">{{ $money($quote->subtotal_minor) }}</td></tr>
                @if ($quote->discount_minor > 0)
                    <tr><td colspan="3">{{ __('Discount') }}</td><td class="num">−{{ $money($quote->discount_minor) }}</td></tr>
                @endif
                <tr><td colspan="3">{{ __('Taxes') }}</td><td class="num">{{ $money($quote->tax_minor) }}</td></tr>
                <tr class="total"><td colspan="3">{{ __('Total') }}</td><td class="num">{{ $money($quote->total_minor) }}</td></tr>
                @if ($deposit > 0)
                    <tr><td colspan="3">{{ __('Deposit to confirm') }}</td><td class="num">{{ $money($deposit) }}</td></tr>
                @endif
            </tfoot>
        </table>
    </div>

    @if ($quote->terms)
        <div class="card"><strong>{{ __('Terms') }}</strong><pre>{{ $quote->terms }}</pre></div>
    @endif

    @if ($quote->status === 'accepted')
        <p class="banner ok">{{ __('Accepted by :name on :date. Thank you!', ['name' => $quote->accepted_name, 'date' => $quote->accepted_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i')]) }}</p>
    @elseif ($canAccept)
        <form method="POST" class="card">
            @csrf
            <strong>{{ __('Accept this quote') }}</strong>
            <label>{{ __('Your full name') }} <input type="text" name="name" required maxlength="150" value="{{ old('name') }}"></label>
            <label>{{ __('Your title (optional)') }} <input type="text" name="title" maxlength="150" value="{{ old('title') }}"></label>
            <label><input type="checkbox" name="terms" value="1" required> {{ __('I accept the quote and the terms above.') }}</label>
            @if ($errors->any())<p class="banner warn">{{ $errors->first() }}</p>@endif
            <button type="submit">{{ __('Accept this quote') }}</button>
        </form>
    @else
        <p class="banner warn">{{ __('This quote can no longer be accepted. Please contact the hotel.') }}</p>
    @endif

    <p class="muted no-print">{{ __('Not a tax invoice.') }}</p>
</main>
</body>
</html>
