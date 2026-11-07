<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;

/**
 * What the current hotel's plan includes. Hotels without a subscription
 * have everything (set-up and legacy hotels).
 */
final class Entitlements
{
    public function has(Module $module): bool
    {
        $plan = $this->plan();

        return $plan === null || in_array($module->value, $plan->modules, true);
    }

    public function staffLimit(): ?int
    {
        return $this->plan()?->staff_limit;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return $this->plan()->modules ?? array_map(fn (Module $m): string => $m->value, Module::cases());
    }

    private function plan(): ?Plan
    {
        if (! app(\App\Domains\Tenancy\TenantContext::class)->has()) {
            return null;
        }

        return Subscription::query()->with('plan')->first()?->plan;
    }
}
