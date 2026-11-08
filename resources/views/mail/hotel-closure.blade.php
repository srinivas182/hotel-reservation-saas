<x-mail::message>
# {{ $closure->hotel?->name }}

{{ __('The hotel was closed on :closed. Its data will be deleted on :date. Until then you can download all of it, or reopen the hotel.', ['closed' => $closure->closed_at->toDateString(), 'date' => $closure->delete_after->toDateString()]) }}

<x-mail::button :url="$url">
{{ __('Download or reopen') }}
</x-mail::button>
</x-mail::message>
