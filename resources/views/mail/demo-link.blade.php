<x-mail::message>
# {{ __('Hello :name,', ['name' => $lead->name]) }}

{{ __('Here is a private demo of the assistant set up for :hotel. Try the scenarios: a guest asking for a room tonight, a room order, a seminar enquiry. The link works for 48 hours.', ['hotel' => $lead->hotel_name]) }}

<x-mail::button :url="$link">
{{ __('Open my demo') }}
</x-mail::button>
</x-mail::message>
