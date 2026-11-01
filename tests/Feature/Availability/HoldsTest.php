<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Availability\Exceptions\NotEnoughRooms;
use App\Domains\Availability\HoldService;
use App\Domains\Availability\HoldStatus;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->exe = roomCategory($this->hotel, 'EXE', 2);
    rateFor($this->hotel, $this->exe);
    $this->holds = app(HoldService::class);
    $this->context = app(TenantContext::class);
});

it('holds rooms and refuses a hold for a room that is already held', function (): void {
    holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-14', 1);
    holdRooms($this->hotel, $this->exe, '2026-11-13', '2026-11-15', 1);

    expect(fn () => holdRooms($this->hotel, $this->exe, '2026-11-13', '2026-11-14', 1))
        ->toThrow(NotEnoughRooms::class);

    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-14'))
        ->toBe(['2026-11-12' => 1, '2026-11-13' => 0, '2026-11-14' => 1]);
});

it('stops counting a hold the moment it lapses, then marks it expired', function (): void {
    $hold = holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13', 2, minutes: 20);
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 0]);

    $this->travel(21)->minutes();

    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 2]);

    $this->artisan('holds:expire')->assertSuccessful();
    expect($this->context->run($this->hotel, fn () => $hold->refresh()->status))->toBe(HoldStatus::Expired);
});

it('uses the hotel hold time by default', function (): void {
    app(TenantContext::class)->run($this->hotel, fn () => App\Domains\Hotel\Models\HotelSettings::current()->update(['hold_minutes' => 45]));

    $hold = holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13');

    expect((int) round(now()->diffInMinutes($hold->expires_at)))->toBe(45);
});

it('releases and extends holds', function (): void {
    $hold = holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13', 2, minutes: 20);

    $this->travel(15)->minutes();
    $this->context->run($this->hotel, fn () => $this->holds->extend($hold, 30));
    $this->travel(20)->minutes();
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 0]);

    $this->context->run($this->hotel, fn () => $this->holds->release($hold));
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 2]);
});

it('revives a lapsed hold only if the rooms are still free', function (): void {
    $lapsed = holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13', 2, minutes: 5);
    $this->travel(6)->minutes();
    holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13', 1);

    expect(fn () => $this->context->run($this->hotel, fn () => $this->holds->extend($lapsed)))->toThrow(NotEnoughRooms::class);
});

it('converts a hold into booked rooms and rebuilds counts that drift', function (): void {
    $hold = holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-14', 1);
    $this->context->run($this->hotel, fn () => $this->holds->convert($hold));

    $this->travel(2)->hours();
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 1, '2026-11-13' => 1]);

    $this->context->run($this->hotel, fn () => InventoryDay::query()->update(['booked' => 0]));
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-12'))->toBe(['2026-11-12' => 2]);

    $this->artisan('inventory:rebuild')->assertSuccessful();
    expect(availableNights($this->hotel, $this->exe, '2026-11-12', '2026-11-13'))->toBe(['2026-11-12' => 1, '2026-11-13' => 1]);
});

it('places manual holds from the calendar with clear errors and an audit entry', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);
    $payload = ['room_category_id' => $this->exe->id, 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'rooms' => 2, 'note' => 'Group call'];

    asStaffOf($this->hotel, $reception)->post(route('hotel.holds.store'), $payload)->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $reception)->post(route('hotel.holds.store'), [...$payload, 'rooms' => 1])->assertSessionHasErrors('rooms');
    asStaffOf($this->hotel, $reception)->post(route('hotel.holds.store'), [...$payload, 'check_in' => '2026-10-20', 'check_out' => '2026-10-21'])->assertSessionHasErrors('check_in');

    expect(AuditLog::query()->where('event', 'availability.hold_placed')->exists())->toBeTrue();
});

it('keeps holds separate between hotels', function (): void {
    $other = Hotel::factory()->create();
    $foreignCategory = roomCategory($other, 'X', 1);
    $foreign = holdRooms($other, $foreignCategory, '2026-11-12', '2026-11-13');
    $manager = staffMember($this->hotel, SystemRole::Manager);

    asStaffOf($this->hotel, $manager)->delete(route('hotel.holds.release', $foreign->id))->assertNotFound();
    asStaffOf($this->hotel, $manager)->post(route('hotel.holds.store'), [
        'room_category_id' => $foreignCategory->id, 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'rooms' => 1,
    ])->assertSessionHasErrors('room_category_id');
});

it('refuses to delete a room category that has holds', function (): void {
    holdRooms($this->hotel, $this->exe, '2026-11-12', '2026-11-13');
    $owner = staffMember($this->hotel, SystemRole::Owner);
    $this->context->run($this->hotel, fn () => App\Domains\Rooms\Models\Room::query()->delete());

    asStaffOf($this->hotel, $owner)->delete(route('hotel.room-categories.destroy', $this->exe->id));

    expect($this->context->run($this->hotel, fn () => App\Domains\Rooms\Models\RoomCategory::query()->whereKey($this->exe->id)->exists()))->toBeTrue();
});
