<?php

declare(strict_types=1);

namespace App\Domains\Finance\Taxes;

use Brick\Money\Money;

/**
 * The result of applying taxes to a price.
 *
 * - net: the price without any tax
 * - lines: each tax and its amount
 * - total: what the guest pays (the entered price plus taxes added on top)
 */
final readonly class TaxBreakdown
{
    /**
     * @param  list<TaxLine>  $lines
     */
    public function __construct(
        public Money $price,
        public Money $net,
        public array $lines,
        public Money $total,
    ) {}

    public function totalTax(): Money
    {
        return $this->total->minus($this->net);
    }

    public function includedTax(): Money
    {
        return $this->price->minus($this->net);
    }

    public function addedTax(): Money
    {
        return $this->total->minus($this->price);
    }
}
