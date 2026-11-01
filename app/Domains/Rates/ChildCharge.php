<?php

declare(strict_types=1);

namespace App\Domains\Rates;

/**
 * How a child in an age band is charged.
 */
enum ChildCharge: string
{
    case Free = 'free';
    case ChildRate = 'child';
    case Adult = 'adult';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::ChildRate => __('Child rate'),
            self::Adult => __('Adult rate'),
        };
    }
}
