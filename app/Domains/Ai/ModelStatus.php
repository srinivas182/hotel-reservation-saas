<?php

declare(strict_types=1);

namespace App\Domains\Ai;

enum ModelStatus: string
{
    case Testing = 'testing';
    case Approved = 'approved';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Testing => __('Testing'),
            self::Approved => __('Approved'),
            self::Retired => __('Retired'),
        };
    }
}
