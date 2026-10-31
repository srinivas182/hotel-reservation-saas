<?php

declare(strict_types=1);

namespace App\Domains\Platform\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes and reads a value through the default cache store (Redis in
 * deployed environments), so a stopped Redis reports as unhealthy.
 */
final class CacheCheck implements HealthCheck
{
    public function name(): string
    {
        return 'cache';
    }

    public function run(): array
    {
        try {
            $value = Str::random(8);
            Cache::put('health:cache-probe', $value, 10);

            return Cache::get('health:cache-probe') === $value
                ? ['ok' => true]
                : ['ok' => false, 'detail' => 'Cache returned an unexpected value.'];
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'Cache store is unreachable.'];
        }
    }
}
