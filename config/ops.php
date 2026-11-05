<?php

declare(strict_types=1);

return [

    // Where MCS operations alerts go (comma-separated addresses).
    'alert_email' => env('OPS_ALERT_EMAIL'),

    // Problems are re-sent at this interval while they last.
    'reminder_minutes' => 120,

    // Background jobs waiting before it counts as a backlog.
    'queue_backlog' => (int) env('OPS_QUEUE_BACKLOG', 200),

    // No WhatsApp notification for this long, during these UTC hours, is a problem.
    'webhook_silence_hours' => 6,
    'daytime_utc' => [7, 21],

    // Failure rates over the last hour (minimum counts avoid noise).
    'ai_failure' => ['min' => 5, 'rate' => 0.5],
    'whatsapp_failure' => ['min' => 5, 'rate' => 0.2],

    // Backups are written by docker/backup/backup.sh and recorded here.
    'backups_enabled' => (bool) env('BACKUPS_ENABLED', false),
    'backup_max_age_hours' => 26,
    'restore_drill_max_age_days' => 35,

];
