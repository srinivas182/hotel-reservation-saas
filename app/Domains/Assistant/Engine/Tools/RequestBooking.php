<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\BookingRequests\Exceptions\RequestNotPossible;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Deposits\Deposits;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * Sends a booking request to reception and holds the rooms. It never
 * confirms a booking: reception decides.
 */
final class RequestBooking extends AssistantTool
{
    public function name(): string
    {
        return 'request_booking';
    }

    public function description(): string
    {
        return 'Send a booking request to reception and hold the rooms, also when reception is closed. Call it as soon as the guest wants to book and you know the room category, dates, number of guests and their full name; if one is missing, ask the guest for it first. Reception confirms or declines, unless the answer says confirmed=true: then the booking is confirmed at once. Follow next_step exactly. Never use it to cancel or change a booking. If it answers possible_duplicate, ask the guest whether they want an extra room on top of their booking, and only then call it again with additional_room=true.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest_name' => $schema->string()->description('The guest\'s full name as they gave it')->required(),
            'category_code' => $schema->string()->description('Room category code')->required(),
            'check_in' => $schema->string()->description('Arrival date, YYYY-MM-DD')->required(),
            'check_out' => $schema->string()->description('Departure date, YYYY-MM-DD')->required(),
            'adults' => $schema->integer()->required(),
            'children_ages' => $schema->array()->items($schema->integer()),
            'rooms' => $schema->integer(),
            'breakfast' => $schema->boolean(),
            'extra_bed' => $schema->boolean(),
            'coupon_code' => $schema->string()->description('A discount code the guest gave, if any'),
            'additional_room' => $schema->boolean()->description('True only after the guest confirmed they want this room in addition to a booking or request they already have for the same dates.'),
            'special_requests' => $schema->string()->description('Short note for reception of what the guest asked for or told you: a pet (which animal, size), a baby cot, a late or early arrival, an accessible room, a celebration… Empty if nothing.'),
        ];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $category = RoomCategory::query()->where('code', strtoupper((string) ($arguments['category_code'] ?? '')))->first();

        if ($category === null) {
            return ['error' => 'Unknown room category code.'];
        }

        try {
            $checkIn = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_in'] ?? ''));
            $checkOut = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_out'] ?? ''));
        } catch (Throwable) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        if ($checkIn === null || $checkOut === null) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        $stay = new StayRequest(
            categoryId: $category->id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: max(1, (int) ($arguments['adults'] ?? 1)),
            childAges: array_values(array_map(fn ($age): int => max(0, min(17, (int) $age)), (array) ($arguments['children_ages'] ?? []))),
            rooms: max(1, (int) ($arguments['rooms'] ?? 1)),
            breakfast: (bool) ($arguments['breakfast'] ?? false),
            extraBed: (bool) ($arguments['extra_bed'] ?? false),
        );

        if ($this->context->isPractice()) {
            return ['practice' => true, 'request_sent' => false, 'note' => 'Practice screen: no request was created. In a real conversation the request goes to reception and the rooms are held.'];
        }

        $conversation = Conversation::query()->with('contact.guest')->find($this->context->conversationId);

        if ($conversation === null) {
            return ['error' => 'Conversation not found.'];
        }

        // 0.38.0: the same guest already has a booking or a request for these dates.
        if (! (bool) ($arguments['additional_room'] ?? false) && ($existing = $this->overlapping($conversation, $checkIn, $checkOut)) !== null) {
            return [
                'request_sent' => false,
                'reason' => 'possible_duplicate',
                'existing' => $existing,
                'message' => 'This guest already has '.$existing.' for overlapping dates. Ask whether they want an extra room in addition; send again with additional_room=true only if they say so.',
            ];
        }

