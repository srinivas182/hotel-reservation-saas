<x-mail::message>
@if ($notice === null)
# {{ __('Confirm your status alerts') }}

{{ __('Confirm this address to receive an email when there is an incident or a planned maintenance.') }}

<x-mail::button :url="$confirm">
{{ __('Confirm') }}
</x-mail::button>
@else
# {{ $notice->text($locale) }}

{{ $update }}

<x-mail::button :url="$page">
{{ __('See the status page') }}
</x-mail::button>

[{{ __('Stop these emails') }}]({{ $unsubscribe }})
@endif
</x-mail::message>
