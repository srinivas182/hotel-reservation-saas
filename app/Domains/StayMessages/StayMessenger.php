<?php

declare(strict_types=1);

namespace App\Domains\StayMessages;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\ConversationDirectory;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Sends the hotel's stay messages: arrival reminder, check-out reminder and
 * thank-you. Each booking gets each message at most once, only with the
 * guest's consent, in their language.
 */
final class StayMessenger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageSender $sender,
        private readonly ConversationDirectory $directory,
    ) {}

    /**
     * @return array<string, array{enabled: bool, time: string}>
     */
    public static function settings(HotelAssistantSettings $settings): array
    {
        $saved = $settings->stay_messages ?? [];
        $result = [];

        foreach (StayMessageKind::cases() as $kind) {
            $result[$kind->value] = [
                'enabled' => (bool) ($saved[$kind->value]['enabled'] ?? false),
                'time' => (string) ($saved[$kind->value]['time'] ?? $kind->defaultTime()),
            ];
        }

        return $result;
    }

    public function sendDue(): int
    {
        $hotel = $this->context->hotel();
        $settings = HotelAssistantSettings::current();
        $now = CarbonImmutable::now($hotel->timezone)->format('H:i');
        $today = HotelClock::today($hotel);
        $sent = 0;

        foreach (self::settings($settings) as $kindValue => $choice) {
            $kind = StayMessageKind::from($kindValue);

            if (! $choice['enabled'] || $now < $choice['time']) {
                continue;
            }

            $target = $kind->target();

            $bookings = Booking::query()
                ->with(['guest', 'category'])
                ->where($target['column'], $today->addDays($target['days_from_today'])->toDateString())
                ->whereIn('status', array_map(fn ($s): string => $s->value, $target['statuses']))
                ->whereDoesntHave('stayMessages', fn ($q) => $q->where('kind', $kind->value))
                ->get();

            foreach ($bookings as $booking) {
                if ($this->send($booking, $kind, $settings)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    public function send(Booking $booking, StayMessageKind $kind, HotelAssistantSettings $settings): bool
    {
        $hotel = $this->context->hotel();
        $guest = $booking->guest;

        [$how, $reason] = match (true) {
            $guest === null || $guest->phone_e164 === null => ['skipped', 'no_phone'],
            ! $guest->whatsapp_opt_in && $booking->whatsapp_consent_at === null => ['skipped', 'no_consent'],
            default => ['pending', null],
        };

        if ($how === 'pending' && $guest !== null) {
            $locale = $guest->preferred_locale ?? $hotel->default_locale;
            $values = $this->values($booking, $locale, $settings);
            $conversation = $this->directory->forWaId(ltrim((string) $guest->phone_e164, '+'));

            try {
                if ($conversation->windowOpen()) {
                    $text = strtr($kind->defaultText($locale), $values);
                    $this->sender->sendText($conversation, $text, null, MessageSource::System);
                    [$how, $reason] = ['text', null];
                } elseif ($kind === StayMessageKind::ThankYou && $values['{review_url}'] === '-') {
                    [$how, $reason] = ['skipped', 'no_review_link'];
                } else {
                    $template = $this->template($kind, $locale) ?? $this->template($kind, $hotel->default_locale);

                    if ($template === null) {
                        [$how, $reason] = ['skipped', 'template_not_approved'];
                    } else {
                        $this->sender->sendTemplate($conversation, $template, $this->templateValues($kind, $values), null, MessageSource::System);
                        [$how, $reason] = ['template', null];
                    }
                }
            } catch (CannotSend $exception) {
                [$how, $reason] = ['skipped', mb_substr($exception->getMessage(), 0, 95)];
            }
        }

        try {
            StayMessage::query()->create(['booking_id' => $booking->id, 'kind' => $kind->value, 'how' => $how, 'reason' => $reason]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        Audit::record('bookings.stay_message', $how === 'skipped' ? 'Stay message not sent' : 'Stay message sent', subject: $booking, properties: [
            'kind' => $kind->value,
            'how' => $how,
            'reason' => $reason,
        ]);

        return $how !== 'skipped';
    }

    private function template(StayMessageKind $kind, string $locale): ?MessageTemplate
    {
        return MessageTemplate::query()->where('name', $kind->template())->where('language', $locale)->where('status', TemplateStatus::Approved->value)->first();
    }

    /**
     * @return array<string, string>
     */
    private function values(Booking $booking, string $locale, HotelAssistantSettings $settings): array
    {
        $hotel = $this->context->hotel();
        $hotelSettings = HotelSettings::current();
        /** @var CarbonImmutable $checkIn */
        $checkIn = $booking->check_in->locale($locale);

        return [
            '{name}' => (string) $booking->guest?->first_name,
            '{reference}' => $booking->reference,
            '{hotel}' => $hotel->name,
            '{check_in}' => $checkIn->isoFormat('dddd D MMMM'),
            '{check_in_time}' => substr((string) $hotelSettings->check_in_time, 0, 5),
            '{check_out_time}' => substr((string) $hotelSettings->check_out_time, 0, 5),
            '{directions}' => $hotelSettings->map_url ?: trim(implode(', ', array_filter([$hotelSettings->address_line1, $hotelSettings->city]))) ?: '-',
            '{review_url}' => trim((string) $settings->review_url) ?: '-',
        ];
    }

    /**
     * @param  array<string, string>  $v
     * @return list<string>
     */
    private function templateValues(StayMessageKind $kind, array $v): array
    {
        return array_map(fn (string $value): string => MessageTemplate::cleanValue($value), match ($kind) {
            StayMessageKind::Reminder => [$v['{name}'], $v['{hotel}'], $v['{check_in}'], $v['{check_in_time}'], $v['{directions}']],
            StayMessageKind::Checkout => [$v['{name}'], $v['{hotel}'], $v['{check_out_time}']],
            StayMessageKind::ThankYou => [$v['{name}'], $v['{hotel}'], $v['{review_url}']],
        });
    }
}
