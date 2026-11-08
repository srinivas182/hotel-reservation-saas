<?php

declare(strict_types=1);

namespace App\Domains\Platform\Features;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Tenancy\TenantContext;

/**
 * Whether a feature is on for the current hotel: on for everyone, off, or
 * on for chosen countries, plans or hotels. Works alongside plan modules.
 */
final class Features
{
    /** @var array<string, FeatureFlag|null> */
    private array $flags = [];

    public function __construct(private readonly TenantContext $context) {}

    public function enabled(Feature $feature): bool
    {
        $flag = $this->flags[$feature->value] ??= FeatureFlag::query()->where('key', $feature->value)->first();

        if ($flag === null || $flag->mode === 'on') {
            return true;
        }

        if ($flag->mode === 'off' || ! $this->context->has()) {
            return false;
        }

        $hotel = $this->context->hotel();
        $plan = Subscription::query()->with('plan')->first()?->plan?->code;

        return in_array($hotel->id, $flag->hotels ?? [], true)
            || in_array($hotel->country_code, $flag->countries ?? [], true)
            || ($plan !== null && in_array($plan, $flag->plans ?? [], true));
    }

    public function forget(): void
    {
        $this->flags = [];
    }
}
