<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What happened to a subscription, and what is to be invoiced (S13.2).
 *
 * @property int $id
 * @property string $subscription_id
 * @property string $kind
 * @property string|null $from_plan_id
 * @property string|null $to_plan_id
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable|null $invoiced_at
 * @property CarbonImmutable|null $created_at
 */
class SubscriptionChange extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'invoiced_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
