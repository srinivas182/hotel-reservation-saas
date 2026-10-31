<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Concerns;

use App\Domains\Tenancy\Exceptions\HotelReassignmentForbidden;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\Scopes\HotelScope;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks an Eloquent model as hotel-owned (ADR-001, ADR-004).
 *
 * - Every query is limited to the current hotel (HotelScope).
 * - hotel_id is always set from the current hotel on create; any value
 *   supplied by the caller is ignored unless scoping is bypassed.
 * - A record can never be moved to another hotel.
 *
 * @mixin Model
 */
trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        static::addGlobalScope(new HotelScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->isBypassed() && $model->getAttribute('hotel_id') !== null) {
                return;
            }

            $model->setAttribute('hotel_id', $context->id());
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('hotel_id') && ! app(TenantContext::class)->isBypassed()) {
                throw HotelReassignmentForbidden::for($model::class);
            }
        });
    }

    public function initializeBelongsToHotel(): void
    {
        $this->mergeGuarded(['hotel_id']);
    }

    /**
     * @return BelongsTo<Hotel, $this>
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
