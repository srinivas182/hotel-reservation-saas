# Contributing

These rules keep delivery predictable and CI minutes low. They apply to every sprint.

## Sprint workflow

1. **Status check first.** Before starting work, confirm the state of `main`, open pull requests and recent CI runs. Never start a sprint whose work already exists in an open or merged PR.
2. **Specification before code.** Each sprint starts from an approved, detailed specification. Anything the high-level plan missed is added to the spec, not improvised in code.
3. **One sprint at a time.** A sprint is merged with a green CI gate before the next sprint begins.
4. **Small pull requests.** A sprint is delivered as a few focused PRs (for example S0.1, S0.2, S0.3), each needing one full CI run.

## Before you push

Run the local gate. It must pass before any push:

```bash
make check
```

This runs Pint, Larastan (level 8), `vp check`, the TypeScript compiler and the Pest suite.

## Pull requests and CI

| PR state         | What CI runs                                                                                                           | Target time |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------- | ----------- |
| Draft            | Quick checks: secrets scan, Pint, Larastan, Composer audit, `vp check`, TypeScript, npm audit                          | < 3 min     |
| Ready for review | Quick checks plus only the affected suites: backend (Pest on MySQL 8.4), frontend (Vitest + build), Docker image build | < 10 min    |

- Open PRs as **draft** while work is in progress. Mark **ready for review** only when the local gate passes; this triggers the single full run.
- Documentation-only changes skip CI.
- CI does not run on `main` after merge.
- The **CI gate** check is the only required status check. It fails on draft PRs by design, so drafts can never be merged.
- Do not push repeatedly to "see if CI passes". Fix locally, then push once.

## Branches and commits

- `main` is protected. All changes arrive through pull requests.
- Branch names: `s{sprint}.{part}-short-description`, for example `s0.1-skeleton-tooling-ci`.
- Commit messages: imperative mood, short summary line, for example `Add hotel tenancy scope`.

## Code standards

- `declare(strict_types=1);` in every PHP file (enforced by Pint and an architecture test).
- Controllers stay thin; business logic lives in services or domain classes.
- No `dd`, `dump`, `ray` or `env()` outside config (enforced by architecture tests).
- Frontend: strict TypeScript, shadcn/ui components, Wayfinder for typed routes.
- Never commit secrets. `.env` is ignored; gitleaks scans every PR.
