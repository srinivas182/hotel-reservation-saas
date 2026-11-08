<?php

declare(strict_types=1);

namespace App\Domains\Ai\Gateway;

use Illuminate\Support\Facades\Cache;

/**
 * Skips an AI model for a minute after it fails 3 times within 2 minutes,
 * so guests don't wait for a provider that is down: the next model in the
 * list answers straight away. Shared by all hotels (providers are shared).
 */
final class CircuitBreaker
{
    public const FAILURES = 3;

    public const WINDOW_SECONDS = 120;

    public const OPEN_SECONDS = 60;

    public function isOpen(int $modelId): bool
    {
        return Cache::has("ai:circuit:open:{$modelId}");
    }

    public function failure(int $modelId): void
    {
        $key = "ai:circuit:failures:{$modelId}";
        Cache::add($key, 0, self::WINDOW_SECONDS);
        $failures = (int) Cache::increment($key);

        if ($failures >= self::FAILURES) {
            Cache::put("ai:circuit:open:{$modelId}", true, self::OPEN_SECONDS);
            Cache::forget($key);
        }
    }

    public function success(int $modelId): void
    {
        Cache::forget("ai:circuit:failures:{$modelId}");
        Cache::forget("ai:circuit:open:{$modelId}");
    }
}
