<x-mail::message>
# {{ __('New hotel sign-up') }}

**{{ $hotel->name }}** ({{ $hotel->country_code }}) — {{ $hotel->owner?->name }}, {{ $hotel->owner?->email }}

<x-mail::button :url="$url">
{{ __('Open the sign-ups') }}
</x-mail::button>
</x-mail::message>
