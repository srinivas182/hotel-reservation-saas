<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

/**
 * Checks run by code on every reply before it can reach a guest. They do
 * not depend on the AI following its instructions.
 */
final class Guardrails
{
    public const MAX_LENGTH = 1000;

    private const CONFIRMATION_PATTERNS = [
        // English
        '/\b(booking|reservation|room|stay|table)\s+(is|has been|was)\s+(now\s+)?(confirmed|booked|reserved|secured)\b/i',
        '/\border\s+(is|has been|was)\s+(now\s+)?(accepted|confirmed)\b/i',
        '/\bcommande\s+(est|a été)\s+(bien\s+)?(acceptée|confirmée|validée)\b/iu',
        '/\b(i|we)\s+(have|\'ve)\s+(confirmed|booked|reserved|secured)\b/i',
        '/\bconfirmed\s+your\s+(booking|reservation|room|stay|table)\b/i',
        '/\byou(\'re| are)\s+(all\s+)?(booked|confirmed)\b/i',
        // French
        '/\b(réservation|chambre|séjour|table)\s+(est|a été|sont|ont été)\s+(bien\s+)?(confirmée?s?|réservée?s?|validée?s?|bloquée?s?)\b/iu',
        '/\b(nous|j\')\s*(avons|ai)\s+(bien\s+)?(confirmé|réservé|validé|bloqué)\b/iu',
        '/\bc\'est\s+(bien\s+)?(réservé|confirmé)\b/iu',
        '/\bvous\s+êtes\s+(bien\s+)?(réservée?s?|confirmée?s?)\b/iu',
    ];

    private const SENSITIVE_REQUEST_PATTERNS = [
        '/\b(card number|credit card|debit card|cvv|cvc|expiry date|passport number|id (card )?number|national id)\b/i',
        '/\b(numéro de (carte|passeport|pièce|cni)|carte bancaire|cryptogramme|date d\'expiration)\b/iu',
    ];

    /**
     * @return list<array{check: string, passed: bool, detail: string|null}>
     */
    public function check(string $reply, ToolContext $context, string $knowledge): array
    {
        $results = [];

        $invented = [];
        foreach (Amounts::find($reply) as $amount) {
            $inKnowledge = str_contains(Amounts::normaliseText($knowledge), ' '.$amount['normalised'].' ');

            if (! $context->allowsAmount($amount['normalised']) && ! $inKnowledge) {
                $invented[] = $amount['raw'];
            }
        }
        $results[] = ['check' => 'amounts_from_tools', 'passed' => $invented === [], 'detail' => $invented === [] ? null : 'Amounts not returned by get_quote: '.implode(', ', $invented)];

        $confirmation = $this->firstMatch($reply, self::CONFIRMATION_PATTERNS);
        $results[] = ['check' => 'no_confirmation', 'passed' => $confirmation === null, 'detail' => $confirmation === null ? null : "Says the booking is confirmed: \"{$confirmation}\""];

        $long = mb_strlen($reply) > self::MAX_LENGTH;
        $results[] = ['check' => 'length', 'passed' => ! $long, 'detail' => $long ? 'Longer than '.self::MAX_LENGTH.' characters' : null];

        $sensitive = $this->firstMatch($reply, self::SENSITIVE_REQUEST_PATTERNS);
        $results[] = ['check' => 'no_sensitive_request', 'passed' => $sensitive === null, 'detail' => $sensitive === null ? null : "Asks for sensitive data: \"{$sensitive}\""];

        $empty = trim($reply) === '';
        $results[] = ['check' => 'not_empty', 'passed' => ! $empty, 'detail' => $empty ? 'Empty reply' : null];

        return $results;
    }

    /**
     * @param  list<array{check: string, passed: bool, detail: string|null}>  $results
     */
    public static function passed(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove card-like numbers from guest text before it is sent to an AI provider.
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\b(?:\d[ \-]?){13,19}\b/', '[card number removed]', $text);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return $match[0];
            }
        }

        return null;
    }
}
