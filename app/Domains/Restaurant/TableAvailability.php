<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

use App\Domains\Restaurant\Models\Minutes;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\OutletClosure;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Seats over time for an outlet. A booking takes its covers from its start
 * for its duration; a time is free when the seats in use throughout stay
 * within the outlet's seats and the arrivals at that time stay within the
 * optional arrival limit. Pending requests hold seats until they expire.
 */
final class TableAvailability
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Every booking time on a date, by service, with the seats still free.
     *
     * @return list<array{period_id: int, period: string, time: string, free_seats: int, available: bool}>
     */
    public function slots(Outlet $outlet, CarbonImmutable $date, int $covers, ?string $ignoreBookingId = null): array
    {
        $hotel = $this->context->hotel();
        $bookings = $this->bookings($outlet, $date, $ignoreBookingId);
        $closures = OutletClosure::query()->where('outlet_id', $outlet->id)->where('date', $date->toDateString())->get();
        $now = $this->nowMinutes($date);
        $slots = [];

        if (! $outlet->active || $closures->contains(fn (OutletClosure $c): bool => $c->service_period_id === null)) {
            return [];
        }

        foreach ($this->periods($outlet, $date) as $period) {
            if ($closures->contains(fn (OutletClosure $c): bool => $c->service_period_id === $period->id)) {
                continue;
            }

            foreach ($period->slots() as $time) {
                $start = Minutes::of($time);

                if ($now !== null && $start <= $now) {
                    continue;
                }

                $free = $this->freeSeats($outlet, $bookings, $start, $outlet->default_duration_minutes, $covers);
                $slots[] = [
                    'period_id' => $period->id,
                    'period' => $period->localizedName($hotel->default_locale, $hotel->default_locale),
                    'time' => $time,
                    'free_seats' => max(0, $free),
                    'available' => $free >= $covers && $covers > 0,
                ];
            }
        }

        return $slots;
    }

    /**
     * Whether a party can be seated at a time (the time must be one the
     * outlet offers that day). Returns null when it can, or the reason.
     */
    public function problem(Outlet $outlet, CarbonImmutable $date, string $time, int $covers, ?int $duration = null, ?string $ignoreBookingId = null): ?string
    {
        if ($covers < 1) {
            return 'covers';
        }

        if ($covers > $outlet->seats) {
            return 'too_many';
        }

        $offered = collect($this->slots($outlet, $date, $covers, $ignoreBookingId))->firstWhere('time', $time);

        if ($offered === null) {
            return 'closed';
        }

        if ($duration !== null && $duration !== $outlet->default_duration_minutes) {
            $start = Minutes::of($time);
            $free = $this->freeSeats($outlet, $this->bookings($outlet, $date, $ignoreBookingId), $start, $duration, $covers);

            return $free >= $covers ? null : 'full';
        }

        return $offered['available'] ? null : 'full';
    }

    /**
     * Seats in use at each booking time of a day (for the grid).
     *
     * @return array<string, int> HH:MM => seats in use
     */
    public function occupancy(Outlet $outlet, CarbonImmutable $date): array
    {
        $bookings = $this->bookings($outlet, $date);
        $result = [];

        foreach ($this->periods($outlet, $date) as $period) {
            foreach ($period->slots() as $time) {
                $t = Minutes::of($time);
                $result[$time] = (int) $bookings->filter(fn (TableBooking $b): bool => $b->startMinutes() <= $t && $t < $b->endMinutes())->sum('covers');
            }
        }

        return $result;
    }

    /**
     * Seats left for a booking from $start for $duration, after this party's
     * arrival limit check (negative or below $covers means it does not fit).
     *
     * @param  Collection<int, TableBooking>  $bookings
     */
    private function freeSeats(Outlet $outlet, Collection $bookings, int $start, int $duration, int $covers): int
    {
        $end = $start + $duration;
        $points = $bookings->toBase()->map(fn (TableBooking $b): int => $b->startMinutes())
            ->filter(fn (int $p): bool => $p > $start && $p < $end)
            ->push($start)
            ->unique();

        $peak = 0;
        foreach ($points as $point) {
            $inUse = (int) $bookings->filter(fn (TableBooking $b): bool => $b->startMinutes() <= $point && $point < $b->endMinutes())->sum('covers');
            $peak = max($peak, $inUse);
        }

        $free = $outlet->seats - $peak;

        if ($outlet->slot_arrival_limit !== null) {
            $arriving = (int) $bookings->filter(fn (TableBooking $b): bool => $b->startMinutes() === $start)->sum('covers');

            if ($arriving + $covers > $outlet->slot_arrival_limit) {
                return min($free, $outlet->slot_arrival_limit - $arriving);
            }
        }

        return $free;
    }

    /**
     * @return Collection<int, TableBooking>
     */
    public function bookings(Outlet $outlet, CarbonImmutable $date, ?string $ignoreBookingId = null): Collection
    {
        return TableBooking::query()
            ->where('outlet_id', $outlet->id)
            ->where('date', $date->toDateString())
            ->whereIn('status', array_map(fn (TableBookingStatus $s): string => $s->value, TableBookingStatus::holdingSeats()))
            ->where(fn ($q) => $q->where('status', '!=', TableBookingStatus::Requested->value)->orWhere('expires_at', '>', now()))
            ->when($ignoreBookingId !== null, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->get();
    }

    /**
     * @return Collection<int, ServicePeriod>
     */
    public function periods(Outlet $outlet, CarbonImmutable $date): Collection
    {
        return ServicePeriod::query()->where('outlet_id', $outlet->id)->where('weekday', $date->dayOfWeekIso)->orderBy('starts_at')->get();
    }

    /**
     * Minutes since midnight now, when the date is today in the hotel (past
     * times are not offered); null for other dates.
     */
    private function nowMinutes(CarbonImmutable $date): ?int
    {
        $hotel = $this->context->hotel();

        if (! HotelClock::today($hotel)->isSameDay($date)) {
            return null;
        }

        return Minutes::of(CarbonImmutable::now($hotel->timezone)->format('H:i'));
    }
}
