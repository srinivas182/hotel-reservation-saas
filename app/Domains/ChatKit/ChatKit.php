<?php

declare(strict_types=1);

namespace App\Domains\ChatKit;

use App\Domains\Audit\Audit;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\ChatKit\Models\ChatSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;

/**
 * The "Chat with us on WhatsApp" kit (Sprint H), for the current hotel:
 * pre-typed greetings with a source code, wa.me links, short links, QR
 * codes, and which source brought which conversations and bookings.
 */
final class ChatKit
{
    /** What the guest wants; each greeting starts a different conversation. */
    public const INTENTS = ['room', 'tonight', 'table', 'event', 'general'];

    /** A code at the very end of a guest's first message: "… #R1". */
    private const CODE = '/\s*#([A-Za-z0-9]{1,6})\s*$/u';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The sources every hotel starts with.
     */
    public function ensureDefaults(): void
    {
        if (ChatSource::query()->exists()) {
            return;
        }

        foreach ([
            ['R1', 'poster', __('Poster at reception'), 'room'],
            ['T1', 'poster', __('Table tent at the restaurant'), 'table'],
            ['W', 'button', __('Website button'), 'room'],
            ['L', 'link', __('Short link (SMS, business card)'), 'room'],
            ['G', 'google', 'Google Maps', 'room'],
            ['F', 'facebook', 'Facebook', 'room'],
            ['I', 'instagram', 'Instagram', 'room'],
        ] as [$code, $kind, $name, $intent]) {
            ChatSource::query()->create(['code' => $code, 'kind' => $kind, 'name' => (string) $name, 'intent' => $intent]);
        }
    }

    /** A new code for a source of this kind: P2, P3… */
    public function nextCode(string $kind): string
    {
        $letter = match ($kind) {
            'poster' => 'P', 'button' => 'W', 'link' => 'L', 'google' => 'G', 'facebook' => 'F', 'instagram' => 'I', default => 'S',
        };
        $taken = ChatSource::query()->pluck('code')->all();

        for ($n = 1; $n < 1000; $n++) {
            if (! in_array($letter.$n, $taken, true)) {
                return $letter.$n;
            }
        }

        return $letter.random_int(1000, 9999);
    }

    /** The hotel's WhatsApp number, digits only, or null when not set. */
    public function number(): ?string
    {
        $settings = HotelSettings::current();
        $number = preg_replace('/\D/', '', (string) ($settings->whatsapp_display_e164 ?? $settings->phone_e164));

        return $number === '' || $number === null ? null : $number;
    }

    public static function greeting(string $intent, string $locale): string
    {
        return match ($intent) {
            'tonight' => (string) __('Hello, do you have a room for tonight?', locale: $locale),
            'table' => (string) __('Hello, I would like to book a table at the restaurant.', locale: $locale),
            'event' => (string) __('Hello, I am organising an event or a seminar.', locale: $locale),
            'general' => (string) __('Hello, I have a question.', locale: $locale),
            default => (string) __('Hello, I would like to book a room.', locale: $locale),
        };
    }

    /** The pre-typed message, ending with the source code. */
    public function prefilled(ChatSource $source, string $locale): string
    {
        return self::greeting($source->intent, $locale).' #'.$source->code;
    }

    public function whatsappUrl(ChatSource $source, string $locale): ?string
    {
        $number = $this->number();

        return $number === null ? null : 'https://wa.me/'.$number.'?text='.rawurlencode($this->prefilled($source, $locale));
    }

    /** The short link that counts the click, then opens WhatsApp. */
    public function shortUrl(ChatSource $source, ?string $locale = null): string
    {
        $default = $this->context->hotel()->default_locale;

        return route('kit.go', [$this->context->hotel()->slug, $source->code, ...($locale !== null && $locale !== $default ? ['lang' => $locale] : [])]);
    }

    public static function qrSvg(string $url, int $size = 600): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($url);
    }

    /**
     * Read a guest's first message: when it ends with a known code, tag
     * the conversation with that source (first one wins) and give back the
     * message without the code.
     */
    public function attribute(Conversation $conversation, string $body): string
    {
        if (preg_match(self::CODE, $body, $m) !== 1) {
            return $body;
        }

        $source = ChatSource::query()->where('code', strtoupper($m[1]))->first();
        if ($source === null) {
            return $body;
        }

        if ($conversation->chat_source_id === null) {
            $conversation->forceFill(['chat_source_id' => $source->id, 'chat_source_at' => now()])->save();
            Audit::record('chat_kit.attributed', 'Conversation started from the WhatsApp kit', subject: $conversation, properties: ['source' => $source->code]);
        }

        $clean = trim((string) preg_replace(self::CODE, '', $body));

        return $clean === '' ? $body : $clean;
    }

    /**
     * Conversations started and bookings that came from each source, over
     * a period: [source id => [chats, bookings]].
     *
     * @return array<int, array{chats: int, bookings: int}>
     */
    public function results(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];

        foreach (Conversation::query()->whereNotNull('chat_source_id')->whereBetween('chat_source_at', [$from, $to])
            ->selectRaw('chat_source_id, count(*) as n')->groupBy('chat_source_id')->get() as $row) {
            $out[(int) $row->getAttribute('chat_source_id')] = ['chats' => (int) $row->getAttribute('n'), 'bookings' => 0];
        }

        $bookings = BookingRequest::query()->whereNotNull('booking_requests.booking_id')->whereBetween('booking_requests.created_at', [$from, $to])
            ->join('conversations', 'conversations.id', '=', 'booking_requests.conversation_id')
            ->whereNotNull('conversations.chat_source_id')
            ->selectRaw('conversations.chat_source_id as source, count(*) as n')->groupBy('conversations.chat_source_id')->get();

        foreach ($bookings as $row) {
            $id = (int) $row->getAttribute('source');
            $out[$id] ??= ['chats' => 0, 'bookings' => 0];
            $out[$id]['bookings'] = (int) $row->getAttribute('n');
        }

        return $out;
    }
}
