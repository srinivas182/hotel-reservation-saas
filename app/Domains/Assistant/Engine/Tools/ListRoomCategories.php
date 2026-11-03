<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ListRoomCategories extends AssistantTool
{
    public function name(): string
    {
        return 'list_room_categories';
    }

    public function description(): string
    {
        return 'List the hotel\'s room categories with their code, capacity and amenities. No prices: use get_quote for prices.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();

        return ['categories' => RoomCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('code')->get()
            ->map(fn (RoomCategory $c): array => [
                'code' => $c->code,
                'name' => $c->localizedName($this->context->locale, $hotel->default_locale),
                'max_adults' => $c->max_adults,
                'max_children' => $c->max_children,
                'max_guests' => $c->max_occupancy,
                'extra_bed' => $c->extra_bed_allowed,
                'amenities' => $c->amenities ?? [],
                'description' => $c->localizedDescription($this->context->locale, $hotel->default_locale),
            ])->values()->all()];
    }
}
