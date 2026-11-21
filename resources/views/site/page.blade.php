@extends('site.layout')

@section('main')
    @php
        $link = fn (string $p) => $locale === 'en' ? ($p === 'home' ? '/en' : '/en/'.$p) : ($p === 'home' ? '/' : '/'.$p);
        $icons = [
            'chat' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 20.5l1.6-4.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.3 3.5h.5a8.5 8.5 0 0 1 8.2 8z"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'wallet' => '<path d="M20 7H5a2 2 0 0 1 0-4h13v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15V7"/><path d="M16 14h.01"/>',
            'utensils' => '<path d="M3 2v7c0 1.1.9 2 2 2h2a2 2 0 0 0 2-2V2M6 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/>',
            'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'crown' => '<path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7z"/><path d="M5 20h14"/>',
            'desk' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/>',
            'glass' => '<path d="M8 22h8M12 15v7M5 3h14l-1 7a6 6 0 0 1-12 0z"/>',
            'party' => '<path d="M5.8 11.3 2 22l10.7-3.8M4 3h.01M22 8h.01M15 2h.01M22 20h.01M22 2l-2.2.7a2.9 2.9 0 0 0-1.9 3.3c.2 1-.5 2-1.5 2.1L15 8.3"/><path d="m9.7 14.3 3-3"/>',
        ];
        $icon = fn (string $k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$k].'</svg>';
        // The part after the last comma of the editable title gets the highlight ("…, jour et nuit").
        $cut = mb_strrpos($title, ',');
        $titleHtml = $cut === false ? e($title) : e(mb_substr($title, 0, $cut + 1)).' <em>'.e(trim(mb_substr($title, $cut + 1))).'</em>';
        $cheapest = collect($pricing['plans'] ?? [])->first();
    @endphp
    @if ($page === 'home')
        @push('head')
            <script type="application/ld+json">{!! json_encode(array_filter([
                '@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $brand['name'],
                'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, Android, iOS',
                'description' => $description, 'url' => url($locale === 'en' ? '/en' : '/'), 'inLanguage' => ['fr', 'en'],
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endpush

        <section class="hero-dark">
            <div class="wrap hero-grid">
                <div>
                    <span class="pill"><b>{{ __('New feature') }}</b> {{ __('Booking requests approved in one tap') }}</span>
                    <h1>{!! $titleHtml !!}</h1>
                    <p class="lead">{{ $description }}</p>
                    <div class="actions">
                        @if ($canSignUp)<a class="btn white" href="/signup">{{ __('Start the free trial') }}</a>@endif
                        <a class="btn light" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a>
                    </div>
                    <ul class="assure">
                        <li>{{ __('14 days free') }}</li>
                        <li>{{ __('No card needed') }}</li>
                        <li>{{ __('French and English') }}</li>
                        <li>{{ __('Mobile money') }}</li>
                    </ul>
                </div>
                <div class="devices" aria-hidden="true">
                    <div class="laptop">
                        <div class="chrome"><i></i><i></i><i></i></div>
                        <div class="screen">
                            <aside><b>{{ $brand['name'] }}</b><span class="on">{{ __('Today') }}</span><span>{{ __('Requests') }}</span><span>{{ __('Inbox') }}</span><span>{{ __('Bookings') }}</span><span>{{ __('Calendar') }}</span><span>{{ __('Restaurant') }}</span></aside>
                            <main>
                                <span class="eb">{{ mb_strtoupper(__('Today')) }}</span>
                                <h4>{{ __('Good evening, Awa') }}</h4>
                                <div class="tiles">
                                    <div class="dark">{{ __('Occupancy tonight') }}<strong>18 / 24</strong></div>
                                    <div>{{ __('Arrivals') }}<strong>6</strong></div>
                                    <div>{{ __('Departures') }}<strong>4</strong></div>
                                    <div>{{ __('In house') }}<strong>18</strong></div>
                                </div>
                                <div class="req"><span class="av">FB</span><span class="grow"><b>Fatou Bamba</b><small>{{ __('Executive · 2 nights · asked on WhatsApp') }}</small></span><span class="ok">{{ __('Approve') }}</span></div>
                                <div class="req"><span class="av">KY</span><span class="grow"><b>Konan Yao</b><small>{{ __('Table for 6 · tonight 20:30') }}</small></span><span class="ok">{{ __('Confirm') }}</span></div>
                            </main>
                        </div>
                    </div>
                    <div class="phone">
                        <div class="wa">
                            <header><i></i><span>{{ __('Your hotel') }}<small>{{ __('online') }}</small></span></header>
                            <div class="msgs seq">
                                <div class="bubble me">{{ __('Hello, do you have a room for two tonight? How much with breakfast?') }}</div>
                                <div class="bubble">{{ __('Good evening! Yes, a Standard room is free tonight: 20,000 FCFA, breakfast included. Shall I prepare the booking?') }}</div>
                                <div class="bubble me">{{ __('Yes please, arriving around 9 pm.') }}</div>
                                <div class="bubble">{{ __('Noted! Reception confirms in a few minutes. 🙏') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="trust" aria-label="{{ __('Countries') }}">
            <div class="wrap">
                <span>{{ __('Ready for hotels in') }}</span>
                <span class="flags">@foreach ($countries->take(8) as $c)<span>{{ $c['name'] }}</span>@endforeach</span>
                <a class="live" href="{{ $locale === 'en' ? '/en/status' : '/status' }}"><span class="dot" aria-hidden="true"></span>{{ __('Service status, live') }}</a>
            </div>
        </section>

        <section class="block" id="how">
            <div class="wrap">
                <div class="head center">
                    <span class="eyebrow">{{ __('How it works') }}</span>
                    <h2>{{ __('Live in an afternoon, not in a month') }}</h2>
                    <p>{{ __('No new app for your guests: they keep writing to you on WhatsApp.') }}</p>
                </div>
                <div class="steps">
                    <article class="card"><h3>{{ __('Connect your WhatsApp number') }}</h3><p>{{ __('In a few clicks with Meta. Your number stays yours, and your team can still answer from their phones.') }}</p></article>
                    <article class="card"><h3>{{ __('Add your rooms and prices') }}</h3><p>{{ __('Or paste your price list: the set-up reads it for you. Taxes and deposits follow your country.') }}</p></article>
                    <article class="card"><h3>{{ __('The assistant answers guests') }}</h3><p>{{ __('Prices, availability, breakfast, directions. Bookings wait for your approval: nothing is confirmed without your team.') }}</p></article>
                </div>
            </div>
        </section>

        <section class="block soft" id="demo-live">
            <div class="wrap demo-grid">
                <div>
                    <div class="head">
                        <span class="eyebrow">{{ __('See it in action') }}</span>
                        <h2>{{ __('A guest writes at 11 pm. You still get the booking.') }}</h2>
                        <p>{{ __('The assistant replies in seconds with your real prices. Reception approves from the phone, and the guest gets the confirmation and a map pin the day before.') }}</p>
                    </div>
                    <div class="staff" id="staff-seq">
                        <div class="notif"><span class="ic">1</span><div><b>{{ __('New booking request') }}</b><small>{{ __('Standard room · tonight · 2 adults · 20,000 FCFA') }}</small><div class="btns"><span>{{ __('Approve') }}</span><span>{{ __('Decline') }}</span></div></div></div>
                        <div class="notif done"><span class="ic">✓</span><div><b>{{ __('Approved by reception') }}</b><small>{{ __('The guest receives the confirmation on WhatsApp, with the deposit link if you ask for one.') }}</small></div></div>
                    </div>
                    <button type="button" class="replay" data-replay>↻ {{ __('Play again') }}</button>
                </div>
                <div class="phone" aria-hidden="true">
                    <div class="wa">
                        <header><i></i><span>{{ __('Your hotel') }}<small>{{ __('online') }}</small></span></header>
                        <div class="msgs" id="wa-seq">
                            <div class="bubble me">{{ __('Good evening, a room for tonight for 2 people?') }}</div>
                            <div class="bubble tb"><span class="typing"><i></i><i></i><i></i></span></div>
                            <div class="bubble">{{ __('Good evening! A Standard room is free: 20,000 FCFA with breakfast. Shall I book it?') }}</div>
                            <div class="bubble me">{{ __('Yes, thank you!') }}</div>
                            <div class="bubble">{{ __('Confirmed ✅ See you tonight! Reception is open until midnight.') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="block" id="roles">
            <div class="wrap">
                <div class="head center">
                    <span class="eyebrow">{{ __('For the whole team') }}</span>
                    <h2>{{ __('Everyone sees what matters to them') }}</h2>
                </div>
                <div class="roles">
                    <article class="card"><span class="ico">{!! $icon('crown') !!}</span><h3>{{ __('Owner') }}</h3><ul><li>{{ __('Morning summary on WhatsApp') }}</li><li>{{ __('Occupancy and revenue at a glance') }}</li><li>{{ __('Deposits paid by mobile money') }}</li></ul></article>
                    <article class="card"><span class="ico">{!! $icon('desk') !!}</span><h3>{{ __('Reception') }}</h3><ul><li>{{ __('Approve bookings in one tap') }}</li><li>{{ __('Arrivals, departures, room bills') }}</li><li>{{ __('Take over any conversation') }}</li></ul></article>
                    <article class="card"><span class="ico">{!! $icon('glass') !!}</span><h3>{{ __('Restaurant & bar') }}</h3><ul><li>{{ __('Table bookings from WhatsApp') }}</li><li>{{ __('Orders charged to the room') }}</li><li>{{ __('Menu kept up to date') }}</li></ul></article>
                    <article class="card"><span class="ico">{!! $icon('party') !!}</span><h3>{{ __('Events') }}</h3><ul><li>{{ __('Weddings, seminars, conferences') }}</li><li>{{ __('Options held, then confirmed') }}</li><li>{{ __('Quotes in your currency') }}</li></ul></article>
                </div>
            </div>
        </section>

        <section class="block soft" id="features">
            <div class="wrap">
                <div class="head">
                    <span class="eyebrow">{{ __('Everything in one place') }}</span>
                    <h2>{{ __('One platform for the whole hotel') }}</h2>
                    <p>{{ __('From the first WhatsApp message to the final bill.') }}</p>
                </div>
                <div class="features">
                    <article class="card"><span class="ico">{!! $icon('chat') !!}</span><h3>{{ __('WhatsApp assistant') }}</h3><p>{{ __('Answers day and night in French and English, from the information your hotel provides.') }}</p></article>
                    <article class="card"><span class="ico">{!! $icon('calendar') !!}</span><h3>{{ __('Bookings and calendar') }}</h3><p>{{ __('Rooms, rates and availability always right. No double bookings.') }}</p></article>
                    <article class="card"><span class="ico">{!! $icon('wallet') !!}</span><h3>{{ __('Deposits and payments') }}</h3><p>{{ __('Payment links by mobile money and card, room bills and invoices.') }}</p></article>
                    <article class="card"><span class="ico">{!! $icon('utensils') !!}</span><h3>{{ __('Restaurant and events') }}</h3><p>{{ __('Tables, room orders, seminars and weddings in the same place.') }}</p></article>
                    <article class="card"><span class="ico">{!! $icon('bell') !!}</span><h3>{{ __('Staff app and alerts') }}</h3><p>{{ __('On the phone, with notifications and one-tap decisions straight from WhatsApp.') }}</p></article>
                    <article class="card"><span class="ico">{!! $icon('shield') !!}</span><h3>{{ __('Secure by design') }}</h3><p>{{ __('Each hotel’s data kept separate and encrypted, two-step login, daily backups.') }}</p></article>
                </div>
            </div>
        </section>

        <section class="block" id="local">
            <div class="wrap local">
                <div class="head" style="margin:0">
                    <span class="eyebrow">{{ __('Built for West Africa') }}</span>
                    <h2>{{ __('Made for how hotels work here') }}</h2>
                    <p>{{ __('Prices in FCFA and your local currency, mobile money, your country’s VAT, and an interface in French first.') }}</p>
                </div>
                <div class="stats">
                    <div class="card"><strong>FCFA</strong><p>{{ __('and other local currencies') }}</p></div>
                    <div class="card"><strong>Mobile money</strong><p>{{ __('Orange Money, MTN, Moov, Wave') }}</p></div>
                    <div class="card"><strong>24/7</strong><p>{{ __('answers, even at night') }}</p></div>
                    <div class="card"><strong>FR · EN</strong><p>{{ __('for your guests and your team') }}</p></div>
                </div>
            </div>
        </section>

        @if (($pricing['plans'] ?? []) !== [])
            <section class="block soft pricing" id="pricing">
                <div class="wrap">
                    <div class="head center">
                        <span class="eyebrow">{{ __('Pricing') }}</span>
                        <h2>{{ __('Simple prices, in your currency') }}</h2>
                        <p>{{ __('Try everything free for 14 days. No card needed.') }}</p>
                    </div>
                    <div style="text-align:center">
                        <div class="billing-switch" role="radiogroup" aria-label="{{ __('Billing period') }}">
                            <label><input type="radio" name="billing" id="bill-monthly" checked> {{ __('Monthly') }}</label>
                            <label><input type="radio" name="billing" id="bill-yearly"> {{ __('Yearly') }}<em>{{ __('2 months free') }}</em></label>
                        </div>
                    </div>
                    <div class="plans">
                        @foreach ($pricing['plans'] as $plan)
                            <article class="card plan {{ $plan['featured'] ? 'featured' : '' }}">
                                @if ($plan['featured'])<span class="tag">{{ __('Most chosen') }}</span>@endif
                                <h3>{{ $plan['name'] }}</h3>
                                @if ($plan['description'])<p class="muted">{{ $plan['description'] }}</p>@endif
                                <p class="price m">{{ $plan['monthly'] }} <span>/ {{ __('month') }}</span></p>
                                <p class="price y">{{ $plan['yearly'] ?? $plan['monthly'] }} <span>/ {{ $plan['yearly'] ? __('year') : __('month') }}</span></p>
                                <ul>
                                    <li>{{ __('WhatsApp assistant') }}</li>
                                    <li>{{ __('Rooms, bookings and front desk') }}</li>
                                    @if (in_array('restaurant', $plan['modules'], true))<li>{{ __('Restaurant and bar') }}</li>@endif
                                    @if (in_array('events', $plan['modules'], true))<li>{{ __('Events') }}</li>@endif
                                    <li>{{ $plan['staff_limit'] === null ? __('Unlimited staff accounts') : __('Up to :limit staff accounts', ['limit' => $plan['staff_limit']]) }}</li>
                                </ul>
                                @if ($canSignUp)<a class="btn {{ $plan['featured'] ? '' : 'ghost' }}" href="/signup">{{ __('Start free') }}</a>@endif
                            </article>
                        @endforeach
                    </div>
                    <p class="fine" style="text-align:center">{{ $pricing['includes_tax'] ? __('Prices include VAT.') : __('Prices exclude VAT.') }} {{ __('WhatsApp messaging fees are billed by Meta directly to the hotel.') }} <a href="{{ $link('pricing') }}">{{ __('All prices and currencies') }} →</a></p>
                </div>
            </section>
        @endif

        <section class="block">
            <div class="wrap">
                <div class="cta">
                    <h2>{{ __('Give your guests an answer tonight') }}</h2>
                    <p>{{ __('Start your 14-day trial, or let us show you the assistant on a demo hotel.') }}</p>
                    <div class="actions">
                        @if ($canSignUp)<a class="btn white" href="/signup">{{ __('Start the free trial') }}</a>@endif
                        <a class="btn light" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a>
                    </div>
                </div>
            </div>
        </section>

        @push('scripts')
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
                // The live demo plays when it comes into view, and again on "Play again".
                (() => {
                    const parts = ['wa-seq', 'staff-seq'].map((id) => document.getElementById(id)).filter(Boolean);
                    const play = () => parts.forEach((el) => { el.classList.remove('pre', 'seq'); void el.offsetWidth; el.classList.add('seq'); });
                    document.querySelector('[data-replay]')?.addEventListener('click', play);
                    if (matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;
                    parts.forEach((el) => el.classList.add('pre'));
                    const io = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) { play(); io.disconnect(); } }, { threshold: 0.35 });
                    io.observe(document.getElementById('demo-live'));
                })();
            </script>
        @endpush
    @elseif ($page === 'pricing')
        <section class="page-hero"><div class="wrap">
            <h1>{{ $title }}</h1>
            <p>{{ $description }}</p>
            <form method="get" action="{{ $link('pricing') }}" class="actions" style="align-items:end">
                <label>{{ __('Currency') }}
                    <select name="currency">
                        @foreach ($currencies as $c)<option value="{{ $c }}" @selected($c === $pricing['currency'])>{{ $c }}</option>@endforeach
                    </select>
                </label>
                <button class="btn ghost" type="submit">{{ __('Show') }}</button>
            </form>
        </div></section>
        <section class="content"><div class="wrap">
            <div class="plans">
                @foreach ($pricing['plans'] as $plan)
                    <article class="card plan {{ $plan['featured'] ? 'featured' : '' }}">
                        @if ($plan['featured'])<span class="tag">{{ __('Most chosen') }}</span>@endif
                        <h2 style="margin:0">{{ $plan['name'] }}</h2>
                        @if ($plan['description'])<p class="muted">{{ $plan['description'] }}</p>@endif
                        <p class="price">{{ $plan['monthly'] }} <span>/ {{ __('month') }}</span></p>
                        @if ($plan['yearly'])<p class="muted">{{ __('or :price a year', ['price' => $plan['yearly']]) }}@if ($plan['yearly_saving']) — {{ __('save :amount', ['amount' => $plan['yearly_saving']]) }}@endif</p>@endif
                        <ul>
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
            <p class="fine">{{ $pricing['includes_tax'] ? __('Prices include VAT.') : __('Prices exclude VAT.') }} {{ __('WhatsApp messaging fees are billed by Meta directly to the hotel.') }}</p>
            @if ($pricing['packs'] !== [])
                <h2>{{ __('Extra credits') }}</h2>
                <ul class="prose">@foreach ($pricing['packs'] as $pack)<li>{{ $pack['name'] }} — {{ $pack['price'] }}</li>@endforeach</ul>
            @endif
        </div></section>
    @elseif ($page === 'demo')
        <section class="page-hero"><div class="wrap">
            <h1>{{ $title }}</h1>
            <p>{{ $description }}</p>
        </div></section>
        <section class="content"><div class="wrap">
            @if (session('status') === 'sent')
                <p class="notice" role="status">{{ __('Thank you! Our team will contact you on WhatsApp shortly.') }}</p>
            @endif
            <form class="demo card" method="post" action="{{ $link('demo') }}">
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
        <section class="page-hero"><div class="wrap"><h1>{{ $title }}</h1><p>{{ $description }}</p></div></section>
        <section class="content"><div class="wrap">
            <div class="prose">{!! $html !!}</div>
            @if ($page === 'countries')
                <ul class="countries">@foreach ($countries as $c)<li>{{ $c['name'] }} ({{ $c['currency'] }})</li>@endforeach</ul>
            @endif
            <p class="actions" style="margin-top:32px"><a class="btn" href="{{ $link('demo') }}">{{ __('Ask for a demo') }}</a></p>
        </div></section>
    @endif
@endsection
