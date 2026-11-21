# Hotel Reservation SaaS

Multi-hotel SaaS platform that lets guests book rooms, tables and events over WhatsApp, while hotel staff manage availability, approvals and guest messaging from a phone-friendly dashboard. Built by **Mayura Consultancy Services**.

> Status: **0.27.1** — usability sprints A–H, the Sprint I design and the Sprint J website delivered; staging deployment pending server access. See `CHANGELOG.md`.
>
> **New here?** Read the [developer guide](docs/developer-guide.md): local set-up, DigitalOcean, default accounts and roles. Server reference: [`docs/deployment.md`](docs/deployment.md).

## Stack

| Layer    | Choice                                                          |
| -------- | --------------------------------------------------------------- |
| Backend  | Laravel 13, PHP 8.4 (8.3+ supported)                            |
| Frontend | React 19, Inertia 3, TypeScript (strict), Tailwind 4, shadcn/ui |
| Data     | MySQL 8.4 LTS, Redis 7                                          |
| Queues   | Laravel Horizon                                                 |
| Auth     | Laravel Fortify (email verification, two-factor authentication) |
| Quality  | Pest 4, Larastan level 8, Pint, Vite+ (`vp check`), Vitest      |

Architecture decisions are recorded in [`docs/adr`](docs/adr).

## Getting started (Docker)

Requirements: Docker with Compose v2, GNU Make.

```bash
cp .env.example .env
make up          # start app, nginx, mysql, redis, horizon, scheduler, mailpit
make install     # composer install, app key, migrations, frontend build
```

- App: http://localhost:8080 (demo sign-ins, all with the password `password`: `admin@example.com` for the platform, `owner.lodge@example.com` and `reception.lodge@example.com` for First Class Lodge; see the [developer guide](docs/developer-guide.md#4-default-accounts-and-passwords))
- Mailpit (captured email): http://localhost:8025
- Frontend dev server with hot reload: `docker compose --profile frontend up vite`

Run `make help` for all commands.

## Everyday commands

| Command      | What it does                                                |
| ------------ | ----------------------------------------------------------- |
| `make check` | Pre-push gate: Pint, Larastan, `vp check`, TypeScript, Pest |
| `make test`  | Full Pest suite in parallel                                 |
| `make fix`   | Auto-fix PHP and frontend formatting                        |
| `make fresh` | Rebuild the database with seed data                         |
| `npm test`   | Frontend component tests (Vitest)                           |

## Project layout

```
app/                 Laravel application (business areas move to app/Domains/* from S0.2)
resources/js/        React + TypeScript frontend (pages, components, layouts)
docker/              PHP and Nginx images for local and server environments
docs/adr/            Architecture decision records
tests/               Pest: Arch, Unit and Feature suites
```

## Contributing

Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a pull request. In short: one sprint at a time, local checks before pushing, draft PRs run quick checks only, and nothing merges without a green **CI gate**.

## License

Proprietary. © Mayura Consultancy Services. All rights reserved.
