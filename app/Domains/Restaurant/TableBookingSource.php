<?php

declare(strict_types=1);

namespace App\Domains\Restaurant;

enum TableBookingSource: string
{
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';
    case WalkIn = 'walk_in';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => __('WhatsApp'),
            self::Phone => __('Phone'),
            self::WalkIn => __('Walk-in'),
        };
    }
}
