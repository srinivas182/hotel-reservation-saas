<?php

declare(strict_types=1);

namespace App\Domains\Rooms;

enum RoomStatus: string
{
    case InService = 'in_service';
    case OutOfOrder = 'out_of_order';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::InService => __('In service'),
            self::OutOfOrder => __('Out of order'),
            self::Maintenance => __('Maintenance'),
        };
    }

    public function sellable(): bool
    {
        return $this === self::InService;
    }
}
