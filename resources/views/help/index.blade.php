@extends('site.layout')

@section('main')
    @php($base = $locale === 'en' ? '/en/help' : '/help')
    <section class="hero"><div class="wrap">
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
        <form method="get" action="{{ $base }}" role="search" class="actions">
            <label class="hp" for="help-q">{{ __('Search the help centre') }}</label>
            <input id="help-q" type="search" name="q" value="{{ $q }}" placeholder="{{ __('Search the help centre') }}" style="flex:1;min-width:220px">
            <button class="btn" type="submit">{{ __('Search') }}</button>
        </form>
    </div></section>
    <section class="content"><div class="wrap">
        @if ($results !== null)
            <h2>{{ __('Results for “:q”', ['q' => $q]) }}</h2>
            @forelse ($results as $a)
                <p><a href="{{ $base }}/{{ $a->slug }}"><strong>{{ $a->text('title', $locale) }}</strong></a><br><span class="muted">{{ $a->excerpt($locale) }}</span></p>
            @empty
                <p class="notice">{{ __('No article found. Try other words, or contact support from the app.') }}</p>
            @endforelse
        @endif
        <div class="grid">
            @foreach ($categories as $category => $articles)
                <section class="card">
                    <h2 style="margin-top:0">{{ \App\Domains\Help\Models\HelpArticle::categoryLabel($category) }}</h2>
                    <ul class="ticks">@foreach ($articles as $a)<li><a href="{{ $base }}/{{ $a->slug }}">{{ $a->text('title', $locale) }}</a></li>@endforeach</ul>
                </section>
            @endforeach
        </div>
    </div></section>
@endsection
