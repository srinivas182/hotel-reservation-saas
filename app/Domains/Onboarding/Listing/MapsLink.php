<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

/**
 * Reads the place name and map pin out of a Google Maps link, without
 * calling any Google service (no key, no cost, nothing stored from Google
 * beyond what the owner confirms).
 *
 * Long links carry the pin in the URL; short links (maps.app.goo.gl) are
 * expanded by ListingImporter with SafeFetcher first.
 */
final class MapsLink
{
    /** Hosts a Google Maps link can be on. */
    private const HOST = '/^(?:(?:www|maps)\.)?google\.[a-z.]{2,6}$|^maps\.app\.goo\.gl$|^goo\.gl$/i';

    public static function isMapsLink(string $url): bool
    {
        $host = (string) parse_url(trim($url), PHP_URL_HOST);

        return $host !== '' && preg_match(self::HOST, $host) === 1;
    }

    public static function isShortLink(string $url): bool
    {
        $host = mb_strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return in_array($host, ['maps.app.goo.gl', 'goo.gl'], true);
    }

    /**
     * @return array{latitude: float, longitude: float, name: string|null}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);

        if (! self::isMapsLink($url) || self::isShortLink($url)) {
            return null;
        }

        $decoded = rawurldecode($url);

        // In order of precision: the place's own pin, then the query, then the map centre.
        $patterns = [
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',
            '/[?&](?:q|query|ll|destination|center)=(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/',
            '/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded, $m) === 1) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
                    continue;
                }

                return ['latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'name' => self::name($url)];
            }
        }

        return null;
    }

    /**
     * The place name in /maps/place/<name>/..., if any.
     */
    public static function name(string $url): ?string
    {
        if (preg_match('#/maps/place/([^/@?]+)#', $url, $m) !== 1) {
            return null;
        }

        $name = trim(str_replace('+', ' ', rawurldecode($m[1])));

        return $name === '' || preg_match('/^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$/', $name) === 1 ? null : mb_substr($name, 0, 120);
    }

    /**
     * A clean link to the pin, to store as the hotel's map link.
     */
    public static function pinUrl(float $latitude, float $longitude): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.$latitude.','.$longitude;
    }
}
