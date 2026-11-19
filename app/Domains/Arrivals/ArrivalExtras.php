<?php

declare(strict_types=1);

namespace App\Domains\Arrivals;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Finance\Money\MoneyFormatter;
use Brick\Money\Money;

/**
 * What a guest can ask for before arriving (Sprint G): a pickup at the
 * bus station or airport, an early check-in, an extra bed. The hotel turns
 * each on and may give a price.
 */
final class ArrivalExtras
{
    public const KEYS = ['pickup', 'early_checkin', 'extra_bed'];

    public static function label(string $key, ?string $locale = null): string
    {
        return match ($key) {
            'pickup' => (string) __('Pickup (station, airport)', locale: $locale),
            'early_checkin' => (string) __('Early check-in', locale: $locale),
            'extra_bed' => (string) __('Extra bed', locale: $locale),
            default => $key,
        };
    }

    /** The button title, 20 characters at most. */
    public static function button(string $key, ?string $locale = null): string
    {
        return mb_substr(match ($key) {
            'pickup' => (string) __('Pickup', locale: $locale),
            'early_checkin' => (string) __('Arrive early', locale: $locale),
            'extra_bed' => (string) __('Extra bed', locale: $locale),
            default => $key,
        }, 0, 20);
    }

    /**
     * The extras this hotel offers, with their price when set.
     *
     * @return array<string, int|null> key => price in minor units
     */
    public static function offered(HotelAssistantSettings $settings): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $choice = $settings->arrival_extras[$key] ?? null;
            if (is_array($choice) && $choice['enabled']) {
                $out[$key] = isset($choice['price_minor']) ? (int) $choice['price_minor'] : null;
            }
        }

        return $out;
    }

    public static function priced(string $key, ?int $price, string $currency, string $locale): string
    {
        return self::label($key, $locale).($price === null ? '' : ' ('.MoneyFormatter::format(Money::ofMinor($price, $currency), $locale).')');
    }
}
