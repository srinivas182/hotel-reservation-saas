<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Tenancy\Models\Hotel;

final class UnlimitedEmailAllowance implements EmailAllowance
{
    public function allows(Hotel $hotel, EmailCategory $category): bool
    {
        return true;
    }
}
