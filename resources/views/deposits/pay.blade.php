<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Deposit') }} {{ $booking->reference }} · {{ $hotel->name }}</title>
    <style>
        :root { color-scheme: light dark; --fg:#111827; --muted:#4b5563; --bg:#f7f7f5; --card:#fff; --line:#e5e7eb; --accent:#0f5132; --accent-fg:#fff; --soft:#ecfdf3; }
        @media (prefers-color-scheme: dark) { :root { --fg:#f3f4f6; --muted:#9ca3af; --bg:#0b1220; --card:#111827; --line:#1f2937; --accent:#34d399; --accent-fg:#052e16; --soft:#052e16; } }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--fg); font-size:17px; line-height:1.45; }
        main { max-width: 520px; margin: 0 auto; padding: 20px 16px 48px; }
        h1 { font-size:1.35rem; margin:0 0 2px; }
        h2 { font-size:1.05rem; margin:0 0 8px; }
        .muted { color:var(--muted); font-size:.92rem; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px; margin-top:14px; }
        .row { display:flex; justify-content:space-between; gap:12px; padding:4px 0; }
        .held { background:var(--soft); border-radius:12px; padding:12px 14px; margin-top:14px; font-weight:600; }
        .choice { display:flex; gap:12px; align-items:flex-start; border:2px solid var(--line); border-radius:12px; padding:12px; margin-top:10px; cursor:pointer; }
        .choice input { width:22px; height:22px; margin-top:2px; accent-color: var(--accent); }
        .choice:has(input:checked) { border-color: var(--accent); }
        .amount { font-weight:700; font-size:1.15rem; }
        button { width:100%; margin-top:16px; background:var(--accent); color:var(--accent-fg); border:0; border-radius:12px; padding:15px 16px; font-size:1.1rem; font-weight:700; min-height:52px; }
        .methods { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
        .methods span { border:1px solid var(--line); border-radius:999px; padding:3px 10px; font-size:.85rem; }
        .ok { background:#dcfce7; color:#14532d; border-radius:12px; padding:14px; margin-top:14px; }
        .warn { background:#fef3c7; color:#78350f; border-radius:12px; padding:14px; margin-top:14px; }
        a.wa { display:block; text-align:center; margin-top:14px; padding:13px; border-radius:12px; border:2px solid var(--accent); color:inherit; text-decoration:none; font-weight:600; }
        small { display:block; margin-top:10px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $hotel->name }}</h1>
    <p class="muted">{{ trim(implode(', ', array_filter([$hotelSettings->address_line1, $hotelSettings->city]))) }}</p>

    <div class="card" aria-label="{{ __('Your booking') }}">
        <h2>{{ __('Booking :reference', ['reference' => $booking->reference]) }}</h2>
        <div class="row"><span class="muted">{{ __('Name') }}</span><span>{{ $booking->guest?->name() }}</span></div>
        @if ($room !== '')
            <div class="row"><span class="muted">{{ __('Room') }}</span><span>{{ $room }}</span></div>
        @endif
        <div class="row"><span class="muted">{{ __('Arrival') }}</span><span>{{ $checkIn }}</span></div>
        <div class="row"><span class="muted">{{ __('Departure') }}</span><span>{{ $checkOut }}</span></div>
    </div>

    @if ($error)
        <div class="warn" role="alert">{{ $error }}</div>
    @endif

    @if ($state === 'paid')
        <div class="ok" role="status">
            <strong>{{ $balance ? __('Payment received. Thank you, see you soon!') : __('Payment received. Your booking is confirmed.') }}</strong><br>
            {{ __(':amount by :method · receipt :receipt', ['amount' => $paid, 'method' => $method, 'receipt' => $link->receipt_number]) }}
            @if ($left !== null)
                <br>{{ __('Left to pay at the hotel: :left.', ['left' => $left]) }}
            @endif
        </div>
    @elseif ($state === 'open')
        @if ($deadline && ! $balance)
            <p class="held">⏳ {{ __('Your rooms are held until :deadline.', ['deadline' => $deadline]) }}</p>
        @endif

        @if ($pending)
            <div class="warn" role="status">{{ __('A payment is in progress. Once your provider confirms it, this page shows it. Reload in a minute.') }}</div>
        @endif

        <form method="post" action="{{ route('deposits.pay', $token) }}" class="card">
            @csrf
            <h2>{{ $balance ? __('Pay before you arrive') : __('Pay to confirm') }}</h2>
            <label class="choice">
                <input type="radio" name="choice" value="deposit" checked>
                <span><span class="amount">{{ $deposit }}</span><br><span class="muted">{{ $balance ? __('What is left of your stay: no queue on arrival') : __('The deposit, to confirm the booking') }}</span></span>
            </label>
            @if ($full)
                <label class="choice">
                    <input type="radio" name="choice" value="full">
                    <span><span class="amount">{{ $full }}</span><br><span class="muted">{{ __('The whole stay, nothing to pay on arrival') }}</span></span>
                </label>
            @endif
            <div class="methods" aria-label="{{ __('Accepted') }}"><span>Wave</span><span>Orange Money</span><span>MTN MoMo</span><span>Moov Money</span></div>
            <button type="submit">{{ __('Pay by mobile money') }}</button>
            <small class="muted">{{ __('You pay :hotel directly, through its payment provider.', ['hotel' => $hotel->name]) }}</small>
        </form>

        @if ($manual !== '')
            <div class="card">
                <h2>{{ __('Or pay by transfer') }}</h2>
                <p>{{ $manual }}</p>
                <p class="muted">{{ __('Then send the screenshot on WhatsApp: the hotel confirms it.') }}</p>
            </div>
        @endif
    @else
        <div class="warn" role="status">{{ __('This payment link is no longer valid. Write to the hotel on WhatsApp.') }}</div>
    @endif

    <div class="card">
        <h2>{{ __('Cancellation') }}</h2>
        <p class="muted">{{ $policy }}</p>
    </div>

    @if ($whatsapp)
        <a class="wa" href="{{ $whatsapp }}" rel="noopener">{{ __('Back to WhatsApp') }}</a>
    @endif
</main>
</body>
</html>
