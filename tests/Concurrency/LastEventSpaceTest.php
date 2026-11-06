<?php

declare(strict_types=1);

use App\Domains\Events\EventService;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Events/helpers.php';

it('serialises two options for the same space and time', function (): void {
    $this->travelTo('2026-11-13 10:00:00');
    $hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan']);
    $s = eventSetup($hotel);
    $user = User::factory()->create();
    $first = eventFor($hotel, $s['a'], $s['morning']);
    $second = eventFor($hotel, $s['a'], $s['morning'], client: 'B');

    config(['database.connections.second' => config('database.connections.mysql')]);
    $other = DB::connection('second');
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
    app(TenantContext::class)->set($hotel);

    DB::beginTransaction();
    app(EventService::class)->option($first, null, $user);

    $blocked = false;
    try {
        $other->select('select id from event_spaces where id = ? for update', [$s['a']->id]);
    } catch (QueryException $exception) {
        $blocked = str_contains($exception->getMessage(), 'Lock wait timeout');
    }
    DB::commit();

    expect($blocked)->toBeTrue()
        ->and(app(EventService::class)->option($second, null, $user)->waitlisted)->toBeTrue();

    $other->disconnect();
    app(TenantContext::class)->forget();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Needs MySQL row locks.');
