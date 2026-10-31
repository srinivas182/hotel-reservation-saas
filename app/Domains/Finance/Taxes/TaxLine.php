<?php

declare(strict_types=1);

namespace App\Domains\Finance\Taxes;

use Brick\Money\Money;

final readonly class TaxLine
{
    public function __construct(
        public TaxRate $rate,
        public Money $amount,
        public bool $inclusive,
    ) {}
}
