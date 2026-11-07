<?php

declare(strict_types=1);

namespace App\Domains\Billing;

enum CreditKind: string
{
    case Ai = 'ai';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Ai => __('AI credits'),
            self::Email => __('Email credits'),
        };
    }
}
