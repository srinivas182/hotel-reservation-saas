<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Tenancy\Models\Hotel;

/**
 * Whether a hotel may send a countable email. Packages and credits (S13)
 * provide the real implementation; until then every hotel is unlimited.
 */
interface EmailAllowance
{
    public function allows(Hotel $hotel, EmailCategory $category): bool;
}
