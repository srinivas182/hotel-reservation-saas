<?php

declare(strict_types=1);

namespace App\Domains\Arrivals\Jobs;

use App\Domains\Arrivals\ArrivalReplies;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A guest tapped a button of the arrival card (Sprint G). When the tap
 * does not belong to this guest, the assistant answers as for any message.
 */
final class HandleArrivalReply implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public int $timeout = 60;

    public function __construct(public readonly string $messageId, public readonly string $payload) {}

    public function handle(ArrivalReplies $replies): void
    {
        $message = Message::query()->with('conversation.contact')->find($this->messageId);

        if ($message === null || $message->conversation === null) {
            return;
        }

        if (! $replies->handle($message->conversation, $this->payload)) {
            ReplyToGuest::schedule($message);
        }
    }
}
