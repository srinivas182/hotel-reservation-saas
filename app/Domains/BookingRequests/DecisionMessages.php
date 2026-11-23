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

    public const PLACEHOLDERS = ['{name}', '{reference}', '{room}', '{check_in}', '{check_out}', '{total}', '{deposit}', '{deposit_deadline}', '{payment_instructions}', '{hotel}', '{pay_now}'];

    public static function default(string $kind, string $locale): string
    {
        $text = match ($kind) {
            // 0.36.4: laid out for WhatsApp: short paragraphs, the stay on separate lines, key facts in bold.
            'confirmed' => "Hello {name},\n\nYour booking *{reference}* at {hotel} is confirmed.\n\n• Room: {room}\n• Arrival: {check_in}\n• Departure: {check_out}\n• Total: *{total}*\n\nSee you soon!",
            'deposit' => "Hello {name},\n\n{hotel} has accepted your request.\n\n• Room: {room}\n• Arrival: {check_in}\n• Departure: {check_out}\n• Total: *{total}*\n\nTo confirm booking *{reference}*, please pay a deposit of *{deposit}* before {deposit_deadline}.\n\nHow to pay: {payment_instructions}",
            'declined' => "Hello {name},\n\nWe are sorry: {hotel} cannot accept your request from {check_in} to {check_out}.\n\nDo not hesitate to write to us for other dates.",
            default => "Hello {name},\n\nThank you for your request from {check_in} to {check_out}.\n\nOur reception will contact you very soon to finalise it.",
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
        $filled = strtr($text, $values);
        $payNow = trim($values['{pay_now}'] ?? '');

        // 0.31: the pay-now / pay-at-the-hotel choice is added to texts that do not place it.
        if ($payNow !== '' && ! str_contains($text, '{pay_now}')) {
            $filled = rtrim($filled)."\n\n".$payNow;
        }

        return $filled;
    }
}
