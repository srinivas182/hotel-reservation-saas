<?php

declare(strict_types=1);

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Models\RateOverride;
use App\Domains\Rates\Quote\Quote;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Rooms/helpers.php';

function rateFor(Hotel $hotel, RoomCategory $category, array $attributes = []): CategoryRate
{
    return app(TenantContext::class)->run($hotel, fn (): CategoryRate => CategoryRate::query()->create([
        'room_category_id' => $category->id,
        'base_minor' => 25000,
        'extra_adult_minor' => 5000,
        'child_minor' => 2500,
        'extra_bed_minor' => 5000,
        'breakfast_mode' => BreakfastMode::Optional,
        'breakfast_minor' => 3000,
        'min_nights' => 1,
        ...$attributes,
    ]));
}

function overrideFor(Hotel $hotel, ?RoomCategory $category, string $from, string $to, array $attributes = []): RateOverride
{
    return app(TenantContext::class)->run($hotel, fn (): RateOverride => RateOverride::query()->create([
        'room_category_id' => $category?->id,
        'from_date' => $from,
        'to_date' => $to,
        'label' => 'Override',
        'stop_sell' => false,
        'closed_to_arrival' => false,
        ...$attributes,
    ]));
}

function bands(Hotel $hotel, array $bands): void
{
    app(TenantContext::class)->run($hotel, fn () => HotelSettings::current()->update(['child_age_bands' => $bands]));
}

function quoteStay(Hotel $hotel, RoomCategory $category, string $in, string $out, int $adults = 2, array $children = [], int $rooms = 1, bool $breakfast = false, bool $extraBed = false): Quote
{
    return app(TenantContext::class)->run($hotel, fn (): Quote => app(QuoteService::class)->quote(new StayRequest(
        categoryId: $category->id,
        checkIn: CarbonImmutable::parse($in),
        checkOut: CarbonImmutable::parse($out),
        adults: $adults,
        childAges: $children,
        rooms: $rooms,
        breakfast: $breakfast,
        extraBed: $extraBed,
    )));
}

/**
 * @return array<string, string>
 */
function lineAmounts(Quote $quote): array
{
    return collect($quote->lines)->mapWithKeys(fn (array $line): array => [$line['code'] => (string) $line['amount']])->all();
}
