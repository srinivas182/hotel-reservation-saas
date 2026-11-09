<x-mail::message>
# {{ __('What’s new this month') }}

@foreach ($notes as $note)
## {{ $note['title'] }}

{{ $note['body'] }}

@endforeach

<x-mail::button :url="$url">
{{ __('See what’s new') }}
</x-mail::button>

[{{ __('Stop product emails') }}]({{ $unsubscribe }})
</x-mail::message>
