<?php

declare(strict_types=1);

use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Rates/helpers.php';

/*
| Real concurrency on MySQL: two database connections compete for the last
| room. This suite commits data (no wrapping transaction), so it runs with
| table truncation and only on MySQL; SQLite runs skip it.
*/

it('serialises two requests for the last room', function (): void {
    $this->travelTo('2026-11-01 10:00:00');

    $hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $category = roomCategory($hotel, 'EXE', 1);
    rateFor($hotel, $category);

    config(['database.connections.second' => config('database.connections.mysql')]);
    $second = DB::connection('second');
    $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $context = app(TenantContext::class);
    $context->set($hotel);
    $holds = app(HoldService::class);

    // Request A holds the last room inside a transaction that is still open.
    DB::beginTransaction();
    $holds->place($category, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-13'), 1);

    // Request B must wait for A's lock on that night (and times out here).
    $blocked = false;
    try {
        $second->select('select id from inventory_days where room_category_id = ? and date = ? for update', [$category->id, '2026-11-12']);
    } catch (QueryException $exception) {
        $blocked = str_contains($exception->getMessage(), 'Lock wait timeout');
    }

    DB::commit();

    expect($blocked)->toBeTrue();

    // Once A has committed, B sees the room as taken.
    expect(fn () => $holds->place($category, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-13'), 1))
        ->toThrow(NotEnoughRooms::class);

    $second->disconnect();
    $context->forget();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Needs MySQL row locks.');
