<?php

declare(strict_types=1);

use App\Domains\Platform\Health\HeartbeatCheck;
use App\Domains\Platform\Health\QueueHeartbeatJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config(['health.token' => 'test-health-token']);
});

function writeHeartbeats(): void
{
    Cache::put(HeartbeatCheck::scheduler()->cacheKey(), now()->getTimestamp(), 600);
    (new QueueHeartbeatJob)->handle();
}

it('is disabled when no token is configured', function (): void {
    config(['health.token' => null]);

    $this->get('/health')->assertNotFound();
});

it('requires the token', function (): void {
    $this->get('/health')->assertUnauthorized();
    $this->get('/health?token=wrong')->assertUnauthorized();
});

it('reports healthy when all checks pass', function (): void {
    writeHeartbeats();

    $this->withHeader('X-Health-Token', 'test-health-token')
        ->get('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.ok', true)
        ->assertJsonPath('checks.cache.ok', true)
        ->assertJsonPath('checks.queue.ok', true)
        ->assertJsonPath('checks.scheduler.ok', true);
});

it('reports failing when the cache store is down', function (): void {
    writeHeartbeats();

    // Point the cache at a Redis server that is not running.
    config([
        'database.redis.cache.host' => '127.0.0.1',
        'database.redis.cache.port' => 1,
        'cache.default' => 'redis',
    ]);

    $this->withHeader('X-Health-Token', 'test-health-token')
        ->get('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'failing')
        ->assertJsonPath('checks.cache.ok', false);
});

it('reports a stale scheduler heartbeat', function (): void {
    writeHeartbeats();
    Cache::put(HeartbeatCheck::scheduler()->cacheKey(), now()->subMinutes(10)->getTimestamp(), 600);

    $this->withHeader('X-Health-Token', 'test-health-token')
        ->get('/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.scheduler.ok', false);
});

it('schedules both heartbeats every minute', function (): void {
    $names = collect(app(Schedule::class)->events())
        ->map(fn ($event): ?string => $event->description)
        ->all();

    expect($names)->toContain('health:scheduler-heartbeat', 'health:queue-heartbeat');
});
