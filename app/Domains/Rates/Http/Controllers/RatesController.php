<?php

declare(strict_types=1);

namespace App\Domains\Rates\Http\Controllers;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\ChildCharge;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Models\RateOverride;
use App\Domains\Rates\Quote\QuotePresenter;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RatesController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, QuotePresenter $quotes): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $settings = HotelSettings::current();
        $digits = Money::zero($hotel->currency)->getCurrency()->getDefaultFractionDigits();
        $major = fn (int $minor): string => (string) Money::ofMinor($minor, $hotel->currency)->getAmount();

        $categories = RoomCategory::query()->orderBy('sort_order')->orderBy('code')->get();
        $rates = CategoryRate::query()->get()->keyBy('room_category_id');

        return Inertia::render('hotel/rates', [
            'currency' => $hotel->currency,
            'minorDigits' => $digits,
            'categories' => $categories->map(function (RoomCategory $category) use ($rates, $major, $locale): array {
                /** @var CategoryRate|null $rate */
                $rate = $rates->get($category->id);

                return [
                    'id' => $category->id,
                    'code' => $category->code,
                    'name' => $category->localizedName($locale, $locale),
                    'active' => $category->active,
                    'extra_bed_allowed' => $category->extra_bed_allowed,
                    'standard_occupancy' => $category->standard_occupancy,
                    'rate' => $rate === null ? null : [
                        'base' => $major($rate->base_minor),
                        'extra_adult' => $major($rate->extra_adult_minor),
                        'child' => $major($rate->child_minor),
                        'extra_bed' => $major($rate->extra_bed_minor),
                        'breakfast_mode' => $rate->breakfast_mode->value,
                        'breakfast' => $major($rate->breakfast_minor),
                        'min_nights' => $rate->min_nights,
                    ],
                ];
            })->values(),
            'overrides' => RateOverride::query()
                ->orderBy('from_date')
                ->get()
                ->map(fn (RateOverride $override): array => [
                    'id' => $override->id,
                    'room_category_id' => $override->room_category_id,
                    'from_date' => $override->from_date->toDateString(),
                    'to_date' => $override->to_date->toDateString(),
                    'weekdays' => $override->weekdays ?? [],
                    'rate' => $override->rate_minor === null ? null : $major($override->rate_minor),
                    'min_nights' => $override->min_nights,
                    'stop_sell' => $override->stop_sell,
                    'closed_to_arrival' => $override->closed_to_arrival,
                    'label' => $override->label,
                    'overlaps' => $this->overlaps($override),
                ])->values(),
            'settings' => [
                'child_age_bands' => $settings->child_age_bands ?? [],
                'hold_minutes' => $settings->hold_minutes,
            ],
            'breakfastModes' => collect(BreakfastMode::cases())->map(fn (BreakfastMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()])->values(),
            'childCharges' => collect(ChildCharge::cases())->map(fn (ChildCharge $charge): array => ['value' => $charge->value, 'label' => $charge->label()])->values(),
            'quote' => Inertia::optional(fn (): array => $quotes->fromRequest($request)),
            'canManage' => $request->user()?->can('rates.manage') === true,
        ]);
    }

    private function overlaps(RateOverride $override): bool
    {
        return RateOverride::query()
            ->whereKeyNot($override->getKey())
            ->where('from_date', '<=', $override->to_date->toDateString())
            ->where('to_date', '>=', $override->from_date->toDateString())
            ->where(fn ($query) => $override->room_category_id === null
                ? $query
                : $query->whereNull('room_category_id')->orWhere('room_category_id', $override->room_category_id))
            ->exists();
    }
}
