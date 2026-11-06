<?php

declare(strict_types=1);

use App\Domains\Restaurant\Exceptions\TableNotPossible;
use App\Domains\Restaurant\Models\OutletClosure;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Restaurant\TableAvailability;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-10 10:00:00');
    $this->hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan']);
    $this->outlet = restaurant($this->hotel);
    $this->slots = fn (string $date, int $covers): array => app(TenantContext::class)->run($this->hotel, fn (): array => app(TableAvailability::class)->slots($this->outlet, CarbonImmutable::parse($date), $covers));
    $this->free = fn (string $date, int $covers): array => array_column(array_filter(($this->slots)($date, $covers), fn (array $s): bool => $s['available']), 'time');
});

it('offers the times of each service up to the last seating', function (): void {
    expect(array_column(($this->slots)('2026-11-13', 2), 'time'))->toBe(['19:00', '19:30', '20:00', '20:30', '21:00', '21:30']);
});

it('counts seats over the time a table is kept', function (): void {
    bookTable($this->hotel, $this->outlet, '2026-11-13', '19:00', 10);
    bookTable($this->hotel, $this->outlet, '2026-11-13', '19:30', 8, '+2250700000002');

    // 4 more people: 19:00–20:30 has 18 of 20 seats in use somewhere in the window.
    expect(($this->free)('2026-11-13', 4))->toBe(['20:30', '21:00', '21:30'])
        ->and(($this->free)('2026-11-13', 2))->toBe(['19:00', '19:30', '20:00', '20:30', '21:00', '21:30']);
});

it('limits how many guests arrive at the same time', function (): void {
    $this->outlet->update(['slot_arrival_limit' => 10]);
    bookTable($this->hotel, $this->outlet, '2026-11-13', '19:00', 10);

    expect(($this->free)('2026-11-13', 2))->not->toContain('19:00')->toContain('19:30');
});

it('holds seats for pending requests until they expire', function (): void {
    bookTable($this->hotel, $this->outlet, '2026-11-13', '20:00', 18, status: TableBookingStatus::Requested);
    expect(($this->free)('2026-11-13', 4))->toBe(['21:30']); // the request holds 18 seats from 20:00 to 21:30

    $this->travel(3)->hours();
    expect(($this->free)('2026-11-13', 4))->toHaveCount(6);
});

it('offers nothing when the outlet is closed, for the day or one service', function (): void {
    app(TenantContext::class)->run($this->hotel, function (): void {
        OutletClosure::query()->create(['outlet_id' => $this->outlet->id, 'date' => '2026-11-13', 'reason' => 'Private event']);
        $friday = ServicePeriod::query()->where('weekday', 6)->firstOrFail();
        OutletClosure::query()->create(['outlet_id' => $this->outlet->id, 'date' => '2026-11-14', 'service_period_id' => $friday->id]);
    });

    expect(($this->slots)('2026-11-13', 2))->toBe([])
        ->and(($this->slots)('2026-11-14', 2))->toBe([]);
});

it('does not offer times already past today, in the hotel\'s time zone', function (): void {
    $this->travelTo('2026-11-10 20:10:00');

    expect(array_column(($this->slots)('2026-11-10', 2), 'time'))->toBe(['20:30', '21:00', '21:30']);
});

it('refuses bookings that do not fit and frees seats when a booking ends', function (): void {
    $booking = bookTable($this->hotel, $this->outlet, '2026-11-13', '19:00', 20);

    expect(fn () => bookTable($this->hotel, $this->outlet, '2026-11-13', '19:30', 2, '+2250700000002'))->toThrow(TableNotPossible::class)
        ->and(fn () => bookTable($this->hotel, $this->outlet, '2026-11-13', '18:45', 2, '+2250700000002'))->toThrow(fn (TableNotPossible $e) => expect($e->reason)->toBe('closed'))
        ->and(fn () => bookTable($this->hotel, $this->outlet, '2026-11-13', '21:00', 21, '+2250700000002'))->toThrow(fn (TableNotPossible $e) => expect($e->reason)->toBe('too_many'));

    app(TenantContext::class)->run($this->hotel, fn () => $booking->update(['status' => TableBookingStatus::Cancelled]));
    expect(bookTable($this->hotel, $this->outlet, '2026-11-13', '19:30', 2, '+2250700000002')->reference)->toBe('T-26-0002');
});
