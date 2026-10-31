<?php

declare(strict_types=1);

namespace App\Domains\Platform\Health;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched every minute by the scheduler. When a queue worker runs it,
 * the queue heartbeat is refreshed.
 */
final class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put(HeartbeatCheck::queue()->cacheKey(), now()->getTimestamp(), 600);
    }
}
