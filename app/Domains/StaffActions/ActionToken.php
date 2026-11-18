<?php

declare(strict_types=1);

namespace App\Domains\StaffActions;

use Carbon\CarbonInterface;

/**
 * The signed code behind a WhatsApp button for staff (Sprint B):
 * "sa1.<action>.<subject>.<user>.<expires>.<signature>".
 *
 * The signature covers the hotel, so a code cannot be replayed in
 * another hotel; it names one person, one subject and one action, and
 * stops working when it expires. It fits Meta's 128-character limit for
 * template button payloads.
 */
final class ActionToken
{
    public const PREFIX = 'sa1.';

    public function __construct(
        public readonly string $action,
        public readonly string $subject,
        public readonly string $userId,
        public readonly int $expires,
    ) {}

    public static function make(string $hotelId, string $action, string $subject, string $userId, CarbonInterface $expires): string
    {
        $body = implode('.', [rtrim(self::PREFIX, '.'), $action, $subject, $userId, $expires->getTimestamp()]);

        return $body.'.'.self::sign($hotelId, $body);
    }

    public static function looksLikeOne(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    /**
     * The token if its signature is right for this hotel (expired or not:
     * the caller says so to the person), otherwise null.
     */
    public static function parse(string $hotelId, string $payload): ?self
    {
        $parts = explode('.', $payload);

        if (count($parts) !== 6 || $parts[0] !== rtrim(self::PREFIX, '.') || ! ctype_digit($parts[4])) {
            return null;
        }

        $body = implode('.', array_slice($parts, 0, 5));

        if (! hash_equals(self::sign($hotelId, $body), $parts[5])) {
            return null;
        }

        return new self($parts[1], $parts[2], $parts[3], (int) $parts[4]);
    }

    public function expired(): bool
    {
        return $this->expires < now()->getTimestamp();
    }

    /**
     * The handler prefix of the action, e.g. "br" for "br_accept".
     */
    public function prefix(): string
    {
        return explode('_', $this->action, 2)[0];
    }

    private static function sign(string $hotelId, string $body): string
    {
        $key = (string) config('app.key');
        $mac = hash_hmac('sha256', $hotelId.'|'.$body, 'staff-actions|'.$key, true);

        return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 22);
    }
}
