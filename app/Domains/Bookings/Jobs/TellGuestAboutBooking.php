<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Jobs;

use App\Domains\Audit\Audit;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 0.38.2: tells the guest on WhatsApp that reception changed or cancelled
 * their booking. Free text within 24 hours of the guest's last message.
 * After that, a change is sent with the approved "booking_confirmed"
 * template (it carries the new dates, room and total). A cancellation has
 * no template, so it is flagged for staff to contact the guest, as is any
 * message that cannot be sent.
 */
final class TellGuestAboutBooking implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public const CHANGED = 'changed';

    public const CANCELLED = 'cancelled';

    /** 0.39.3: reception declined the guest's change or cancellation request. */
    public const DECLINED = 'declined';

    /** Retry policy (S16): attempts before the job is marked failed. */
    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [10, 60];

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 60;

    public function __construct(
        public readonly string $bookingId,
        public readonly string $kind,
        public readonly ?string $requestKind = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * The guest's WhatsApp conversation: the one the booking request came
     * from, otherwise the latest one with the guest's number.
     */
    public static function conversationFor(Booking $booking): ?Conversation
    {
        $fromRequest = BookingRequest::query()->where('booking_id', $booking->id)->whereNotNull('conversation_id')->value('conversation_id');

        if (is_string($fromRequest)) {
            return Conversation::query()->with('contact')->whereKey($fromRequest)->first();
        }

        return Conversation::query()->with('contact')
            ->whereHas('contact', fn ($q) => $q->where('guest_id', $booking->guest_id))
            ->orderByDesc('last_inbound_at')
            ->first();
    }

    public function handle(MessageSender $sender, TenantContext $context): void
    {
        $booking = Booking::query()->with(['guest', 'category'])->find($this->bookingId);
        $conversation = $booking === null ? null : self::conversationFor($booking);

        if ($booking === null || $conversation === null) {
            return;
        }

        $hotel = $context->hotel();
        $locale = $booking->guest->preferred_locale
            ?? $conversation->messages()->where('direction', 'in')->whereNotNull('language')->orderByDesc('sent_at')->orderByDesc('id')->value('language')
            ?? $hotel->default_locale;
        $date = function (CarbonImmutable $d) use ($locale): string {
            /** @var CarbonImmutable $localized */
            $localized = $d->locale($locale);

            return $localized->isoFormat('D MMMM YYYY');
        };
        $v = [
            '{name}' => (string) ($booking->guest->first_name ?? ''),
            '{reference}' => $booking->reference,
            '{room}' => (string) $booking->category?->localizedName($locale, $hotel->default_locale),
            '{rooms}' => (string) $booking->rooms,
            '{check_in}' => $date($booking->check_in),
            '{check_out}' => $date($booking->check_out),
            '{guests}' => (string) ($booking->adults + count(array_filter($booking->children_ages, fn ($a): bool => $a !== null))),
            '{total}' => MoneyFormatter::format(Money::ofMinor($booking->total_minor, $booking->currency), $locale),
            '{hotel}' => $hotel->name,
        ];

        $v['{reason}'] = (string) $this->reason;
        $text = strtr(match (true) {
            $this->kind === self::CANCELLED => (string) __('Hello {name}, your booking {reference} at {hotel} ({check_in} to {check_out}) is cancelled. If you have already paid, reception will contact you about the refund.', locale: $locale),
            $this->kind === self::DECLINED && $this->requestKind === 'cancellation' => (string) __('Hello {name}, reception could not cancel your booking {reference} as asked: {reason}. Your booking stays as it is ({check_in} to {check_out}).', locale: $locale),
            $this->kind === self::DECLINED => (string) __('Hello {name}, reception could not make the change you asked for to booking {reference}: {reason}. Your booking stays as it is: {room}, {check_in} to {check_out}, total {total}.', locale: $locale),
            default => (string) __('Hello {name}, your booking {reference} at {hotel} has been updated: {room} × {rooms}, from {check_in} to {check_out}, {guests} guest(s). New total: {total}. See you soon!', locale: $locale),
        }, $v);

        try {
            if ($conversation->windowOpen()) {
                $sender->sendText($conversation, $text, null, MessageSource::System);
                $this->done($booking, 'text', $conversation);

                return;
            }

            $template = $this->kind === self::CHANGED ? $this->template($locale, $hotel->default_locale) : null;

            if ($template !== null) {
                $values = [$v['{name}'], $v['{reference}'], $v['{room}'], $v['{check_in}'], $v['{check_out}'], $v['{total}'], $v['{hotel}']];
                $sender->sendTemplate($conversation, $template, array_map(fn (string $x): string => MessageTemplate::cleanValue($x), $values), null, MessageSource::System);
                $this->done($booking, 'template', $conversation);

                return;
            }
        } catch (CannotSend) {
            // Staff contact the guest instead.
        }

        $conversation->forceFill(['needs_attention' => true, 'handover_reason' => "guest_not_told:booking_{$this->kind}"])->save();
        Audit::record('bookings.guest_not_told', 'The guest could not be told on WhatsApp', subject: $booking, properties: ['kind' => $this->kind]);
    }

    private function template(string $locale, string $default): ?MessageTemplate
    {
        return MessageTemplate::query()->where('name', 'booking_confirmed')->where('status', TemplateStatus::Approved->value)
            ->whereIn('language', [$locale, $default])
            ->orderByRaw('case when language = ? then 0 else 1 end', [$locale])
            ->first();
    }

    private function done(Booking $booking, string $how, Conversation $conversation): void
    {
        // The assistant's "change booking <ref>" or "cancellation: <ref>"
        // flag is done once reception has made the change and told the guest.
        $reason = (string) $conversation->handover_reason;
        if ($conversation->needs_attention && str_contains($reason, $booking->reference)
            && (str_contains($reason, 'change booking') || str_contains($reason, 'cancellation') || str_contains($reason, 'change'))) {
            $conversation->forceFill(['needs_attention' => false, 'handover_reason' => null])->save();
        }

        Audit::record('bookings.guest_told', match ($this->kind) {
            self::CANCELLED => 'The guest was told about the cancellation on WhatsApp',
            self::DECLINED => 'The guest was told reception declined the request on WhatsApp',
            default => 'The guest was sent the new booking details on WhatsApp',
        }, subject: $booking, properties: ['kind' => $this->kind, 'how' => $how]);
    }
}
