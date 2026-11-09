<?php

declare(strict_types=1);

namespace App\Domains\Sales;

/**
 * G: what the assistant is worth to a hotel, in plain numbers. Deliberately
 * simple and transparent: every input is shown next to the result.
 *
 * Bookings recovered a month = WhatsApp enquiries a day × share missed today
 * (nights, busy desk, slow replies) × share that would have booked × 30.
 * Revenue = bookings recovered × average nights × average price per night.
 */
final class Roi
{
    public const DEFAULTS = ['enquiries_per_day' => 10, 'missed_percent' => 30, 'conversion_percent' => 25, 'average_nights' => 2, 'night_price' => 25000];

    /**
     * @param  array<string, int|float>  $in
     * @return array{bookings: float, revenue: int, cost: int, net: int, multiple: float|null, payback_days: int|null}
     */
    public static function compute(array $in, int $monthlyCost): array
    {
        $in = [...self::DEFAULTS, ...$in];
        $bookings = round((float) $in['enquiries_per_day'] * ((float) $in['missed_percent'] / 100) * ((float) $in['conversion_percent'] / 100) * 30, 1);
        $revenue = (int) round($bookings * (float) $in['average_nights'] * (float) $in['night_price']);
        $daily = $revenue / 30;

        return [
            'bookings' => $bookings,
            'revenue' => $revenue,
            'cost' => $monthlyCost,
            'net' => $revenue - $monthlyCost,
            'multiple' => $monthlyCost > 0 ? round($revenue / $monthlyCost, 1) : null,
            'payback_days' => $daily > 0 ? (int) max(1, ceil($monthlyCost / $daily)) : null,
        ];
    }
}
