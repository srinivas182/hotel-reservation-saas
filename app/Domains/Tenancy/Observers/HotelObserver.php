<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Observers;

use App\Domains\Access\HotelRoles;
use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Arr;

final class HotelObserver
{
    public function created(Hotel $hotel): void
    {
        app(HotelRoles::class)->provision($hotel);

        app(TenantContext::class)->run($hotel, fn () => HotelSettings::query()->create([
            'enabled_locales' => [$hotel->default_locale],
        ]));

        Audit::record('hotel.created', 'Hotel created', subject: $hotel, properties: [
            'attributes' => Arr::only($hotel->getAttributes(), ['name', 'slug', 'country_code', 'currency', 'timezone', 'status']),
        ]);
    }

    public function updated(Hotel $hotel): void
    {
        $changes = Arr::except($hotel->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        Audit::record('hotel.updated', 'Hotel updated', subject: $hotel, properties: [
            'old' => Arr::only($hotel->getOriginal(), array_keys($changes)),
            'new' => $changes,
        ]);
    }
}
