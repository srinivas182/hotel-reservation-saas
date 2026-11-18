# ADR-006: Set-up import reads the owner's own material, not Booking.com or Google

- **Status:** Accepted
- **Date:** 2026-11-18

## Context

Usability Sprint A lets a new hotel start from what it already has instead of typing everything. The first idea was to paste a Google Business or Booking.com link and pre-fill name, address, photos, rooms and FAQs from it.

## Decision

- The import reads **material the owner controls**: their own website (one page, robots.txt respected), a Google Maps link (place name and pin are read from the link itself, no Google service is called), text they paste (copied from their own Booking.com, Google or Facebook page), and photos or PDFs of their brochure or rate card (read by the AI gateway with attachments).
- Booking.com and Google pages are **never fetched** by the platform.
- The result is a **draft reviewed item by item**; nothing reaches the hotel's data until the owner ticks it and presses "Use these".
- Pages are read by `SafeFetcher` (public addresses only, connection pinned to the checked address, redirects checked, size and time limits).

## Alternatives considered

- **Fetch Booking.com listing pages.** Rejected: no public listing API, and their terms forbid automated copying; the importer would also break whenever the page changes.
- **Google Places API.** Rejected for now: a paid key per call, and Google's terms do not allow keeping its photos and reviews; the pin from the link gives the location at no cost. Can be added later as an option if owners need it.
- **AI reading only, no review screen.** Rejected: a wrong price or time sent to guests is costly; the owner must confirm.

## Consequences

- Works for hotels with only a Facebook page and a paper rate card, which is common in West Africa.
- Photos for the hotel's page still come from the owner's own uploads.
- Reading photos depends on the AI provider; when it fails, the text is read alone and the owner is told.
