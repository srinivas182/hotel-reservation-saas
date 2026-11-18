# ADR-008: The staff app is an installable web app (PWA) with Web Push, not a store app

- **Status:** Accepted
- **Date:** 2026-11-19

## Context

Staff need alerts on their phones that are free (WhatsApp templates cost the hotel a Meta fee each), quick to answer, and usable on weak connections (usability Sprint D). Most staff phones in Côte d'Ivoire are Android.

## Decision

- The dashboard is an **installable web app**: manifest, service worker, original icon. No app store.
- **Web Push with VAPID** (`minishlink/web-push`, OpenSSL only): one key pair per deployment, made at provisioning.
- Alerts go to the app **first**, and WhatsApp follows if a notification is **not opened within 5 minutes**. Staff without the app get WhatsApp as before.
- Taps on notification buttons reuse the **Sprint B signed codes** (ADR-007); the service worker has no session or CSRF token, so the code is the proof. It also needs the person to still have the app on a phone in that hotel.
- Offline: staff pages from the network, the last copy when there is no connection; only guest replies are queued.

## Alternatives considered

- **Native Android/iOS apps.** Rejected for now: store accounts, reviews and two codebases for the same screens; updates would not reach phones at the same time as the server.
- **Firebase Cloud Messaging SDK.** Rejected: an extra account and SDK; standard Web Push reaches Chrome (through FCM), Firefox, Edge and Safari without it.
- **Queue every offline form.** Rejected: replaying a booking change minutes later can do the wrong thing; replies are safe to send late.

## Consequences

- On iPhone, notifications need the app on the home screen (iOS 16.4+); the app explains how.
- The push services see only encrypted payloads; subscription details are stored encrypted.
- Losing the VAPID keys means every phone subscribes again.
