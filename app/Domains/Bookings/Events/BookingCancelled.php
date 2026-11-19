<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tentative or confirmed booking was cancelled, by staff or because its
 * deposit deadline passed (expired). Deposits (Sprint F) listen.
 */
final class BookingCancelled
{
    use Dispatchable;

    public function __construct(public readonly string $bookingId, public readonly bool $expired) {}
}
