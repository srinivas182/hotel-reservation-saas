<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\CreditKind;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Credits granted to a hotel (the month's allowance, trial credits, a pack,
 * an adjustment), in hundredths of a credit, and how many were used.
 *
 * @property int $id
 * @property string $hotel_id
 * @property CreditKind $kind
 * @property string $source allowance, trial, pack, adjustment
 * @property int $granted
 * @property int $used
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $credit_pack_id
 * @property string|null $reason
 */
class CreditBucket extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => CreditKind::class, 'granted' => 'integer', 'used' => 'integer', 'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<CreditBucket>  $query
     * @return Builder<CreditBucket>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
