<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Models;

/**
 * Times of day as minutes since midnight ("19:30" = 1170).
 */
final class Minutes
{
    public static function of(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }

    public static function format(int $minutes): string
    {
        $minutes %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
