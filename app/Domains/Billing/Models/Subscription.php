<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $hotel_id
 * @property string $plan_id
 * @property string $status trialing, active, cancelled
 * @property string $interval monthly, yearly
 * @property string $currency
 * @property int $price_minor
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable|null $trial_ends_at
 * @property string|null $pending_plan_id
 * @property array<string, string>|null $warnings
 * @property int|null $billing_entity_id
 * @property array{name?: string, address?: string, tax_id?: string, email?: string}|null $billing_details
 * @property CarbonImmutable|null $reduced_at
 * @property CarbonImmutable|null $grace_until
 * @property-read Plan|null $plan
 * @property-read Plan|null $pendingPlan
 */
class Subscription extends Model
{
    use BelongsToHotel, HasUlids;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'warnings' => 'array',
            'billing_details' => 'array',
            'reduced_at' => 'immutable_datetime',
            'grace_until' => \App\Support\Casts\DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /**
     * The current hotel's subscription with its plan, read once per web
     * request (entitlements, features and announcements all need it).
     * Read-only: change a subscription through a fresh query.
     */
    public static function current(): ?self
    {
        $hotel = app(\App\Domains\Tenancy\TenantContext::class)->id();

        return \App\Support\RequestMemo::remember('subscription|'.$hotel, fn (): ?self => self::query()->with('plan')->first());
    }

    public static function currentPlanCode(): ?string
    {
        return self::current()?->plan?->code;
    }

    protected static function booted(): void
    {
        $forget = function (): void {
            \App\Support\RequestMemo::forget('subscription|');
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
