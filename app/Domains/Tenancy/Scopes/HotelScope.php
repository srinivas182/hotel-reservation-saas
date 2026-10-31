<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Scopes;

use App\Domains\Tenancy\Exceptions\MissingHotelContext;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query on a hotel-owned model to the current hotel.
 * Fails closed: with no current hotel the query throws instead of
 * returning data from every hotel.
 *
 * @implements Scope<Model>
 */
final class HotelScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        if (! $context->has()) {
            throw MissingHotelContext::forQuery($model::class);
        }

        $builder->where($model->qualifyColumn('hotel_id'), $context->id());
    }
}
