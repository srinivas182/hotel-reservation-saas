<?php

declare(strict_types=1);

namespace App\Domains\Sales\Models;

use App\Domains\Billing\Models\Plan;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Site\Models\Lead;
use App\Support\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H: a priced proposal for a lead, with a link to start the trial.
 *
 * @property int $id
 * @property int $lead_id
 * @property string $plan_id
 * @property string $currency
 * @property string $cycle
 * @property int $discount_percent
 * @property array<string, int|float> $roi
 * @property string $locale
 * @property string|null $note
 * @property string $token
 * @property CarbonImmutable $valid_until
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $viewed_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $signup_code_id
 * @property CarbonImmutable|null $created_at
 * @property-read Lead $lead
 * @property-read Plan $plan
 * @property-read SignupCode|null $code
 */
class Proposal extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roi' => 'array', 'note' => 'encrypted', 'valid_until' => DateOnly::class, 'discount_percent' => 'integer',
            'sent_at' => 'immutable_datetime', 'viewed_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        ];
    }

    /** Price per billing period, in minor units, after the discount. */
    public function price(): int
    {
        $base = (int) ($this->plan->price($this->currency, $this->cycle) ?? 0);

        return (int) round($base * (100 - $this->discount_percent) / 100);
    }

    public function monthlyCost(): int
    {
        return $this->cycle === 'yearly' ? (int) round($this->price() / 12) : $this->price();
    }

    public function expired(): bool
    {
        return $this->valid_until->endOfDay()->isPast();
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<SignupCode, $this>
     */
    public function code(): BelongsTo
    {
        return $this->belongsTo(SignupCode::class, 'signup_code_id');
    }
}
