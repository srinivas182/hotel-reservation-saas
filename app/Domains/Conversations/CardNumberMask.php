<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

/**
 * Card details never reach the database (0.38.0): a guest who types a
 * card number, its expiry or security code in WhatsApp has them masked
 * before the message is stored, shown to staff or sent to the AI. Only the
 * last four digits stay, so staff can still recognise the card.
 */
final class CardNumberMask
{
    public static function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $found = false;

        // 13 to 19 digits, possibly in groups separated by spaces, dots or dashes.
        $text = (string) preg_replace_callback('/(?<!\d)(?:\d[ .\-]?){12,18}\d(?!\d)/u', function (array $m) use (&$found): string {
            $digits = (string) preg_replace('/\D/', '', $m[0]);

            if (! self::luhn($digits)) {
                return $m[0];
            }
            $found = true;

            return '•••• •••• •••• '.substr($digits, -4);
        }, $text);

        // 0.39.3: next to a card number, a plain "code" or "the code is 123"
        // is the security code too (a spoken voice note, TC124).
        if ($found) {
            $text = (string) preg_replace('/\b(code|pin)\b((?:\s+\w+){0,2}?\s*[:=#\-]?\s*)\d{3,4}\b/iu', '$1$2•••', $text);
        }

        // The security code after its usual names: CVV, CVC, CID, cryptogramme, code de sécurité.
        $text = (string) preg_replace('/\b(cvv2?|cvc2?|cid|csc|crypto(?:gramme)?|code\s+de\s+s[ée]curit[ée]|security\s+code)\b(\s*[:=#\-]?\s*)\d{3,4}\b/iu', '$1$2•••', $text);

        // The expiry date next to "exp", "expiry", "expiration", "valid".
        return (string) preg_replace('/\b(exp(?:iry|iration|\.)?|valid(?:e)?(?:\s+(?:thru|until|jusqu\'?au))?|date\s+d\'expiration)(\s*[:=\-]?\s*)(0?[1-9]|1[0-2])\s*[\/\-.]\s*(\d{2}|\d{4})\b/iu', '$1$2••/••', $text);
    }

    private static function luhn(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $d = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }
}
