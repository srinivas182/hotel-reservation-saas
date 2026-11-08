@extends('site.layout')

@section('main')
    @php($base = $locale === 'en' ? '/en/help' : '/help')
    <section class="content"><div class="wrap" style="max-width:760px">
        <p class="muted"><a href="{{ $base }}">{{ __('Help centre') }}</a> · {{ \App\Domains\Help\Models\HelpArticle::categoryLabel($article->category) }}</p>
        <h1>{{ $title }}</h1>
        {!! $html !!}
        <div class="notice" style="margin-top:32px">
            @if (session('status') === 'thanks')
                <p role="status" style="margin:0">{{ __('Thank you for your feedback.') }}</p>
            @else
                <form method="post" action="{{ $base }}/{{ $article->slug }}/feedback" class="actions" style="align-items:center">
                    @csrf
                    <span>{{ __('Did this answer your question?') }}</span>
                    <button class="btn ghost" name="helpful" value="1" type="submit">{{ __('Yes') }}</button>
                    <button class="btn ghost" name="helpful" value="0" type="submit">{{ __('No') }}</button>
                </form>
            @endif
        </div>
        @if ($related->isNotEmpty())
            <h2>{{ __('Related articles') }}</h2>
            <ul class="ticks">@foreach ($related as $a)<li><a href="{{ $base }}/{{ $a->slug }}">{{ $a->text('title', $locale) }}</a></li>@endforeach</ul>
        @endif
    </div></section>
@endsection
