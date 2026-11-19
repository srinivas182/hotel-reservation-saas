<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Simulated payment') }}</title>
    <style>
        body { margin:0; font-family: system-ui, sans-serif; background:#fff7ed; color:#111827; }
        main { max-width: 460px; margin: 0 auto; padding: 24px 16px; }
        .badge { display:inline-block; background:#f97316; color:#fff; border-radius:999px; padding:2px 10px; font-size:.8rem; }
        label { display:block; margin-top:10px; }
        select, button { width:100%; padding:12px; font-size:1rem; border-radius:10px; margin-top:6px; }
        button { border:0; background:#111827; color:#fff; margin-top:12px; }
        button.refuse { background:#b91c1c; }
    </style>
</head>
<body>
<main>
    <span class="badge">{{ __('Simulation: no real money') }}</span>
    <h1>{{ $hotel->name }}</h1>
    <p>{{ __('Amount') }}: <strong>{{ $amount }}</strong> · {{ $attempt->reference }}</p>
    <form method="post" action="{{ route('deposits.simulate.pay', $attempt->reference) }}">
        @csrf
        <label for="method">{{ __('Pay with') }}</label>
        <select id="method" name="method">
            @foreach ($methods as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" name="outcome" value="paid">{{ __('Pay') }}</button>
        <button type="submit" name="outcome" value="failed" class="refuse">{{ __('Refuse the payment') }}</button>
    </form>
</main>
</body>
</html>
