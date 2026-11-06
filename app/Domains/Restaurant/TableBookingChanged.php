<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A WhatsApp table booking was confirmed, declined or expired: the guest is
 * told by the system (never by the AI).
 */
final class TableBookingChanged
{
    use Dispatchable;

    public function __construct(public readonly string $bookingId) {}
}
