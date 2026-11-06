<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

enum TableBookingStatus: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case Finished = 'finished';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Requested => __('Waiting for staff'),
            self::Confirmed => __('Confirmed'),
            self::Arrived => __('Arrived'),
            self::Finished => __('Finished'),
            self::NoShow => __('No-show'),
            self::Cancelled => __('Cancelled'),
            self::Declined => __('Declined'),
            self::Expired => __('Expired'),
        };
    }

    /**
     * Statuses whose covers take seats.
     *
     * @return list<self>
     */
    public static function holdingSeats(): array
    {
        return [self::Requested, self::Confirmed, self::Arrived];
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Confirmed, self::Declined, self::Expired, self::Cancelled],
            self::Confirmed => [self::Arrived, self::NoShow, self::Cancelled],
            self::Arrived => [self::Finished],
            default => [],
        };
    }
}
