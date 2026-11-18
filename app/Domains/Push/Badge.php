<?php

declare(strict_types=1);

namespace App\Domains\Push;

use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\Conversations\Models\Conversation;

/**
 * The number on the app icon (Sprint D): WhatsApp requests waiting and
 * conversations that need staff.
 */
final class Badge
{
    public static function count(): int
    {
        return BookingRequest::query()->where('status', BookingRequestStatus::Pending->value)->where('expires_at', '>', now())->count()
            + Conversation::query()->where('needs_attention', true)->count();
    }
}
