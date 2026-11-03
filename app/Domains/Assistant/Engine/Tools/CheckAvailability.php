<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Availability\AvailabilityService;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

final class CheckAvailability extends AssistantTool
{
    public function name(): string
    {
        return 'check_availability';
    }

    public function description(): string
    {
        return 'Rooms free for a stay, per room category (the lowest number free on any night of the stay). Dates are YYYY-MM-DD; check_out is the departure day.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'check_in' => $schema->string()->description('Arrival date, YYYY-MM-DD')->required(),
            'check_out' => $schema->string()->description('Departure date, YYYY-MM-DD')->required(),
            'category_code' => $schema->string()->description('Optional room category code; all categories when empty'),
        ];
    }

    protected function run(array $arguments): array
    {
        try {
            $checkIn = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_in'] ?? ''));
            $checkOut = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_out'] ?? ''));
        } catch (Throwable) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        if ($checkIn === null || $checkOut === null || $checkOut->lte($checkIn)) {
            return ['error' => 'check_out must be after check_in.'];
        }

        $hotel = app(TenantContext::class)->hotel();

        if ($checkIn->lt(HotelClock::today($hotel))) {
            return ['error' => 'check_in is in the past.'];
        }

        if ($checkIn->diffInDays($checkOut) > 30) {
            return ['error' => 'Stays longer than 30 nights must be handled by staff.'];
        }

        $categories = RoomCategory::query()->where('active', true)
            ->when(($arguments['category_code'] ?? '') !== '', fn ($q) => $q->where('code', strtoupper((string) $arguments['category_code'])))
            ->orderBy('sort_order')->get();

        if ($categories->isEmpty()) {
            return ['error' => 'Unknown room category code.'];
        }

        $grid = app(AvailabilityService::class)->forRange(
            array_values(array_map('strval', $categories->pluck('id')->all())),
            $checkIn,
            $checkOut->subDay(),
        );

        $free = function (string $categoryId) use ($grid): int {
            $nights = array_map(fn (array $night): int => $night['available'], array_values($grid[$categoryId] ?? []));

            return $nights === [] ? 0 : min($nights);
        };

        return ['check_in' => $checkIn->toDateString(), 'check_out' => $checkOut->toDateString(), 'categories' => $categories->map(fn (RoomCategory $c): array => [
            'code' => $c->code,
            'name' => $c->localizedName($this->context->locale, $hotel->default_locale),
            'rooms_free' => $free($c->id),
        ])->values()->all()];
    }
}
