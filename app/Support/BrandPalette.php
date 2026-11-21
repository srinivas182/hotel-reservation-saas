<?php

declare(strict_types=1);

namespace App\Support;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Tenancy\TenantContext;

/**
 * Turns one colour into the accent tokens of the app (Sprint I): the colour
 * itself, a soft tint for backgrounds, a dark ink for text on that tint and
 * the text colour for buttons, for light and dark mode. Whatever colour a
 * hotel picks, text stays readable (WCAG AA contrast, 4.5:1).
 *
 * The colour is the hotel's back-office colour (Hotel → Settings), else the
 * platform brand colour (Platform → Website).
 */
final class BrandPalette
{
    public const DEFAULT = '#4f46e5';

    /**
     * The colour for the current request: the hotel's, else the platform's.
     */
    public static function currentColor(): string
    {
        $context = app(TenantContext::class);
        if ($context->has()) {
            $hotel = HotelSettings::current()->app_color;
            if (self::valid($hotel)) {
                return strtolower((string) $hotel);
            }
        }
        $brand = BrandSettings::cached()['color'] ?? null;

        return self::valid($brand) ? strtolower((string) $brand) : self::DEFAULT;
    }

    public static function valid(?string $hex): bool
    {
        return $hex !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /**
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public static function for(string $hex): array
    {
        $rgb = self::rgb(self::valid($hex) ? $hex : self::DEFAULT);
        $white = [255, 255, 255];
        $ivory = [246, 246, 251];
        $navy = [11, 12, 26];

        // Light mode: the brand must carry white (or dark) text and show on the page.
        $brand = $rgb;
        while (self::contrast($brand, $ivory) < 3.0 && self::luminance($brand) > 0.05) {
            $brand = self::mix($brand, [0, 0, 0], 0.1);
        }
        $onBrand = self::contrast($brand, $white) >= 4.5 ? $white : [20, 20, 43];
        $soft = self::mix($rgb, $white, 0.9);
        $ink = $rgb;
        while (self::contrast($ink, $soft) < 4.5) {
            $ink = self::mix($ink, [0, 0, 0], 0.12);
        }

        // Dark mode: a lighter brand that reads on the navy background.
        $darkBrand = $rgb;
        while (self::contrast($darkBrand, $navy) < 5.0) {
            $darkBrand = self::mix($darkBrand, $white, 0.12);
        }
        $darkSoft = self::mix($rgb, $navy, 0.78);
        $darkInk = self::mix($darkBrand, $white, 0.4);
        while (self::contrast($darkInk, $darkSoft) < 4.5) {
            $darkInk = self::mix($darkInk, $white, 0.15);
        }

        return [
            'light' => ['--brand' => self::hex($brand), '--brand-soft' => self::hex($soft), '--brand-ink' => self::hex($ink), '--primary-foreground' => self::hex($onBrand), '--sidebar-primary-foreground' => self::hex($onBrand)],
            'dark' => ['--brand' => self::hex($darkBrand), '--brand-soft' => self::hex($darkSoft), '--brand-ink' => self::hex($darkInk), '--primary-foreground' => '#0b0c1a', '--sidebar-primary-foreground' => '#0b0c1a'],
        ];
    }

    /**
     * The CSS for the page head, so the first paint is already in the right colour.
     */
    public static function css(string $hex): string
    {
        $p = self::for($hex);
        $decl = fn (array $vars): string => implode(' ', array_map(fn (string $k, string $v): string => "{$k}: {$v};", array_keys($vars), $vars));

        // html:root and html.dark win over the stylesheet's :root and .dark, whatever the order.
        return 'html:root { '.$decl($p['light']).' } html.dark { '.$decl($p['dark']).' }';
    }

    /**
     * Contrast ratio between two #rrggbb colours (1 to 21).
     */
    public static function contrastOf(string $a, string $b): float
    {
        return self::contrast(self::rgb($a), self::rgb($b));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $c
     */
    private static function hex(array $c): string
    {
        return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     * @return array{0: int, 1: int, 2: int}
     */
    private static function mix(array $a, array $b, float $amount): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $amount),
            (int) round($a[1] + ($b[1] - $a[1]) * $amount),
            (int) round($a[2] + ($b[2] - $a[2]) * $amount),
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $c
     */
    private static function luminance(array $c): float
    {
        $channel = function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($c[0]) + 0.7152 * $channel($c[1]) + 0.0722 * $channel($c[2]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    public static function contrast(array $a, array $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
