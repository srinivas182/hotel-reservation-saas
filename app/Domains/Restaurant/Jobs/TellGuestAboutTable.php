<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Jobs;

use App\Domains\Audit\Audit;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells a WhatsApp guest that their table is confirmed, declined, or that
 * the request was not answered in time. Free text within 24 hours,
 * otherwise the approved template; else staff are asked to contact them.
 */
final class TellGuestAboutTable implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $bookingId) {}

    public function handle(MessageSender $sender, TenantContext $context): void
    {
        $booking = TableBooking::query()->with(['guest', 'outlet'])->find($this->bookingId);

        if ($booking === null || $booking->conversation_id === null) {
            return;
        }

        $kind = match ($booking->status) {
            TableBookingStatus::Confirmed => 'confirmed',
            TableBookingStatus::Declined => 'declined',
            TableBookingStatus::Expired => 'expired',
            default => null,
        };

        if ($kind === null || $booking->guest_notified === $kind) {
            return;
        }

        $conversation = Conversation::query()->find($booking->conversation_id);
        $hotel = $context->hotel();
        $locale = $booking->guest->preferred_locale ?? $hotel->default_locale;

        if ($conversation === null) {
            return;
        }

        /** @var CarbonImmutable $day */
        $day = $booking->date->locale($locale);
        $v = [
            '{name}' => (string) ($booking->guest->first_name ?? ''),
            '{outlet}' => (string) $booking->outlet?->localizedName($locale, $hotel->default_locale),
            '{date}' => $day->isoFormat('dddd D MMMM'),
            '{time}' => $booking->time,
            '{covers}' => (string) $booking->covers,
            '{reference}' => $booking->reference,
            '{hotel}' => $hotel->name,
        ];

        $text = strtr(match ($kind) {
            'confirmed' => (string) __('Hello {name}, your table at {outlet} is confirmed for {date} at {time}, for {covers} people. Reference {reference}. See you soon at {hotel}!', locale: $locale),
            'declined' => (string) __('Hello {name}, we are sorry: {outlet} cannot take your booking for {date} at {time}. Do not hesitate to ask us for another time.', locale: $locale),
            default => (string) __('Hello {name}, thank you for your table request for {date} at {time}. Our team will contact you very soon.', locale: $locale),
        }, $v);

        try {
            if ($conversation->windowOpen()) {
                $sender->sendText($conversation, $text, null, MessageSource::System);
                $this->done($booking, $kind, 'text');

                return;
            }

            $name = $kind === 'confirmed' ? 'table_booking_confirmed' : 'table_booking_declined';
            $template = MessageTemplate::query()->where('name', $name)->where('status', TemplateStatus::Approved->value)->whereIn('language', [$locale, $hotel->default_locale])
                ->orderByRaw('case when language = ? then 0 else 1 end', [$locale])->first();

            if ($template !== null && $kind !== 'expired') {
                $values = $kind === 'confirmed'
                    ? [$v['{name}'], $v['{outlet}'], $v['{date}'], $v['{time}'], $v['{covers}'], $v['{reference}'], $v['{hotel}']]
                    : [$v['{name}'], $v['{outlet}'], $v['{date}'], $v['{time}']];
                $sender->sendTemplate($conversation, $template, array_map(fn (string $x): string => MessageTemplate::cleanValue($x), $values), null, MessageSource::System);
                $this->done($booking, $kind, 'template');

                return;
            }
        } catch (CannotSend) {
            // Staff contact the guest instead.
        }

        $conversation->forceFill(['needs_attention' => true, 'handover_reason' => "table_guest_not_told:{$kind}"])->save();
    }

    private function done(TableBooking $booking, string $kind, string $how): void
    {
        $booking->forceFill(['guest_notified' => $kind])->save();
        Audit::record('table_bookings.guest_told', 'The guest was told about the table on WhatsApp', subject: $booking, properties: ['kind' => $kind, 'how' => $how]);
    }
}
