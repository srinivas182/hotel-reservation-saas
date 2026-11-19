<div class="face">
    @if ($logo)
        <img class="logo" src="{{ $logo }}" alt="">
    @endif
    <div class="band">
        <div class="hotel">{{ $hotel->name }}</div>
        <div class="title">{{ $title }}</div>
    </div>
    <img class="qr" src="{{ $qr }}" alt="QR">
    <div class="scan">{{ __('Scan with your phone camera: WhatsApp opens with your message ready.') }}</div>
    <div class="reasons">
        @foreach ($reasons as $reason)
            <span>{{ $reason }}</span>
        @endforeach
    </div>
    @if ($number)
        <div class="number">WhatsApp +{{ $number }}</div>
    @endif
    <div class="url">{{ $url }}</div>
</div>
