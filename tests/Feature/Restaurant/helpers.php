<?php

declare(strict_types=1);

use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Restaurant\NewTableBooking;
use App\Domains\Restaurant\TableBookingService;
use App\Domains\Restaurant\TableBookingSource;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * A 20-seat restaurant with dinner 19:00–22:30 (bookings every 30 minutes,
 * last at 21:30, tables kept 90 minutes) every day.
 */
function restaurant(Hotel $hotel, array $overrides = []): Outlet
{
    return app(TenantContext::class)->run($hotel, function () use ($overrides): Outlet {
        $outlet = Outlet::query()->create([
            'name' => ['fr' => 'Le Restaurant', 'en' => 'The Restaurant'], 'type' => 'restaurant', 'seats' => 20,
            'default_duration_minutes' => 90, 'auto_confirm' => true, 'auto_confirm_max_party' => 6, 'active' => true,
            'public_slug' => 'restaurant', ...$overrides,
        ]);

        foreach (range(1, 7) as $weekday) {
            ServicePeriod::query()->create(['outlet_id' => $outlet->id, 'weekday' => $weekday, 'name' => ['fr' => 'Dîner', 'en' => 'Dinner'], 'starts_at' => '19:00', 'ends_at' => '22:30', 'slot_minutes' => 30, 'last_seating_minutes' => 60]);
        }

        return $outlet;
    });
}

function bookTable(Hotel $hotel, Outlet $outlet, string $date, string $time, int $covers, string $phone = '+2250700000001', TableBookingStatus $status = TableBookingStatus::Confirmed): TableBooking
{
    return app(TenantContext::class)->run($hotel, fn (): TableBooking => app(TableBookingService::class)->create(new NewTableBooking(
        outletId: $outlet->id,
        date: CarbonImmutable::parse($date),
        time: $time,
        covers: $covers,
        guest: ['first_name' => 'Awa', 'last_name' => 'Koné', 'phone' => $phone],
        source: TableBookingSource::Phone,
        status: $status,
        expiresAt: $status === TableBookingStatus::Requested ? now()->addHours(2)->toImmutable() : null,
    )));
}
