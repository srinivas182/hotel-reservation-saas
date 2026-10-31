# ADR-004: Hotel data separation fails closed

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

In a shared database, the most damaging failure is code that runs without a current hotel and silently returns or changes data for every hotel. This typically happens in queued jobs, console commands and scheduled tasks, where there is no request.

## Decision

- Querying a hotel-owned model with no current hotel **throws** `MissingHotelContext`. It never returns unscoped data.
- Creating a hotel-owned record always takes `hotel_id` from the current hotel; values supplied by callers are ignored. Records can never be moved to another hotel.
- Queued jobs carry the hotel they were dispatched for and run as that hotel; the previous context is restored afterwards. Jobs implementing `RequiresHotelContext` cannot be dispatched without a hotel.
- Console commands and scheduled work must set a hotel explicitly (`TenantContext::run()`) or loop with `TenantContext::eachHotel()`.
- Cross-hotel access for platform staff is only possible through `TenantContext::bypass($reason, ...)`, and every use is written to the audit log.
- The current hotel is resolved before route-model binding, so a record from another hotel in a URL returns 404.

## Consequences

- Mistakes surface as exceptions in development and tests rather than as data leaks in production.
- `dispatch()` returns a pending dispatch that is sent when it goes out of scope. Inside `TenantContext::run()`, dispatch in a statement (not as the return value of an arrow function) so the job is sent while the hotel is still set.
- `TenantContext` is a scoped binding, so it is reset between requests (Octane-safe) and between queued jobs.
