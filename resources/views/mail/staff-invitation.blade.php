<x-mail::message>
# {{ __('Join :hotel', ['hotel' => $hotelName]) }}

{{ __('You have been invited to join :hotel as :role.', ['hotel' => $hotelName, 'role' => $roleName]) }}

<x-mail::button :url="$url">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation expires on :date.', ['date' => $expiresAt->translatedFormat('j F Y')]) }}

{{ __('If you were not expecting this invitation, you can ignore this email.') }}
</x-mail::message>
