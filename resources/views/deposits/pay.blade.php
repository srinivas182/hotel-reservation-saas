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
        button.alt { background:transparent; color:inherit; border:2px solid var(--accent); }
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
    @if ($notice)
        <div class="ok" role="status">{{ $notice }}</div>
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
                <span><span class="amount">{{ $deposit }}</span><br><span class="muted">{{ $balance ? ($saving ? __('The whole stay, paid now: you save :percent%', ['percent' => $saving]) : __('What is left of your stay: no queue on arrival')) : __('The deposit, to confirm the booking') }}</span></span>
            </label>
            @if ($full)
                <label class="choice">
                    <input type="radio" name="choice" value="full">
                    <span><span class="amount">{{ $full }}</span><br><span class="muted">{{ $saving ? __('The whole stay, paid now: you save :percent%', ['percent' => $saving]) : __('The whole stay, nothing to pay on arrival') }}</span></span>
                </label>
            @endif
            @if ($mobileMoney)
                <button type="submit" name="method" value="{{ $mobileMoney }}">{{ __('Pay by mobile money') }}</button>
                <div class="methods" aria-label="{{ __('Accepted') }}"><span>Wave</span><span>Orange Money</span><span>MTN MoMo</span><span>Moov Money</span></div>
            @endif
            @if ($cards)
                <button type="submit" name="method" value="stripe" @class(['alt' => $mobileMoney])>{{ __('Pay by card') }}</button>
                <div class="methods" aria-label="{{ __('Accepted') }}"><span>Visa</span><span>Mastercard</span><span>American Express</span></div>
            @endif
            @if ($bank && $transferUntil)
                <button type="submit" name="method" value="bank_transfer" @class(['alt' => $mobileMoney || $cards])>{{ __('Pay by bank transfer') }}</button>
                <small class="muted">{{ __('Transfer before :deadline; the hotel confirms once the money arrives.', ['deadline' => $transferUntil]) }}</small>
            @endif
            @if ($mobileMoney || $cards)
                <small class="muted">{{ __('You pay :hotel directly, through its payment provider.', ['hotel' => $hotel->name]) }}</small>
            @endif
            @if (! $mobileMoney && ! $cards && ! ($bank && $transferUntil))
                <p class="warn">{{ __('Online payment is not available. Write to the hotel on WhatsApp.') }}</p>
            @endif
        </form>

        @if ($transfer && $bank)
            <div class="card" aria-label="{{ __('Bank transfer') }}">
                <h2>{{ __('Your bank transfer') }}</h2>
                <div class="row"><span class="muted">{{ __('Amount') }}</span><span class="amount">{{ $transfer['amount'] }}</span></div>
                <div class="row"><span class="muted">{{ __('Reference to write on the transfer') }}</span><strong>{{ $transfer['reference'] }}</strong></div>
                <div class="row"><span class="muted">{{ __('Account holder') }}</span><span>{{ $bank['account_holder'] }}</span></div>
                @if (($bank['bank_name'] ?? '') !== '')
                    <div class="row"><span class="muted">{{ __('Bank') }}</span><span>{{ $bank['bank_name'] }}</span></div>
                @endif
                @if (($bank['account_number'] ?? '') !== '')
                    <div class="row"><span class="muted">{{ __('Account number') }}</span><span>{{ $bank['account_number'] }}</span></div>
                @endif
                @if (($bank['iban'] ?? '') !== '')
                    <div class="row"><span class="muted">IBAN</span><span>{{ $bank['iban'] }}</span></div>
                @endif
                @if (($bank['swift'] ?? '') !== '')
                    <div class="row"><span class="muted">SWIFT / BIC</span><span>{{ $bank['swift'] }}</span></div>
                @endif
                @if (($bank['branch'] ?? '') !== '')
                    <div class="row"><span class="muted">{{ __('Branch') }}</span><span>{{ $bank['branch'] }}</span></div>
                @endif
                @if (($bank['note'] ?? '') !== '')
                    <p class="muted">{{ $bank['note'] }}</p>
                @endif
                <p class="held">⏳ {{ __('Transfer before :deadline, then send the receipt below.', ['deadline' => $transfer['deadline']]) }}</p>
                @if ($transfer['proof_at'])
                    <div class="ok" role="status">{{ __('Receipt received :at. The hotel checks its bank account and confirms on WhatsApp.', ['at' => $transfer['proof_at']]) }}</div>
                @endif
                <form method="post" action="{{ route('deposits.proof', $token) }}" enctype="multipart/form-data">
                    @csrf
                    <label class="muted" for="receipt">{{ $transfer['proof_at'] ? __('Send another receipt') : __('Your transfer receipt (photo or PDF)') }}</label>
                    <input id="receipt" type="file" name="receipt" accept="image/*,application/pdf" required style="display:block;margin-top:8px;width:100%">
                    <button type="submit">{{ __('Send the receipt') }}</button>
                </form>
                <small class="muted">{{ __('You can also send the receipt on WhatsApp. Nothing is confirmed until the hotel sees the money on its account.') }}</small>
            </div>
        @endif

        @if ($manual !== '' && ! $bank)
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
