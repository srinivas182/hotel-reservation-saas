<?php

declare(strict_types=1);

namespace App\Domains\Hotel\Support;

/**
 * Languages the platform supports. Guest-facing languages grow with the
 * S5 translation workflow; the staff interface ships English and French.
 */
final class Locales
{
    /** @var array<string, string> */
    public const SUPPORTED = [
        'en' => 'English',
        'fr' => 'Français',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }
}
