<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use App\Domains\Assistant\Knowledge\KnowledgeSection;

/**
 * The shape of an import draft, built from the AI answer, the page's
 * structured data and the Maps pin. Everything is cleaned and capped here,
 * so the review screen and ListingApplier can trust it.
 *
 * {
 *   profile: {name|address_line1|city|check_in_time|check_out_time|location: {value, sure}},
 *   rooms: [{name, count, adults, price, sure}],
 *   entries: [{locale, section, question, content, sure}],
 *   notes: [string]
 * }
 */
final class ListingDraft
{
    public const PROFILE = ['name', 'address_line1', 'city', 'check_in_time', 'check_out_time', 'location'];

    private const MAX_ROOMS = 20;

    private const MAX_ENTRIES = 60;

    /**
     * @param  array<string, mixed>  $structured  from PageReader
     * @param  array{latitude: float, longitude: float, name: string|null}|null  $pin
     * @param  list<string>  $notes
     * @return array{profile: array<string, array{value: mixed, sure: bool}>, rooms: list<array{name: string, count: int|null, adults: int, price: int|null, sure: bool}>, entries: list<array{locale: string, section: string, question: string|null, content: string, sure: bool}>, notes: list<string>}
     */
    public static function build(?string $aiText, array $structured, ?array $pin, array $notes = []): array
    {
        $ai = self::decode($aiText);
        $profile = [];

        foreach (['name', 'address_line1', 'city', 'check_in_time', 'check_out_time'] as $key) {
            $item = $ai['profile'][$key] ?? null;
            $value = is_array($item) ? ($item['value'] ?? null) : $item;
            $value = self::clean($key, $value);

            if ($value !== null) {
                $profile[$key] = ['value' => $value, 'sure' => ! is_array($item) || ($item['sure'] ?? true) !== false];
            }
        }

        // The website's own structured data and the Maps pin are what the hotel published itself.
        $own = [
            'name' => $structured['name'] ?? $pin['name'] ?? null,
            'address_line1' => $structured['street'] ?? null,
            'city' => $structured['city'] ?? null,
            'check_in_time' => $structured['check_in_time'] ?? null,
            'check_out_time' => $structured['check_out_time'] ?? null,
        ];
        foreach ($own as $key => $value) {
            $value = self::clean($key, $value);
            if ($value !== null) {
                $profile[$key] = ['value' => $value, 'sure' => true];
            }
        }

        $lat = $pin['latitude'] ?? $structured['latitude'] ?? null;
        $lng = $pin['longitude'] ?? $structured['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $profile['location'] = ['value' => ['latitude' => round((float) $lat, 7), 'longitude' => round((float) $lng, 7)], 'sure' => $pin !== null];
        }

        $ordered = [];
        foreach (self::PROFILE as $key) {
            if (isset($profile[$key])) {
                $ordered[$key] = $profile[$key];
            }
        }

        return [
            'profile' => $ordered,
            'rooms' => self::rooms($ai['rooms'] ?? []),
            'entries' => self::entries($ai['entries'] ?? []),
            'notes' => array_values(array_unique(array_map(fn (string $n): string => mb_substr($n, 0, 300), $notes))),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public static function isEmpty(array $draft): bool
    {
        return ($draft['profile'] ?? []) === [] && ($draft['rooms'] ?? []) === [] && ($draft['entries'] ?? []) === [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $text = trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $json = json_decode($text, true);

        // Some models add a sentence around the JSON: keep the outermost object.
        if (! is_array($json) && preg_match('/\{.*\}/s', $text, $m) === 1) {
            $json = json_decode($m[0], true);
        }

        return is_array($json) ? $json : [];
    }

    private static function clean(string $key, mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        if (in_array($key, ['check_in_time', 'check_out_time'], true)) {
            return PageReader::time($value);
        }

        $max = ['name' => 120, 'address_line1' => 200, 'city' => 100][$key] ?? 200;

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return list<array{name: string, count: int|null, adults: int, price: int|null, sure: bool}>
     */
    private static function rooms(mixed $rooms): array
    {
        $out = [];

        foreach (is_array($rooms) ? $rooms : [] as $room) {
            if (! is_array($room)) {
                continue;
            }

            $name = trim(strip_tags((string) ($room['name'] ?? '')));
            if ($name === '' || count($out) >= self::MAX_ROOMS) {
                continue;
            }

            $count = is_numeric($room['count'] ?? null) ? (int) $room['count'] : null;
            $price = is_numeric(str_replace([' ', "\u{00A0}", "\u{202F}", ','], '', (string) ($room['price'] ?? ''))) ? (int) round((float) str_replace([' ', "\u{00A0}", "\u{202F}", ','], '', (string) $room['price'])) : null;

            $out[] = [
                'name' => mb_substr($name, 0, 60),
                'count' => $count !== null && $count >= 1 && $count <= 500 ? $count : null,
                'adults' => max(1, min(10, is_numeric($room['adults'] ?? null) ? (int) $room['adults'] : 2)),
                'price' => $price !== null && $price > 0 && $price < 100_000_000 ? $price : null,
                'sure' => ($room['sure'] ?? true) !== false,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{locale: string, section: string, question: string|null, content: string, sure: bool}>
     */
    private static function entries(mixed $entries): array
    {
        $out = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || count($out) >= self::MAX_ENTRIES) {
                continue;
            }

            $section = KnowledgeSection::tryFrom((string) ($entry['section'] ?? ''));
            $locale = (string) ($entry['locale'] ?? '');
            $content = trim(strip_tags((string) ($entry['content'] ?? '')));

            if ($section === null || ! in_array($locale, ['fr', 'en'], true) || $content === '') {
                continue;
            }

            $question = isset($entry['question']) && is_string($entry['question']) && trim($entry['question']) !== '' ? mb_substr(trim(strip_tags($entry['question'])), 0, 255) : null;

            $out[] = ['locale' => $locale, 'section' => $section->value, 'question' => $question, 'content' => mb_substr($content, 0, 4000), 'sure' => ($entry['sure'] ?? true) !== false];
        }

        return $out;
    }
}
