<x-mail::message>
# {{ $hotelName }} · {{ $s['date'] }}

| | |
|:--|--:|
| {{ __('Arrivals today') }} | {{ $s['arrivals'] }} |
| {{ __('Departures today') }} | {{ $s['departures'] }} |
| {{ __('Rooms occupied tonight') }} | {{ $s['occupied'] }} / {{ $s['rooms'] }} |
| {{ __('New bookings') }} | {{ $s['new_bookings'] }} ({{ $s['new_value'] }}) |
| {{ __('WhatsApp requests approved / declined / expired') }} | {{ $s['requests']['approved'] }} / {{ $s['requests']['declined'] }} / {{ $s['requests']['expired'] }} |
| {{ __('Conversations needing attention') }} | {{ $s['attention'] }} |
| {{ __('AI cost today') }} | {{ $s['ai_cost'] }} |
</x-mail::message>
