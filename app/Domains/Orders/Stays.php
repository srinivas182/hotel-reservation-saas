<?php

declare(strict_types=1);

namespace App\Domains\Orders;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\GuestDirectory;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Orders\Models\StayPhone;

/**
 * Finds the checked-in stay a phone number belongs to: the booking guest's
 * phone, or an extra number reception added to the stay. Any other number
 * is an outside guest.
 */
final class Stays
{
    public function forPhone(?string $phone): ?Booking
    {
        $phone = GuestDirectory::normalisePhone($phone);

        if ($phone === null) {
            return null;
        }

        $extra = StayPhone::query()->where('phone', $phone)->pluck('booking_id')->all();

        return Booking::query()
            ->with(['bookingRooms.room', 'guest'])
            ->where('status', BookingStatus::CheckedIn->value)
            ->where(fn ($q) => $q->whereHas('guest', fn ($g) => $g->where('phone_e164', $phone))->orWhereIn('id', $extra))
            ->orderByDesc('checked_in_at')
            ->first();
    }

    /**
     * @return list<string>
     */
    public static function roomNumbers(Booking $booking): array
    {
        return array_values(array_filter($booking->bookingRooms->map(fn ($r): ?string => $r->room?->number)->all()));
    }
}
