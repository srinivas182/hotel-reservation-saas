# Release process (S16)

## Versions

- The version is in `VERSION` (semantic versioning). It is shown by `/health` and sent with error reports.
- Until commercial launch: `0.<sprint>.<part>` (S16.3 → `0.16.3`). Launch is `1.0.0`.
- Every release has a section in `CHANGELOG.md` (what changed for hotels and for the platform team).

## Before merging

- CI green (quick checks, backend on MySQL, frontend, Docker, load smoke when relevant).
- **Migrations add, never remove or rename.** `tests/Unit/MigrationSafetyTest.php` fails on a dropped or renamed column or table, or a column change, inside `up()` unless the same `up()` explains why it is safe with `// migration-safety: <reason>`. A rollback switches code back but does not reverse migrations, so the previous release must keep working on the new schema.
- Removing something takes two releases: first stop using it, then drop it in a later release with a `migration-safety` note.

## Browser tests

- `tests/e2e` (Playwright): run in CI on pull requests that touch screens, and nightly.
- Locally: prepare a simulated staging (`SIMULATION_MODE=true php artisan e2e:prepare`), start `php artisan serve` and a queue worker, then `npx playwright test` (set `PW_CHROMIUM_PATH` to use a local Chromium).

## Releasing (staging first, then production)

1. Update `VERSION` and the `CHANGELOG.md` heading; merge.
2. `./deploy.sh` on the server: fetch → build images tagged with the commit → **database backup** → migrations → swap containers → health check. If the health check fails, the previous release is restored automatically.
3. Smoke check: log in, Today, inbox, send a test WhatsApp message on staging, `/health` shows the new version.
4. Watch error tracking and the Operations page for 30 minutes.

## Rolling back

- `./deploy.sh --rollback` switches back to the previous release's images (seconds).
- Data changes are not reversed; restore from the pre-deploy backup only if a migration damaged data (see `docs/operations.md`, backups and restore).

## Error tracking

- Set `SENTRY_LARAVEL_DSN` (Sentry or a self-hosted GlitchTip). Off when empty.
- Reports carry the version and the request reference shown on error pages; request bodies, cookies, auth headers, user emails and IP addresses are removed, and phone numbers, emails and secrets are redacted from messages.
