<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\TableAvailability;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

final class RestaurantAvailability extends AssistantTool
{
    public function name(): string
    {
        return 'restaurant_availability';
    }

    public function description(): string
    {
        return 'Restaurants and bars of the hotel, and the free booking times for a party on a date. Call without outlet to list the outlets.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outlet' => $schema->string()->description('Outlet code from this tool\'s list (empty to list outlets)'),
            'date' => $schema->string()->description('YYYY-MM-DD'),
            'people' => $schema->integer()->description('Number of people'),
        ];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $outlets = Outlet::query()->where('active', true)->orderBy('sort_order')->get();
        $list = $outlets->map(fn (Outlet $o): array => ['code' => $o->public_slug, 'name' => $o->localizedName($this->context->locale, $hotel->default_locale), 'type' => $o->type->value])->values()->all();
        $outlet = $outlets->firstWhere('public_slug', (string) ($arguments['outlet'] ?? ''));

        if ($outlet === null) {
            return ['outlets' => $list, 'note' => 'Call again with an outlet code, a date and the number of people.'];
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['date'] ?? ''));
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->lt(HotelClock::today($hotel))) {
            return ['error' => 'Give a date from today, as YYYY-MM-DD.'];
        }

        $people = max(1, (int) ($arguments['people'] ?? 2));
        $slots = app(TableAvailability::class)->slots($outlet, $date, $people);

        return [
            'outlet' => $outlet->localizedName($this->context->locale, $hotel->default_locale),
            'date' => $date->toDateString(),
            'people' => $people,
            'free_times' => array_values(array_map(fn (array $s): string => $s['time'].' ('.$s['period'].')', array_filter($slots, fn (array $s): bool => $s['available']))),
            'closed' => $slots === [],
            'automatic_confirmation_up_to' => $outlet->auto_confirm ? $outlet->auto_confirm_max_party : 0,
        ];
    }
}
