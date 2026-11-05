<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Ai\Models\AiRun;
use App\Domains\Availability\AvailabilityService;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;

/**
 * The owner's end-of-day figures for the current hotel (hotel time zone).
 */
final class OwnerSummary
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * @return array{date: string, arrivals: int, departures: int, occupied: int, rooms: int, new_bookings: int, new_value: string, requests: array{approved: int, declined: int, expired: int}, attention: int, ai_cost: string}
     */
    public function build(string $locale): array
    {
        $hotel = $this->context->hotel();
        $today = HotelClock::today($hotel);
        $date = $today->toDateString();
        $dayStart = $today->shiftTimezone($hotel->timezone)->utc();
        $dayEnd = $dayStart->addDay();
        $live = [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value, BookingStatus::CheckedOut->value];

        $categories = array_values(array_map('strval', RoomCategory::query()->where('active', true)->pluck('id')->all()));
        $tonight = $categories === [] ? [] : $this->availability->forRange($categories, $today, $today);
        $rooms = 0;
        $occupied = 0;
        foreach ($tonight as $nights) {
            $night = $nights[$date] ?? null;
            $rooms += $night['sellable'] ?? 0;
            $occupied += $night['booked'] ?? 0;
        }

        $newBookings = Booking::query()
            ->where('created_at', '>=', $dayStart)->where('created_at', '<', $dayEnd)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->get(['total_minor', 'currency']);

        $decided = BookingRequest::query()
            ->where('decided_at', '>=', $dayStart)->where('decided_at', '<', $dayEnd)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        /** @var \Carbon\CarbonImmutable $localized */
        $localized = $today->locale($locale);

        return [
            'date' => $localized->isoFormat('D MMMM YYYY'),
            'arrivals' => Booking::query()->where('check_in', $date)->whereIn('status', $live)->count(),
            'departures' => Booking::query()->where('check_out', $date)->whereIn('status', $live)->count(),
            'occupied' => $occupied,
            'rooms' => $rooms,
            'new_bookings' => $newBookings->count(),
            'new_value' => MoneyFormatter::format(Money::ofMinor((int) $newBookings->sum('total_minor'), $hotel->currency), $locale),
            'requests' => [
                'approved' => (int) ($decided[BookingRequestStatus::Approved->value] ?? 0),
                'declined' => (int) ($decided[BookingRequestStatus::Declined->value] ?? 0),
                'expired' => (int) ($decided[BookingRequestStatus::Expired->value] ?? 0),
            ],
            'attention' => Conversation::query()->where('needs_attention', true)->count(),
            'ai_cost' => 'US$ '.number_format(AiRun::query()->where('created_at', '>=', $dayStart)->where('created_at', '<', $dayEnd)->sum('cost_micro_usd') / 1_000_000, 2),
        ];
    }
}
