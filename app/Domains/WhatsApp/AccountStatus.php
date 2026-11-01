<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp;

enum AccountStatus: string
{
    case Connected = 'connected';
    case ReconnectRequired = 'reconnect_required';
    case Restricted = 'restricted';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => __('Connected'),
            self::ReconnectRequired => __('Reconnect needed'),
            self::Restricted => __('Restricted by Meta'),
            self::Disconnected => __('Disconnected'),
        };
    }

    public function canSend(): bool
    {
        return $this === self::Connected;
    }
}
