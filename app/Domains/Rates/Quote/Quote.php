<?php

declare(strict_types=1);

namespace App\Domains\Rates\Quote;

use App\Domains\Finance\Taxes\TaxBreakdown;
use Brick\Money\Money;

/**
 * The priced result of a stay request. When issues exist, prices may be
 * incomplete and the stay must not be offered as bookable.
 *
 * @phpstan-type Night array{date: string, rate: Money, label: string|null, available: int}
 * @phpstan-type Line array{code: string, label: string, quantity: int, unit: Money, amount: Money}
 */
final readonly class Quote
{
    /**
     * @param  list<Night>  $nights
     * @param  list<Line>  $lines
     * @param  list<QuoteIssue>  $issues
     */
    public function __construct(
        public StayRequest $request,
        public array $nights,
        public array $lines,
        public Money $subtotal,
        public ?TaxBreakdown $roomTaxes,
        public ?TaxBreakdown $breakfastTaxes,
        public Money $total,
        public array $issues,
    ) {}

    public function bookable(): bool
    {
        return $this->issues === [];
    }

    public function hasIssue(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function minAvailable(): int
    {
        return $this->nights === [] ? 0 : min(array_map(fn (array $night): int => $night['available'], $this->nights));
    }
}
