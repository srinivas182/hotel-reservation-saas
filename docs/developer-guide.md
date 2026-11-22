# Developer guide

How to run Assistant Hôtel on your own computer and on DigitalOcean, which accounts and roles exist, and how to use the platform once it is running.

| You want to…                              | Go to                                                                  |
| ----------------------------------------- | ---------------------------------------------------------------------- |
| Run it on your computer (Docker)          | [2. Local set-up with Docker](#2-local-set-up-with-docker-recommended) |
| Run it without Docker                     | [3. Local set-up without Docker](#3-local-set-up-without-docker)       |
| Know the demo sign-ins                    | [4. Default accounts and passwords](#4-default-accounts-and-passwords) |
| Understand who can do what                | [5. Roles](#5-roles)                                                   |
| Try a guest conversation without WhatsApp | [6. Simulation mode](#6-simulation-mode-whatsapp-and-ai-without-meta)  |
| Put staging or production on DigitalOcean | [8. DigitalOcean](#8-digitalocean-staging-and-production)              |
| Use the platform day to day               | [9. Using the platform](#9-using-the-platform)                         |
| Fix a common problem                      | [10. Troubleshooting](#10-troubleshooting)                             |

Related documents: [`deployment.md`](deployment.md) (server reference), [`cloudpanel.md`](cloudpanel.md) (servers managed with CloudPanel), [`operations.md`](operations.md), [`security.md`](security.md), [`launch-defaults.md`](launch-defaults.md), [`release-process.md`](release-process.md).

---

## 1. What you are setting up

```
Browser / staff phone (PWA) ─┐
Guests on WhatsApp ── Meta ──┼─► Nginx ─► Laravel 13 (PHP 8.4) ─► MySQL 8.4
                             │             │  React 19 + Inertia      Redis 7 (cache, sessions, queues)
                             │             └► Horizon (queue workers) · Scheduler (every minute)
                             └─ (servers only) Caddy in front, HTTPS by Let's Encrypt
```

- **One application** serves the public website, the **platform console** (`/platform`, for MCS and CNH staff) and the **hotel app** (`/hotel/...`, for each hotel's staff). Hotels are separated in the database by `hotel_id`; staff only ever see the hotels they belong to.
- **Queues matter**: guest replies, alerts, PDFs and e-mails run in Horizon. If Horizon is not running, the assistant does not answer.
- **The scheduler matters**: reminders, expiries, morning summaries and the one-minute undo of staff decisions run from `php artisan schedule:run` every minute.

---

## 2. Local set-up with Docker (recommended)

### Prerequisites

- Docker Desktop (macOS, Windows with WSL 2) or Docker Engine with the Compose plugin (Linux). 4 GB of memory for Docker is enough.
- Git and `make`. On Windows, run everything inside WSL 2 (Ubuntu), with the code in the Linux file system (`~/code`, not `/mnt/c/...`), or it will be slow.
- Free ports: **8080** (app), **8025** (Mailpit), **3306** (MySQL), **6379** (Redis), **5173** (Vite, only with hot reload).

### First run

```bash
git clone https://github.com/srinivas182/hotel-reservation-saas.git
cd hotel-reservation-saas
cp .env.example .env
make up        # starts app, nginx, mysql, redis, horizon, scheduler, mailpit
make install   # composer install, app key, migrate --seed, frontend build
```

Open:

| Address                           | What                                                       |
| --------------------------------- | ---------------------------------------------------------- |
| http://localhost:8080             | The application (public website; sign in at `/login`)      |
| http://localhost:8080/platform    | Platform console (sign in as the super admin)              |
| http://localhost:8080/hotel/today | Hotel app home ("Today")                                   |
| http://localhost:8025             | **Mailpit**: every e-mail the app sends locally lands here |
| http://localhost:8080/horizon     | Queue dashboard (super admin only)                         |
| http://localhost:8080/help        | Built-in help centre                                       |

`make install` seeds the demo data because `.env.example` has `APP_ENV=local` (see [section 4](#4-default-accounts-and-passwords)).

### Everyday commands

| Command                 | Does                                                         |
| ----------------------- | ------------------------------------------------------------ |
| `make up` / `make down` | Start / stop the stack                                       |
| `make shell`            | Shell inside the app container (run `php artisan ...` there) |
| `make fresh`            | Drop and rebuild the database with demo data                 |
| `make logs`             | Follow the application log                                   |
| `make test`             | Full test suite in parallel                                  |
| `make check`            | What CI checks: Pint, Larastan level 8, TypeScript, tests    |
| `make fix`              | Auto-fix PHP and frontend formatting                         |
| `make build`            | Rebuild the PHP image (after changing the Dockerfile)        |

Without `make`: `docker compose exec app php artisan <command>`.

### Frontend with hot reload

`make install` builds the assets once. To see React changes instantly:

```bash
docker compose --profile frontend up -d vite   # Vite on :5173
```

Keep using http://localhost:8080; the page picks up changes from Vite. After changing routes or controllers, regenerate the typed route helpers:

```bash
docker compose exec app php artisan wayfinder:generate --with-form
```

---

## 3. Local set-up without Docker

Use this when Docker is not an option. You need:

- **PHP 8.4** (8.3 works for development) with `bcmath, gd, intl, opcache, pcntl, pdo_mysql, zip, redis` (and `pdo_sqlite` for the tests)
- **Composer 2**, **Node 22** and npm
- **MySQL 8.4** and **Redis 7** (or the simpler drivers below)

```bash
git clone https://github.com/srinivas182/hotel-reservation-saas.git
cd hotel-reservation-saas
cp .env.example .env
composer install
npm ci
php artisan key:generate
```

Edit `.env` for your machine:

```dotenv
APP_URL=http://localhost:8000
DB_HOST=127.0.0.1          # not "mysql": that is the Docker service name
REDIS_HOST=127.0.0.1
MAIL_HOST=127.0.0.1        # run Mailpit locally, or set MAIL_MAILER=log
```

No Redis? Use `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` (Horizon then does not apply: use `php artisan queue:work`).

```bash
php artisan migrate --seed
npm run build                    # or: npm run dev (hot reload)
```

Run these together, each in its own terminal (or with a process manager):

```bash
php artisan serve                 # http://localhost:8000
php artisan horizon               # or: php artisan queue:work (database queue)
php artisan schedule:work         # the scheduler
```

---

## 4. Default accounts and passwords

> **Rule:** known passwords exist **only** for local development and for automated tests. A real server (staging or production) never gets a known super admin: you create it with `platform:create-admin`, which prints a random one-time password.

### 4.1 Local development (`APP_ENV=local`, created by `migrate --seed` / `make install` / `make fresh`)

All passwords are **`password`**.

| E-mail                        | Where they land   | Role                                         | Two-step login at first sign-in |
| ----------------------------- | ----------------- | -------------------------------------------- | ------------------------------- |
| `admin@example.com`           | `/platform`       | Platform **Super admin**                     | **Yes** (required)              |
| `owner.lodge@example.com`     | First Class Lodge | Hotel **Owner** (Côte d'Ivoire, XOF, French) | **Yes** (required)              |
| `reception.lodge@example.com` | First Class Lodge | Hotel **Reception**                          | No (optional)                   |
| `owner.city@example.com`      | Demo City Hotel   | Hotel **Owner** (South Africa, ZAR, English) | **Yes** (required)              |
| `reception.city@example.com`  | Demo City Hotel   | Hotel **Reception**                          | No (optional)                   |

First Class Lodge comes with its rooms and rates (the pilot hotel's real set-up), so you can make bookings straight away. Demo City Hotel is nearly empty: use it to try the set-up wizard.

**Two-step login:** platform staff and hotel owners must set it up before they can do anything else (`EnsureTwoFactorEnrolled`). At the first sign-in you are sent to **Settings → Security**: scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…), type the 6-digit code and **save the recovery codes**. Locally, if you lose the phone, run `make fresh` (or reset the user, below).

### 4.2 Automated browser tests (`php artisan e2e:prepare`)

Used by the Playwright tests in `tests/e2e` and the **e2e** CI workflow. The two-step secrets are fixed so the tests can compute the codes.

| E-mail                    | Password   | Two-step secret (TOTP) |
| ------------------------- | ---------- | ---------------------- |
| `admin.e2e@example.com`   | `password` | `JBSWY3DPEHPK3PXP`     |
| `owner.lodge@example.com` | `password` | `KRSXG5CTMVRXEZLU`     |

These values are public (they are in this repository). `e2e:prepare` is for a throw-away test database only; **never run it on a server people can reach**.

### 4.3 Staging with simulation (`php artisan simulation:prepare`)

Creates the two demo hotels and their four staff accounts (password `password`) so load tests and drills have data. It does **not** create a super admin. Because staging is on the internet, **change the demo passwords** after preparing it, or have each demo owner suspend the accounts you don't use (**Staff** page).

### 4.4 Staging and production

Nobody exists until you create the first super admin:

```bash
docker compose -f compose.prod.yaml exec app php artisan platform:create-admin you@example.com --first-name=You --last-name=Name
```

```
 INFO  Platform user created: you@example.com (super_admin).

  Sign in at ............ https://staging.example.com/login
  E-mail ................ you@example.com
  Password (shown once) . 8yXq…(20 characters)

 WARN  Two-step login is required at the first sign-in: have an authenticator app ready.
```

| Option             | Use                                                                                       |
| ------------------ | ----------------------------------------------------------------------------------------- |
| `--role=`          | `super_admin` (default), `operations`, `finance` or `sales`                               |
| `--reset-password` | An existing account gets a new random password; its two-step login is set up again        |
| `--force`          | Allow a second super admin (refused by default; add people from Platform → Users instead) |

Every run is written to the audit log (`platform.admin_created` / `platform.admin_updated`).

### 4.5 Resetting a password

- Anyone: **Forgot your password?** on the sign-in page (locally the e-mail arrives in Mailpit).
- Platform staff, from the server: `php artisan platform:create-admin their@email --role=<their role> --reset-password`.
- A hotel owner can resend an invitation or suspend an account from **Staff**; staff then use **Forgot your password?** themselves.

---

## 5. Roles

There are two separate sets of roles. A platform role never gives access to a hotel's data by itself, and a hotel role never gives access to the platform console.

### 5.1 Platform roles (MCS and CNH staff, `/platform`)

| Role            | Typically      | Can                                                                                                                                                                      |
| --------------- | -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Super admin** | MCS            | Everything. Only the super admin: **Users** (platform staff), plans and catalogue, sign-up settings, configuration (AI, mail providers, features), audit export, Horizon |
| **Operations**  | CNH operations | Hotels (view and manage), go-live and readiness, support requests, announcements, audit log, billing (view), sign-ups and sign-up codes, help content                    |
| **Finance**     | CNH accounts   | Hotels (view), billing and invoices (view and manage)                                                                                                                    |
| **Sales**       | CNH sales      | Hotels (view), leads, website, sign-ups and sign-up codes, help content                                                                                                  |

All platform roles can add notes on a hotel. The exact list per role is in `App\Domains\Platform\PlatformAbility`. Platform staff open a hotel's data only through a **support session**, which the owner may have to approve first (Hotel → Settings) and which is logged.

### 5.2 Hotel roles (each hotel's staff, `/hotel/...`)

These six roles are copied into every hotel and kept up to date by `php artisan permissions:sync` (run by the seeders and on every deploy). An owner can also make **custom roles** from **Staff → Roles** (`/staff/roles`).

| Area                                                       | Owner | Manager |       Reception        | Restaurant & Bar | Events coordinator |        Read-only         |
| ---------------------------------------------------------- | :---: | :-----: | :--------------------: | :--------------: | :----------------: | :----------------------: |
| Today, bookings, calendar                                  |   ✓   |    ✓    | ✓ (manage, front desk) |       view       |        view        |           view           |
| Booking requests (approve / decline)                       |   ✓   |    ✓    |           ✓            |                  |                    |                          |
| WhatsApp inbox (read / reply)                              |   ✓   |    ✓    |           ✓            |                  |                    |           read           |
| Availability                                               |   ✓   |    ✓    |       ✓ (change)       |                  |                    |           view           |
| Rooms and rates                                            |   ✓   |    ✓    |          view          |                  |                    |           view           |
| Folio: charges and payments                                |   ✓   |    ✓    |           ✓            |                  |                    |           view           |
| Restaurant tables and orders                               |   ✓   |    ✓    |           ✓            |        ✓         |                    |           view           |
| Restaurant menu                                            |   ✓   |    ✓    |                        |        ✓         |                    |                          |
| Events and venues                                          |   ✓   |    ✓    |          view          |                  |         ✓          |           view           |
| Assistant, information pack, WhatsApp kit, settings, taxes |   ✓   |    ✓    |                        |                  |                    | settings and taxes: view |
| Staff list                                                 |   ✓   |    ✓    |          view          |                  |                    |           view           |
| Audit log, usage, exports                                  |   ✓   |    ✓    |                        |                  |                    |     view and export      |
| **Roles and permissions**                                  |   ✓   |         |                        |                  |                    |                          |
| **Connect WhatsApp number**                                |   ✓   |         |                        |                  |                    |                          |
| **Subscription and billing**                               |   ✓   |         |                        |                  |                    |                          |
| Contact support                                            |   ✓   |    ✓    |           ✓            |        ✓         |         ✓          |            ✓             |

Source of truth: `App\Domains\Access\SystemRole::permissions()` and the `Permission` enum. Every hotel route checks its permission; `HotelRouteAuthorizationTest` fails if a new route forgets.

A person can belong to several hotels with a different role in each; **My hotels** (`/account/hotels`) switches between them.

---

## 6. Simulation mode (WhatsApp and AI without Meta)

Simulation replaces the Meta Graph API and the AI provider with fakes, so you can watch the assistant answer "guests" without a real number, real guests or paid AI. It refuses to start in production.

Locally:

```dotenv
# .env
SIMULATION_MODE=true
WHATSAPP_APP_SECRET=any-long-random-string   # signs the simulated webhook calls
```

```bash
make shell
php artisan config:clear
php artisan simulation:prepare                         # simulated number SIM-5550001 for First Class Lodge, assistant on Automatic
php artisan simulation:guests SIM-5550001 --conversations=3 --messages=2
php artisan simulation:prepare --report                # counts: conversations, replies, failures, duplicates
```

Then sign in as `reception.lodge@example.com` and open **Inbox** and **Requests**: the conversations, the assistant's replies and any booking requests appear as they would with real guests. `--duplicate` sends every notification twice (as Meta sometimes does) to prove nothing is answered twice. Deposit links open a simulated payment page (`/pay-simulation/...`).

Turn it off again with `SIMULATION_MODE=false` and `php artisan config:clear`.

For a sales demo with no set-up, platform staff can also use **Platform → Demo**, and each hotel has a safe practice mode under **Hotel → Demo**.

---

## 7. Tests and checks (before every push)

```bash
make check          # or, without Docker:
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run check && npm run types:check
php artisan test --parallel
php artisan translations:check          # every text in English and French
```

- **Pest 4** tests are in `tests/Feature` and `tests/Unit`; they use SQLite in memory and fake every outside service (no network).
- **Browser tests** (Playwright, `tests/e2e`) run in CI on every pull request touching the UI. Locally: `npx playwright install chromium`, then follow the steps of `.github/workflows/e2e.yml` (an SQLite database, `APP_ENV=staging`, `SIMULATION_MODE=true`, `php artisan e2e:prepare`, `php artisan serve`, two `queue:work`, `npx playwright test`).
- Guard tests that fail on purpose when a rule is broken: a hotel route without a permission, a public route without rate limiting, an outside HTTP call without a timeout, a job without `$tries`/`$backoff`/`$timeout`, a screen without a help article, the Today page going over its query budget.
- New user-facing text: write it in English with `__()`, then add the French in `lang/fr.json` (`php artisan translations:check --sync` lists what is missing).
- Use the project's formatters (`make fix`, `npm run check:fix`); never run a formatter without the project's configuration.

Branches and releases: work on a branch, open a pull request, merge only when CI is fully green. Versions and the changelog follow [`release-process.md`](release-process.md).

---

## 8. DigitalOcean (staging and production)

One Droplet per environment. Staging and production never share a server, a database or keys.

### 8.1 Create the Droplet

1. **Create → Droplets**: Ubuntu **24.04 LTS** x64.
2. Size: **2 vCPU / 4 GB** for staging (Basic, Regular or Premium). For production start at **4 vCPU / 8 GB** and watch the load; check DigitalOcean's current prices.
3. Region: the nearest to the hotels and to Meta's servers, typically **Frankfurt (FRA1)**, **Amsterdam (AMS3)** or **London (LON1)** for West Africa.
4. Authentication: **SSH key** (add yours). Do not choose a password.
5. Recommended: enable **Backups** (weekly server images) and **Monitoring**; add a **Reserved IP** so the address survives rebuilding the Droplet.
6. Optional: a **Cloud Firewall** allowing only 22 (SSH, ideally from your IPs), 80 and 443. The provisioning script also sets up the server's own firewall.

### 8.2 Point the domain

Create an **A record** for the host (for example `staging.assistant-hotel.com`) pointing at the Droplet's IP (or Reserved IP), either in **Networking → Domains** (after pointing the domain's name servers at DigitalOcean) or at your registrar. Check it before deploying: `dig +short staging.assistant-hotel.com`. Caddy requests the HTTPS certificate on the first deploy; it fails if DNS does not point at the server yet.

### 8.3 Provision the server (once)

```bash
ssh root@<droplet-ip>
curl -fsSL https://raw.githubusercontent.com/srinivas182/hotel-reservation-saas/main/scripts/server/provision.sh -o provision.sh
bash provision.sh staging staging.assistant-hotel.com ops@example.com
#            or: production app.assistant-hotel.com ops@example.com
```

It is safe to run again. It:

- creates the `deploy` user (in the `docker` group, with root's SSH key), turns off root and password log-in, sets up the firewall, fail2ban, automatic security updates, 2 GB of swap and time sync;
- installs Docker and Compose;
- clones the application into `/srv/hotel-saas` and writes `.env` with random passwords and keys, VAPID keys for staff notifications, and `SIMULATION_MODE=true` on staging only.

It prints what is left to do. From now on, log in as `deploy`: `ssh deploy@<droplet-ip>`.

> **Save `BACKUP_ENCRYPTION_KEY`** from `/srv/hotel-saas/.env` in your password manager. Without it, backups cannot be restored.

### 8.4 First deploy and first admin

```bash
ssh deploy@<droplet-ip>
cd /srv/hotel-saas
./deploy.sh                                   # builds, backs up, migrates, starts, checks /health
docker compose -f compose.prod.yaml exec app php artisan launch:defaults
docker compose -f compose.prod.yaml exec app php artisan platform:create-admin you@example.com --first-name=You --last-name=Name
```

Sign in at `https://<domain>/login` with the one-time password, set up two-step login, then add the team from **Platform → Users** (see [9.1](#91-platform-staff)).

`launch:defaults` applies the starting brand, prices, support hours and legal texts ([`launch-defaults.md`](launch-defaults.md)); it never overwrites a choice already made.

**Staging only**, for drills and demos:

```bash
docker compose -f compose.prod.yaml exec app php artisan simulation:prepare
./scripts/drills/staging-drills.sh            # failure drills; results: php artisan drills:status
```

Then change the demo staff passwords ([4.3](#43-staging-with-simulation-php-artisan-simulationprepare)).

### 8.5 Releases and rollback

```bash
./deploy.sh              # deploy origin/main
./deploy.sh v0.25.1      # a tag or commit
./deploy.sh --rollback   # back to the previous release
```

Each release takes a database backup before migrating, and puts the previous release back by itself if `/health` fails. A rollback restores code, not the database, so migrations are always backward compatible (add first, remove in a later release).

**Staging from GitHub** without SSH: the **Deploy to staging** workflow (Actions → Deploy to staging → Run workflow). One-time set-up, in **Settings → Environments → `staging`**: secrets `STAGING_HOST`, `STAGING_SSH_KEY` (a key made only for GitHub, its public half in `/home/deploy/.ssh/authorized_keys`), `STAGING_DOMAIN` and `STAGING_KNOWN_HOSTS` (`ssh-keyscan <host>`). It refuses a server whose `.env` is not `APP_ENV=staging`. Details in [`deployment.md`](deployment.md#staging-from-github-no-ssh-session-needed).

### 8.6 Production settings to fill in

In `/srv/hotel-saas/.env` (then `./deploy.sh` or `php artisan config:cache`):

| Setting                                                                                                           | Where it comes from                                                 |
| ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `WHATSAPP_APP_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_CONFIG_ID`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_GRAPH_VERSION` | Meta App Dashboard ([`deployment.md`](deployment.md#whatsapp-meta)) |
| `SENTRY_LARAVEL_DSN`                                                                                              | Sentry project (error tracking)                                     |
| `CSP_ENFORCE=true`                                                                                                | After a week without unexpected reports (`drills:status`)           |
| `SIMULATION_MODE=false`                                                                                           | Always on production (the app refuses to start otherwise)           |

In Meta, set the webhook to `https://<host>/webhooks/whatsapp` with the same verify token and subscribe to `messages`, `message_template_status_update`, `phone_number_quality_update`, `account_update` (and `smb_message_echoes` for numbers shared with the WhatsApp Business app).

In the platform console (no server access needed): AI providers (**Platform → AI**), e-mail providers (**Platform → Mail providers**), payment providers and plans (**Platform → Billing**), the launch checklist (**Platform → Launch**; `php artisan launch:check` fails until every required item is done).

### 8.7 Backups, health and logs

- Nightly encrypted database backup, as `deploy` (`crontab -e`):

    ```cron
    15 2 * * * cd /srv/hotel-saas && ./docker/backup/backup.sh nightly >> /var/log/hotel-saas-backup.log 2>&1
    ```

    Dumps are kept 14 days in `/var/backups/hotel-saas`. Copy them off the server, for example to a **DigitalOcean Space** with `s3cmd` or `rclone`. Restoring: [`deployment.md`](deployment.md#restore); test a restore before the pilot.

- Uptime: point a monitor (DigitalOcean Uptime, or any other) at `https://<host>/health` with header `X-Health-Token: <HEALTH_CHECK_TOKEN>`. `/up` answers without checking dependencies.
- Logs: `docker compose -f compose.prod.yaml logs -f app horizon` (JSON lines with `request_id`, `hotel_id`, `user_id`). Queues: `https://<host>/horizon` (super admin).

---

## 9. Using the platform

### 9.1 Platform staff

1. Sign in at `/login`; you land on **Platform** (`/platform`).
2. **Platform → Users**: invite colleagues with their role. They get an e-mail to choose their password, then set up two-step login.
3. **Platform → Hotels → Create** (or let hotels sign up from the website at `/signup`, reviewed in **Sign-ups**). Each hotel gets an owner by e-mail.
4. Day to day: **Operations** (problems to look at), **WhatsApp** (number health), **Support** (requests from hotels), **Billing** and **Invoices**, **Leads** and **Demo**, **Status**, **Announcements**, **Audit**.

### 9.2 Hotel owner

1. Sign in, set up two-step login, accept the legal texts.
2. **Set-up** (`/hotel/setup`) walks through hotel details, rooms, rates, taxes, deposit rules, staff, and connecting the WhatsApp number (**Hotel → WhatsApp**, through Meta's Embedded Signup).
3. **Assistant → Information pack**: what the assistant may tell guests; **Practice** to try questions before going live; **Go live** when the readiness checks pass.
4. **WhatsApp kit**: posters, QR codes, a website button and links that bring guests to the number, with results per source.
5. Then: Today, bookings, deposits, staff, billing; a morning summary arrives on WhatsApp each day.

### 9.3 Reception

**Today** (`/hotel/today`) is the home: arrivals, departures, requests waiting, guests who confirmed. Approve or decline **booking requests** (also from WhatsApp alert buttons, with a one-minute undo), answer guests in the **Inbox** when the assistant hands over, record payments on the **folio**, and use the calendar for walk-ins. On a phone, install the staff app from the browser's menu ("Add to Home screen") and turn on notifications.

### 9.4 Other roles

- **Restaurant & Bar**: tables, orders and the menu under **Restaurant**.
- **Events coordinator**: enquiries, options and venues under **Events**.
- **Read-only**: everything visible, nothing changeable; for accountants and auditors.

Every screen has a **?** with its help article; the whole help centre is at `/help`.

---

## 10. Troubleshooting

| Problem                                          | Fix                                                                                          |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------- |
| "Vite manifest not found" or a blank page        | `npm run build` (or start Vite), then reload                                                 |
| Port 8080 / 3306 already in use                  | Stop the other service, or change the port in `compose.yaml`                                 |
| No e-mail arrives locally                        | Open Mailpit at http://localhost:8025                                                        |
| The assistant does not answer, PDFs never arrive | Horizon is not running: `docker compose ps horizon`, `make logs`; on a server, `/horizon`    |
| Reminders and undo never happen                  | The scheduler is not running (`scheduler` service, or `php artisan schedule:work`)           |
| Redirected to Security after every sign-in       | Two-step login is required for your role: finish setting it up                               |
| Lost the authenticator phone (local)             | `make fresh`, or `php artisan platform:create-admin <e-mail> --role=<role> --reset-password` |
| "A super admin already exists"                   | Sign in as them and add people in Platform → Users, or use `--force`                         |
| A new page or route is not found by TypeScript   | `php artisan wayfinder:generate --with-form`                                                 |
| Permission changes not visible                   | `php artisan permissions:sync`                                                               |
| Text appears in English in the French app        | Add it to `lang/fr.json` (`php artisan translations:check --sync`)                           |
| Certificate error on a new server                | DNS does not point at the server yet; fix the A record, then `./deploy.sh` again             |
| `deploy.sh` rolled back by itself                | `/health` failed: `docker compose -f compose.prod.yaml logs app`, fix, deploy again          |
