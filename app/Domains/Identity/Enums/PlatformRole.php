<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Roles held by MCS platform staff. These are separate from, and never
 * mixed with, the per-hotel roles introduced in S1.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';
}
