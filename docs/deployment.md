# Deployment (staging and production)

The platform runs on a single Docker host per environment. `compose.prod.yaml` defines the stack and `deploy.sh` performs releases.

```
Internet ──► Caddy (HTTPS, Let's Encrypt) ──► web (Nginx + release assets) ──► app (PHP-FPM 8.4)
                                                                     horizon (queues) · scheduler
                                                                     MySQL 8.4 · Redis 7
```

## 1. Server requirements

- Ubuntu 24.04 LTS VPS, 2 vCPU / 4 GB RAM minimum for staging.
- Docker Engine with the Compose plugin, Git, curl, openssl.
- A DNS record (for example `staging.example.com`) pointing at the server.
- Ports 80 and 443 open; SSH restricted to known IPs or keys.

## 2. First-time setup

```bash
# As a non-root deploy user in the docker group
git clone https://github.com/srinivas182/hotel-reservation-saas.git /srv/hotel-saas
cd /srv/hotel-saas
cp .env.example .env
```

Edit `.env` for the environment. Minimum values:

| Variable                          | Value                                                                                                                                        |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                         | `staging` or `production`                                                                                                                    |
| `APP_DEBUG`                       | `false`                                                                                                                                      |
| `APP_URL`                         | `https://staging.example.com`                                                                                                                |
| `APP_KEY`                         | generate with `docker run --rm hotel-saas-php:<release> php artisan key:generate --show` after the first build, or any `base64:` 32-byte key |
| `APP_DOMAIN`                      | `staging.example.com` (used by Caddy)                                                                                                        |
| `ACME_EMAIL`                      | address for certificate expiry notices                                                                                                       |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | long random strings                                                                                                                          |
| `SESSION_SECURE_COOKIE`           | `true`                                                                                                                                       |
| `LOG_CHANNEL`                     | `json` (structured logs on stderr)                                                                                                           |
| `HEALTH_CHECK_TOKEN`              | long random string                                                                                                                           |
| `BACKUP_ENCRYPTION_KEY`           | long random string, **stored outside the server too**                                                                                        |

Then deploy:

```bash
./deploy.sh
```

## 3. Releases

```bash
./deploy.sh              # deploy origin/main
./deploy.sh v0.1.0       # deploy a tag or commit
./deploy.sh --rollback   # switch back to the previous release
```

Each release builds two images tagged with the commit (`hotel-saas-php:<sha>`, `hotel-saas-web:<sha>`), backs up the database, runs migrations, swaps containers and calls `/health`. A failed health check restores the previous release automatically.

Expect a few seconds of interruption while containers are swapped; blue-green deployment is planned for the DevOps sprint. Because a rollback restores code but not the database, **migrations must remain backward compatible with the previous release** (add columns and tables first; remove them in a later release).

## 4. Health and monitoring

- `https://<domain>/up` — liveness, no dependencies.
- `https://<domain>/health` with header `X-Health-Token: <token>` — database, Redis, queue worker heartbeat and scheduler heartbeat. Returns `200` when healthy and `503` with details when not. Point uptime monitoring at this URL.
- Logs: `docker compose -f compose.prod.yaml logs -f app horizon` (JSON lines with `request_id`, `hotel_id`, `user_id`).

## 5. Backups

`docker/backup/backup.sh` writes a compressed, AES-256 encrypted MySQL dump to `/var/backups/hotel-saas` and deletes dumps older than 14 days. `deploy.sh` runs it before every migration. Schedule a nightly run:

```cron
15 2 * * * cd /srv/hotel-saas && ./docker/backup/backup.sh nightly >> /var/log/hotel-saas-backup.log 2>&1
```

Copy backups off the server (object storage) as part of the DevOps sprint; until then, download them periodically.

### Restore

```bash
cd /srv/hotel-saas && set -a && source .env && set +a
openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_KEY \
  -in /var/backups/hotel-saas/<file>.sql.gz.enc | gunzip \
  | docker compose -f compose.prod.yaml exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

A restore test is part of the pilot readiness checklist (S8).

## WhatsApp (Meta)

MCS connects to the WhatsApp Cloud API as a Meta Tech Provider. One Meta app
serves every hotel; each hotel connects its own number from Hotel settings →
WhatsApp (Embedded Signup v4) and pays Meta directly.

Set these in the server's `.env` (never commit them):

| Variable                 | Where it comes from                                                 |
| ------------------------ | ------------------------------------------------------------------- |
| `WHATSAPP_APP_ID`        | App Dashboard, top of the page                                      |
| `WHATSAPP_APP_SECRET`    | App Dashboard → App settings → Basic                                |
| `WHATSAPP_CONFIG_ID`     | Facebook Login for Business → Configurations (Embedded Signup v4)   |
| `WHATSAPP_VERIFY_TOKEN`  | Any long random string; the same value goes in the webhook settings |
| `WHATSAPP_GRAPH_VERSION` | Graph API version, e.g. `v23.0`                                     |

Webhook address for Meta: `https://<staging or production host>/webhooks/whatsapp`.
Subscribe to: `messages`, `message_template_status_update`,
`phone_number_quality_update`, `account_update`, and for numbers shared with
the WhatsApp Business app, `smb_message_echoes`.

The Platform → WhatsApp page shows which settings are present, the webhook
address, when the last webhook arrived and every connected number.
