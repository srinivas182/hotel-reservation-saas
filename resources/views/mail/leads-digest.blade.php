<x-mail::message>
# {{ __('Leads') }}

@if ($new !== [])
## {{ __('New since yesterday') }}
@foreach ($new as $l)
- **{{ $l['hotel'] }}** — {{ $l['name'] }}, {{ $l['whatsapp'] }}
@endforeach
@endif

@if ($overdue !== [])
## {{ __('To follow up') }}
@foreach ($overdue as $l)
- **{{ $l['hotel'] }}** — {{ $l['name'] }}, {{ $l['whatsapp'] }} ({{ $l['follow_up'] }})
@endforeach
@endif

<x-mail::button :url="$url">
{{ __('Open the leads') }}
</x-mail::button>
</x-mail::message>
