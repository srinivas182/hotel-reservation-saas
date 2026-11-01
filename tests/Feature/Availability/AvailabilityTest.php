<?php

declare(strict_types=1);

use App\Domains\Availability\AvailabilityService;
use App\Domains\Availability\HoldService;
use App\Domains\Availability\InventoryControls;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->exe = roomCategory($this->hotel, 'EXE', 5);
    rateFor($this->hotel, $this->exe);
});

it('subtracts rooms held back, held and booked', function (): void {
    app(TenantContext::class)->run($this->hotel, fn () => app(InventoryControls::class)
        ->setHeldBack([$this->exe->id], CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-12'), 1));
    holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-14', 2);
    $booked = holdRooms($this->hotel, $this->exe, '2026-11-13', '2026-11-14', 1);
    app(TenantContext::class)->run($this->hotel, fn () => app(HoldService::class)->convert($booked));

    $night = app(TenantContext::class)->run($this->hotel, fn () => app(AvailabilityService::class)
        ->forRange([$this->exe->id], CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-14')))[$this->exe->id];

    expect($night['2026-11-12'])->toMatchArray(['sellable' => 5, 'held_back' => 1, 'held' => 2, 'booked' => 0, 'available' => 2])
        ->and($night['2026-11-13'])->toMatchArray(['held' => 2, 'booked' => 1, 'available' => 2])
        ->and($night['2026-11-14']['available'])->toBe(5);
});

it('makes nothing available on stop-sell nights', function (): void {
    app(TenantContext::class)->run($this->hotel, fn () => app(InventoryControls::class)
        ->setStopSell([$this->exe->id], CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-13'), true));

    expect(availableNights($this->hotel, $this->exe, '2026-11-11', '2026-11-14'))
        ->toBe(['2026-11-11' => 5, '2026-11-12' => 0, '2026-11-13' => 0, '2026-11-14' => 5])
        ->and(quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-13')->hasIssue('stop_sell'))->toBeTrue();
});

it('feeds quotes with live availability', function (): void {
    holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13', 4);

    $quote = quoteStay($this->hotel, $this->exe, '2026-11-12', '2026-11-13', adults: 4, rooms: 2);

    expect($quote->nights[0]['available'])->toBe(1)
        ->and($quote->hasIssue('not_available'))->toBeTrue();
});

it('uses a fixed number of queries for 4 categories over 60 nights', function (): void {
    foreach (['STD', 'MSU', 'SUI'] as $code) {
        roomCategory($this->hotel, $code, 3);
    }
    holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-20', 1);

    $count = function (int $nights): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TenantContext::class)->run($this->hotel, fn () => app(AvailabilityService::class)
            ->forRange(null, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01')->addDays($nights - 1)));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(60))->toBeLessThanOrEqual(6)
        ->and($count(60))->toBe($count(7));
});
