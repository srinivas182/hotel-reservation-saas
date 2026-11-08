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

    /** Retry policy (S16): attempts before the job is marked failed. */
    public int $tries = 1;

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 30;

    public function handle(): void
    {
        Cache::put(HeartbeatCheck::queue()->cacheKey(), now()->getTimestamp(), 600);
    }
}
