<?php

declare(strict_types=1);

use App\Domains\Bookings\BookingLifecycle;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Menu\MenuSection;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Bookings/helpers.php';
require_once __DIR__.'/../Restaurant/helpers.php';

/**
 * A guest checked in today in the category's first room.
 */
function checkedIn(Hotel $hotel, RoomCategory $category, string $phone = '+2250700000001', string $out = '+1 day'): Booking
{
    $today = now($hotel->timezone)->toDateString();
    $booking = book($hotel, $category, $today, now($hotel->timezone)->modify($out)->format('Y-m-d'), phone: $phone);

    return app(TenantContext::class)->run($hotel, function () use ($booking, $category): Booking {
        app(BookingLifecycle::class)->checkIn($booking, [1 => Room::query()->where('room_category_id', $category->id)->orderBy('number')->value('id')]);

        return $booking->fresh(['guest', 'bookingRooms.room']);
    });
}

/**
 * Room service with a short menu; returns [outlet, poulet, flag].
 *
 * @return array{0: Outlet, 1: MenuItem, 2: MenuItem}
 */
function roomService(Hotel $hotel): array
{
    $outlet = restaurant($hotel, ['name' => ['fr' => 'Service en chambre'], 'type' => 'room_service', 'public_slug' => 'room-service', 'orders_enabled' => true]);

    return app(TenantContext::class)->run($hotel, function () use ($outlet): array {
        $section = MenuSection::query()->create(['outlet_id' => $outlet->id, 'name' => ['fr' => 'Carte']]);

        return [
            $outlet,
            MenuItem::query()->create(['menu_section_id' => $section->id, 'name' => ['fr' => 'Poulet braisé'], 'price_minor' => 5000, 'active' => true]),
            MenuItem::query()->create(['menu_section_id' => $section->id, 'name' => ['fr' => 'Bière Flag'], 'price_minor' => 1000, 'active' => true]),
        ];
    });
}
