<?php

declare(strict_types=1);

namespace App\Domains\Platform\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Checks that a background process (scheduler or queue worker) has written
 * its heartbeat recently. Heartbeats are written every minute.
 */
final class HeartbeatCheck implements HealthCheck
{
    public const MAX_AGE_SECONDS = 180;

    public function __construct(
        private readonly string $name,
        private readonly string $cacheKey,
    ) {}

    public static function scheduler(): self
    {
        return new self('scheduler', 'health:heartbeat:scheduler');
    }

    public static function queue(): self
    {
        return new self('queue', 'health:heartbeat:queue');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function cacheKey(): string
    {
        return $this->cacheKey;
    }

    public function run(): array
    {
        try {
            $timestamp = Cache::get($this->cacheKey);
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'Heartbeat could not be read.'];
        }

        if (! is_int($timestamp)) {
            return ['ok' => false, 'detail' => 'No heartbeat recorded yet.'];
        }

        $age = Carbon::now()->getTimestamp() - $timestamp;

        return $age <= self::MAX_AGE_SECONDS
            ? ['ok' => true]
            : ['ok' => false, 'detail' => "Last heartbeat {$age} seconds ago."];
    }
}
