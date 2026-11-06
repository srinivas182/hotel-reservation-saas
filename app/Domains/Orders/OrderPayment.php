<?php

declare(strict_types=1);

namespace App\Domains\Orders;

enum OrderPayment: string
{
    case RoomCharge = 'room_charge';
    case OnDelivery = 'on_delivery';

    public function label(): string
    {
        return match ($this) {
            self::RoomCharge => __('On the room bill'),
            self::OnDelivery => __('Paid on delivery'),
        };
    }
}
