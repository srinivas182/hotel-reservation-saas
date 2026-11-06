# Cut-over plan — First Class Lodge

Owner: MCS (delivery lead) with CNH (project lead) and the hotel manager.
Every step is reversible: switching the assistant **Off** returns all
conversations to staff immediately, and the WhatsApp Business app keeps
working throughout (coexistence).

## Before day −7

- Production and staging servers live with HTTPS; `deploy.sh` run; backups and the restore drill green; uptime monitor and ops alerts configured.
- Meta: business verification done, App Review approved, Embedded Signup configuration ready.
- All templates submitted early (approval can take days): booking_confirmed, booking_declined, request_expired, booking_deposit_request (if deposits), staff_alert, owner_daily_summary, stay_reminder, checkout_reminder, stay_thank_you — French and English.
- AI: two providers configured, assistant model approved with a passing test suite.
- Staging: load tests and failure drills passed (`docs/failure-drills.md`).
- CNH: information pack written (FR/EN), deposit policy, reception hours, review link, privacy notice reviewed by their adviser, data processing agreement signed.

## Day −7 to −3: set-up and training

- Hotel set-up on production with real rooms, rates and taxes; staff accounts; alerts and owner summary set.
- Training session 1 (reception, 90 min): Today screen, requests, inbox, practice screen.
- Training session 2 (reception + owner, 60 min): supervised drafts, handover, what to do when.

## Day −3: connect the number (assistant Off)

- Owner connects the existing number with Embedded Signup (coexistence). Reception keeps using the WhatsApp Business app; the inbox mirrors conversations.
- Check: test messages from MCS and CNH phones arrive; receipts update; Go-live page all green except the assistant mode.

## Day 0: Supervised, during reception hours

- Mode **Supervised**, 08:00–20:00 (switch Off at night for the first three days).
- MCS reviews every conversation daily; issues logged with severity and owner.
- Daily 15-minute call with the hotel at 18:00.

## Day 3: Supervised, 24 hours

- If no safety incident: keep Supervised overnight too (drafts wait for the morning team; guests at night get the holding message).

## Day 7: decision

- Go to **Automatic** if: ≥ 90% of drafts sent unchanged, no safety incident (wrong price, confirmation wording, sensitive data), reception comfortable.
- Otherwise: stay Supervised another week, fix the information pack or rules, repeat.

## Rollback triggers (any one)

- A guest is given a wrong price or told a booking is confirmed without approval.
- Messages stop arriving or replies are duplicated.
- The hotel asks for it.

Action: Assistant → Off (instant), tell the hotel, investigate with the pilot dashboard and logs, fix, resume Supervised.

## Weeks 1–2: close support

- Daily review of conversations by MCS (under the data processing agreement).
- Incident report within 24 hours for anything serious.
- Weekly review with CNH using the pilot dashboard: replies within a minute, handled without staff, request decision time, request-to-booking rate, AI and WhatsApp costs.
