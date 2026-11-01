<?php

declare(strict_types=1);

namespace App\Domains\Rates;

enum BreakfastMode: string
{
    case Included = 'included';
    case Optional = 'optional';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Included => __('Included'),
            self::Optional => __('Optional, charged per person per night'),
            self::None => __('Not offered'),
        };
    }
}
