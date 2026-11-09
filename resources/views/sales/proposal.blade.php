@php
    $features = [__('Rooms, bookings and front desk'), __('WhatsApp assistant')];
    if (in_array('restaurant', $modules, true)) { $features[] = __('Restaurant and bar'); }
    if (in_array('ordering', $modules, true)) { $features[] = __('Room orders and room bill'); }
    if (in_array('events', $modules, true)) { $features[] = __('Events'); }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Proposal for :hotel', ['hotel' => $p->lead->hotel_name]) }} · {{ $brand['name'] }}</title>
    <style>
        :root { --brand: {{ $brand['color'] }}; }
        body { font-family: {{ $pdf ? 'DejaVu Sans, sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' }}; color: #111827; margin: 0; background: #fff; line-height: 1.5; font-size: {{ $pdf ? '11px' : '16px' }}; }
        .wrap { max-width: 760px; margin: 0 auto; padding: {{ $pdf ? '0' : '24px 20px 48px' }}; }
        .band { background: {{ $brand['color'] }}; color: #fff; padding: 18px 22px; border-radius: {{ $pdf ? '0' : '14px' }}; }
        .band h1 { margin: 0 0 4px; font-size: {{ $pdf ? '20px' : '26px' }}; }
        h2 { color: #111827; font-size: {{ $pdf ? '14px' : '19px' }}; margin: 22px 0 8px; }
        .muted { color: #4b5563; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td { border: 1px solid #e5e7eb; padding: 10px; vertical-align: top; width: 33%; }
        .big { font-size: {{ $pdf ? '17px' : '24px' }}; font-weight: bold; color: #111827; }
        .btn { display: inline-block; background: {{ $brand['color'] }}; color: #fff; text-decoration: none; padding: 12px 18px; border-radius: 10px; font-weight: 600; border: 0; font-size: 16px; cursor: pointer; }
        .btn.ghost { background: #fff; color: #111827; border: 1px solid #d1d5db; }
        .note { border-left: 4px solid {{ $brand['color'] }}; padding: 6px 12px; background: #f9fafb; }
        .btn:focus-visible { outline: 3px solid #111827; outline-offset: 2px; }
    </style>
</head>
<body>
<main class="wrap">
    <div class="band">
        <h1>{{ __('Proposal for :hotel', ['hotel' => $p->lead->hotel_name]) }}</h1>
        <div>{{ $brand['name'] }} · {{ __('valid until :date', ['date' => $p->valid_until->format('d/m/Y')]) }}</div>
    </div>

    @if ($p->note)
        <p class="note">{{ $p->note }}</p>
    @endif

    <h2>{{ __('What the assistant brings you each month') }}</h2>
    <table class="grid">
        <tr>
            <td><div class="muted">{{ __('Bookings recovered') }}</div><div class="big">{{ number_format($roi['bookings'], 1, ',', ' ') }}</div></td>
            <td><div class="muted">{{ __('Extra revenue') }}</div><div class="big">{{ $roi['revenue_text'] }}</div></td>
            <td><div class="muted">{{ __('Pays for itself in') }}</div><div class="big">{{ $roi['payback_days'] === null ? '—' : trans_choice(':count day|:count days', $roi['payback_days']) }}</div></td>
        </tr>
    </table>
    <p class="muted">{{ __('Based on :enquiries WhatsApp enquiries a day, :missed% missed today (nights, busy desk), :conversion% of those booking, :nights nights on average at :price.', ['enquiries' => $inputs['enquiries_per_day'], 'missed' => $inputs['missed_percent'], 'conversion' => $inputs['conversion_percent'], 'nights' => $inputs['average_nights'], 'price' => $roi['night_price_text']]) }}
        @if ($roi['multiple']) {{ __('That is :multiple times the price of the plan.', ['multiple' => number_format($roi['multiple'], 1, ',', ' ')]) }} @endif
    </p>

    <h2>{{ __('Recommended plan: :plan', ['plan' => $plan]) }}</h2>
    <p class="big">{{ $price }} <span class="muted" style="font-size:14px">/ {{ $p->cycle === 'yearly' ? __('year') : __('month') }}</span></p>
    @if ($p->discount_percent > 0)
        <p class="muted">{{ __(':percent% off the list price of :price.', ['percent' => $p->discount_percent, 'price' => $base]) }}</p>
    @endif
    <p class="muted">{{ $includesTax ? __('Prices include VAT.') : __('Prices exclude VAT.') }} {{ __('WhatsApp messaging fees are billed by Meta directly to the hotel.') }}</p>
    <ul>@foreach ($features as $f)<li>{{ $f }}</li>@endforeach</ul>

    <h2>{{ __('Next step') }}</h2>
    <p>{{ __(':days days free trial, guided setup in about an hour, then you decide.', ['days' => $p->plan->trial_days]) }}</p>
    @if (! $pdf)
        @if ($p->expired())
            <p class="note">{{ __('This proposal has expired. Contact us for a new one.') }}</p>
        @else
            <form method="post" action="{{ url('/proposal/'.$p->token.'/accept') }}" style="display:inline">
                @csrf
                <button class="btn" type="submit">{{ __('Start my trial') }}</button>
            </form>
        @endif
        <a class="btn ghost" href="{{ url('/proposal/'.$p->token.'/pdf') }}">{{ __('Download as PDF') }}</a>
    @else
        <p>{{ __('Start your trial here: :url', ['url' => $url]) }}</p>
    @endif

    <p class="muted" style="margin-top:28px">{{ $issuer }} @if ($brand['support_email'])· {{ $brand['support_email'] }}@endif @if ($brand['support_whatsapp'])· WhatsApp {{ $brand['support_whatsapp'] }}@endif</p>
</main>
</body>
</html>
