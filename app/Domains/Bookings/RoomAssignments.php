<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Rooms\RoomStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which physical rooms can be given to a booking: rooms of the booking's
 * category that are in service, not closed on any of its nights, and not
 * given to another live booking on any of the same nights.
 */
final class RoomAssignments
{
    /**
     * Rooms that can be given to the booking (rooms it already holds included).
     *
     * @return Collection<int, Room>
     */
    public function eligibleRooms(Booking $booking, ?CarbonImmutable $checkIn = null, ?CarbonImmutable $checkOut = null, ?string $categoryId = null): Collection
    {
        $checkIn ??= $booking->check_in;
        $checkOut ??= $booking->check_out;
        $categoryId ??= $booking->room_category_id;

        $rooms = Room::query()
            ->where('room_category_id', $categoryId)
            ->where('status', RoomStatus::InService->value)
            ->get();

        $closed = RoomClosure::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->where('from_date', '<', $checkOut->toDateString())
            ->where('to_date', '>=', $checkIn->toDateString())
            ->pluck('room_id')
            ->all();

        $taken = $this->takenRoomIds($checkIn, $checkOut, $booking->id);

        return $rooms
            ->reject(fn (Room $room): bool => in_array($room->id, $closed, true) || in_array($room->id, $taken, true))
            ->sortBy(fn (Room $room): string => str_pad($room->number, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    public function canAssign(Booking $booking, Room $room): bool
    {
        return $this->eligibleRooms($booking)->contains(fn (Room $eligible): bool => $eligible->id === $room->id);
    }

    /**
     * A free room for the next unassigned line, avoiding rooms the booking
     * already uses.
     */
    public function suggest(Booking $booking): ?Room
    {
        $used = $booking->bookingRooms()->whereNotNull('room_id')->pluck('room_id')->all();

        return $this->eligibleRooms($booking)->first(fn (Room $room): bool => ! in_array($room->id, $used, true));
    }

    /**
     * @return list<string>
     */
    private function takenRoomIds(CarbonImmutable $checkIn, CarbonImmutable $checkOut, string $exceptBookingId): array
    {
        return array_values(BookingRoom::query()
            ->whereNotNull('room_id')
            ->whereHas('booking', fn ($query) => $query
                ->whereKeyNot($exceptBookingId)
                ->whereIn('status', [BookingStatus::Tentative->value, BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
                ->where('check_in', '<', $checkOut->toDateString())
                ->where('check_out', '>', $checkIn->toDateString()))
            ->pluck('room_id')
            ->map(fn ($id): string => (string) $id)
            ->all());
    }
}
