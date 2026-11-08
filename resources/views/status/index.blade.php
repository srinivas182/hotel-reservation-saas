@extends('site.layout')

@section('main')
    @php
        $base = $locale === 'en' ? '/en/status' : '/status';
        $label = ['operational' => __('Operational'), 'maintenance' => __('Maintenance'), 'degraded' => __('Slower than usual'), 'outage' => __('Not working')];
        $colour = ['operational' => '#15803d', 'maintenance' => '#1d4ed8', 'degraded' => '#b45309', 'outage' => '#b91c1c'];
        $stateLabel = ['investigating' => __('Investigating'), 'identified' => __('Cause found'), 'monitoring' => __('Fixed, watching'), 'resolved' => __('Resolved'), 'scheduled' => __('Planned')];
    @endphp
    <section class="hero"><div class="wrap">
        <h1>{{ $title }}</h1>
        <p class="notice" role="status" style="border-left:6px solid {{ $colour[$overall] }}">
            <strong>{{ $overall === 'operational' ? __('Everything is working.') : $label[$overall] }}</strong>
            <span class="muted"> · {{ __('Updated :time', ['time' => now()->format('H:i').' UTC']) }}</span>
        </p>
        <form method="get" action="{{ $base }}" class="actions">
            <label>{{ __('Country') }}
                <select name="country">
                    <option value="">{{ __('All countries') }}</option>
                    @foreach ($countries as $c)<option value="{{ $c['code'] }}" @selected($country === $c['code'])>{{ $c['name'] }}</option>@endforeach
                </select>
            </label>
            <button class="btn ghost" type="submit">{{ __('Show') }}</button>
        </form>
    </div></section>
    <section class="content"><div class="wrap">
        <ul class="ticks" style="list-style:none;padding:0">
            @foreach ($components as $component => $state)
                <li style="display:flex;justify-content:space-between;border-bottom:1px solid var(--line);padding:10px 0">
                    <span>{{ \App\Domains\Status\Models\StatusNotice::componentLabel($component) }}</span>
                    <strong style="color:{{ $colour[$state] }}">{{ $label[$state] }}</strong>
                </li>
            @endforeach
        </ul>

        @foreach ($active as $notice)
            <article class="card" style="margin-top:20px">
                <h2 style="margin-top:0">{{ $notice->text($locale) }}</h2>
                <p class="muted">{{ $label[$notice->level] ?? '' }} · {{ __('since :time', ['time' => $notice->starts_at->format('Y-m-d H:i').' UTC']) }}@if ($notice->ends_at) · {{ __('until :time', ['time' => $notice->ends_at->format('Y-m-d H:i').' UTC']) }}@endif</p>
                @foreach ($notice->updates->reverse() as $u)
                    <p><strong>{{ $stateLabel[$u->state] ?? $u->state }}</strong> — {{ $u->created_at?->format('H:i') }} UTC<br>{{ $u->body[$locale] ?? $u->body['fr'] }}</p>
                @endforeach
            </article>
        @endforeach

        <h2>{{ __('Get alerts by email') }}</h2>
        @if (session('status') === 'check-email')<p class="notice" role="status">{{ __('Check your email to confirm.') }}</p>@endif
        @if (session('status') === 'confirmed')<p class="notice" role="status">{{ __('Alerts confirmed. Thank you.') }}</p>@endif
        @if (session('status') === 'unsubscribed')<p class="notice" role="status">{{ __('You will not receive alerts any more.') }}</p>@endif
        <form method="post" action="{{ $base }}/subscribe" class="actions">
            @csrf
            <label>{{ __('Email') }}<input type="email" name="email" required maxlength="191" autocomplete="email"></label>
            <label>{{ __('Country') }}
                <select name="country_code">
                    <option value="">{{ __('All countries') }}</option>
                    @foreach ($countries as $c)<option value="{{ $c['code'] }}" @selected($country === $c['code'])>{{ $c['name'] }}</option>@endforeach
                </select>
            </label>
            <button class="btn" type="submit" style="align-self:end">{{ __('Subscribe') }}</button>
        </form>
        @error('email')<p class="error">{{ $message }}</p>@enderror

        <h2>{{ __('Last 90 days') }}</h2>
        @forelse ($history as $notice)
            <p><strong>{{ $notice->text($locale) }}</strong><br><span class="muted">{{ $notice->starts_at->format('Y-m-d H:i') }} → {{ $notice->resolved_at?->format('Y-m-d H:i') }} UTC</span></p>
        @empty
            <p class="muted">{{ __('No incidents in the last 90 days.') }}</p>
        @endforelse
    </div></section>
@endsection
