<x-mail::message>
@switch($kind)
@case('welcome')
# {{ __('Welcome to your hotel assistant!') }}

{{ __(':hotel is ready to be set up. Follow the steps: rooms, prices, WhatsApp and your information pack. It takes about an hour.', ['hotel' => $hotel->name]) }}
@break
@case('setup')
# {{ __('Your next step') }}

{{ __('Your setup is not finished yet. Next step: :step.', ['step' => $next ?? '—']) }}
@break
@case('trial_3')
# {{ __('Your trial ends in 3 days') }}

{{ __('Choose your plan and pay online or by transfer to keep the assistant answering your guests.') }}
@break
@case('trial_last')
# {{ __('Your trial ends today') }}

{{ __('Choose your plan and pay online or by transfer to keep the assistant answering your guests.') }}
@break
@case('golive_request')
# {{ __('Go-live request') }}

{{ __(':hotel has finished its setup and asks to go live. Review and approve it on the platform.', ['hotel' => $hotel->name]) }}
@break
@case('golive_approved')
# {{ __('You are live!') }}

{{ __('The assistant now prepares replies to your guests in supervised mode: check and send them from the inbox. After a week, you can switch to automatic.') }}
@break
@default
# {{ __('Your go-live request needs changes') }}

{{ $reason }}
@endswitch

<x-mail::button :url="$url">
{{ __('Open') }}
</x-mail::button>
</x-mail::message>
