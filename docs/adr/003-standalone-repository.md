# ADR-003: Standalone repository, not the GMLM core

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

MCS already operates the GMLM platform, whose architecture gives each client an isolated deployment and database. The hotel product is a subscription SaaS for many small hotels at low monthly prices, which needs fast self-service onboarding and low per-hotel infrastructure cost.

## Decision

Build the hotel product in its own repository, `srinivas182/hotel-reservation-saas`, as a single shared platform with hotel-level data separation (see ADR-001). Reuse team skills and conventions, not the GMLM codebase.

## Alternatives considered

| Option               | Why not                                                                                                                   |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Extend the GMLM core | Its per-client deployment model conflicts with shared multi-hotel tenancy; mixing domains would complicate both products. |
| Fork GMLM            | Inherits MLM-specific code and debt with no benefit for hospitality.                                                      |

## Consequences

- Independent release cycle and versioning.
- Enterprise customers who require full isolation can still receive a dedicated deployment of this same codebase.
