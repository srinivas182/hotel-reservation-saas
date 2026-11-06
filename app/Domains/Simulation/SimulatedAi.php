<?php

declare(strict_types=1);

namespace App\Domains\Simulation;

use RuntimeException;

/**
 * Plausible assistant answers without a real AI provider: realistic delay,
 * optional failures, and replies with no prices (prices need tools).
 */
final class SimulatedAi
{
    /**
     * @return array<string, mixed>
     */
    public static function assistantReply(string $prompt): array
    {
        self::wait('ai');

        $text = mb_strtolower($prompt);
        $english = preg_match('/\b(hello|hi|room|price|book|please)\b/', $text) === 1;

        [$intent, $fr, $en] = match (true) {
            preg_match('/inadmissible|plainte|problème|broken|complain/u', $text) === 1 => ['complaint', 'Je suis désolé. Je transmets votre message à la réception.', 'I am sorry. I am passing your message to reception.'],
            preg_match('/réserv|book/u', $text) === 1 => ['booking_request', 'Avec plaisir ! Pour quelles dates, combien de personnes, et à quel nom ?', 'With pleasure! For which dates, how many guests, and in what name?'],
            preg_match('/prix|combien|tarif|price|cost/u', $text) === 1 => ['price_question', 'Pour vous donner le prix exact, pouvez-vous m’indiquer vos dates d’arrivée et de départ ?', 'To give you the exact price, could you tell me your arrival and departure dates?'],
            default => ['greeting', 'Bonjour et bienvenue ! Comment puis-je vous aider ?', 'Hello and welcome! How can I help you?'],
        };

        return [
            'reply' => $english ? $en : $fr,
            'language' => $english ? 'en' : 'fr',
            'intent' => $intent,
            'handover' => false,
            'handover_reason' => '',
            'confidence' => 0.9,
        ];
    }

    public static function translation(string $prompt): string
    {
        self::wait('ai');

        return '[simulated translation] '.$prompt;
    }

    /**
     * Sleep for a random time in the configured range, and fail at the
     * configured rate.
     */
    public static function wait(string $service): void
    {
        [$min, $max] = config("simulation.{$service}.latency_ms");
        usleep(random_int((int) $min, max((int) $min, (int) $max)) * 1000);

        if (mt_rand() / mt_getrandmax() < (float) config("simulation.{$service}.failure_rate")) {
            throw new RuntimeException("Simulated {$service} outage.");
        }
    }
}
