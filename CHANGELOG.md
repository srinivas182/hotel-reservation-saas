# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
