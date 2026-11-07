<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111827; }
        .top { width: 100%; }
        .top td { vertical-align: top; }
        h1 { font-size: 18px; color: #312e81; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.lines th { background: #eef2ff; text-align: left; padding: 6px; }
        table.lines td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 45%; margin-left: 55%; margin-top: 10px; border-collapse: collapse; }
        .totals td { padding: 4px 6px; }
        .grand td { font-weight: bold; border-top: 2px solid #111827; }
        .box { border: 1px solid #e5e7eb; padding: 8px; margin-top: 16px; }
        .stamp { color: #047857; font-weight: bold; font-size: 14px; margin-top: 10px; }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td style="width:55%">
                <h1>{{ $invoice->issuer['name'] ?? '' }}</h1>
                <div>{{ $invoice->issuer['legal_name'] ?? '' }}</div>
                <div class="muted">{!! nl2br(e($invoice->issuer['address'] ?? '')) !!}</div>
                @if (! empty($invoice->issuer['registration']))<div class="muted">RCCM : {{ $invoice->issuer['registration'] }}</div>@endif
                @if (! empty($invoice->issuer['tax_id']))<div class="muted">{{ __('Tax ID') }} : {{ $invoice->issuer['tax_id'] }}</div>@endif
                <div class="muted">{{ $invoice->issuer['email'] ?? '' }} {{ $invoice->issuer['phone'] ?? '' }}</div>
            </td>
            <td>
                <h1>{{ $invoice->kind === 'credit_note' ? __('Credit note') : __('Invoice') }}</h1>
                <div><strong>{{ $invoice->number }}</strong></div>
                <div>{{ __('Date') }} : {{ $invoice->issued_on->toDateString() }}</div>
                @if ($invoice->kind === 'invoice')<div>{{ __('Due date') }} : {{ $invoice->due_on->toDateString() }}</div>@endif
                <div class="box">
                    <strong>{{ $invoice->customer['name'] ?? '' }}</strong><br>
                    {{ $invoice->customer['address'] ?? '' }}<br>
                    @if (! empty($invoice->customer['tax_id'])){{ __('Tax ID') }} : {{ $invoice->customer['tax_id'] }}@endif
                </div>
            </td>
        </tr>
    </table>

    @if ($invoice->reason)<p class="muted">{{ __('Reason') }} : {{ $invoice->reason }}</p>@endif

    <table class="lines">
        <thead><tr><th>{{ __('Description') }}</th><th class="num">{{ __('Quantity') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr><td>{{ $line->description }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ $money($line->unit_minor) }}</td><td class="num">{{ $money($line->amount_minor) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ __('Subtotal') }}</td><td class="num">{{ $money($invoice->subtotal_minor) }}</td></tr>
        @foreach ($invoice->taxes as $tax)
            <tr><td>{{ $tax['name'] }} ({{ rtrim(rtrim(number_format($tax['rate_bps'] / 100, 2, ',', ' '), '0'), ',') }} %)</td><td class="num">{{ $money($tax['amount_minor']) }}</td></tr>
        @endforeach
        <tr class="grand"><td>{{ __('Total') }}</td><td class="num">{{ $money($invoice->total_minor) }}</td></tr>
        @if ($invoice->kind === 'invoice' && $invoice->paid_minor > 0)
            <tr><td>{{ __('Paid') }}</td><td class="num">{{ $money($invoice->paid_minor) }}</td></tr>
            <tr><td>{{ __('Balance due') }}</td><td class="num">{{ $money($invoice->balance()) }}</td></tr>
        @endif
    </table>

    @if ($invoice->kind === 'invoice' && $invoice->balance() === 0 && $invoice->paid_minor > 0)<p class="stamp">{{ __('Paid') }}</p>@endif

    @if (! empty($invoice->issuer['payment_instructions']) && $invoice->kind === 'invoice')
        <div class="box"><strong>{{ __('How to pay') }}</strong><br>{!! nl2br(e($invoice->issuer['payment_instructions'])) !!}</div>
    @endif
    @if (! empty($invoice->issuer['footer']))<p class="muted">{!! nl2br(e($invoice->issuer['footer'])) !!}</p>@endif
</body>
</html>
