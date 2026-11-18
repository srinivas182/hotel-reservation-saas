<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use DOMDocument;
use DOMXPath;

/**
 * Turns a hotel web page into facts and text: the structured hotel data
 * (schema.org JSON-LD) when the page has it, and the readable text.
 */
final class PageReader
{
    public const MAX_TEXT = 15_000;

    private const LODGING = ['hotel', 'lodgingbusiness', 'motel', 'resort', 'bedandbreakfast', 'hostel', 'campground'];

    private const BUSINESS = ['localbusiness', 'organization'];

    /**
     * @return array{title: string|null, description: string|null, structured: array<string, mixed>, text: string}
     */
    public static function read(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $structured = [];
        foreach (self::nodes($xpath, '//script[@type="application/ld+json"]') as $node) {
            $json = json_decode(trim($node->textContent), true);
            if (is_array($json)) {
                $structured = self::better($structured, self::fromJsonLd($json));
            }
        }

        $title = trim((string) (self::nodes($xpath, '//title')[0]->textContent ?? '')) ?: null;
        $description = trim((string) (self::nodes($xpath, '//meta[@name="description" or @property="og:description"]/@content')[0]->nodeValue ?? '')) ?: null;

        foreach (['//script', '//style', '//noscript', '//svg', '//template', '//iframe'] as $query) {
            foreach (self::nodes($xpath, $query) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $body = self::nodes($xpath, '//body')[0] ?? null;
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $body === null ? '' : $body->textContent);
        $text = trim((string) preg_replace('/\s*\n\s*/', "\n", $text));

        return [
            'title' => $title === null ? null : mb_substr($title, 0, 200),
            'description' => $description === null ? null : mb_substr($description, 0, 500),
            'structured' => $structured,
            'text' => mb_substr($text, 0, self::MAX_TEXT),
        ];
    }

    /**
     * @return list<\DOMNode>
     */
    private static function nodes(DOMXPath $xpath, string $query): array
    {
        $list = $xpath->query($query);
        $nodes = [];

        foreach ($list === false ? [] : $list as $node) {
            if ($node instanceof \DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The hotel facts in one JSON-LD document (which may hold a @graph).
     *
     * @param  array<mixed>  $json
     * @return array<string, mixed>
     */
    public static function fromJsonLd(array $json): array
    {
        $nodes = array_is_list($json) ? $json : (isset($json['@graph']) && is_array($json['@graph']) ? $json['@graph'] : [$json]);
        $found = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $types = array_map(fn ($t): string => mb_strtolower((string) $t), (array) ($node['@type'] ?? []));
            $rank = array_intersect($types, self::LODGING) !== [] ? 2 : (array_intersect($types, self::BUSINESS) !== [] ? 1 : 0);

            if ($rank === 0) {
                continue;
            }

            $address = is_array($node['address'] ?? null) ? $node['address'] : [];
            $geo = is_array($node['geo'] ?? null) ? $node['geo'] : [];
            $amenities = [];
            foreach ((array) ($node['amenityFeature'] ?? []) as $amenity) {
                $name = is_array($amenity) ? ($amenity['name'] ?? null) : $amenity;
                if (is_string($name) && trim($name) !== '') {
                    $amenities[] = mb_substr(trim($name), 0, 60);
                }
            }

            $candidate = array_filter([
                'rank' => $rank,
                'name' => self::text($node['name'] ?? null, 120),
                'description' => self::text($node['description'] ?? null, 1500),
                'telephone' => self::text($node['telephone'] ?? null, 30),
                'street' => self::text($address['streetAddress'] ?? (is_string($node['address'] ?? null) ? $node['address'] : null), 200),
                'city' => self::text($address['addressLocality'] ?? null, 100),
                'latitude' => is_numeric($geo['latitude'] ?? null) ? (float) $geo['latitude'] : null,
                'longitude' => is_numeric($geo['longitude'] ?? null) ? (float) $geo['longitude'] : null,
                'check_in_time' => self::time($node['checkinTime'] ?? null),
                'check_out_time' => self::time($node['checkoutTime'] ?? null),
                'price_range' => self::text($node['priceRange'] ?? null, 60),
                'amenities' => array_slice(array_values(array_unique($amenities)), 0, 30),
            ], fn ($v): bool => $v !== null && $v !== []);

            $found = self::better($found, $candidate);
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function better(array $a, array $b): array
    {
        if ($a === []) {
            return $b;
        }

        return ((int) ($b['rank'] ?? 0)) > ((int) ($a['rank'] ?? 0)) ? $b + $a : $a + $b;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim(strip_tags($value)) === '') {
            return null;
        }

        return mb_substr(trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, $max);
    }

    /**
     * "14:00", "T14:00:00", "2 PM" => "14:00".
     */
    public static function time(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/(\d{1,2})[:hH](\d{2})/', $value, $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/\b(\d{1,2})\s*(am|pm)\b/i', $value, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            $hour = ((int) $m[1] % 12) + (mb_strtolower($m[2]) === 'pm' ? 12 : 0);

            return sprintf('%02d:00', $hour);
        }

        return null;
    }
}
