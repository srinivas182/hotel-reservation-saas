<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Messaging\Email\EmailAllowance;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

/**
 * Guest, operational and marketing emails use email credits; security and
 * system emails (password resets, login codes, alerts) are never blocked.
 */
final class CreditEmailAllowance implements EmailAllowance
{
    public function allows(Hotel $hotel, EmailCategory $category): bool
    {
        if (! $category->countable()) {
            return true;
        }

        return app(TenantContext::class)->run($hotel, fn (): bool => app(Credits::class)->canUse(CreditKind::Email));
    }
}
