<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Statement of account') }} · {{ $booking->reference }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color:#111827; margin:0; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px; }
        header { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; border-bottom:2px solid #312e81; padding-bottom:12px; }
        h1 { font-size:1.3rem; margin:0; color:#312e81; }
        .muted { color:#6b7280; font-size:.85rem; }
        table { width:100%; border-collapse:collapse; margin-top:16px; font-size:.9rem; }
        th, td { text-align:left; padding:6px 4px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
        td.num, th.num { text-align:right; white-space:nowrap; }
        tr.corrected td { color:#9ca3af; text-decoration:line-through; }
        tfoot td { font-weight:600; border-bottom:none; }
        .balance td { font-size:1.05rem; border-top:2px solid #111827; }
        .note { margin-top:24px; font-size:.8rem; color:#6b7280; }
        @media print { .no-print { display:none; } main { padding:0; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>{{ $hotel->name }}</h1>
            <div class="muted">{{ trim(implode(', ', array_filter([$settings->address_line1, $settings->city]))) }} {{ $settings->phone_e164 }}</div>
        </div>
        <div style="text-align:right">
            <strong>{{ __('Statement of account') }}</strong><br>
            <span class="muted">{{ __('Booking') }} {{ $booking->reference }}</span><br>
            <span class="muted">{{ now($hotel->timezone)->format('Y-m-d H:i') }}</span>
        </div>
    </header>

    <p>
        <strong>{{ $booking->guest?->name() }}</strong><br>
        <span class="muted">{{ $booking->check_in->toDateString() }} → {{ $booking->check_out->toDateString() }}
            @php($rooms = $booking->bookingRooms->map(fn ($r) => $r->room?->number)->filter()->implode(', '))
            @if ($rooms) · {{ __('Room') }} {{ $rooms }} @endif
        </span>
    </p>

    <table>
        <thead>
            <tr><th>{{ __('Date') }}</th><th>{{ __('Description') }}</th><th class="num">{{ __('Charges') }}</th><th class="num">{{ __('Payments') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['lines'] as $line)
                <tr class="{{ $line['corrected'] ? 'corrected' : '' }}">
                    <td>{{ substr((string) $line['at'], 0, 10) }}</td>
                    <td>{{ $line['description'] }}@if ($line['quantity'] > 1) × {{ $line['quantity'] }}@endif @if ($line['reason'])<br><span class="muted">{{ $line['reason'] }}</span>@endif</td>
                    <td class="num">{{ $line['type'] !== 'payment' ? $money($line['amount_minor']) : '' }}</td>
                    <td class="num">{{ $line['type'] === 'payment' ? $money($line['amount_minor']) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td></td><td>{{ __('Totals') }}</td><td class="num">{{ $money($statement['charges_minor']) }}</td><td class="num">{{ $money($statement['payments_minor']) }}</td></tr>
            <tr class="balance"><td></td><td>{{ __('Balance due') }}</td><td class="num" colspan="2">{{ $money($statement['balance_minor']) }}</td></tr>
        </tfoot>
    </table>

    <p class="note">{{ __('Statement of account, not a tax invoice. Amounts include taxes.') }}</p>
    <p class="no-print"><button onclick="window.print()">{{ __('Print') }}</button></p>
</main>
</body>
</html>
