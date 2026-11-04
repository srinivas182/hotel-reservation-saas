<?php

declare(strict_types=1);

namespace App\Domains\Assistant;

/**
 * A light language guess from common words, for the languages the platform
 * supports. Returns null when unsure; the assistant's own judgement is used
 * for replies (S6).
 */
final class LanguageDetector
{
    private const WORDS = [
        'fr' => ['bonjour', 'bonsoir', 'merci', 'je', 'vous', 'nous', 'est', 'une', 'pour', 'chambre', 'avec', 'les', 'des', 'pas', 'combien', 'oui', 'non', 'nuit', 'nuits', 'réservation', 'disponible', 'svp', 'stp', 'mon', 'votre', 'c\'est', 'quel', 'quelle', 'demain', 'ce', 'du', 'au'],
        'en' => ['hello', 'hi', 'thanks', 'thank', 'you', 'the', 'is', 'room', 'for', 'with', 'do', 'have', 'how', 'much', 'night', 'nights', 'booking', 'available', 'please', 'my', 'your', 'what', 'tomorrow', 'we', 'are', 'can', 'i', 'and', 'yes', 'no'],
    ];

    public function detect(?string $text): ?string
    {
        $words = preg_split('/[^\p{L}\']+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return null;
        }

        $scores = [];
        foreach (self::WORDS as $language => $list) {
            $scores[$language] = count(array_intersect($words, $list));
        }

        arsort($scores);
        $best = array_key_first($scores);
        $values = array_values($scores);

        if ($values[0] === 0 || $values[0] === $values[1]) {
            return null;
        }

        return $best;
    }
}
