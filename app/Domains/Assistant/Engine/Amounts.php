<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

/**
 * Finds money amounts in a reply ("50 000 FCFA", "€120,50", "XOF 25.000")
 * and normalises numbers so "50 000", "50,000" and "50000.00" compare equal.
 */
final class Amounts
{
    private const CURRENCY = '(?:FCFA|F\s?CFA|CFA|XOF|EUR|€|USD|US\$|\$|INR|₹|ZAR|GBP|£|F)';

    /**
     * @return list<array{raw: string, normalised: string}>
     */
    public static function find(string $text): array
    {
        $number = '(\d{1,3}(?:[\s\x{00A0}\x{202F}.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)';
        $patterns = [
            '/'.$number.'\s?'.self::CURRENCY.'(?![A-Za-z])/u',
            '/(?<![A-Za-z])'.self::CURRENCY.'\s?'.$number.'/u',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $found[$match[0]] = ['raw' => trim($match[0]), 'normalised' => self::normalise($match[1])];
                }
            }
        }

        return array_values($found);
    }

    /**
     * Every amount written in a text, normalised and space-separated, so a
     * reply may quote amounts that appear in the information pack.
     */
    public static function normaliseText(string $text): string
    {
        return ' '.implode(' ', array_map(fn (array $a): string => $a['normalised'], self::find($text))).' ';
    }

    public static function normalise(string $number): string
    {
        $clean = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $number) ?? $number;

        // A separator followed by exactly 1-2 digits at the end is the decimal mark.
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $clean, $m) === 1) {
            $integer = preg_replace('/[.,]/', '', $m[1]) ?? $m[1];
            $decimal = rtrim($m[2], '0');

            return ltrim($integer, '0').($decimal !== '' ? '.'.$decimal : '') ?: '0';
        }

        $integer = preg_replace('/[.,]/', '', $clean) ?? $clean;

        return ltrim($integer, '0') ?: '0';
    }
}
