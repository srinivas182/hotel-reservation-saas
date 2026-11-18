<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Staff app notifications (Web Push, Sprint D)
|--------------------------------------------------------------------------
| VAPID keys identify this server to the browsers' push services. Make a
| pair once per deployment with `php artisan push:keys` and keep them: new
| keys make every phone subscribe again. Without keys, the app works and
| alerts go by WhatsApp only.
*/

return [
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'support@example.com')),

    // Minutes before an unopened notification is followed by the WhatsApp alert.
    'whatsapp_backup_minutes' => (int) env('PUSH_WHATSAPP_BACKUP_MINUTES', 5),

    // Seconds a notification waits at the push service for an offline phone.
    'ttl' => 4 * 3600,
];
