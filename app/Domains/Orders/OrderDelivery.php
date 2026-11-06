<?php

declare(strict_types=1);

namespace App\Domains\Orders;

enum OrderDelivery: string
{
    case Room = 'room';
    case Outlet = 'outlet';

    public function label(): string
    {
        return match ($this) {
            self::Room => __('To the room'),
            self::Outlet => __('At the outlet'),
        };
    }
}
