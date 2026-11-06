<?php

declare(strict_types=1);

namespace App\Domains\Events;

enum Layout: string
{
    case Theatre = 'theatre';
    case Classroom = 'classroom';
    case UShape = 'u_shape';
    case Banquet = 'banquet';
    case Cocktail = 'cocktail';

    public function label(): string
    {
        return match ($this) {
            self::Theatre => __('Theatre'),
            self::Classroom => __('Classroom'),
            self::UShape => __('U-shape'),
            self::Banquet => __('Banquet'),
            self::Cocktail => __('Cocktail'),
        };
    }
}
