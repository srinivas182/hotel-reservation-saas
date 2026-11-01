<?php

declare(strict_types=1);

namespace App\Domains\Tenancy;

use App\Domains\Tenancy\Models\Hotel;
use Carbon\CarbonImmutable;

/**
 * The hotel's calendar date.
 *
 * Stay dates (check-in, nights, closures) are plain dates, held as midnight
 * UTC. "Today" must be the hotel's local date expressed the same way, or
 * comparing it with a stay date would mix two time zones (at 23:30 UTC it
 * is already tomorrow in Dhaka).
 */
final class HotelClock
{
    public static function today(Hotel $hotel): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($hotel->timezone)->toDateString());
    }
}
