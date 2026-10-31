<?php

declare(strict_types=1);

namespace App\Domains\Finance\Taxes;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Applies a hotel's taxes to a price.
 *
 * Rules (no tax on tax):
 * - Taxes "included in the price" are part of the entered price. Fixed
 *   included taxes are taken out first, then included percentages are
 *   worked back from what remains: net = remainder / (1 + sum of rates).
 * - Taxes "added on top" are calculated on the net price (percentages) or
 *   as fixed amounts, and added to what the guest pays.
 * - Every tax line is rounded to the currency's minor unit (half up). The
 *   net absorbs rounding, so the included taxes plus net always equal the
 *   entered price exactly.
 */
final class TaxCalculator
{
    /**
     * @param  iterable<TaxRate>  $rates
     * @param  Money  $price  price of the whole line, as entered (for example 2 nights x rate)
     */
    public function calculate(
        iterable $rates,
        Money $price,
        TaxScope $scope,
        CarbonInterface $date,
        int $nights = 1,
        int $guests = 1,
    ): TaxBreakdown {
        if ($nights < 1 || $guests < 1) {
            throw new InvalidArgumentException('Nights and guests must be at least 1.');
        }

        $applicable = collect($rates)
            ->filter(fn (TaxRate $rate): bool => $rate->appliesTo($scope) && $rate->isEffectiveOn($date))
            ->sortBy('sort_order')
            ->values();

        $currency = $price->getCurrency();
        $zero = Money::zero($currency);

        // 1. Fixed taxes included in the price.
        $includedFixed = [];
        $remainder = $price;
        foreach ($applicable->where('inclusive', true) as $rate) {
            if ($rate->type->isPercentage()) {
                continue;
            }
            $amount = $this->fixedAmount($rate, $currency->getCurrencyCode(), $nights, $guests);
            $includedFixed[] = new TaxLine($rate, $amount, true);
            $remainder = $remainder->minus($amount);
        }

        // 2. Percentage taxes included in the price.
        $includedPercentages = $applicable->filter(fn (TaxRate $r): bool => $r->inclusive && $r->type->isPercentage());
        $rateSum = $includedPercentages->sum(fn (TaxRate $r): int => (int) $r->percentage_bps);
        $divisor = BigDecimal::of(10000 + $rateSum)->dividedBy(10000, 10, RoundingMode::HalfUp);
        $netExact = $remainder->getAmount()->dividedBy($divisor, 10, RoundingMode::HalfUp);

        $includedPercentageLines = [];
        $includedPercentageTotal = $zero;
        foreach ($includedPercentages as $rate) {
            $amount = Money::of(
                $netExact->multipliedBy((int) $rate->percentage_bps)->dividedBy(10000, 10, RoundingMode::HalfUp),
                $currency,
                roundingMode: RoundingMode::HalfUp,
            );
            $includedPercentageLines[] = new TaxLine($rate, $amount, true);
            $includedPercentageTotal = $includedPercentageTotal->plus($amount);
        }

        // Net absorbs rounding so that net + included taxes == price.
        $net = $remainder->minus($includedPercentageTotal);

        // 3. Taxes added on top.
        $addedLines = [];
        $total = $price;
        foreach ($applicable->where('inclusive', false) as $rate) {
            $amount = $rate->type->isPercentage()
                ? $net->multipliedBy(
                    BigDecimal::of((int) $rate->percentage_bps)->dividedBy(10000, 10, RoundingMode::HalfUp),
                    RoundingMode::HalfUp,
                )
                : $this->fixedAmount($rate, $currency->getCurrencyCode(), $nights, $guests);

            $addedLines[] = new TaxLine($rate, $amount, false);
            $total = $total->plus($amount);
        }

        $lines = [...$includedFixed, ...$includedPercentageLines, ...$addedLines];
        usort($lines, fn (TaxLine $a, TaxLine $b): int => $a->rate->sort_order <=> $b->rate->sort_order);

        return new TaxBreakdown($price, $net, $lines, $total);
    }

    private function fixedAmount(TaxRate $rate, string $currency, int $nights, int $guests): Money
    {
        $unit = Money::ofMinor((int) $rate->amount_minor, $currency);

        return match ($rate->type) {
            TaxType::FixedPerNight => $unit->multipliedBy($nights),
            TaxType::FixedPerPersonPerNight => $unit->multipliedBy($nights * $guests),
            TaxType::FixedPerStay => $unit,
            TaxType::Percentage => throw new InvalidArgumentException('Percentage taxes have no fixed amount.'),
        };
    }
}
