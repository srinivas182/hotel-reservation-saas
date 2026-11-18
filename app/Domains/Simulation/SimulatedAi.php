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
     * A set-up import read from a typical West African hotel's material:
     * three room types with FCFA prices, times, and pack entries in both
     * languages. One price is marked "check this", as real readings are.
     */
    public static function listing(string $prompt): string
    {
        self::wait('ai');

        $name = preg_match('/Place name: (.+)/', $prompt, $m) === 1 ? trim($m[1]) : null;

        return (string) json_encode([
            'profile' => array_filter([
                'name' => $name === null ? null : ['value' => $name, 'sure' => true],
                'address_line1' => ['value' => 'Quartier Résidentiel, près de la Grande Mosquée', 'sure' => true],
                'city' => ['value' => 'Korhogo', 'sure' => true],
                'check_in_time' => ['value' => '13:00', 'sure' => true],
                'check_out_time' => ['value' => '11:00', 'sure' => false],
            ]),
            'rooms' => [
                ['name' => 'Standard', 'count' => 12, 'adults' => 2, 'price' => 20000, 'sure' => true],
                ['name' => 'Supérieure', 'count' => 6, 'adults' => 2, 'price' => 30000, 'sure' => true],
                ['name' => 'Suite', 'count' => 2, 'adults' => 3, 'price' => 50000, 'sure' => false],
            ],
            'entries' => [
                ['locale' => 'fr', 'section' => 'restaurant', 'question' => 'Le petit-déjeuner est-il inclus ?', 'content' => 'Le petit-déjeuner est servi de 6 h 30 à 10 h, au prix de 3 000 FCFA par personne.', 'sure' => true],
                ['locale' => 'en', 'section' => 'restaurant', 'question' => 'Is breakfast included?', 'content' => 'Breakfast is served from 6:30 to 10:00 and costs 3,000 FCFA per person.', 'sure' => true],
                ['locale' => 'fr', 'section' => 'payments', 'question' => 'Comment puis-je payer ?', 'content' => 'Espèces, Orange Money, MTN MoMo et Wave.', 'sure' => true],
                ['locale' => 'en', 'section' => 'payments', 'question' => 'How can I pay?', 'content' => 'Cash, Orange Money, MTN MoMo and Wave.', 'sure' => true],
                ['locale' => 'fr', 'section' => 'parking', 'question' => 'Y a-t-il un parking ?', 'content' => 'Parking gratuit et gardé dans la cour.', 'sure' => true],
                ['locale' => 'en', 'section' => 'parking', 'question' => 'Is there parking?', 'content' => 'Free, guarded parking in the courtyard.', 'sure' => true],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * An information pack drafted from the questionnaire, on staging.
     */
    public static function packDraft(string $prompt): string
    {
        self::wait('ai');

        return (string) json_encode(['entries' => [
            ['locale' => 'fr', 'section' => 'about', 'question' => null, 'content' => 'Hôtel calme et accueillant, à deux pas du centre-ville.'],
            ['locale' => 'en', 'section' => 'about', 'question' => null, 'content' => 'A quiet, welcoming hotel a short walk from the town centre.'],
        ]], JSON_UNESCAPED_UNICODE);
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
