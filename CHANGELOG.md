# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### S2.3 — Availability, holds and calendar

#### Added

- Availability per room category and night: rooms in service minus closures, rooms held back, active holds and booked rooms; nothing available on stop-sell nights. `AvailabilityService` answers any range in a fixed number of queries (four for any number of categories and nights).
- Room holds while a booking is in progress (`HoldService`): place, extend, release, expire and convert to booked rooms (used by bookings in S3). Writers lock each night's inventory row in date order inside one transaction, so two requests for the last room are served one after the other and only one succeeds. A lapsed hold stops counting immediately; `holds:expire` (every minute) keeps statuses accurate; a lapsed hold can be revived only if the rooms are still free.
- `inventory:rebuild` recalculates booked counts from their source records (converted holds until bookings arrive in S3).
- Availability calendar (`/hotel/calendar`): categories against 14 or 30 nights with rooms free / in service and the nightly price, colour-coded; range actions to stop or reopen sales, hold rooms back, or set a price (as a rate override); manual holds with extend and release; and "Try a stay".
- Quotes now use live availability, including holds, held-back rooms and calendar stop-sell.
- A room category with holds can no longer be deleted.
- A MySQL concurrency test where two database connections compete for the last room.
- English and French text for the new screens.

#### Changed

- Nightly prices and restrictions after overrides are worked out in one place (`NightlyRates`) for quotes and the calendar; "Try a stay" is a shared component on the rates and calendar pages.

### S2.2 — Rates and quote engine

#### Added