        try {
            $request = app(BookingRequestService::class)->submit($conversation, $stay, (string) ($arguments['guest_name'] ?? ''), isset($arguments['coupon_code']) ? (string) $arguments['coupon_code'] : null, isset($arguments['special_requests']) ? (string) $arguments['special_requests'] : null);
        } catch (RequestNotPossible $exception) {
            return ['request_sent' => false, 'reason' => $exception->reason, 'message' => $exception->getMessage()];
        }

        $locale = $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale;
        $total = Money::ofMinor($request->total_minor, $request->currency);
        $this->context->allowMoney($total);
        $prepay = app(Deposits::class)->prepayPrice($request->total_minor, $request->currency);
        $extra = [];
        if ($request->discount_minor > 0) {
            $off = Money::ofMinor($request->discount_minor, $request->currency);
            $this->context->allowMoney($off);
            $extra['coupon_discount'] = MoneyFormatter::format($off, $locale);
        }
        if ($prepay !== null) {
            $payNow = Money::ofMinor($prepay, $request->currency);
            $this->context->allowMoney($payNow);
            $extra['pay_now_online'] = MoneyFormatter::format($payNow, $locale);
            $extra['payment_choice'] = 'Once reception confirms, the guest receives a link to pay the whole stay now for pay_now_online, or can pay total_to_pay at the hotel.';
        }

        // 0.39.0: confirmed at once when the hotel allows it and rooms are free.
        $booking = app(BookingRequestService::class)->autoConfirm($request);

        if ($booking !== null) {
            unset($extra['payment_choice']);

            return [
                ...$extra,
                'request_sent' => true,
                'confirmed' => true,
                'booking_reference' => $booking->reference,
                'status' => $booking->status->value,
                'total_to_pay' => MoneyFormatter::format(Money::ofMinor($booking->total_minor, $booking->currency), $locale),
                'next_step' => 'The booking is CONFIRMED. The guest receives a separate message right now with the booking reference, the details and how to pay (payment link or pay at the hotel). Reply in one or two short sentences: thank the guest, say the booking is confirmed and the details and payment link are in the next message. Do not repeat the link or the amounts.'
                    .($booking->status->value === 'tentative' ? ' The room is kept until the deposit is paid: say so.' : ''),
            ];
        }

        return [
            ...$extra,
            'request_sent' => true,
            'reference' => $request->reference,
            'total_to_pay' => MoneyFormatter::format($total, $locale),
            'rooms_held_until' => $request->expires_at->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            'next_step' => 'Tell the guest the request was sent and reception will confirm it. Do NOT say it is confirmed.',
        ];
    }

    /**
     * A booking or waiting request of this guest that overlaps the stay, as
     * words for the assistant ("booking CTH-26-0011, 10–12 December"), or null.
     */
    private function overlapping(Conversation $conversation, CarbonImmutable $checkIn, CarbonImmutable $checkOut): ?string
    {
        $guestId = $conversation->contact?->guest_id;

        if ($guestId !== null) {
            $booking = \App\Domains\Bookings\Models\Booking::query()
                ->where('guest_id', $guestId)
                ->whereIn('status', [\App\Domains\Bookings\BookingStatus::Tentative->value, \App\Domains\Bookings\BookingStatus::Confirmed->value, \App\Domains\Bookings\BookingStatus::CheckedIn->value])
                ->whereDate('check_in', '<', $checkOut->toDateString())
                ->whereDate('check_out', '>', $checkIn->toDateString())
                ->first();
            if ($booking !== null) {
                return 'booking '.$booking->reference.' ('.$booking->check_in->toDateString().' to '.$booking->check_out->toDateString().')';
            }
        }

        $request = \App\Domains\BookingRequests\BookingRequest::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', \App\Domains\BookingRequests\BookingRequestStatus::Pending->value)
            ->whereDate('check_in', '<', $checkOut->toDateString())
            ->whereDate('check_out', '>', $checkIn->toDateString())
            ->first();

        return $request === null ? null : 'a booking request '.$request->reference.' waiting for reception ('.$request->check_in->toDateString().' to '.$request->check_out->toDateString().')';
    }
}
