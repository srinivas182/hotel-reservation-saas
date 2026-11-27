<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\BookingRequests\BookingChangeRequests;
use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\GuestBookings;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Deposits\DepositPolicy;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;

/**
 * 0.39.0: the guest cancels their own booking on WhatsApp, within the
 * hotel's free cancellation time ("refunded if cancelled at least N days
 * before arrival", set on the Deposits page). The rooms are freed at once
 * for other guests and any payment is refunded by the usual deposit rule.
 *
 * Two steps, so a misunderstood message never cancels a stay: the first
 * call (confirm=false) describes the booking and remembers that the guest
 * was asked; only a second call within 30 minutes, after the guest said
 * yes, cancels it. Only bookings of this WhatsApp number can be found.
 */
final class CancelMyBooking extends AssistantTool
{
    private const CONFIRM_MINUTES = 30;

    public function name(): string
    {
        return 'cancel_booking';
    }

    public function description(): string
    {
        return 'Cancel the guest\'s own booking when they ask to cancel. First call it with confirm=false: it says whether the guest may cancel it themselves and what happens to any payment; repeat that to the guest and ask them to confirm. Only after the guest clearly says yes, call it again with confirm=true. If it answers allowed=false, follow its next_step (reception handles it).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_reference' => $schema->string()->description('The booking reference (e.g. CTH-26-0008), if the guest has several bookings'),
            'confirm' => $schema->boolean()->description('true only after the guest confirmed the cancellation')->required(),
        ];
    }

    protected function run(array $arguments): array
    {
        if ($this->context->isPractice()) {
            return ['practice' => true, 'cancelled' => false, 'note' => 'Practice screen: nothing was cancelled.'];
        }

        $conversation = Conversation::query()->with('contact')->find($this->context->conversationId);

        if ($conversation === null) {
            return ['found' => false, 'next_step' => 'No booking is linked to this WhatsApp number. Ask for the booking reference and pass the request to reception (handover, reason "cancellation: <reference>").'];
        }

        // 0.39.2: by the guest linked to this number or any guest with the same phone.
        $bookings = app(GuestBookings::class)->forConversation($conversation, [BookingStatus::Tentative, BookingStatus::Confirmed]);

        $reference = strtoupper(trim((string) ($arguments['booking_reference'] ?? '')));
        $booking = $reference !== '' ? $bookings->firstWhere('reference', $reference) : ($bookings->count() === 1 ? $bookings->first() : null);

        if ($booking === null) {
            return [
                'found' => $bookings->isNotEmpty(),
                'bookings' => $bookings->map(fn (Booking $b): string => $b->reference.' ('.$b->check_in->toDateString().' to '.$b->check_out->toDateString().')')->values()->all(),
                'next_step' => $bookings->isEmpty() ? 'This WhatsApp number has no booking that can be cancelled. Never invent one.' : 'Ask the guest which booking they mean.',
            ];
        }

        $hotel = app(TenantContext::class)->hotel();
        $settings = HotelAssistantSettings::current();
        $locale = $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale;
        $unpaid = $booking->status === BookingStatus::Tentative;
        // A tentative booking holds no payment: it can be released until the arrival day.
        $allowed = $unpaid
            ? HotelClock::today($hotel)->lt($booking->check_in)
            : DepositPolicy::refundable($booking, $settings, $hotel);

        if (! $allowed) {
            // 0.39.3: a cancellation request reception sees on the Today page.
            if ((bool) ($arguments['confirm'] ?? false)) {
                app(BookingChangeRequests::class)->requestCancellation($booking, $conversation, 'after the free cancellation time');
            }

            return [
                'allowed' => false,
                'reference' => $booking->reference,
                'sent_to_reception' => (bool) ($arguments['confirm'] ?? false),
                'next_step' => 'The free cancellation time for this booking is over, so the guest cannot cancel it alone: reception decides and confirms. If the guest has not yet confirmed they want to cancel, ask; when they say yes, call cancel_booking again with confirm=true and it is sent to reception. Set handover to true with the reason "cancellation: '.$booking->reference.'". Never say it is cancelled.',
            ];
        }

        $key = "cancel-asked:{$conversation->id}:{$booking->id}";

        if (! (bool) ($arguments['confirm'] ?? false) || ! Cache::has($key)) {
            Cache::put($key, true, now()->addMinutes(self::CONFIRM_MINUTES));

            return [
                'allowed' => true,
                'cancelled' => false,
                'reference' => $booking->reference,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'payment_rule' => $unpaid ? 'Nothing was paid: nothing to refund.' : DepositPolicy::text($settings, $locale),
                'next_step' => 'Tell the guest which booking will be cancelled (reference and dates) and the payment rule, and ask them to confirm. Call again with confirm=true only after they clearly say yes.',
            ];
        }

        try {
            app(BookingLifecycle::class)->cancel($booking, (string) __('Cancelled by the guest on WhatsApp', locale: $hotel->default_locale));
        } catch (BookingActionNotAllowed $exception) {
            return ['cancelled' => false, 'message' => $exception->getMessage(), 'next_step' => 'Pass the request to reception (handover, reason "cancellation: '.$booking->reference.'").'];
        }

        Cache::forget($key);

        return [
            'cancelled' => true,
            'reference' => $booking->reference,
            'refund' => $unpaid ? 'none: nothing was paid' : 'any payment is refunded by the hotel, as in payment_rule',
            'payment_rule' => $unpaid ? null : DepositPolicy::text($settings, $locale),
            'next_step' => 'Tell the guest the booking is cancelled'.($unpaid ? '' : ' and that any payment made will be refunded as in payment_rule').'. Do not hand over.',
        ];
    }
}
