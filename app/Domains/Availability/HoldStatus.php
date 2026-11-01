<?php

declare(strict_types=1);

namespace App\Domains\Availability;

enum HoldStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Released => __('Released'),
            self::Expired => __('Expired'),
            self::Converted => __('Booked'),
        };
    }
}
