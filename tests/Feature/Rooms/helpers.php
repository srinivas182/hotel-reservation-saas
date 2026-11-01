<?php

declare(strict_types=1);

use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

function roomCategory(Hotel $hotel, string $code = 'STD', int $rooms = 0, array $attributes = []): RoomCategory
{
    return app(TenantContext::class)->run($hotel, function () use ($code, $rooms, $attributes): RoomCategory {
        $category = RoomCategory::query()->create([
            'code' => $code,
            'name' => ['en' => $code.' room', 'fr' => 'Chambre '.$code],
            'standard_occupancy' => 2,
            'max_adults' => 2,
            'max_children' => 1,
            'max_occupancy' => 3,
            'extra_bed_allowed' => false,
            'amenities' => [],
            'active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);

        static $next = 100;
        foreach (range(1, $rooms) as $i) {
            if ($rooms === 0) {
                break;
            }
            Room::query()->create([
                'room_category_id' => $category->id,
                'number' => $code.'-'.(++$next),
                'status' => RoomStatus::InService,
            ]);
        }

        return $category;
    });
}

function categoryPayload(array $overrides = []): array
{
    return [
        'code' => 'exe',
        'name' => ['fr' => 'Exécutive', 'en' => 'Executive'],
        'description' => ['fr' => 'Grande chambre', 'en' => ''],
        'standard_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 1,
        'max_occupancy' => 3,
        'extra_bed_allowed' => true,
        'amenities' => ['wifi', 'air_conditioning'],
        'active' => true,
        'sort_order' => 1,
        ...$overrides,
    ];
}
