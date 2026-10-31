# ADR-002: Laravel 13, PHP 8.4 and MySQL 8.4 LTS

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

The MCS standard stack lists Laravel 11 and MySQL 8.0. Laravel 11 left security support in March 2026 and MySQL 8.0 reached end of life in April 2026. This is a new product expected to run for many years, so it should start on supported versions.

## Decision

- **Laravel 13** (current major, bug fixes to Q3 2027, security fixes to Q1 2028).
- **PHP 8.4** in Docker and on servers. The code stays compatible with PHP 8.3, Laravel 13's minimum.
- **MySQL 8.4 LTS** for all environments, including CI.
- **Redis 7** for cache, sessions and queues.

## Alternatives considered

| Option          | Why not                                                                                |
| --------------- | -------------------------------------------------------------------------------------- |
| Laravel 11 / 12 | 11 has no security support; 12 would force an early major upgrade.                     |
| MySQL 8.0       | End of life.                                                                           |
| PostgreSQL      | Strong option, but MySQL matches team experience and every target hosting environment. |

## Consequences

- One major Laravel upgrade per year is expected; Laravel majors are typically low-effort.
- CI runs on PHP 8.4 and MySQL 8.4, matching production.
