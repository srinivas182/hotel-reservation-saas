<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

enum OutletType: string
{
    case Restaurant = 'restaurant';
    case Bar = 'bar';
    case Terrace = 'terrace';
    case RoomService = 'room_service';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => __('Restaurant'),
            self::Bar => __('Bar'),
            self::Terrace => __('Terrace'),
            self::RoomService => __('Room service'),
        };
    }
}
