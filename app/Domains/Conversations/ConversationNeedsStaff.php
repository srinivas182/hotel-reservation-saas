<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff should look at a conversation: it was passed to them, or a guest
 * has been waiting with no reply. Staff alerts listen to this (S7.2).
 */
final class ConversationNeedsStaff
{
    use Dispatchable;

    /**
     * @param  'handover'|'guest_waiting'  $reason
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $reason,
    ) {}
}
