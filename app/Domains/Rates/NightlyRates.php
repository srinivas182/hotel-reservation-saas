<?php

declare(strict_types=1);

namespace App\Domains\Rates;

use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rates\Models\RateOverride;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * The price and restrictions of each night for one or more categories,
 * after date overrides. When several overrides apply to a night, the most
 * recently changed one with a price sets the price; restrictions from all
 * of them apply. Two queries, however many nights or categories.
 */
final class NightlyRates
{
    /**
     * @param  list<string>  $categoryIds
     * @return array<string, array<string, array{rate_minor: int|null, label: string|null, stop_sell: bool, closed_to_arrival: bool, min_nights: int}>>
     */
    public function forRange(array $categoryIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        $rates = CategoryRate::query()->whereIn('room_category_id', $categoryIds)->get()->keyBy('room_category_id');

        $overrides = RateOverride::query()
            ->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())
            ->where(fn ($query) => $query->whereNull('room_category_id')->orWhereIn('room_category_id', $categoryIds))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $result = [];

        foreach ($categoryIds as $categoryId) {
            /** @var CategoryRate|null $rate */
            $rate = $rates->get($categoryId);

            foreach (CarbonPeriod::create($from, $to) as $night) {
                $night = CarbonImmutable::instance($night);
                $applicable = $overrides->filter(fn (RateOverride $override): bool => $override->appliesTo($categoryId, $night));
                $priced = $applicable->first(fn (RateOverride $override): bool => $override->rate_minor !== null);

                $result[$categoryId][$night->toDateString()] = [
                    'rate_minor' => $priced instanceof RateOverride ? (int) $priced->rate_minor : $rate?->base_minor,
                    'label' => $priced?->label,
                    'stop_sell' => $applicable->contains(fn (RateOverride $override): bool => $override->stop_sell),
                    'closed_to_arrival' => $applicable->contains(fn (RateOverride $override): bool => $override->closed_to_arrival),
                    'min_nights' => max($rate->min_nights ?? 1, (int) $applicable->max(fn (RateOverride $override): int => (int) $override->min_nights)),
                ];
            }
        }

        return $result;
    }
}
