<?php

declare(strict_types=1);

use App\Domains\Messaging\Email\Models\EmailMessage;
use App\Domains\Platform\Health\HeartbeatCheck;
use App\Domains\Platform\Health\QueueHeartbeatJob;
use App\Domains\WhatsApp\Models\WebhookEvent;
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

/*
| Email log retention: entries older than 90 days are removed. Usage
| records are kept (they are the basis for credits and billing).
*/
Schedule::command('model:prune', ['--model' => [EmailMessage::class, WebhookEvent::class]])
    ->name('prune:email-messages')
    ->daily();

// Room holds: lapsed holds stop counting immediately; this keeps statuses accurate.
Schedule::command('holds:expire')
    ->name('holds:expire')
    ->everyMinute()
    ->withoutOverlapping();

// Tentative bookings that were not confirmed in time free their rooms.
Schedule::command('bookings:expire-tentative')
    ->name('bookings:expire-tentative')
    ->everyMinute()
    ->withoutOverlapping();

// Every connected WhatsApp number is checked once a day (expired tokens, quality).
Schedule::command('whatsapp:check')
    ->name('whatsapp:check')
    ->dailyAt('06:00');
