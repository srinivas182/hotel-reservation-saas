<?php

declare(strict_types=1);

use App\Domains\Availability\HoldService;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Rates/helpers.php';

function holdRooms(Hotel $hotel, RoomCategory $category, string $in, string $out, int $rooms = 1, ?int $minutes = null): InventoryHold
{
    return app(TenantContext::class)->run($hotel, fn (): InventoryHold => app(HoldService::class)->place(
        $category,
        CarbonImmutable::parse($in),
        CarbonImmutable::parse($out),
        $rooms,
        minutes: $minutes,
    ));
}

/**
 * @return array<string, int>
 */
function availableNights(Hotel $hotel, RoomCategory $category, string $from, string $to): array
{
    return app(TenantContext::class)->run($hotel, fn (): array => app(App\Domains\Availability\AvailabilityService::class)
        ->availableFor($category->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
}
