@php
    app()->setLocale($locale);
    $title = match ($intent) {
        'table' => __('Book your table on WhatsApp'),
        'event' => __('Your event or seminar? Write to us on WhatsApp'),
        'tonight' => __('A room tonight? Ask us on WhatsApp'),
        default => __('Book your room on WhatsApp'),
    };
    $reasons = [__('Booking in 2 minutes'), __('Pay by Wave or Orange Money'), __('Answer 24 hours a day')];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: {{ $format === 'a5' ? '0' : '14mm' }}; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; }
        .face { text-align: center; }
        .band { background-color: {{ $color }}; color: #ffffff; padding: 6mm 5mm; }
        .hotel { font-size: 12pt; letter-spacing: 0.5pt; text-transform: uppercase; }
        .title { font-size: 24pt; font-weight: bold; line-height: 1.2; margin-top: 2mm; }
        .qr { width: 115mm; height: 115mm; margin-top: 9mm; }
        .scan { font-size: 12pt; color: #374151; margin: 4mm 8mm 0; }
        .reasons { margin-top: 7mm; font-size: 12pt; }
        .reasons span { display: inline-block; margin: 1.5mm 1.5mm; padding: 1.5mm 3.5mm; border: 0.4mm solid {{ $color }}; }
        .number { font-size: 19pt; font-weight: bold; margin-top: 8mm; }
        .url { font-size: 10pt; color: #6b7280; margin-top: 2mm; }
        .logo { max-height: 18mm; max-width: 60mm; margin-bottom: 4mm; }
        /* A5 table tent: two halves of an A4 landscape sheet, the left one upside down, fold in the middle. */
        table.tent { width: 297mm; height: 210mm; border-collapse: collapse; }
        table.tent td { width: 148.5mm; height: 210mm; vertical-align: middle; padding: 0; }
        table.tent td.fold { border-right: 0.3mm dashed #9ca3af; }
        .tent .face { padding: 0 9mm; }
        .tent .turn { transform: rotate(180deg); }
        .tent .title { font-size: 16pt; }
        .tent .hotel { font-size: 10pt; }
        .tent .qr { width: 70mm; height: 70mm; margin-top: 5mm; }
        .tent .scan { font-size: 9.5pt; margin: 3mm 2mm 0; }
        .tent .reasons { font-size: 9pt; margin-top: 3mm; }
        .tent .number { font-size: 13pt; margin-top: 4mm; }
        .tent .url { font-size: 8pt; }
        .tent .logo { max-height: 11mm; margin-bottom: 2mm; }
    </style>
</head>
<body>
@php
    $face = function () use ($logo, $hotel, $title, $qr, $reasons, $number, $url) {
        return view('chat-kit.poster-face', compact('logo', 'hotel', 'title', 'qr', 'reasons', 'number', 'url'))->render();
    };
@endphp
@if ($format === 'a5')
    <table class="tent">
        <tr>
            <td class="fold"><div class="turn">{!! $face() !!}</div></td>
            <td>{!! $face() !!}</td>
        </tr>
    </table>
@else
    {!! $face() !!}
@endif
</body>
</html>
