<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiRun;
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
Schedule::command('model:prune', ['--model' => [EmailMessage::class, WebhookEvent::class, AiRun::class]])
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

// WhatsApp booking requests nobody answered in time release their rooms.
Schedule::command('booking-requests:expire')
    ->name('booking-requests:expire')
    ->everyMinute()
    ->withoutOverlapping();

// Guests waiting too long for staff get one holding message; staff are alerted again.
Schedule::command('conversations:check-waiting')
    ->name('conversations:check-waiting')
    ->everyMinute()
    ->withoutOverlapping();

// Owners' daily summaries go out at each owner's chosen time.
Schedule::command('owners:daily-summary')
    ->name('owners:daily-summary')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Arrival reminders, check-out reminders and thank-you messages.
Schedule::command('stay-messages:send')
    ->name('stay-messages:send')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// MCS operations: problems are emailed when they start, every two hours, and when they clear.
Schedule::command('platform:ops-check')
    ->name('platform:ops-check')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// WhatsApp table requests nobody answered in time release their seats.
Schedule::command('table-requests:expire')
    ->name('table-requests:expire')
    ->everyMinute()
    ->withoutOverlapping();

// Event options past their date release the space to the waiting list.
Schedule::command('events:expire-options')
    ->name('events:expire-options')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Attendee room blocks past their cut-off are recorded as released.
Schedule::command('events:release-room-blocks')
    ->name('events:release-room-blocks')
    ->hourly()
    ->withoutOverlapping();

// Subscriptions: trial end, renewal, scheduled downgrades, the period's credits.
Schedule::command('billing:renew')
    ->name('billing:renew')
    ->hourly()
    ->withoutOverlapping();

// Invoices for renewals, upgrades and packs.
Schedule::command('billing:invoice')
    ->name('billing:invoice')
    ->hourly()
    ->withoutOverlapping();

// Payment reminders and reduced mode.
Schedule::command('billing:dunning')
    ->name('billing:dunning')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Setup reminders and trial-ending emails.
Schedule::command('onboarding:nudges')
    ->name('onboarding:nudges')
    ->dailyAt('08:00')
    ->withoutOverlapping();

// Announcement emails (once, when an announcement starts).
Schedule::command('announcements:send')
    ->name('announcements:send')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Closed hotels: reminders before deletion, deletion after 90 days.
Schedule::command('hotels:closures')
    ->name('hotels:closures')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// New leads and follow-ups due, to the sales team.
Schedule::command('leads:digest')
    ->name('leads:digest')
    ->dailyAt('07:30')
    ->withoutOverlapping();
