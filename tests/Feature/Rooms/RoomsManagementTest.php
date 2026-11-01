<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    $this->hotel = Hotel::factory()->create(['default_locale' => 'fr']);
    $this->manager = staffMember($this->hotel, SystemRole::Manager);
    $this->context = app(TenantContext::class);
    $this->context->run($this->hotel, fn () => App\Domains\Hotel\Models\HotelSettings::current()->update(['enabled_locales' => ['fr', 'en']]));
});

function inHotel(Hotel $hotel, Closure $callback): mixed
{
    return app(TenantContext::class)->run($hotel, $callback);
}

it('creates a room category and audits it', function (): void {
    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.room-categories.store'), categoryPayload())
        ->assertSessionHasNoErrors();

    $category = inHotel($this->hotel, fn () => RoomCategory::query()->sole());

    expect($category->code)->toBe('EXE')
        ->and($category->name)->toEqual(['fr' => 'Exécutive', 'en' => 'Executive']) // MySQL JSON does not keep key order
        ->and($category->description)->toBe(['fr' => 'Grande chambre'])
        ->and($category->amenities)->toBe(['wifi', 'air_conditioning'])
        ->and(AuditLog::query()->where('event', 'rooms.category_created')->exists())->toBeTrue();
});

it('keeps names only for the hotel\'s enabled languages', function (): void {
    $this->context->run($this->hotel, fn () => App\Domains\Hotel\Models\HotelSettings::current()->update(['enabled_locales' => ['fr']]));

    asStaffOf($this->hotel, $this->manager)->post(route('hotel.room-categories.store'), categoryPayload());

    expect(inHotel($this->hotel, fn () => RoomCategory::query()->sole()->name))->toBe(['fr' => 'Exécutive']);
});

it('validates category guest limits, codes and amenities', function (): void {
    roomCategory($this->hotel, 'EXE');

    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.room-categories.store'), categoryPayload([
            'standard_occupancy' => 4,
            'max_children' => 5,
            'amenities' => ['jacuzzi'],
            'name' => ['fr' => ''],
        ]))
        ->assertSessionHasErrors(['code', 'standard_occupancy', 'max_children', 'amenities.0', 'name.fr']);
});

it('allows the same category code in different hotels', function (): void {
    roomCategory(Hotel::factory()->create(), 'EXE');

    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.room-categories.store'), categoryPayload())
        ->assertSessionHasNoErrors();
});

it('refuses to delete a category that still has rooms', function (): void {
    $withRooms = roomCategory($this->hotel, 'STD', rooms: 1);
    $empty = roomCategory($this->hotel, 'EMP');

    asStaffOf($this->hotel, $this->manager)->delete(route('hotel.room-categories.destroy', $withRooms));
    asStaffOf($this->hotel, $this->manager)->delete(route('hotel.room-categories.destroy', $empty));

    expect(inHotel($this->hotel, fn () => RoomCategory::query()->pluck('code')->all()))->toBe(['STD']);
});

it('adds, updates and removes rooms with unique numbers', function (): void {
    $category = roomCategory($this->hotel, 'STD');
    $as = fn () => asStaffOf($this->hotel, $this->manager);

    $as()->post(route('hotel.rooms.store'), ['room_category_id' => $category->id, 'number' => '101', 'status' => 'in_service'])->assertSessionHasNoErrors();
    $as()->post(route('hotel.rooms.store'), ['room_category_id' => $category->id, 'number' => '101', 'status' => 'in_service'])->assertSessionHasErrors('number');

    $room = inHotel($this->hotel, fn () => Room::query()->sole());
    $as()->put(route('hotel.rooms.update', $room), ['room_category_id' => $category->id, 'number' => '101', 'status' => 'out_of_order'])->assertSessionHasNoErrors();
    expect($room->refresh()->status->value)->toBe('out_of_order');

    $as()->delete(route('hotel.rooms.destroy', $room));
    expect(inHotel($this->hotel, fn () => Room::query()->count()))->toBe(0)
        ->and(AuditLog::query()->whereIn('event', ['rooms.room_created', 'rooms.room_updated', 'rooms.room_deleted'])->count())->toBe(3);
});

it('adds rooms in bulk and skips numbers that exist', function (): void {
    $category = roomCategory($this->hotel, 'STD');
    inHotel($this->hotel, fn () => Room::query()->create(['room_category_id' => $category->id, 'number' => '103', 'status' => 'in_service']));

    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.rooms.bulk'), ['room_category_id' => $category->id, 'from' => 101, 'to' => 105, 'floor' => '1'])
        ->assertSessionHasNoErrors();

    expect(inHotel($this->hotel, fn () => Room::query()->orderBy('number')->pluck('number')->all()))->toBe(['101', '102', '103', '104', '105']);

    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.rooms.bulk'), ['room_category_id' => $category->id, 'from' => 1, 'to' => 500])
        ->assertSessionHasErrors('to');
});

it('closes rooms for dates and refuses overlapping closures', function (): void {
    $category = roomCategory($this->hotel, 'STD', rooms: 1);
    $room = inHotel($this->hotel, fn () => Room::query()->sole());
    $as = fn () => asStaffOf($this->hotel, $this->manager);

    $as()->post(route('hotel.rooms.closures.store', $room), ['from_date' => '2026-12-03', 'to_date' => '2026-12-07', 'reason' => 'Painting'])->assertSessionHasNoErrors();
    $as()->post(route('hotel.rooms.closures.store', $room), ['from_date' => '2026-12-06', 'to_date' => '2026-12-09'])->assertSessionHasErrors('from_date');
    $as()->post(route('hotel.rooms.closures.store', $room), ['from_date' => '2026-12-10', 'to_date' => '2026-12-09'])->assertSessionHasErrors('to_date');

    $closure = inHotel($this->hotel, fn () => RoomClosure::query()->sole());
    $as()->delete(route('hotel.room-closures.destroy', $closure));

    expect(inHotel($this->hotel, fn () => RoomClosure::query()->count()))->toBe(0)
        ->and(AuditLog::query()->whereIn('event', ['rooms.closure_added', 'rooms.closure_removed'])->count())->toBe(2);
});

it('lets reception view rooms but not change them', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->get(route('hotel.rooms'))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
    asStaffOf($this->hotel, $reception)->post(route('hotel.room-categories.store'), categoryPayload())->assertForbidden();
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::RestaurantBar))->get(route('hotel.rooms'))->assertForbidden();
});

it('keeps rooms and categories separate between hotels', function (): void {
    $other = Hotel::factory()->create();
    $foreignCategory = roomCategory($other, 'STD', rooms: 1);
    $foreignRoom = inHotel($other, fn () => Room::query()->sole());
    $ownCategory = roomCategory($this->hotel, 'OWN');
    $as = fn () => asStaffOf($this->hotel, $this->manager);

    $as()->put(route('hotel.room-categories.update', $foreignCategory), categoryPayload())->assertNotFound();
    $as()->delete(route('hotel.rooms.destroy', $foreignRoom))->assertNotFound();
    $as()->post(route('hotel.rooms.closures.store', $foreignRoom), ['from_date' => '2026-12-01', 'to_date' => '2026-12-02'])->assertNotFound();
    $as()->post(route('hotel.rooms.store'), ['room_category_id' => $foreignCategory->id, 'number' => '999', 'status' => 'in_service'])
        ->assertSessionHasErrors('room_category_id');

    $as()->get(route('hotel.rooms'))->assertInertia(fn ($page) => $page
        ->has('categories', 1)
        ->where('categories.0.id', $ownCategory->id)
        ->has('rooms', 0));
});
