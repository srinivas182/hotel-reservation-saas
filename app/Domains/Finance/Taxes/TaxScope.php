<?php

declare(strict_types=1);

namespace App\Domains\Finance\Taxes;

/**
 * What a tax applies to.
 */
enum TaxScope: string
{
    case Rooms = 'rooms';
    case FoodBeverage = 'food_beverage';
    case Events = 'events';

    public function label(): string
    {
        return match ($this) {
            self::Rooms => __('Rooms'),
            self::FoodBeverage => __('Food & beverage'),
            self::Events => __('Events'),
        };
    }
}
