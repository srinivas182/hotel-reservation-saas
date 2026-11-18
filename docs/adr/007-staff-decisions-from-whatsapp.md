# ADR-007: Staff decide booking requests from WhatsApp buttons, with a one-minute undo

- **Status:** Accepted
- **Date:** 2026-11-18

## Context

Owners and managers in West African hotels live on WhatsApp; opening the dashboard to accept a request is the slowest step between a guest's question and a confirmed booking (usability Sprint B). A button in WhatsApp is weaker proof of identity than a dashboard login with two-step verification.

## Decision

- Buttons carry a **signed code** (HMAC with the application key) that names the hotel, the person, the request and the action, and expires with the request. A tap counts only from the **phone the card was sent to**, from an **active member** with the permission to decide requests and the personal setting on (default: owners and managers).
- A tap **schedules** the decision; it is carried out after **one minute**, during which an Undo button cancels it. Nothing is booked or released during the minute. Requests expiring in under 90 seconds are decided at once.
- The decision then goes through `BookingRequestService`, exactly as from the dashboard, and is audited as made from WhatsApp. A **changed price** is never accepted from WhatsApp.
- Taps are handled before conversations: they never become guest messages.
- The mechanism lives in `app/Domains/StaffActions`, reusable for other staff decisions.

## Alternatives considered

- **Decide at once, reverse on undo.** Rejected: an undo would cancel a real booking and re-hold rooms, with more ways to fail.
- **A link to a one-time web page instead of buttons.** Rejected for now: one more screen on a weak connection; can be added for actions needing more input.
- **No undo.** Rejected: fat-finger taps on small screens are common, and a wrong decline is sent to the guest.

## Consequences

- Guests hear back about one minute later when staff answer from WhatsApp.
- Someone holding an owner's unlocked phone can decide requests: the same risk as the WhatsApp Business app itself; each person can turn the setting off, and every decision is audited.
- The `booking_request_decision` template must be approved by Meta for buttons outside the 24-hour window; until then the plain alert is sent.
