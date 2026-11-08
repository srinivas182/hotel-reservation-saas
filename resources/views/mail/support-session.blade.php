<x-mail::message>
# {{ $kind === 'approval' ? __('Access request from our support team') : __('Support session started') }}

{{ __(':name from the platform team :what your account for :hotel, for 30 minutes (:mode).', [
    'name' => $session->user?->name,
    'what' => $kind === 'approval' ? __('asks to access') : __('is now helping in'),
    'hotel' => $session->hotel?->name,
    'mode' => $session->mode === 'write' ? __('can make changes') : __('read-only'),
]) }}

**{{ __('Reason') }}:** {{ $session->reason }}

{{ __('Everything done in the session appears in your history. You can end it at any time.') }}

<x-mail::button :url="$url">
{{ $kind === 'approval' ? __('Approve or refuse') : __('See support sessions') }}
</x-mail::button>
</x-mail::message>
