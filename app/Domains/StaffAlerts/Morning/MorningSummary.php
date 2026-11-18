<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Morning;

use App\Domains\Availability\AvailabilityService;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Folio\Folio;
use App\Domains\Folio\FolioLine;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The figures of the morning summary (Sprint C), in hotel time: the day
 * ahead, last night's money, the same weekday last week, the next seven
 * nights and what is still to be paid by today's guests.
 */
final class MorningSummary
{
    /** A night below this occupancy is named as weak. */
    public const WEAK_PERCENT = 50;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AvailabilityService $availability,
        private readonly Folio $folio,
        private readonly \App\Domains\Workspace\ShiftChecklists $checklists,
    ) {}

    /**
     * @return array{
     *   today: CarbonImmutable, arrivals: int, departures: int, in_house: int,
     *   occupied: int, rooms: int, percent: int, percent_last_week: int|null,
     *   collected: int, collected_by_method: array<string, int>, collected_last_week: int,
     *   week: array<string, int>, weak: list<string>,
     *   to_collect: int, to_collect_guests: int,
     *   pending_requests: int, attention: int, quiet: bool,
     *   checklists: list<array{name: string, done: int, total: int, left: list<string>}>
     * }
     */
    public function build(): array
    {
        $hotel = $this->context->hotel();
        $today = HotelClock::today($hotel);
        $nights = $this->occupancy($today->subDays(7), $today->addDays(7));
        [$occupied, $rooms] = $nights[$today->toDateString()] ?? [0, 0];
        $lastWeek = $nights[$today->subDays(7)->toDateString()] ?? [0, 0];

        $week = [];
        $weak = [];
        for ($day = $today->addDay(); $day->lte($today->addDays(7)); $day = $day->addDay()) {
            [$booked, $sellable] = $nights[$day->toDateString()] ?? [0, 0];
            $percent = self::percent($booked, $sellable);
            $week[$day->toDateString()] = $percent;
            if ($sellable > 0 && $percent < self::WEAK_PERCENT) {
                $weak[] = $day->toDateString();
            }
        }

        [$collected, $byMethod] = $this->collected($today->subDay());
        [$collectedLastWeek] = $this->collected($today->subDays(8));
        $due = $this->toCollect();
        $arrivals = $this->arrivals()->count();
        $departures = $this->departures()->count();
        $pending = BookingRequest::query()->where('status', BookingRequestStatus::Pending->value)->where('expires_at', '>', now())->count();
        $attention = Conversation::query()->where('needs_attention', true)->count();

        return [
            'today' => $today,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'in_house' => $this->inHouse()->count(),
            'occupied' => $occupied,
            'rooms' => $rooms,
            'percent' => self::percent($occupied, $rooms),
            'percent_last_week' => $lastWeek[1] > 0 ? self::percent($lastWeek[0], $lastWeek[1]) : null,
            'collected' => $collected,
            'collected_by_method' => $byMethod,
            'collected_last_week' => $collectedLastWeek,
            'week' => $week,
            'weak' => $weak,
            'to_collect' => array_sum(array_column($due, 'balance')),
            'to_collect_guests' => count($due),
            'pending_requests' => $pending,
            'attention' => $attention,
            'quiet' => $arrivals === 0 && $departures === 0 && $pending === 0 && $attention === 0,
            // Sprint E: yesterday's shift checklists.
            'checklists' => array_map(fn (array $l): array => [
                'name' => $l['name'], 'done' => $l['done'], 'total' => $l['total'],
                'left' => array_values(array_map(fn (array $i): string => $i['text'], array_filter($l['items'], fn (array $i): bool => ! $i['done']))),
            ], array_values(array_filter($this->checklists->forDay($today->subDay()), fn (array $l): bool => $l['total'] > 0))),
        ];
    }

    /**
     * Today's arrivals still expected or already in.
     *
     * @return Collection<int, Booking>
     */
    public function arrivals(): Collection
    {
        return Booking::query()->with(['guest', 'category'])
            ->where('check_in', $this->today()->toDateString())
            ->whereIn('status', [BookingStatus::Tentative->value, BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
            ->orderBy('created_at')->get();
    }

    /**
     * Today's departures, leaving or already gone.
     *
     * @return Collection<int, Booking>
     */
    public function departures(): Collection
    {
        return Booking::query()->with(['guest', 'category'])
            ->where('check_out', $this->today()->toDateString())
            ->whereIn('status', [BookingStatus::CheckedIn->value, BookingStatus::CheckedOut->value])
            ->orderBy('created_at')->get();
    }

    /**
     * @return Collection<int, Booking>
     */
    public function inHouse(): Collection
    {
        return Booking::query()->with(['guest', 'category'])->where('status', BookingStatus::CheckedIn->value)->orderBy('check_out')->get();
    }

    /**
     * Guests arriving or leaving today who still have something to pay,
     * tentative bookings whose deposit has not come included.
     *
     * @return list<array{booking: Booking, balance: int, arriving: bool}>
     */
    public function toCollect(): array
    {
        $due = [];
        $departing = $this->inHouse()->filter(fn (Booking $b): bool => $b->check_out->toDateString() === $this->today()->toDateString());

        foreach ([[$this->arrivals()->where('status', '!=', BookingStatus::CheckedIn), true], [$departing, false]] as [$bookings, $arriving]) {
            foreach ($bookings as $booking) {
                $balance = $this->folio->balance($booking);
                if ($balance > 0) {
                    $due[] = ['booking' => $booking, 'balance' => $balance, 'arriving' => $arriving];
                }
            }
        }

        return $due;
    }

    /**
     * Money received on one hotel day, in total and by payment method
     * (corrections of payments are negative payments, so they count).
     *
     * @return array{0: int, 1: array<string, int>}
     */
    private function collected(CarbonImmutable $day): array
    {
        $hotel = $this->context->hotel();
        $start = $day->shiftTimezone($hotel->timezone)->utc();

        $byMethod = FolioLine::query()->where('type', 'payment')
            ->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay())
            ->selectRaw('method, sum(amount_minor) as total')->groupBy('method')->get();

        $methods = [];
        foreach ($byMethod as $row) {
            $total = (int) $row->getAttribute('total');
            if ($total !== 0) {
                $methods[(string) ($row->getAttribute('method') ?? 'other')] = $total;
            }
        }
        arsort($methods);

        return [array_sum($methods), $methods];
    }

    /**
     * @return array<string, array{0: int, 1: int}> date => [booked, sellable]
     */
    private function occupancy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $categories = array_values(array_map('strval', RoomCategory::query()->where('active', true)->pluck('id')->all()));
        $nights = [];

        foreach ($categories === [] ? [] : $this->availability->forRange($categories, $from, $to) as $days) {
            foreach ($days as $date => $night) {
                $nights[$date] ??= [0, 0];
                $nights[$date][0] += $night['booked'];
                $nights[$date][1] += $night['sellable'];
            }
        }

        return $nights;
    }

    private function today(): CarbonImmutable
    {
        return HotelClock::today($this->context->hotel());
    }

    public static function percent(int $part, int $whole): int
    {
        return $whole <= 0 ? 0 : (int) round(100 * $part / $whole);
    }
}
