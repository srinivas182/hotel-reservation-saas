# Running on CloudPanel (DigitalOcean)

For a server managed with [CloudPanel](https://www.cloudpanel.io/) instead of the Docker set-up in [`deployment.md`](deployment.md). CloudPanel runs the site with Nginx and PHP-FPM; the code lives in the site's folder and is updated with Git.

Throughout: `<site-user>` is the site's user in CloudPanel and `<domain>` its domain (for example `cnhglobaltech.io`). The site folder is `/home/<site-user>/htdocs/<domain>`.

---

## 1. Log in to the server (SSH)

1. CloudPanel → **Sites** → your site → **Settings**: note the **site user**. Under **SSH/FTP** you can add an SSH key or set its password.
2. From your computer (Terminal on macOS/Linux, PowerShell or PuTTY on Windows):

    ```bash
    ssh <site-user>@<server-ip>
    cd ~/htdocs/<domain>
    ```

Every command below runs there, as the site user (not root), so files keep the right owner.

On a server with several PHP versions, call PHP by its version: `php8.4 artisan …` (or `php8.3`). Check with `php8.4 -v`; the site's version is set in CloudPanel → Site → **Settings → PHP version**.

---

## 2. Update to the latest version

1. CloudPanel → **Databases** → your database → **Export** (a backup, in case).
2. Then:

    ```bash
    cd ~/htdocs/<domain>
    bash scripts/cloudpanel/update.sh
    ```

The script turns maintenance mode on, pulls `main`, installs the PHP packages, builds the screens, applies the database changes, refreshes caches, restarts the queue workers and turns maintenance mode off. If a step fails it stops there and the site comes back up on the previous screens. A specific version: `bash scripts/cloudpanel/update.sh v0.27.1`.

If it says **"Files were changed on the server"**: someone edited files directly. See them with `git status`, keep a copy if needed, then `git checkout -- .` and run it again.

If it says **npm is not installed**: install Node 22 for the site user once:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
source ~/.bashrc && nvm install 22
```

---

## 3. Create the super admin

```bash
php8.4 artisan launch:defaults
php8.4 artisan platform:create-admin you@example.com --first-name=Your --last-name=Name
```

It prints a password **once**: copy it, then sign in at `https://<domain>/login`. Other platform staff are added from **Platform → Users** (they get an e-mail to choose their password). Forgot it: run the same command with `--reset-password`.

---

## 4. A testing server: settings in `.env`

The settings are in `~/htdocs/<domain>/.env` (edit with `nano .env`, or CloudPanel's **File Manager**). After any change: `php8.4 artisan optimize`.

| Setting                    | Testing                                       | Production                                                 |
| -------------------------- | --------------------------------------------- | ---------------------------------------------------------- |
| `APP_ENV`                  | `staging`                                     | `production`                                               |
| `APP_DEBUG`                | `false`                                       | `false`                                                    |
| `AUTH_TWO_FACTOR_REQUIRED` | `false`: no two-step login while testing      | `true` (production always requires it, whatever this says) |
| `SIMULATION_MODE`          | `true`: test guest conversations without Meta | `false` (refused in production)                            |
| `APP_LOCALE`               | `en`                                          | `en`                                                       |

With simulation on:

```bash
php8.4 artisan simulation:prepare                         # demo hotels and a simulated WhatsApp number
php8.4 artisan simulation:guests SIM-5550001 --conversations=3
```

Then sign in as `reception.lodge@example.com` / `password` (demo receptionist) to see the conversations and booking requests. These demo accounts use a known password: only on a testing server.

---

## 5. Background work (check once)

The assistant's replies, alerts, e-mails and PDFs run in the background. Two things must be running; without them the site works but guests get no answers.

**Scheduler.** CloudPanel → Site → **Cron Jobs** → add, every minute (`* * * * *`):

```
/usr/bin/php8.4 /home/<site-user>/htdocs/<domain>/artisan schedule:run >> /dev/null 2>&1
```

**Queue workers.** With Redis (`QUEUE_CONNECTION=redis`, the default), Horizon runs them. As root, create `/etc/supervisor/conf.d/hotel-horizon.conf`:

```ini
[program:hotel-horizon]
command=/usr/bin/php8.4 /home/<site-user>/htdocs/<domain>/artisan horizon
user=<site-user>
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/home/<site-user>/logs/horizon.log
redirect_stderr=true
```

then `apt install -y supervisor redis-server` (if missing) and `supervisorctl reread && supervisorctl update`. Without Redis: `QUEUE_CONNECTION=database` in `.env` and `command=… artisan queue:work --tries=3 --max-time=3600` instead.

Check: `supervisorctl status`, and `https://<domain>/up` answers "OK". Errors are in `storage/logs/laravel.log`.

Supervisor is required, not optional: `update.sh` stops Horizon at the end of every update (`horizon:terminate`) so it loads the new code, and only supervisor starts it again. Horizon started by hand (`nohup`, a terminal) stays stopped after the next update, logout or crash, and jobs pile up ("background jobs are waiting"). Use supervisor or the cron job below, never both.

Without root (no supervisor): open the DigitalOcean Droplet Console (it logs in as root), or, as the site user, add this CloudPanel cron job every minute instead; `flock` keeps it to one Horizon and restarts it within a minute after an update or a crash:

```
cd /home/<site-user>/htdocs/<domain> && /usr/bin/flock -n /tmp/hotel-horizon.lock /usr/bin/php8.4 artisan horizon >> /home/<site-user>/logs/horizon.log 2>&1
```

**Backups.** CloudPanel → Cron Jobs, every night (`15 2 * * *`):

```
bash /home/<site-user>/htdocs/<domain>/scripts/cloudpanel/backup.sh >> /home/<site-user>/logs/backup.log 2>&1
```

It dumps the database (`mysqldump --single-transaction`, password from `.env` through `MYSQL_PWD`, never on the command line) and `storage/app` into `~/backups` (only the site user can read it), keeps 14 days, and records the result so Platform → Operations and the go-live check "Backup succeeded in the last 26 hours" see it. Run it once by hand after setting it up. Copy `~/backups` off the server too (CloudPanel's remote backups, or `rclone`): a backup on the same disk does not survive losing the droplet.

**Email.** `MAIL_MAILER=platform` in `.env` (then `php8.4 artisan config:cache`), and enable a provider in Platform → Email providers. With `MAIL_MAILER=log` nothing is sent, even though a provider's "Send test" button works (it talks to the provider directly). `update.sh` and Platform → Operations warn about both.

---

## 6. Real WhatsApp on a testing server

Simulation and real WhatsApp work side by side: only the simulated demo numbers (`SIM-…`) go to the simulator, every other number goes to Meta.

1. In `.env`: `WHATSAPP_APP_ID`, `WHATSAPP_APP_SECRET` (Meta app → App settings → Basic) and `WHATSAPP_VERIFY_TOKEN` (any long random text, `openssl rand -hex 24`). Then `php8.4 artisan optimize`.
2. Meta app → Use cases → WhatsApp → Customize → Configuration: callback URL `https://<domain>/webhooks/whatsapp`, the same verify token, **Verify and save**, subscribe to **messages**. **Publish** the app: an unpublished app receives no real messages.
3. **Meta's test number** (no Embedded Signup needed): Business settings → System users → add an admin system user, assign the app and the WhatsApp Business account (full control), **Generate token** with `whatsapp_business_messaging` and `whatsapp_business_management`, expiry **Never**. Then Platform → Hotels → the hotel → **Connect a WhatsApp number manually (testing)**: Phone Number ID and WhatsApp Business account ID from WhatsApp → API Setup, and the token. The test number can message up to 5 numbers added as recipients in API Setup.
4. Answers stay simulated while `SIMULATION_FAKE_AI=true` (the default). For real AI answers: add a provider in Platform → AI, set `SIMULATION_FAKE_AI=false`, `php8.4 artisan optimize`.
5. A hotel's own number, later: `WHATSAPP_CONFIG_ID` (Facebook Login for Business → Configurations → WhatsApp Embedded Signup) and the hotel connects from Hotel → WhatsApp.

**AI answers:** Platform → AI → Add provider (OpenAI recommended: one key covers chat and voice notes) → Add model → **Load models from the provider**: pick a chat model (chat + tools) and a speech model (`gpt-4o-transcribe` or `whisper-1`, transcription) → Run test suite → set them as main models. Then `SIMULATION_FAKE_AI=false` and `php8.4 artisan optimize`.

**Card payments in test mode (Stripe):** Platform → Billing → Payment gateways → Add → Stripe, mode Test, the `sk_test_…` secret key, currencies `XOF`. In Stripe (test mode): Developers → Webhooks → Add endpoint, the URL shown under the form, events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`; paste its signing secret (`whsec_…`). Test card `4242 4242 4242 4242`, any future date, any CVC. A hotel can take card deposits the same way in Hotel → Deposits (its own Stripe account and webhook).

Manual connection is for super admins only and is off in production unless `WHATSAPP_MANUAL_CONNECT=true`.

---

## 7. Common problems

| Problem                                                    | Fix                                                                                                                                                                                                                                                                                                                                                                     |
| ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Blank page or "Vite manifest not found"                    | The screens were not built: run the update script (or `PHP_BIN=php8.4 npm run build`)                                                                                                                                                                                                                                                                                   |
| 500 error after an update                                  | `tail -50 storage/logs/laravel.log`; often a missing `.env` setting or the wrong PHP version                                                                                                                                                                                                                                                                            |
| "Permission denied" on storage                             | You ran a command as root: `chown -R <site-user>:<site-user> storage bootstrap/cache` (as root)                                                                                                                                                                                                                                                                         |
| No e-mails arrive                                          | Platform → Email providers, or the `MAIL_*` settings in `.env`                                                                                                                                                                                                                                                                                                          |
| "Connection could not be established" on port 587          | DigitalOcean blocks outgoing SMTP on new servers: use Brevo with an **API key** (sent over HTTPS), or ask DigitalOcean support to open SMTP                                                                                                                                                                                                                             |
| The assistant never answers                                | Queue workers not running: `supervisorctl status` (section 5)                                                                                                                                                                                                                                                                                                           |
| Reminders, undo, morning summary never happen              | The scheduler cron job is missing (section 5)                                                                                                                                                                                                                                                                                                                           |
| "The MAC is invalid" in the log                            | Something was saved under an earlier `APP_KEY`. Put the old key in `APP_PREVIOUS_KEYS=base64:…` and `php8.4 artisan optimize`; without it, enter the provider keys again. Never change `APP_KEY` on a server with data . A WhatsApp number shows "Reconnect required": the super admin enters its access key again (Platform → Hotels → the hotel → Support and access) |
| Horizon "running" but jobs keep piling up                  | `APP_ENV` must match an entry in `config/horizon.php` (production, staging, local, or the `*` catch-all). Check with `php8.4 artisan horizon:supervisors`: it must list at least one supervisor                                                                                                                                                                         |
| Build fails with "Could not load …/resources/js/actions/…" | Old cached routes: `php8.4 artisan optimize:clear`, then run the update again (the script does this itself from 0.31.2)                                                                                                                                                                                                                                                 |
| `wayfinder:generate` fails during the build                | The build used an older PHP: `PHP_BIN=php8.4 npm run build` (the script does this)                                                                                                                                                                                                                                                                                      |
