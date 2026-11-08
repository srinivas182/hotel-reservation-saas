<x-mail::message>
# {{ __('Request #:id', ['id' => $ticket->id]) }} — {{ $ticket->subject }}

@if ($kind === 'solved')
{{ __('We have marked your request as solved. One tap tells us if we helped:') }}
@endif

{{ $excerpt }}

<x-mail::button :url="$url">
{{ $kind === 'solved' ? __('Rate the answer') : __('Open the request') }}
</x-mail::button>
</x-mail::message>
