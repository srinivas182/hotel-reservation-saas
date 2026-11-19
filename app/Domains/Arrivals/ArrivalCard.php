<?php

declare(strict_types=1);

namespace App\Domains\Arrivals;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Arrivals\Models\ArrivalCheck;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Deposits\Deposits;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The arrival card the day before (Sprint G): who, when, what is left to
 * pay (with the link), how to find the hotel; then a real map pin. Three
 * buttons: I confirm, not coming, a question.
 *
 * Within 24 hours of the guest's last message it is an interactive message
 * (with the entrance photo on top); otherwise the approved template with
 * the same three buttons. Tapping a button reopens the window, so the pin
 * and the arrival time list follow then.
 */
final class ArrivalCard
{
    public const TEMPLATE = 'arrival_card';

    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageSender $sender,
        private readonly Deposits $deposits,
        private readonly Folio $folio,
    ) {}

    /**
     * @return 'text'|'template'|null how it went, null when it could not be sent
     *
     * @throws CannotSend
     */
    public function send(Booking $booking, Conversation $conversation, string $locale): ?string
    {
        $check = ArrivalCheck::for($booking);
        $how = null;

        if ($conversation->windowOpen()) {
            $this->sender->sendInteractive($conversation, $this->interactive($booking, $locale));
            $this->pin($booking, $conversation, $locale, $check);
            $how = 'text';
        } else {
            $template = $this->template($locale) ?? $this->template($this->context->hotel()->default_locale);
            if ($template !== null) {
                $v = $this->values($booking, $locale);
                $this->sender->sendTemplate(
                    $conversation,
                    $template,
                    array_map(fn (string $x): string => MessageTemplate::cleanValue($x), [$v['name'], $v['hotel'], $v['reference'], $v['check_in_time']]),
                    null,
                    MessageSource::System,
                    [ArrivalReplies::payload('yes', $booking), ArrivalReplies::payload('no', $booking), ArrivalReplies::payload('ask', $booking)],
                );
                $how = 'template';
            }
        }

        if ($how !== null) {
            $check->forceFill(['card_sent_at' => now(), 'card_how' => $how])->save();
        }

        return $how;
    }

    /**
     * The map pin and the directions, once per booking.
     */
    public function pin(Booking $booking, Conversation $conversation, string $locale, ?ArrivalCheck $check = null): void
    {
        $check ??= ArrivalCheck::for($booking);
        if ($check->pin_sent_at !== null) {
            return;
        }

        $place = HotelSettings::current();
        $hotel = $this->context->hotel();
        $address = trim(implode(', ', array_filter([$place->address_line1, $place->address_line2, $place->city])));

        if ($place->latitude !== null && $place->longitude !== null) {
            $this->sender->sendLocation($conversation, $place->latitude, $place->longitude, $hotel->name, $address);
        }

        $settings = HotelAssistantSettings::current();
        $directions = self::localized($settings->arrival_directions, $locale, $hotel->default_locale);
        $tip = self::localized($settings->arrival_tip, $locale, $hotel->default_locale);
        $lines = array_values(array_filter([
            $directions === '' ? null : '📍 '.$directions,
            $tip === '' ? null : '🛵 '.$tip,
            $place->latitude === null && $place->map_url !== null ? '🗺️ '.$place->map_url : null,
        ]));

        if ($lines !== []) {
            $this->sender->sendText($conversation, implode("\n", $lines), null, MessageSource::System);
        }

        $check->forceFill(['pin_sent_at' => now()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function interactive(Booking $booking, string $locale): array
    {
        $v = $this->values($booking, $locale);
        $settings = HotelAssistantSettings::current();

        $lines = [
            (string) __('Hello :name, we look forward to welcoming you tomorrow at :hotel 🏨', $v, $locale),
            '',
            (string) __('Booking :reference · :room', $v, $locale),
            '🗓️ '.$v['dates'],
            '🕑 '.__('Check-in from :check_in_time', $v, $locale),
        ];

        if ($v['left'] !== null) {
            $lines[] = '💰 '.__('Left to pay: :left', ['left' => $v['left']], $locale);
            if ($v['pay_url'] !== null) {
                $lines[] = (string) __('Pay now by mobile money, no queue on arrival: :url', ['url' => $v['pay_url']], $locale);
            }
        }

        $lines[] = '';
        $lines[] = (string) __('Tap a button to answer.', locale: $locale);

        return [
            'type' => 'button',
            ...($settings->arrival_photo_path === null ? [] : ['header' => ['type' => 'image', 'image' => ['link' => route('arrivals.entrance', $this->context->hotel()->slug)]]]),
            'body' => ['text' => mb_substr(implode("\n", $lines), 0, 1024)],
            'footer' => ['text' => (string) __('The map pin follows.', locale: $locale)],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => ArrivalReplies::payload('yes', $booking), 'title' => (string) __('I confirm', locale: $locale)]],
                ['type' => 'reply', 'reply' => ['id' => ArrivalReplies::payload('no', $booking), 'title' => (string) __('Not coming', locale: $locale)]],
                ['type' => 'reply', 'reply' => ['id' => ArrivalReplies::payload('ask', $booking), 'title' => (string) __('I have a question', locale: $locale)]],
            ]],
        ];
    }

    /**
     * @return array{name: string, hotel: string, reference: string, room: string, dates: string, check_in_time: string, left: string|null, pay_url: string|null}
     */
    private function values(Booking $booking, string $locale): array
    {
        $hotel = $this->context->hotel();
        $place = HotelSettings::current();
        $balance = $this->folio->balance($booking);
        $link = $balance > 0 ? $this->deposits->balanceLinkFor($booking) : null;
        $format = fn (CarbonImmutable $d): string => $d->settings(['locale' => $locale])->isoFormat('ddd D MMM');

        return [
            'name' => (string) $booking->guest?->first_name,
            'hotel' => $hotel->name,
            'reference' => $booking->reference,
            'room' => (string) $booking->category?->localizedName($locale, $hotel->default_locale),
            'dates' => $format($booking->check_in).' → '.$format($booking->check_out),
            'check_in_time' => substr((string) $place->check_in_time, 0, 5) ?: '14:00',
            'left' => $balance > 0 ? MoneyFormatter::format(Money::ofMinor($balance, $booking->currency), $locale) : null,
            'pay_url' => $link === null ? null : $this->deposits->url($link),
        ];
    }

    private function template(string $locale): ?MessageTemplate
    {
        return MessageTemplate::query()->where('name', self::TEMPLATE)->where('language', $locale)->where('status', TemplateStatus::Approved->value)->first();
    }

    /**
     * @param  array<string, string>|null  $texts
     */
    public static function localized(?array $texts, string $locale, string $default): string
    {
        return trim((string) ($texts[$locale] ?? $texts[$default] ?? ''));
    }
}
