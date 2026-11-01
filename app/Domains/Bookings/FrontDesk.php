<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What reception needs today, in the hotel's time zone.
 */
final class FrontDesk
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RoomAssignments $assignments,
    ) {}

    public function today(): CarbonImmutable
    {
        return HotelClock::today($this->context->hotel());
    }

    /**
     * @return Collection<int, Booking>
     */
    public function arrivals(): Collection
    {
        return $this->bookings()
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Tentative->value])
            ->where('check_in', $this->today()->toDateString())
            ->get();
    }

    /**
     * Confirmed guests whose arrival date has passed and who have not arrived.
     *
     * @return Collection<int, Booking>
     */
    public function possibleNoShows(): Collection
    {
        return $this->bookings()
            ->where('status', BookingStatus::Confirmed->value)
            ->where('check_in', '<', $this->today()->toDateString())
            ->get();
    }

    /**
     * @return Collection<int, Booking>
     */
    public function inHouse(): Collection
    {
        return $this->bookings()->where('status', BookingStatus::CheckedIn->value)->get();
    }

    /**
     * Checked-in guests due to leave today (or already overdue).
     *
     * @return Collection<int, Booking>
     */
    public function departures(): Collection
    {
        return $this->bookings()
            ->where('status', BookingStatus::CheckedIn->value)
            ->where('check_out', '<=', $this->today()->toDateString())
            ->get();
    }

    /**
     * Tentative bookings whose deadline is within the next 24 hours.
     *
     * @return Collection<int, Booking>
     */
    public function tentativeDueSoon(): Collection
    {
        return $this->bookings()
            ->where('status', BookingStatus::Tentative->value)
            ->where('tentative_until', '<=', now()->addDay())
            ->orderBy('tentative_until')
            ->get();
    }

    /**
     * Room numbers to suggest for each unassigned line of a booking.
     *
     * @return array<int, string> position => room id
     */
    public function suggestedRooms(Booking $booking): array
    {
        $eligible = $this->assignments->eligibleRooms($booking);
        $used = $booking->bookingRooms->pluck('room_id')->filter()->all();
        $suggested = [];

        foreach ($booking->bookingRooms as $line) {
            if ($line->room_id !== null) {
                $suggested[$line->position] = $line->room_id;

                continue;
            }

            $room = $eligible->first(fn (Room $room): bool => ! in_array($room->id, $used, true));

            if ($room !== null) {
                $suggested[$line->position] = $room->id;
                $used[] = $room->id;
            }
        }

        return $suggested;
    }

    /**
     * Every room tonight: free, occupied, arriving, departing or out of service.
     *
     * @return list<array{id: string, number: string, floor: string|null, category_id: string, state: string, departing: bool, arriving: bool, booking_id: string|null, reference: string|null, guest: string|null}>
     */
    public function roomsBoard(): array
    {
        $today = $this->today()->toDateString();

        $rooms = Room::query()->with('category')->get()
            ->sortBy(fn (Room $room): string => str_pad($room->number, 10, '0', STR_PAD_LEFT))
            ->values();

        $closedTonight = RoomClosure::query()
            ->where('from_date', '<=', $today)
            ->where('to_date', '>=', $today)
            ->pluck('room_id')
            ->all();

        $lines = BookingRoom::query()
            ->whereNotNull('room_id')
            ->with('booking.guest')
            ->whereHas('booking', fn ($query) => $query
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value])
                ->where('check_in', '<=', $today)
                ->where('check_out', '>=', $today))
            ->get();

        $board = [];

        foreach ($rooms as $room) {
            $occupant = null;
            $arriving = null;
            $departing = false;

            foreach ($lines->where('room_id', $room->id) as $line) {
                $booking = $line->booking;

                if ($booking === null) {
                    continue;
                }

                $staysTonight = $booking->check_out->toDateString() > $today;

                if ($booking->status === BookingStatus::CheckedIn) {
                    if ($staysTonight) {
                        $occupant = $booking;
                    } else {
                        $departing = true;
                    }
                } elseif ($booking->status === BookingStatus::Confirmed && $booking->check_in->toDateString() === $today) {
                    $arriving = $booking;
                }
            }

            $outOfService = $room->status !== RoomStatus::InService || in_array($room->id, $closedTonight, true);
            $shown = $occupant ?? $arriving;

            $board[] = [
                'id' => $room->id,
                'number' => $room->number,
                'floor' => $room->floor,
                'category_id' => $room->room_category_id,
                'state' => match (true) {
                    $occupant !== null => 'occupied',
                    $outOfService => 'out_of_service',
                    $arriving !== null => 'arriving',
                    default => 'free',
                },
                'departing' => $departing,
                'arriving' => $arriving !== null,
                'booking_id' => $shown?->id,
                'reference' => $shown?->reference,
                'guest' => $shown?->guest?->name(),
            ];
        }

        return $board;
    }

    /**
     * Room numbers occupied tonight (for the register check).
     *
     * @return list<string>
     */
    public function occupiedTonight(): array
    {
        return array_values(array_map(
            fn (array $room): string => $room['number'],
            array_filter($this->roomsBoard(), fn (array $room): bool => $room['state'] === 'occupied'),
        ));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Booking>
     */
    private function bookings(): \Illuminate\Database\Eloquent\Builder
    {
        return Booking::query()->with(['guest', 'category', 'bookingRooms.room'])->orderBy('check_in')->orderBy('reference');
    }
}
