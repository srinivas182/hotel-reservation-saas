<?php

declare(strict_types=1);

use App\Domains\Restaurant\Exceptions\TableNotPossible;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Restaurant/helpers.php';

it('serialises two bookings for the last seats', function (): void {
    $this->travelTo('2026-11-10 10:00:00');
    $hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan']);
    $outlet = restaurant($hotel, ['seats' => 4]);

    config(['database.connections.second' => config('database.connections.mysql')]);
    $second = DB::connection('second');
    $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

    app(TenantContext::class)->set($hotel);

    // Booking A takes the last seats inside a transaction that is still open.
    DB::beginTransaction();
    bookTable($hotel, $outlet, '2026-11-13', '20:00', 4);

    // Booking B must wait for A's lock on the outlet (and times out here).
    $blocked = false;
    try {
        $second->select('select id from outlets where id = ? for update', [$outlet->id]);
    } catch (QueryException $exception) {
        $blocked = str_contains($exception->getMessage(), 'Lock wait timeout');
    }

    DB::commit();
    expect($blocked)->toBeTrue();

    // Once A has committed, B finds the seats taken.
    expect(fn () => bookTable($hotel, $outlet, '2026-11-13', '20:00', 2, '+2250700000002'))->toThrow(TableNotPossible::class);

    $second->disconnect();
    app(TenantContext::class)->forget();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Needs MySQL row locks.');
