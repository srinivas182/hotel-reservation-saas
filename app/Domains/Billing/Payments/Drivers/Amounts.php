<?php

declare(strict_types=1);

namespace App\Domains\Billing\Payments\Drivers;

use Brick\Money\Money;

/**
 * Converting between our minor units and what each provider expects.
 */
final class Amounts
{
    public static function major(int $minor, string $currency): string
    {
        return (string) Money::ofMinor($minor, $currency)->getAmount();
    }

    public static function fromMajor(string|int|float $major, string $currency): int
    {
        return Money::of((string) $major, $currency, roundingMode: \Brick\Math\RoundingMode::HalfUp)->getMinorAmount()->toInt();
    }
}
