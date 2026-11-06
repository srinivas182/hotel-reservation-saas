<?php

declare(strict_types=1);

namespace App\Domains\Orders;

enum OrderSource: string
{
    case WhatsApp = 'whatsapp';
    case Phone = 'phone';
    case Counter = 'counter';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => __('WhatsApp'),
            self::Phone => __('Phone'),
            self::Counter => __('At the counter'),
        };
    }
}
