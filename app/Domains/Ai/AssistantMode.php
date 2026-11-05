<?php

declare(strict_types=1);

namespace App\Domains\Ai;

/**
 * How the assistant works with guests at a hotel.
 */
enum AssistantMode: string
{
    case Off = 'off';
    case Supervised = 'supervised';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Off => __('Off: staff answer everything'),
            self::Supervised => __('Supervised: the assistant drafts, staff approve each reply'),
            self::Automatic => __('Automatic: the assistant replies by itself'),
        };
    }
}
