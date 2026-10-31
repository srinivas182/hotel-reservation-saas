<?php

declare(strict_types=1);

namespace App\Domains\Finance\Taxes;

enum TaxType: string
{
    case Percentage = 'percentage';
    case FixedPerNight = 'fixed_per_night';
    case FixedPerPersonPerNight = 'fixed_per_person_per_night';
    case FixedPerStay = 'fixed_per_stay';

    public function isPercentage(): bool
    {
        return $this === self::Percentage;
    }

    public function label(): string
    {
        return match ($this) {
            self::Percentage => __('Percentage'),
            self::FixedPerNight => __('Fixed amount per night'),
            self::FixedPerPersonPerNight => __('Fixed amount per person per night'),
            self::FixedPerStay => __('Fixed amount per stay'),
        };
    }
}
