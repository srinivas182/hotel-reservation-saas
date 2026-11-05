# Operations runbook

For MCS operations. Covers what the ops check emails mean, how to roll back,
and how to restore a backup. Every hotel can always fall back to staff
answering on WhatsApp: when in doubt, switch the assistant **Off** first
(Hotel → AI assistant, or Platform → AI → "Switch AI off for every hotel").

## Monitoring at a glance

| What              | Where                                                                                                                                                                                                 |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Uptime            | External monitor on `https://<host>/health` every minute (UptimeRobot or Better Stack).                                                                                                               |
| Platform problems | `platform:ops-check` every 5 minutes emails `OPS_ALERT_EMAIL`: once when a problem starts, every 2 hours while it lasts, once when it clears. Open problems are listed on Platform → Pilot dashboard. |
| Errors            | Sentry, when `SENTRY_LARAVEL_DSN` is set.                                                                                                                                                             |
| Logs              | JSON logs with `request_id`, `hotel_id`, `user_id` (`docker compose -f compose.prod.yaml logs app worker scheduler`).                                                                                 |

## Problems the ops check reports

### queue_backlog — Background jobs are backing up

Replies, media downloads and emails wait in the queue.

1. `docker compose -f compose.prod.yaml ps` — are `worker` / `horizon` running?
2. Check Horizon (`/horizon`, super admins) for failing jobs.
3. Restart workers: `docker compose -f compose.prod.yaml restart worker`.

### scheduler_stopped / queue_stopped — heartbeats missing

Scheduled work (holds expiry, request expiry, stay messages, summaries) or
workers are not running. Restart the `scheduler` or `worker` service and check
its logs. After a long stop, run once: `php artisan holds:expire`,
`php artisan booking-requests:expire`, `php artisan bookings:expire-tentative`.

### webhook_silence — No WhatsApp notifications are arriving

1. Platform → WhatsApp: is the webhook address correct? When did the last one arrive?
2. Meta App Dashboard → WhatsApp → Configuration: webhook subscribed, verify token matches.
3. Send a test message to a hotel number. If nothing arrives, check Caddy logs for `/webhooks/whatsapp`.

### webhook_failures — Notifications failing to process

`php artisan whatsapp:replay --failed` after fixing the cause (see the error
on Platform → WhatsApp and in the logs). Replays never duplicate messages.

### ai_failures — AI providers are failing

Platform → AI: "Test" each model. If the main provider is down the backup
answers; if both fail, guests are passed to staff automatically. Consider
switching hotels to Supervised or Off until it recovers.

### whatsapp_failures — Messages failing to send

Inbox shows each failure's reason. Common: access token expired (hotel
owner reconnects WhatsApp), account restricted by Meta (Meta Business Suite),
template paused (resubmit).

### backup_missing / backup_failed

Run `./docker/backup/backup.sh manual` on the server and read its output.
Check disk space (`df -h`), `BACKUP_ENCRYPTION_KEY`, and off-site credentials.

### restore_drill — failed or overdue

Run `./docker/backup/verify-restore.sh` and read which check failed (see below).

## Backups

- `docker/backup/backup.sh` — nightly by host cron (`15 2 * * *`) and by
  `deploy.sh` before migrations. Encrypted with `BACKUP_ENCRYPTION_KEY`
  (AES-256, keep a copy of the key **off the server**). Local copies are kept
  14 days. With `BACKUP_S3_URI` set, copies go off-site: 14 daily, 8 weekly
  (Sundays), 6 monthly (1st of the month).
- Every result is recorded (Platform → Pilot dashboard, Hotel → Go-live).

### Restore drill (monthly: `0 4 1 * *`)

`./docker/backup/verify-restore.sh [file]` restores the latest backup into a
temporary database, then the application checks: every migration present,
hotels/users/settings have data, and encrypted values are readable with
this server's `APP_KEY`. The temporary database is always dropped.

### Real restore (disaster recovery)

1. Switch every hotel's assistant Off (Platform → AI → switch off) and tell CNH.
2. Put the app in maintenance: `docker compose -f compose.prod.yaml exec app php artisan down`.
3. Take a backup of the current state first: `./docker/backup/backup.sh before-restore`.
4. Restore:
    ```
    openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_KEY -in <file> | gunzip \
      | docker compose -f compose.prod.yaml exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
    ```
5. `php artisan migrate --force`, then `php artisan up`.
6. `php artisan inventory:rebuild` and `php artisan whatsapp:replay` (messages received since the backup were stored by Meta's retries only if within Meta's retry window).
7. Switch assistants back on once the hotel has checked its bookings.

## Rollback

- **Fastest:** switch the assistant Off for the hotel (instant; staff answer in the inbox or the WhatsApp Business app).
- **Code:** `deploy.sh` rolls back automatically when health checks fail. Manual: `./deploy.sh --rollback` switches back to the previous release images.
- **Data:** pilot migrations are additive only, so a code rollback never needs a database rollback.
