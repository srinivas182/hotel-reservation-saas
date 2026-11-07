<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Subscriptions;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

/**
 * @param  list<string>  $modules
 */
function billingPlan(string $code, array $modules = [], ?int $staff = null, int $ai = 100, int $email = 50, int $monthlyXof = 25000): Plan
{
    return Plan::query()->create([
        'code' => $code, 'name' => ['fr' => ucfirst($code), 'en' => ucfirst($code)], 'modules' => $modules, 'staff_limit' => $staff,
        'ai_credits' => $ai, 'email_credits' => $email, 'prices' => ['XOF' => ['monthly' => $monthlyXof, 'yearly' => $monthlyXof * 10]],
        'trial_days' => 14, 'active' => true,
    ]);
}

function subscribe(Hotel $hotel, Plan $plan, bool $trial = false, string $interval = 'monthly'): Subscription
{
    return app(TenantContext::class)->run($hotel, fn (): Subscription => app(Subscriptions::class)->start($plan, $interval, 'XOF', $trial));
}
