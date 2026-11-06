<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Http\Controllers;

use App\Domains\Restaurant\Models\Minutes;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Restaurant\TableAvailability;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The restaurant team's day: bookings by service, seats in use, and the
 * times still free for a new booking.
 */
final class RestaurantController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, TableAvailability $availability): Response
    {
        $hotel = $context->hotel();
        $locale = $hotel->default_locale;
        $data = $request->validate([
            'outlet' => ['nullable', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'covers' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $outlets = Outlet::query()->where('active', true)->orderBy('sort_order')->get();
        $outlet = $outlets->firstWhere('id', $data['outlet'] ?? null) ?? $outlets->first();
        $date = isset($data['date']) ? CarbonImmutable::parse($data['date']) : HotelClock::today($hotel);

        $periods = [];
        $occupancy = [];

        if ($outlet !== null) {
            $bookings = TableBooking::query()->with('guest')
                ->where('outlet_id', $outlet->id)->where('date', $date->toDateString())
                ->orderBy('time')->get();
            $occupancy = $availability->occupancy($outlet, $date);

            foreach ($availability->periods($outlet, $date) as $period) {
                $inPeriod = $bookings->filter(fn (TableBooking $b): bool => Minutes::of($b->time) >= Minutes::of($period->starts_at) && Minutes::of($b->time) < Minutes::of($period->ends_at));
                $periods[] = [
                    'id' => $period->id,
                    'name' => $period->localizedName($locale, $locale),
                    'hours' => $period->starts_at.'–'.$period->ends_at,
                    'covers' => (int) $inPeriod->filter(fn (TableBooking $b): bool => in_array($b->status, TableBookingStatus::holdingSeats(), true))->sum('covers'),
                    'bookings' => $inPeriod->map(fn (TableBooking $b): array => $this->present($b))->values(),
                ];
            }

            // Bookings outside today's services (e.g. after a change of hours).
            $listed = collect($periods)->flatMap(fn (array $p) => collect($p['bookings'])->pluck('id'))->all();
            $other = $bookings->reject(fn (TableBooking $b): bool => in_array($b->id, $listed, true));
            if ($other->isNotEmpty()) {
                $periods[] = ['id' => 0, 'name' => __('Other times'), 'hours' => '', 'covers' => (int) $other->sum('covers'), 'bookings' => $other->map(fn (TableBooking $b): array => $this->present($b))->values()];
            }
        }

        return Inertia::render('hotel/restaurant', [
            'outlets' => $outlets->map(fn (Outlet $o): array => ['id' => $o->id, 'name' => $o->localizedName($locale, $locale), 'seats' => $o->seats])->values(),
            'outlet' => $outlet === null ? null : ['id' => $outlet->id, 'name' => $outlet->localizedName($locale, $locale), 'seats' => $outlet->seats],
            'date' => $date->toDateString(),
            'periods' => $periods,
            'occupancy' => $occupancy,
            'slots' => Inertia::optional(fn (): array => $outlet === null ? [] : $availability->slots($outlet, $date, (int) ($data['covers'] ?? 2))),
            'can' => [
                'bookings' => $request->user()?->can('restaurant.bookings') === true,
                'manage' => $request->user()?->can('restaurant.manage') === true,
                'menu' => $request->user()?->can('restaurant.menu') === true,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TableBooking $b): array
    {
        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'time' => $b->time,
            'covers' => $b->covers,
            'guest' => $b->guest?->name(),
            'phone' => $b->guest?->phone_e164,
            'status' => $b->status->value,
            'status_label' => $b->status->label(),
            'source' => $b->source->label(),
            'notes' => $b->notes,
            'next' => array_map(fn (TableBookingStatus $s): array => ['value' => $s->value, 'label' => $s->label()], $b->status->allowedNext()),
        ];
    }
}
