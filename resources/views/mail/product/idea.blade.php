<x-mail::message>
# {{ $idea->title }}

**{{ \App\Domains\Product\Models\Idea::statusLabel($idea->status) }}**

@if ($idea->reply)
{{ $idea->reply }}
@endif

<x-mail::button :url="$url">
{{ __('See the ideas') }}
</x-mail::button>

[{{ __('Stop product emails') }}]({{ $unsubscribe }})
</x-mail::message>
