@extends('site.layout')

@section('main')
    @php($link = fn (string $p) => $locale === 'en' ? ($p === 'home' ? '/en' : '/en/'.$p) : ($p === 'home' ? '/' : '/'.$p))
    @if ($page === 'home')
        <section class="hero">
            <div class="wrap">
                <h1>{{ $title }}</h1>
                <p>{{ $description }}</p>
                <div class="actions">
                    <a class="btn" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a>
                    @if ($canSignUp)<a class="btn ghost" href="/signup">{{ __('Start the free trial') }}</a>@endif
                    <a class="btn ghost" href="{{ $link('pricing') }}">{{ __('See the prices') }}</a>
                </div>
            </div>
        </section>
        <section class="wrap" aria-label="{{ __('Example conversation') }}">
            <div class="chat">
                <div class="msg in">{{ __('Hello, do you have a room for two tonight? How much with breakfast?') }}</div>
                <div class="msg out">{{ __('Good evening! Yes, a Standard room is free tonight: 20,000 FCFA, breakfast included. Shall I prepare the booking? Reception will confirm it within minutes.') }}</div>
                <div class="msg in">{{ __('Yes please, arriving around 9 pm.') }}</div>
            </div>
        </section>
        <section class="content"><div class="wrap">{!! $html !!}</div></section>
    @elseif ($page === 'pricing')
        <section class="hero"><div class="wrap">
            <h1>{{ $title }}</h1>
            <p>{{ $description }}</p>
            <form method="get" action="{{ $link('pricing') }}" class="actions">
                <label>{{ __('Currency') }}
                    <select name="currency">
                        @foreach ($currencies as $c)<option value="{{ $c }}" @selected($c === $pricing['currency'])>{{ $c }}</option>@endforeach
                    </select>
                </label>
                <button class="btn ghost" type="submit">{{ __('Show') }}</button>
            </form>
        </div></section>
        <section class="content"><div class="wrap">
            <div class="grid">
                @foreach ($pricing['plans'] as $plan)
                    <article class="card {{ $plan['featured'] ? 'featured' : '' }}">
                        <h2 style="margin:0">{{ $plan['name'] }}</h2>
                        @if ($plan['description'])<p class="muted">{{ $plan['description'] }}</p>@endif
                        <p class="price">{{ $plan['monthly'] }} <span class="muted">/ {{ __('month') }}</span></p>
                        @if ($plan['yearly'])<p class="muted">{{ __('or :price a year', ['price' => $plan['yearly']]) }}@if ($plan['yearly_saving']) — {{ __('save :amount', ['amount' => $plan['yearly_saving']]) }}@endif</p>@endif
                        <ul class="ticks">
                            <li>{{ __('Rooms, bookings and front desk') }}</li>
                            <li>{{ __('WhatsApp assistant') }}</li>
                            @if (in_array('restaurant', $plan['modules'], true))<li>{{ __('Restaurant and bar') }}</li>@endif
                            @if (in_array('ordering', $plan['modules'], true))<li>{{ __('Room orders and room bill') }}</li>@endif
                            @if (in_array('events', $plan['modules'], true))<li>{{ __('Events') }}</li>@endif
                            <li>{{ $plan['staff_limit'] === null ? __('Unlimited staff accounts') : __('Up to :limit staff accounts', ['limit' => $plan['staff_limit']]) }}</li>
                            <li>{{ __(':credits AI credits / month', ['credits' => number_format($plan['ai_credits'], 0, ',', ' ')]) }}</li>
                            <li>{{ __(':credits email credits / month', ['credits' => number_format($plan['email_credits'], 0, ',', ' ')]) }}</li>
                        </ul>
                        @if ($canSignUp)<a class="btn" href="/signup">{{ __(':days days free', ['days' => $plan['trial_days']]) }}</a>@endif
                    </article>
                @endforeach
            </div>
            <p class="muted">{{ $pricing['includes_tax'] ? __('Prices include VAT.') : __('Prices exclude VAT.') }} {{ __('WhatsApp messaging fees are billed by Meta directly to the hotel.') }}</p>
            @if ($pricing['packs'] !== [])
                <h2>{{ __('Extra credits') }}</h2>
                <ul class="ticks">@foreach ($pricing['packs'] as $pack)<li>{{ $pack['name'] }} — {{ $pack['price'] }}</li>@endforeach</ul>
            @endif
        </div></section>
    @elseif ($page === 'demo')
        <section class="hero"><div class="wrap">
            <h1>{{ $title }}</h1>
            <p>{{ $description }}</p>
        </div></section>
        <section class="content"><div class="wrap">
            @if (session('status') === 'sent')
                <p class="notice" role="status">{{ __('Thank you! Our team will contact you on WhatsApp shortly.') }}</p>
            @endif
            <form class="demo" method="post" action="{{ $link('demo') }}">
                @csrf
                <div class="two">
                    <label>{{ __('Your name') }}<input name="name" required maxlength="120" autocomplete="name" value="{{ old('name') }}"></label>
                    <label>{{ __('Hotel name') }}<input name="hotel_name" required maxlength="120" value="{{ old('hotel_name') }}"></label>
                </div>
                @error('name')<p class="error">{{ $message }}</p>@enderror
                @error('hotel_name')<p class="error">{{ $message }}</p>@enderror
                <div class="two">
                    <label>{{ __('Country') }}
                        <select name="country_code">
                            <option value="">—</option>
                            @foreach ($countries as $c)<option value="{{ $c['code'] }}" @selected(old('country_code', 'CI') === $c['code'])>{{ $c['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('City') }}<input name="city" maxlength="120" value="{{ old('city') }}"></label>
                </div>
                <div class="two">
                    <label>{{ __('WhatsApp number') }}<input name="whatsapp" required maxlength="25" inputmode="tel" autocomplete="tel" value="{{ old('whatsapp') }}"></label>
                    <label>{{ __('Number of rooms') }}<input name="rooms" type="number" min="1" max="2000" value="{{ old('rooms') }}"></label>
                </div>
                @error('whatsapp')<p class="error">{{ $message }}</p>@enderror
                <div class="two">
                    <label>{{ __('Email (optional)') }}<input name="email" type="email" maxlength="191" autocomplete="email" value="{{ old('email') }}"></label>
                    <label>{{ __('Best time to call') }}<input name="contact_time" maxlength="60" value="{{ old('contact_time') }}"></label>
                </div>
                <label>{{ __('Message (optional)') }}<textarea name="message" rows="4" maxlength="2000">{{ old('message') }}</textarea></label>
                <label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
                <div><button class="btn" type="submit">{{ __('Send my request') }}</button></div>
            </form>
        </div></section>
    @else
        <section class="hero"><div class="wrap"><h1>{{ $title }}</h1><p>{{ $description }}</p></div></section>
        <section class="content"><div class="wrap">
            {!! $html !!}
            @if ($page === 'countries')
                <ul class="countries">@foreach ($countries as $c)<li>{{ $c['name'] }} ({{ $c['currency'] }})</li>@endforeach</ul>
            @endif
            <p class="actions" style="margin-top:32px"><a class="btn" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a></p>
        </div></section>
    @endif
@endsection
