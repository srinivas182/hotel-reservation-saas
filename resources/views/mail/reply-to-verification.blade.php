<x-mail::message>
# {{ __('Confirm this address') }}

{{ __(':hotel wants guest replies to emails to be sent to this address.', ['hotel' => $hotelName]) }}

<x-mail::button :url="$url">
{{ __('Confirm address') }}
</x-mail::button>

{{ __('The link is valid for 48 hours. If you did not expect this email, you can ignore it.') }}
</x-mail::message>
