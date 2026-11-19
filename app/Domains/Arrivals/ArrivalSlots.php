<?php

declare(strict_types=1);

namespace App\Domains\Arrivals;

/**
 * The arrival times a guest can choose (Sprint G), as WhatsApp list rows.
 */
final class ArrivalSlots
{
    /** slot => [from, to) in hours, hotel time. */
    public const SLOTS = [
        'morning' => [0, 12],
        'midday' => [12, 15],
        'afternoon' => [15, 18],
        'evening' => [18, 21],
        'night' => [21, 24],
    ];

    public static function label(string $slot, ?string $locale = null): string
    {
        return match ($slot) {
            'morning' => (string) __('Before 12:00', locale: $locale),
            'midday' => (string) __('12:00 – 15:00', locale: $locale),
            'afternoon' => (string) __('15:00 – 18:00', locale: $locale),
            'evening' => (string) __('18:00 – 21:00', locale: $locale),
            'night' => (string) __('After 21:00', locale: $locale),
            default => $slot,
        };
    }

    /** Does a guest arriving in this slot come after reception closes (HH:MM)? */
    public static function afterClosing(string $slot, ?string $closesAt): bool
    {
        if ($closesAt === null || preg_match('/^(\d{2}):(\d{2})$/', $closesAt, $m) !== 1 || ! isset(self::SLOTS[$slot])) {
            return false;
        }

        $closes = (int) $m[1] + ((int) $m[2]) / 60;

        return self::SLOTS[$slot][1] > $closes;
    }
}
