<?php

declare(strict_types=1);

use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\NewBooking;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Availability/helpers.php';

function book(
    Hotel $hotel,
    RoomCategory $category,
    string $in,
    string $out,
    int $rooms = 1,
    BookingStatus $status = BookingStatus::Confirmed,
    string $phone = '+2250700000001',
    ?Money $override = null,
    ?string $reason = null,
    int $adults = 2,
): Booking {
    return app(TenantContext::class)->run($hotel, fn (): Booking => app(BookingService::class)->create(new NewBooking(
        stay: new StayRequest($category->id, CarbonImmutable::parse($in), CarbonImmutable::parse($out), adults: $adults, rooms: $rooms),
        guest: ['first_name' => 'Awa', 'last_name' => 'Koné', 'phone' => $phone],
        channel: BookingChannel::Phone,
        status: $status,
        overrideTotal: $override,
        overrideReason: $reason,
    )));
}

function bookingPayload(RoomCategory $category, array $overrides = []): array
{
    return [
        'room_category_id' => $category->id,
        'check_in' => '2026-11-12',
        'check_out' => '2026-11-14',
        'adults' => 2,
        'children' => '',
        'rooms' => 1,
        'breakfast' => false,
        'extra_bed' => false,
        'status' => 'confirmed',
        'channel' => 'walk_in',
        'first_name' => 'Moussa',
        'last_name' => 'Traoré',
        'phone' => '+225 07 11 22 33 44',
        'email' => null,
        'preferred_locale' => 'fr',
        ...$overrides,
    ];
}
