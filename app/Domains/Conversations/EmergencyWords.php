<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use Illuminate\Support\Str;

/**
 * 0.39.3: a guest message about a medical emergency, a fire or danger is
 * always flagged for staff, even when the assistant did not mark it as an
 * emergency (TC75: a guest wrote that their father had chest pain; the
 * reply was right but staff were not alerted).
 */
final class EmergencyWords
{
    /** English and French, accents removed. */
    private const PATTERNS = [
        'ambulances?', 'emergency(?! exits?)', 'urgences?', 'chest pain', 'douleurs? (a|dans) la poitrine',
        'can(no|\'?)t breathe', 'not breathing', 'ne respire (pas|plus)', 'respire mal', 'unconscious', 'inconsciente?', 'evanoui\w*',
        'heart attack', 'crise cardiaque', 'malaise', 'bleeding', 'saigne\w*', 'on fire', 'there is a fire', 'incendie', 'au feu',
        'call the police', 'appelez la police', 'assaulted', 'agresse\w*', 'call a doctor', 'appelez un medecin', 'au secours',
    ];

    public static function matches(?string $text): bool
    {
        $folded = ' '.strtolower(Str::ascii((string) $text)).' ';

        foreach (self::PATTERNS as $pattern) {
            if (preg_match('/\b'.$pattern.'\b/', $folded) === 1) {
                return true;
            }
        }

        return false;
    }
}
