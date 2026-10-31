# ADR-001: Shared database with hotel-level data separation

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

The product is sold on subscription to many small hotels at low monthly prices. Each hotel needs its data kept strictly separate, fast self-service onboarding, and low infrastructure cost per hotel. Some larger customers may require full isolation.

## Decision

- All hotels share one application and one MySQL database.
- Every hotel-owned table has a `hotel_id` column (ULID) and a composite index that starts with `hotel_id`.
- Every hotel-owned model uses the `BelongsToHotel` trait, which limits all queries to the current hotel and sets `hotel_id` on create (see ADR-004).
- The current hotel lives in `TenantContext`, resolved per request from the user's session and verified against their active membership.
- Users are not hotel-owned. Access to hotels is through `hotel_user` memberships; platform roles (MCS staff) are separate from hotel roles.
- Enterprise customers who need full isolation receive a dedicated deployment of the same codebase.

## Alternatives considered

| Option                      | Why not                                                                                                                                                   |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Database per hotel          | Higher cost and operational load per hotel (migrations, backups, connections); slow onboarding. Kept as the Enterprise option via a dedicated deployment. |
| Schema per hotel            | Same operational drawbacks as database per hotel in MySQL.                                                                                                |
| Third-party tenancy package | Adds an abstraction we would have to work around; the required behaviour is small and is fully covered by our own tests.                                  |

## Consequences

- A single missed scope would leak data, so separation fails closed (ADR-004) and an automated test fails the build if any model with a `hotel_id` column lacks `BelongsToHotel`.
- Reporting across hotels is possible for platform staff, but only through the audited bypass.