- Rates page (`/hotel/rates`): per room category a nightly rate (covering the category's standard number of guests), extra adult, child and extra-bed charges per night, breakfast (included, optional with a price per person per night, or not offered) and a minimum stay. Amounts are stored in minor units of the hotel currency.
- Date overrides for one or all categories, optionally on chosen weekdays only: a different nightly rate, a minimum stay, stop-sale or no arrivals. When overrides overlap, the most recently changed one sets the price (overlaps are flagged); restrictions from all of them apply.
- Hotel-wide pricing settings: up to three child age bands (free, child rate or adult rate; older children pay as adults) and how long rooms are held while a booking is in progress.
- `QuoteService`: the single source of every price. Prices each night, spreads guests across the rooms asked for, charges extras, applies room taxes (rooms scope) and breakfast taxes (food & beverage scope) with the tax calculator, shows rooms free per night, and reports stable issue codes (minimum stay, no arrivals, stop-sale, not enough rooms, too many guests, one adult per room, invalid or past dates, stays over 30 nights, no rate, category not on sale, extra bed or breakfast not offered).
- "Try a stay" on the rates page, showing exactly what a guest would be quoted.
- Audit entries for rates, overrides and pricing settings; reference rates for First Class Lodge in the demo data; English and French text.

#### Changed

- The hotel currency can no longer be changed once room rates exist.

#### Fixed

- Dates without times (room closures, tax and override date ranges) are stored as plain dates, so date-range queries are correct on SQLite as well as MySQL.

### S2.1 — Room categories, rooms and closures

#### Added

- Rooms page (`/hotel/rooms`): room categories with names and descriptions per enabled language, a short code, guests included in the price, maximum adults, children and guests, extra bed, amenities from a fixed list, on-sale switch and order. Categories with rooms cannot be deleted (switch them off instead).
- Rooms: unique number per hotel, category, floor, status (in service, out of order, maintenance), notes; add one room or a numbered range (up to 200 at a time, existing numbers skipped).
- Room closures for date ranges with a reason (both dates are closed nights); overlapping closures are refused.
- `RoomInventory`: sellable rooms per category and night (rooms in service minus closures) in two queries for any date range; the base for availability in S2.3.
- New permissions: `rooms.view`, `rooms.manage`, `rates.view`, `rates.manage`, `availability.view`, `availability.manage`. Reception can view rooms, rates and availability and manage availability; Read-only can view them.
- All changes audited; English and French text (496 strings).
- Local demo data: First Class Lodge with its 4 categories and 26 rooms.

### S1.4 — Email platform and usage

#### Added

- Email providers managed by super admins in platform admin (`/platform/mail-providers`): Brevo, Amazon SES, Postmark, Mailgun, Resend and any SMTP server, all over authenticated SMTP. Enable or disable, order of use, sending address on the verified platform domain, test email with the last result. Credentials are encrypted and never sent back to the browser (a saved secret is kept when its field is left blank). Changes are audited.
- `platform` mailer: tries enabled providers in order and fails over to the next; sends from the provider's address while keeping the display name; uses `MAIL_FALLBACK_MAILER` when no provider is enabled (Mailpit locally, array in tests).
- Email categories (security, account, guest, operational, marketing). Security and account emails are never counted or blocked; the others are checked against the hotel's allowance (unlimited until packages arrive in S13) and logged as blocked when refused.
- Email log of every attempt (sent, failed, blocked) with hotel, provider and recipients; subjects of security emails are not stored; entries are pruned after 90 days.
- Append-only usage ledger (`usage_records`) recording each sent countable email once per recipient; the basis for credits and billing in S13.
- Hotel email identity in Hotel settings: sender name (defaults to the hotel name) and a reply-to address that is used only after it is confirmed by email (48-hour link); until then replies go to the hotel's contact email.
- Usage page (`/hotel/usage`, new `usage.view` permission for Owner, Manager and Read-only): emails this month by type, failures, the last 30 days, and the limit ("Unlimited (pilot)").
- Platform-wide email totals for the last 30 days on the providers page.
- English and French text for all new screens and emails.

#### Changed

- `MAIL_MAILER` defaults to `platform`; staff invitations are tagged as security emails.

### S1.3 — Languages, formatting, hotel audit log and platform users

#### Added

- English and French throughout the interface and emails. Translations live in `lang/en.json` and `lang/fr.json` (English text as the key) and are shared by Laravel (`__()`) and React (`t()`); French framework messages (validation, sign-in, passwords) from Laravel Lang (MIT) with field names translated.
- Language chosen per request: the user's own choice, then the visitor's session choice, then the hotel's default language, then the browser's preferred language, then English. Language switcher in the user menu (saved on the account). Emails and notifications follow the recipient's language.
- `php artisan translations:check` fails when a string used in code is missing from `en.json` or another language misses, adds or leaves empty a key; runs in CI.
- `useFormat()` for dates, times, money and numbers in the user's language and the hotel's time zone and currency.
- Hotel audit log page (`/hotel/audit`, permission `audit.view`): filter by event, person and dates; current hotel only.
- Platform users (`/platform/users`): search, deactivate and reactivate accounts (sessions end immediately, sign-in refused), invite MCS staff as super admin or support (password link by email; two-factor required).
- Role choice when adding an existing user to a hotel in platform admin.

#### Changed

- `/` now sends visitors to sign-in and signed-in users to the dashboard; the starter-kit welcome page is removed.
- Users without their own language choice follow the hotel's default language (`preferred_locale` is now optional).
- The current hotel is cleared at the start of every request before being resolved.

### S1.2 — Hotel settings, money and taxes

#### Added

- Hotel settings page (`/hotel/settings`): name and legal name, contact details (phone and WhatsApp number in international format, email, address, map link), check-in and check-out times, latest late check-out, staffed hours per weekday (up to three periods a day), time zone, currency, default and enabled languages, and a preview of dates and money. Viewing needs `hotel.settings.view`; changing needs `hotel.settings.manage`. Changes are audited.
- One `hotel_settings` row per hotel, created with every new hotel (and for existing hotels by the migration).
- Money handled with `brick/money` in minor units; `MoneyFormatter` formats amounts with the locale's conventions.
- Taxes (`/hotel/taxes`): percentage or fixed per night, per person per night or per stay; included in prices or added on top; applies to rooms, food & beverage and/or events; active switch, start and end dates, order; names per enabled language. Audited.
- `TaxCalculator`: included taxes are worked back from the price (fixed first, then percentages, no tax on tax), added taxes are calculated on the net price; each tax is rounded to the currency's minor unit and the net absorbs rounding so totals always reconcile. Tested in FCFA, euro and rupee.
- "Try it" preview on the taxes page using the same calculator as the server.
- Hotel settings and Taxes appear in the sidebar for users allowed to see them.

### S1.1 — Hotel roles, staff management and invitations

#### Added

- Permissions per hotel with `spatie/laravel-permission` in teams mode (the hotel is the team; ULID keys). The current hotel sets the permission team automatically.
- Permission catalogue in code (`App\Domains\Access\Permission`) and six system roles copied into every hotel: Owner, Manager, Reception, Restaurant & Bar, Events coordinator, Read-only. `php artisan permissions:sync` keeps them in line with the code and runs on every deployment.
- Custom roles: hotels create roles by ticking permissions; system roles cannot be edited; roles in use cannot be deleted.
- Staff page: roles, suspend and restore, remove, primary-owner transfer (password confirmation). Safety rules: no changes to your own access, only owners grant or remove the Owner role, the primary owner cannot be removed, and a hotel always keeps an active owner.
- Email invitations valid for 7 days, stored as token hashes, rate-limited per hotel; new people create an account from the link (email counts as verified), existing accounts sign in and accept; resend and cancel. Owner invitees go straight to two-factor setup.
- The current hotel's permissions are shared with the frontend; the Staff menu appears for users who can see staff.
- Audit entries for invitations, role changes, custom roles and primary-owner changes.

#### Changed

- Hotel owners assigned in platform admin and demo data receive the Owner role; platform admin can choose a role when adding an existing user.
- Two-factor authentication is required for anyone holding the Owner role in any hotel.
- Role and permission relations are reloaded whenever the current hotel is resolved, so rights from one hotel are never reused in another.

### S0.3 — App shell, platform admin, security headers, monitoring and deployment

#### Added

- App shell: hotel switcher in the sidebar, Hotel and Platform admin menus (platform menu only for MCS staff); starter-kit links removed.
- Platform admin (`/platform`): hotel list with search and pagination, create and edit hotels (slug generated and kept unique, owner by email), add existing users to a hotel and remove them. Support staff can view; only super admins can change. All changes are audited with the acting user.
- Security headers on every page: Content Security Policy with per-request nonce (report-only, switchable to enforcing with `CSP_ENFORCE`), HSTS over HTTPS, frame blocking, no MIME sniffing, referrer and permissions policies; `/csp-report` endpoint logs violations.
- Structured JSON log channel (`LOG_CHANNEL=json`); every log line carries `request_id`, `user_id` and `hotel_id`.
- `/health` (token-protected): database, Redis, queue-worker heartbeat and scheduler heartbeat; returns 503 with details when anything fails. Scheduler and queue heartbeats run every minute.
- Production images: PHP (`prod`) and Nginx with the release's assets (`web`), built per commit; PHP-FPM container health check.
- `compose.prod.yaml` (Caddy with automatic HTTPS, Nginx, PHP-FPM, Horizon, scheduler, MySQL 8.4, Redis 7), `deploy.sh` (build, pre-migration backup, migrate, swap, health check, automatic rollback) and `docker/backup/backup.sh` (encrypted, compressed MySQL dumps with 14-day retention).
- `docs/deployment.md`: server setup, releases, monitoring, backups and restore.

#### Changed

- Trusted proxy ranges configured for the Docker network so HTTPS is detected behind Caddy.
- CI builds and smoke-tests the `prod` and `web` images when Docker or deployment files change.
- CI publishes the last lines of a failing build or test step as an error annotation on the PR, so failures are readable without downloading logs.

### S0.2 — Hotel data separation, sign-in security and audit log

#### Added

- Hotels (`hotels`) and memberships (`hotel_user`), with ULID keys, hotel status (trial, active, suspended, closed) and an optional owner.
- `TenantContext` (scoped per request and per job) and the `BelongsToHotel` trait: every hotel-owned query is limited to the current hotel and **fails closed** when no hotel is set; `hotel_id` is always taken from the current hotel; records cannot move between hotels.
- Current hotel resolved from the session and verified against an active membership before route-model binding; hotel switcher endpoint; `hotel` route middleware for hotel-only pages; current hotel and switchable hotels shared with the frontend.
- Queued jobs carry and restore their hotel; jobs implementing `RequiresHotelContext` cannot be dispatched without one. `TenantContext::run()`, `eachHotel()` and an audited `bypass()` for platform staff.
- Mandatory two-factor authentication for hotel owners and platform staff.
- Password policy: at least 12 characters with letters and numbers, checked against known breached passwords.
- Append-only audit log (`spatie/laravel-activitylog`, extended with hotel, request ID, IP address and browser): sign-ins, failed sign-ins, lockouts, sign-outs, password resets, two-factor changes, hotel changes, membership changes and scoping bypasses.
- Request ID on every request (`X-Request-Id`), added to logs and audit entries.
- Platform roles (`super_admin`, `support`); Horizon dashboard limited to super admins.
- Local demo seeder: platform admin and two hotels with owners and receptionists.
- ADR-001 (shared database with hotel separation) and ADR-004 (fail-closed separation).
- Tests: hotel scoping, 404 on other hotels' records, queue context, membership and switching, an automated check that every model with `hotel_id` is scoped, two-factor enforcement, recovery codes, password policy, audit immutability and request IDs.

#### Changed

- Users have ULID IDs, first and last names (a full `name` is still provided), phone, preferred language, platform role, last sign-in time and soft deletes.
- Sign-in throttling now records a lockout entry in the audit log.

#### Fixed

- Test-only fixture migration now always runs after the application's migrations (MySQL rejected its foreign key to `hotels`).

### S0.1 — Skeleton, tooling and CI

#### Added

- Laravel 13 application from the official React starter kit (React 19, Inertia 3, TypeScript, Tailwind 4, shadcn/ui) with Fortify authentication: login, password reset, email verification, password confirmation and two-factor authentication. Public registration and passkeys are disabled.
- Pest 4 test runner with an Arch suite: no debugging statements, strict types in `App`, controller naming, `env()` only in config.
- Larastan raised to level 8; Pint enforces `declare(strict_types=1)` and strict comparisons.
- Vitest with React Testing Library for frontend component tests (`npm test`).
- Laravel Horizon (dashboard closed outside local until platform roles arrive in S0.2).
- Docker Compose development stack: PHP 8.4 FPM, Nginx, MySQL 8.4, Redis 7, Horizon, scheduler, Mailpit and an optional Vite service; `Makefile` shortcuts.
- GitHub Actions CI: draft PRs run quick checks only; ready PRs add only the affected suites; single required **CI gate** check; gitleaks secrets scan; Composer and npm audits; pinned action SHAs.
- Documentation: README, CONTRIBUTING (sprint and CI rules), ADR-002, ADR-003 and ADR-005.

#### Changed

- Fonts are self-hosted (Inter via Fontsource) instead of fetched from a third-party CDN at build time.
- Feature tests run without Vite, so backend CI does not need a frontend build.
