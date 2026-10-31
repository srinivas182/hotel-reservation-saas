<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Health\CacheCheck;
use App\Domains\Platform\Health\DatabaseCheck;
use App\Domains\Platform\Health\HealthCheck;
use App\Domains\Platform\Health\HeartbeatCheck;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deep health check for monitoring: database, cache (Redis), queue worker
 * and scheduler. Protected by a token; disabled when no token is set.
 * Liveness without dependencies stays at /up.
 */
final class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = config('health.token');

        abort_unless(is_string($token) && $token !== '', 404);

        $provided = $request->header('X-Health-Token') ?? $request->query('token');
        abort_unless(is_string($provided) && hash_equals($token, $provided), 401);

        /** @var list<HealthCheck> $checks */
        $checks = [
            new DatabaseCheck,
            new CacheCheck,
            HeartbeatCheck::queue(),
            HeartbeatCheck::scheduler(),
        ];

        $results = [];
        foreach ($checks as $check) {
            $results[$check->name()] = $check->run();
        }

        $healthy = collect($results)->every(fn (array $result): bool => $result['ok']);

        return response()->json([
            'status' => $healthy ? 'ok' : 'failing',
            'checks' => $results,
            'checked_at' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
