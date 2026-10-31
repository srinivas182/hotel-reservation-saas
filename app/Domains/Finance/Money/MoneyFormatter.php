<?php

declare(strict_types=1);

namespace App\Domains\Finance\Money;

use Brick\Money\Money;
use NumberFormatter;

/**
 * Formats money for people, using the locale's conventions (for example
 * "25 000 F CFA" in French, "CFA 25,000" in English).
 */
final class MoneyFormatter
{
    public static function format(Money $money, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $digits = $money->getCurrency()->getDefaultFractionDigits();
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        $formatted = $formatter->formatCurrency(
            $money->getAmount()->toFloat(),
            $money->getCurrency()->getCurrencyCode(),
        );

        return $formatted === false ? (string) $money : $formatted;
    }

    /**
     * Parse an amount typed by a person (in major units, for example "25000"
     * or "12.50") into money in the given currency.
     */
    public static function fromMajor(string|int|float $amount, string $currency): Money
    {
        return Money::of((string) $amount, $currency, roundingMode: \Brick\Math\RoundingMode::HalfUp);
    }
}
