<x-mail::message>
# {{ $kind === 'new' ? __('New booking request') : __('Booking request expired') }}

**{{ $guest }}** · {{ $stay }} · {{ $total }}

@if ($kind === 'new')
{{ __('The rooms are held until :time. Approve or decline the request before then.', ['time' => $expiresAt]) }}
@else
{{ __('Nobody answered in time, so the held rooms were released. Please contact the guest.') }}
@endif

<x-mail::button :url="$url">
{{ __('Open the requests') }}
</x-mail::button>
</x-mail::message>
