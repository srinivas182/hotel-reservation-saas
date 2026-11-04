<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

/**
 * Who answers a conversation.
 */
enum ConversationMode: string
{
    case Assistant = 'assistant';
    case Staff = 'staff';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Assistant => __('Assistant answers'),
            self::Staff => __('Staff answer'),
            self::Paused => __('Paused'),
        };
    }
}
