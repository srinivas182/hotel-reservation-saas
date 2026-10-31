<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

enum HotelStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * Whether staff may work inside a hotel with this status.
     */
    public function allowsAccess(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
