<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Removes phone numbers, email addresses and secrets from every log line
 * and its context before it is written (logs leave the hotel's database
 * and are kept longer, so they must not carry personal data).
 */
final class RedactSensitiveData
{
    private const PATTERNS = [
        // Email addresses.
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[email]',
        // Bearer tokens, Meta tokens, provider keys.
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [secret]',
        '/\b(EAA[A-Za-z0-9]{20,}|sk_(live|test)_[A-Za-z0-9]{8,}|FLWSECK[_-][A-Za-z0-9\-]{8,}|xox[bp]-[A-Za-z0-9\-]{10,})\b/' => '[secret]',
        '/\b(token|secret|password|api_key|apikey|access_token)=([^&\s"]+)/i' => '$1=[secret]',
        // Phone numbers: + and 8 to 15 digits, possibly spaced.
        '/\+?\b\d(?:[\s.\-]?\d){7,14}\b/' => '[phone]',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                    message: self::redact($record->message),
                    context: self::redactArray($record->context),
                    extra: self::redactArray($record->extra),
                ));
            }
        }
    }

    public static function redact(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public static function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|password|api_?key|credentials|pin/i', $key) === 1) {
                $values[$key] = '[secret]';
            } elseif (is_string($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $values[$key] = self::redactArray($value);
            } elseif ($value instanceof \Throwable) {
                $values[$key] = get_class($value).': '.self::redact($value->getMessage());
            }
        }

        return $values;
    }
}
