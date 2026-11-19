<?php

declare(strict_types=1);

namespace App\Domains\Arrivals;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Arrivals\Models\ArrivalCheck;
use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\ConversationHandover;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Deposits\DepositPolicy;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\StaffAlerts\AlertKind;
use App\Domains\StaffAlerts\StaffAlerts;
use App\Domains\Tenancy\TenantContext;
use Throwable;

/**
 * The guest's taps on the arrival card and what follows (Sprint G).
 *
 * Button codes are ga:{action}:{booking id}; they need no signature
 * because a tap only counts when it comes from the booking's own WhatsApp
 * number. Actions: yes, no, cancel (confirmed "not coming"), ask,
 * eta-{slot}, x-{extra}.
 */
final class ArrivalReplies
{
    private const PREFIX = 'ga:';

    public function __construct(
        private readonly TenantContext $context,
        private readonly MessageSender $sender,
        private readonly ArrivalCard $card,
        private readonly StaffAlerts $alerts,
        private readonly ConversationHandover $handover,
    ) {}

    public static function payload(string $action, Booking $booking): string
    {
        return self::PREFIX.$action.':'.$booking->id;
    }

    /**
     * The arrival code in an incoming message, if any.
     *
     * @param  array<string, mixed>  $message
     */
    public static function payloadOf(array $message): ?string
    {
        $payload = match ((string) ($message['type'] ?? '')) {
            'interactive' => $message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null,
            'button' => $message['button']['payload'] ?? null,
            default => null,
        };

        return is_string($payload) && preg_match('/^ga:[a-z0-9_-]{2,30}:[0-9A-Za-z]{26}$/', $payload) === 1 ? $payload : null;
    }

