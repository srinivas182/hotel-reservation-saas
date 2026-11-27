<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\BookingRequests\BookingChangeRequests;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\GuestBookings;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\StayChanges;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * 0.37.6: what a change to the guest's own booking would cost (one more
 * adult, a child, other dates, another room, breakfast). Only the guest's
 * own bookings, found from this WhatsApp number: any other number learns
 * nothing. The change itself is made by reception.
 */
final class QuoteBookingChange extends AssistantTool
{
    /** Same room and same nights: the booking already holds them. */
    private const OWN_ROOM_ISSUES = ['not_available', 'kept_for_reception', 'stop_sell', 'closed_to_arrival', 'min_stay', 'past_date'];

    public function name(): string
    {
        return 'quote_booking_change';
    }

    public function description(): string
    {
        return 'The guest\'s own upcoming bookings, and the exact new price when they want to change one: more or fewer adults or children, other dates, another room category, more rooms, breakfast, an extra bed. Give only what changes; the rest stays as booked. Returns the current total, the new total and the difference. Call it whenever a guest asks to change their booking or what a change would cost. A guest already staying at the hotel can only change the departure date (stay longer or leave earlier): give check_out. When the guest confirms they want the change, call it again with the same changes and send_to_reception=true: reception then applies it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'booking_reference' => $schema->string()->description('The booking reference (e.g. CTH-26-0008), if the guest has several bookings'),
            'adults' => $schema->integer()->description('New number of adults, if it changes'),
            'children_ages' => $schema->array()->items($schema->integer())->description('New ages of all children, if they change'),
            'check_in' => $schema->string()->description('New arrival date YYYY-MM-DD, if it changes'),
            'check_out' => $schema->string()->description('New departure date YYYY-MM-DD, if it changes'),
            'category_code' => $schema->string()->description('New room category code, if it changes'),
            'rooms' => $schema->integer()->description('New number of rooms, if it changes'),
            'breakfast' => $schema->boolean()->description('Breakfast wanted or not, if it changes'),
            'extra_bed' => $schema->boolean()->description('An extra bed wanted or not, if it changes'),
            'send_to_reception' => $schema->boolean()->description('true only after the guest confirmed they want this change: it is sent to reception to apply'),
        ];
    }

    protected function run(array $arguments): array
    {
        $conversation = $this->context->isPractice() ? null : Conversation::query()->with('contact')->find($this->context->conversationId);
        // 0.39.2: by the guest linked to this number or any guest with the same phone.
        $bookings = app(GuestBookings::class)->forConversation($conversation, [BookingStatus::Tentative, BookingStatus::Confirmed, BookingStatus::CheckedIn]);

        if ($conversation === null || $bookings->isEmpty() && $conversation->contact?->guest_id === null) {
            return ['found' => false, 'message' => 'No booking is linked to this WhatsApp number. Ask for the booking reference and pass the request to reception (handover).'];
        }

        $reference = strtoupper(trim((string) ($arguments['booking_reference'] ?? '')));
        $booking = $reference !== '' ? $bookings->firstWhere('reference', $reference) : ($bookings->count() === 1 ? $bookings->first() : null);

        if ($booking === null) {
            return [
                'found' => $bookings->isNotEmpty(),
                'message' => $bookings->isEmpty() ? 'This WhatsApp number has no upcoming booking.' : 'Ask the guest which booking they mean.',
                'bookings' => $bookings->map(fn (Booking $b): array => $this->describe($b))->values()->all(),
            ];
        }

        $hotel = app(TenantContext::class)->hotel();

        // 0.40.0: a guest already in the hotel can only stay longer or leave earlier.
        if ($booking->status === BookingStatus::CheckedIn) {
            return $this->inHouse($booking, $conversation, $arguments);
        }

        $category = isset($arguments['category_code'])
            ? RoomCategory::query()->where('code', strtoupper((string) $arguments['category_code']))->first()
            : $booking->category;

        if ($category === null) {
            return ['error' => 'Unknown room category code. Use list_room_categories.'];
        }

        try {
            $checkIn = isset($arguments['check_in']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $arguments['check_in']) : $booking->check_in;
            $checkOut = isset($arguments['check_out']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $arguments['check_out']) : $booking->check_out;
        } catch (Throwable) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        if ($checkIn === null || $checkOut === null) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        $rooms = max(1, (int) ($arguments['rooms'] ?? $booking->rooms));
        $quote = app(QuoteService::class)->quote($newStay = new StayRequest(
            categoryId: $category->id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: max(1, (int) ($arguments['adults'] ?? $booking->adults)),
            childAges: array_values(array_map(fn ($age): int => max(0, min(17, (int) $age)), (array) ($arguments['children_ages'] ?? array_filter($booking->children_ages, fn ($a): bool => $a !== null)))),
            rooms: $rooms,
            breakfast: (bool) ($arguments['breakfast'] ?? $booking->breakfast),
            extraBed: (bool) ($arguments['extra_bed'] ?? $booking->extra_bed),
        ));

        // Only the guests change: the booking keeps its own room and nights.
        $sameRoomAndNights = $category->id === $booking->room_category_id && $rooms <= $booking->rooms
            && $checkIn->isSameDay($booking->check_in) && $checkOut->isSameDay($booking->check_out);
        $issues = array_values(array_filter($quote->issues, fn (QuoteIssue $i): bool => ! ($sameRoomAndNights && in_array($i->code, self::OWN_ROOM_ISSUES, true))));

        $format = function (Money $money) use ($hotel): string {
            $this->context->allowMoney($money->abs());

            return MoneyFormatter::format($money, $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale);
        };

        $current = Money::ofMinor($booking->total_minor, $booking->currency);
        $new = $quote->total;
        $difference = $new->minus($current);

        // 0.39.3: the guest confirmed: a change request for reception (Today page).
        $sent = false;
        if ((bool) ($arguments['send_to_reception'] ?? false) && $issues === []) {
            $summary = $this->summary($booking, $newStay, $category->localizedName($this->context->locale, $hotel->default_locale));
            app(BookingChangeRequests::class)->requestChange($booking, $conversation, $newStay, $new, $summary);
            $sent = true;
        }

        return [
            'found' => true,
            'sent_to_reception' => $sent,
            'booking' => $this->describe($booking),
            'new_stay' => [
                'category' => $category->localizedName($this->context->locale, $hotel->default_locale),
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'rooms' => $rooms,
                'adults' => max(1, (int) ($arguments['adults'] ?? $booking->adults)),
            ],
            'possible' => $issues === [],
            'problems' => array_map(fn (QuoteIssue $i): array => ['code' => $i->code, 'message' => $i->message], $issues),
            'current_total' => $format($current),
            'new_total' => $format($new),
            'difference' => $format($difference),
            'more_to_pay' => $difference->isPositive(),
            'special_price' => $booking->price_overridden || $booking->coupon_code !== null,
            'note' => ($sent
                ? 'The change was sent to reception, who apply it and confirm to the guest. Tell the guest exactly that, in one or two sentences. Never say the change is made, done or possible.'
                : 'Tell the guest what the change would cost (new total and difference) and that reception applies it after checking; ask if they want you to send it to reception. Never say the change is possible, made or done. If the guest already said yes, call this tool again with send_to_reception=true.')
                .($booking->price_overridden || $booking->coupon_code !== null ? ' This booking has a special price or a discount code: say reception will confirm the final price.' : ''),
        ];
    }

    /**
     * The guest is checked in: only the departure date changes. Extra
     * nights are priced at today's rates; nights not stayed come off the
     * bill as the hotel decided (Rates page).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function inHouse(Booking $booking, Conversation $conversation, array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $other = array_intersect(array_keys(array_filter($arguments, fn ($value): bool => $value !== null)), ['adults', 'children_ages', 'check_in', 'category_code', 'rooms', 'breakfast', 'extra_bed']);

        try {
            $checkOut = isset($arguments['check_out']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $arguments['check_out']) : null;
        } catch (Throwable) {
            $checkOut = null;
        }

        if ($checkOut === null) {
            return [
                'found' => true,
                'booking' => $this->describe($booking),
                'checked_in' => true,
                'note' => $other !== []
                    ? 'The guest is already staying at the hotel: only the departure date can be changed here. For anything else (room, guests, breakfast, extra bed), pass the request to reception (handover, reason "change booking '.$booking->reference.'").'
                    : 'Ask the guest for the new departure date (YYYY-MM-DD), then call this tool again with check_out.',
            ];
        }

        $preview = app(StayChanges::class)->preview($booking, $checkOut);
        $format = function (int $minor) use ($booking, $hotel): string {
            $money = Money::ofMinor(abs($minor), $booking->currency);
            $this->context->allowMoney($money);

            return MoneyFormatter::format($money, $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale);
        };

        $sent = false;
        if ((bool) ($arguments['send_to_reception'] ?? false) && $preview['possible']) {
            $stay = new StayRequest(
                categoryId: $booking->room_category_id,
                checkIn: $booking->check_in,
                checkOut: $checkOut,
                adults: $booking->adults,
                childAges: array_values(array_filter($booking->children_ages, fn ($a): bool => $a !== null)),
                rooms: $booking->rooms,
                breakfast: $booking->breakfast,
                extraBed: $booking->extra_bed,
            );
            $summary = ($preview['kind'] === 'extend' ? 'stay longer: leave ' : 'leave earlier: leave ').$checkOut->toDateString();
            app(BookingChangeRequests::class)->requestChange($booking, $conversation, $stay, Money::ofMinor($preview['new_total_minor'], $booking->currency), $summary);
            $sent = true;
        }

        return [
            'found' => true,
            'checked_in' => true,
            'sent_to_reception' => $sent,
            'booking' => $this->describe($booking),
            'change' => $preview['kind'] === 'extend' ? 'stay longer' : ($preview['kind'] === 'shorten' ? 'leave earlier' : 'no change'),
            'new_check_out' => $checkOut->toDateString(),
            'nights_changed' => $preview['nights_changed'],
            'possible' => $preview['possible'],
            'problems' => $preview['problems'],
            'current_total' => $format($preview['current_total_minor']),
            'new_total' => $format($preview['new_total_minor']),
            'difference' => $format($preview['difference_minor']),
            'more_to_pay' => $preview['difference_minor'] > 0,
            'one_night_still_charged' => $preview['fee_nights'] > 0,
            'other_changes_ignored' => $other !== [],
            'note' => ($sent
                ? 'The request was sent to reception, who confirm it to the guest. Tell the guest exactly that. Never say the change is made.'
                : ($preview['possible']
                    ? 'Tell the guest the new total and the difference, and that reception confirms it; ask if they want you to send it to reception. If they already said yes, call this tool again with send_to_reception=true. Never say the change is made or possible.'
                    : 'Tell the guest it cannot be arranged for that date (problems) and offer to pass it to reception.'))
                .($preview['fee_nights'] > 0 ? ' The hotel still charges one night when a guest leaves early: say so.' : '')
                .($other !== [] ? ' Only the departure date can change during a stay; for the other changes, pass to reception.' : ''),
        ];
    }

    private function summary(Booking $booking, StayRequest $stay, string $category): string
    {
        $parts = [];
        if (! $stay->checkIn->isSameDay($booking->check_in) || ! $stay->checkOut->isSameDay($booking->check_out)) {
            $parts[] = $stay->checkIn->toDateString().' – '.$stay->checkOut->toDateString();
        }
        if ($stay->categoryId !== $booking->room_category_id) {
            $parts[] = $category;
        }
        if ($stay->rooms !== $booking->rooms) {
            $parts[] = $stay->rooms.' room(s)';
        }
        if ($stay->adults !== $booking->adults || count($stay->childAges) !== count(array_filter($booking->children_ages, fn ($a): bool => $a !== null))) {
            $parts[] = $stay->adults.' adult(s), '.count($stay->childAges).' child(ren)';
        }
        if ($stay->breakfast !== $booking->breakfast) {
            $parts[] = $stay->breakfast ? 'with breakfast' : 'without breakfast';
        }
        if ($stay->extraBed !== $booking->extra_bed) {
            $parts[] = $stay->extraBed ? 'extra bed' : 'no extra bed';
        }

        return $parts === [] ? 'no change' : implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Booking $booking): array
    {
        $hotel = app(TenantContext::class)->hotel();

        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'category' => (string) $booking->category?->localizedName($this->context->locale, $hotel->default_locale),
            'category_code' => (string) $booking->category?->code,
            'check_in' => $booking->check_in->toDateString(),
            'check_out' => $booking->check_out->toDateString(),
            'rooms' => $booking->rooms,
            'adults' => $booking->adults,
            'children' => count(array_filter($booking->children_ages, fn ($a): bool => $a !== null)),
            'breakfast' => $booking->breakfast,
        ];
    }
}
