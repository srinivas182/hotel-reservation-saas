<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Roles held by platform staff (MCS and CNH). Separate from, and never
 * mixed with, the per-hotel roles. What each may do: PlatformAbility.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Finance = 'finance';
    case Sales = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => __('Super admin'),
            self::Operations => __('Operations'),
            self::Finance => __('Finance'),
            self::Sales => __('Sales'),
        };
    }
}