    /**
     * Apply a tap. Returns false when it is not a valid tap for this
     * conversation (the assistant then answers as usual).
     */
    public function handle(Conversation $conversation, string $payload): bool
    {
        [, $action, $bookingId] = explode(':', $payload, 3);
        $booking = Booking::query()->with(['guest', 'category'])->find($bookingId);
        $waId = $conversation->contact?->wa_id;

        if ($booking === null || $waId === null || ltrim((string) $booking->guest?->phone_e164, '+') !== $waId) {
            return false;
        }

        $hotel = $this->context->hotel();
        $locale = $booking->guest->preferred_locale ?? $hotel->default_locale;
        $name = (string) $booking->guest?->first_name;

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Tentative], true)) {
            $this->say($conversation, (string) __('This booking is no longer active. Write to us here if you need anything.', locale: $locale));

            return true;
        }

        $check = ArrivalCheck::for($booking);
        Audit::record('arrivals.reply', 'Guest answered the arrival card', subject: $booking, properties: ['action' => $action]);

        match (true) {
            $action === 'yes' => $this->confirmed($booking, $check, $conversation, $locale, $name),
            $action === 'no' => $this->askToCancel($booking, $conversation, $locale),
            $action === 'cancel' => $this->notComing($booking, $check, $conversation, $locale),
            $action === 'ask' => $this->question($booking, $check, $conversation, $locale),
            str_starts_with($action, 'eta-') => $this->eta($booking, $check, $conversation, $locale, substr($action, 4)),
            str_starts_with($action, 'x-') => $this->extra($booking, $check, $conversation, $locale, substr($action, 2)),
            default => null,
        };

        return true;
    }

    private function confirmed(Booking $booking, ArrivalCheck $check, Conversation $conversation, string $locale, string $name): void
    {
        $first = $check->confirmed_at === null;
        $check->forceFill(['confirmed_at' => $check->confirmed_at ?? now(), 'declined_at' => null, 'flagged_at' => null])->save();

        $this->say($conversation, (string) __('Thank you :name, see you tomorrow! 🙏', ['name' => $name], $locale));
        $this->card->pin($booking, $conversation, $locale, $check);

        if ($check->eta_slot === null) {
            $this->sender->sendInteractive($conversation, [
                'type' => 'list',
                'body' => ['text' => (string) __('What time do you expect to arrive? It helps us get your room ready.', locale: $locale)],
                'action' => [
                    'button' => mb_substr((string) __('Choose a time', locale: $locale), 0, 20),
                    'sections' => [[
                        'title' => mb_substr((string) __('Arrival time', locale: $locale), 0, 24),
                        'rows' => array_map(fn (string $slot): array => [
                            'id' => self::payload('eta-'.$slot, $booking),
                            'title' => mb_substr(ArrivalSlots::label($slot, $locale), 0, 24),
                        ], array_keys(ArrivalSlots::SLOTS)),
                    ]],
                ],
            ]);
        }

        if ($first) {
            $this->offerExtras($booking, $conversation, $locale);
        }
    }

    private function askToCancel(Booking $booking, Conversation $conversation, string $locale): void
    {
        $paid = DepositLink::query()->where('booking_id', $booking->id)->where('status', 'paid')->exists();
        $text = (string) __('You are no longer coming? Reception will cancel booking :reference and confirm here.', ['reference' => $booking->reference], $locale);
        if ($paid) {
            $text .= "\n".DepositPolicy::text(HotelAssistantSettings::current(), $locale);
        }

        $this->sender->sendInteractive($conversation, [
            'type' => 'button',
            'body' => ['text' => $text],
            'action' => ['buttons' => [
                ['type' => 'reply', 'reply' => ['id' => self::payload('cancel', $booking), 'title' => mb_substr((string) __('Yes, cancel', locale: $locale), 0, 20)]],
                ['type' => 'reply', 'reply' => ['id' => self::payload('yes', $booking), 'title' => mb_substr((string) __('I am coming', locale: $locale), 0, 20)]],
            ]],
        ]);
    }

    private function notComing(Booking $booking, ArrivalCheck $check, Conversation $conversation, string $locale): void
    {
        if ($check->declined_at !== null) {
            return;
        }

        $check->forceFill(['declined_at' => now(), 'confirmed_at' => null])->save();
        $conversation->forceFill(['needs_attention' => true, 'handover_reason' => 'arrival_not_coming', 'waiting_since' => $conversation->waiting_since ?? now()])->save();

        $this->say($conversation, (string) __('Noted. Reception will cancel your booking and confirm here. Thank you for telling us.', locale: $locale));
        $this->alert(AlertKind::ArrivalCancelling, (string) __(':guest (:reference, arriving :date) is not coming: cancel the booking from the dashboard.', [
            'guest' => (string) $booking->guest?->name(), 'reference' => $booking->reference, 'date' => $booking->check_in->format('d/m'),
        ], $this->staffLocale()), $booking->id, urgent: true);
    }

    private function question(Booking $booking, ArrivalCheck $check, Conversation $conversation, string $locale): void
    {
        $check->forceFill(['question_at' => now()])->save();
        $this->say($conversation, (string) __('Write your question here: someone from the team answers you.', locale: $locale));
        $this->handover->toStaff($conversation, 'Question before arrival ('.$booking->reference.')');
    }

    private function eta(Booking $booking, ArrivalCheck $check, Conversation $conversation, string $locale, string $slot): void
    {
        if (! isset(ArrivalSlots::SLOTS[$slot])) {
            return;
        }

        $check->forceFill(['eta_slot' => $slot, 'eta_at' => now(), 'confirmed_at' => $check->confirmed_at ?? now()])->save();
        $settings = HotelAssistantSettings::current();
        $lines = [(string) __('Noted: arrival :slot.', ['slot' => ArrivalSlots::label($slot, $locale)], $locale)];

        if (ArrivalSlots::afterClosing($slot, $settings->reception_closes_at)) {
            $night = ArrivalCard::localized($settings->night_arrival, $locale, $this->context->hotel()->default_locale);
            $lines[] = '🌙 '.($night !== ''
                ? $night
                : __('Reception closes at :time: write to us here when you are on your way, so someone waits for you.', ['time' => $settings->reception_closes_at], $locale));
        }

        $this->say($conversation, implode("\n", $lines));
    }

    private function offerExtras(Booking $booking, Conversation $conversation, string $locale): void
    {
        $offered = ArrivalExtras::offered(HotelAssistantSettings::current());
        if ($offered === []) {
            return;
        }

        $lines = [(string) __('Do you need anything else for your arrival?', locale: $locale)];
        foreach ($offered as $key => $price) {
            $lines[] = '• '.ArrivalExtras::priced($key, $price, $booking->currency, $locale);
        }

        $this->sender->sendInteractive($conversation, [
            'type' => 'button',
            'body' => ['text' => implode("\n", $lines)],
            'action' => ['buttons' => array_map(fn (string $key): array => [
                'type' => 'reply', 'reply' => ['id' => self::payload('x-'.$key, $booking), 'title' => ArrivalExtras::button($key, $locale)],
            ], array_slice(array_keys($offered), 0, 3))],
        ]);
    }

    private function extra(Booking $booking, ArrivalCheck $check, Conversation $conversation, string $locale, string $key): void
    {
        if (! array_key_exists($key, ArrivalExtras::offered(HotelAssistantSettings::current()))) {
            return;
        }

        $extras = $check->extras ?? [];
        if (in_array($key, $extras, true)) {
            $this->say($conversation, (string) __('Already passed on to reception.', locale: $locale));

            return;
        }

        $check->forceFill(['extras' => [...$extras, $key]])->save();
        $conversation->forceFill(['needs_attention' => true, 'waiting_since' => $conversation->waiting_since ?? now()])->save();

        $this->say($conversation, (string) __('Passed on to reception: :extra. We confirm here shortly.', ['extra' => ArrivalExtras::label($key, $locale)], $locale));
        $this->alert(AlertKind::ArrivalRequest, (string) __(':guest (:reference, arriving :date) asks for: :extra', [
            'guest' => (string) $booking->guest?->name(), 'reference' => $booking->reference, 'date' => $booking->check_in->format('d/m'), 'extra' => ArrivalExtras::label($key, $this->staffLocale()),
        ], $this->staffLocale()), $booking->id);
    }

    /** Alerts are written in the hotel's language, whoever triggered them. */
    private function staffLocale(): string
    {
        return $this->context->hotel()->default_locale;
    }

    private function say(Conversation $conversation, string $text): void
    {
        $this->sender->sendText($conversation, $text, null, MessageSource::System);
    }

    private function alert(AlertKind $kind, string $details, string $subjectId, bool $urgent = false): void
    {
        try {
            $this->alerts->notify($kind, $details, $subjectId, urgent: $urgent);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
