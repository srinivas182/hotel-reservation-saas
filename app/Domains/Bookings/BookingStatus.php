<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

enum BookingStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Tentative => __('Tentative'),
            self::Confirmed => __('Confirmed'),
            self::CheckedIn => __('Checked in'),
            self::CheckedOut => __('Checked out'),
            self::NoShow => __('No-show'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * Statuses whose nights count as booked in availability.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::Confirmed, self::CheckedIn, self::CheckedOut];
    }
}
