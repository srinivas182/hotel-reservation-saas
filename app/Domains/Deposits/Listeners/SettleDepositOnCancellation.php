<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Listeners;

use App\Domains\Bookings\Events\BookingCancelled;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Deposits\Deposits;

/**
 * Close the deposit link of a cancelled booking and work out any refund.
 * Runs right away, in the hotel of the cancellation.
 */
final class SettleDepositOnCancellation
{
    public function __construct(private readonly Deposits $deposits) {}

    public function handle(BookingCancelled $event): void
    {
        $booking = Booking::query()->with('guest')->find($event->bookingId);

        if ($booking !== null) {
            $this->deposits->bookingCancelled($booking, $event->expired);
        }
    }
}
