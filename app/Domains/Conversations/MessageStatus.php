<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

enum MessageStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Statuses only move forward: a late "delivered" never replaces "read".
     */
    public function rank(): int
    {
        return match ($this) {
            self::Received, self::Queued => 0,
            self::Accepted => 1,
            self::Sent => 2,
            self::Delivered => 3,
            self::Read => 4,
            self::Failed => 5,
        };
    }

    public function canMoveTo(self $next): bool
    {
        if ($this === self::Failed) {
            return false;
        }

        // Meta can report a failure after "sent", but not after delivery.
        if ($next === self::Failed) {
            return $this->rank() < self::Delivered->rank();
        }

        return $next->rank() > $this->rank();
    }
}
