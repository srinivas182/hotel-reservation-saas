<?php

declare(strict_types=1);

use App\Domains\Platform\Health\HeartbeatCheck;
use App\Domains\Platform\Health\QueueHeartbeatJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Health heartbeats
|--------------------------------------------------------------------------
|
| The scheduler records that it ran, and dispatches a job whose execution
| proves a queue worker is processing jobs. /health reports both.
|
*/

Schedule::call(fn () => Cache::put(HeartbeatCheck::scheduler()->cacheKey(), now()->getTimestamp(), 600))
    ->name('health:scheduler-heartbeat')
    ->everyMinute();

Schedule::job(new QueueHeartbeatJob)
    ->name('health:queue-heartbeat')
    ->everyMinute();
