<x-mail::message>
# {{ __('AI spending alert') }}

{{ __('The AI assistant at :hotel has used US$ :spent of today’s US$ :limit limit.', ['hotel' => $hotelName, 'spent' => $spent, 'limit' => $limit]) }}

{{ __('When the limit is reached, guests’ messages are passed to your staff until tomorrow. You can change the limit in the assistant settings.') }}
</x-mail::message>
