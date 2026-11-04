<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Ai\Models\HotelAssistantSettings;

/**
 * Wording of the messages that tell a guest what happened to their
 * request. Hotels can replace each text per language; placeholders in
 * braces are filled in when sending.
 */
final class DecisionMessages
{
    public const KINDS = ['confirmed', 'deposit', 'declined', 'expired'];

    public const PLACEHOLDERS = ['{name}', '{reference}', '{room}', '{check_in}', '{check_out}', '{total}', '{deposit}', '{deposit_deadline}', '{payment_instructions}', '{hotel}'];

    public static function default(string $kind, string $locale): string
    {
        $text = match ($kind) {
            'confirmed' => 'Hello {name}, your booking {reference} is confirmed: {room}, from {check_in} to {check_out}. Total: {total}. See you soon at {hotel}!',
            'deposit' => 'Hello {name}, {hotel} has accepted your request ({room}, from {check_in} to {check_out}, total {total}). To confirm booking {reference}, please pay a deposit of {deposit} before {deposit_deadline}. How to pay: {payment_instructions}',
            'declined' => 'Hello {name}, we are sorry: {hotel} cannot accept your request from {check_in} to {check_out}. Do not hesitate to write to us for other dates.',
            default => 'Hello {name}, thank you for your request from {check_in} to {check_out}. Our reception will contact you very soon to finalise it.',
        };

        return __($text, locale: $locale);
    }

    public static function text(HotelAssistantSettings $settings, string $kind, string $locale): string
    {
        $custom = $settings->decision_messages[$locale][$kind] ?? null;

        return is_string($custom) && trim($custom) !== '' ? $custom : self::default($kind, $locale);
    }

    /**
     * @param  array<string, string>  $values  placeholder => value
     */
    public static function fill(string $text, array $values): string
    {
        return strtr($text, $values);
    }
}
