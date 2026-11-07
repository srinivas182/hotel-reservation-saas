<?php

declare(strict_types=1);

namespace App\Domains\Billing;

/**
 * Optional modules a plan can include. Rooms, WhatsApp and the assistant
 * are always included.
 */
enum Module: string
{
    case Restaurant = 'restaurant';
    case Ordering = 'ordering';
    case Events = 'events';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => __('Restaurant & Bar'),
            self::Ordering => __('Orders and room bill charges'),
            self::Events => __('Events'),
        };
    }
}
