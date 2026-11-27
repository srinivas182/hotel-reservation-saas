<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\GuestBookings;
use App\Domains\Bookings\Models\Booking;
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
        return 'The guest\'s own upcoming bookings, and the exact new price when they want to change one: more or fewer adults or children, other dates, another room category, more rooms, breakfast. Give only what changes; the rest stays as booked. Returns the current total, the new total and the difference. Call it whenever a guest asks to change their booking or what a change would cost.';
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
        $quote = app(QuoteService::class)->quote(new StayRequest(
            categoryId: $category->id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: max(1, (int) ($arguments['adults'] ?? $booking->adults)),
            childAges: array_values(array_map(fn ($age): int => max(0, min(17, (int) $age)), (array) ($arguments['children_ages'] ?? array_filter($booking->children_ages, fn ($a): bool => $a !== null)))),
            rooms: $rooms,
            breakfast: (bool) ($arguments['breakfast'] ?? $booking->breakfast),
            extraBed: $booking->extra_bed,
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

        return [
            'found' => true,
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
            'note' => 'Reception makes the change: tell the guest the new total and the difference, then set handover to true with the reason "change booking '.$booking->reference.': <what changes>, new total <new_total>". Never say the change is done.'
                .($booking->price_overridden || $booking->coupon_code !== null ? ' This booking has a special price or a discount code: say reception will confirm the final price.' : ''),
        ];
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
