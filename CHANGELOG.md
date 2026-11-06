# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Hotfix — WhatsApp webhook blocked by CSRF protection

#### Fixed

- Meta's WhatsApp notifications (`POST /webhooks/whatsapp`) and browsers' CSP reports (`POST /csp-report`) were exempted from the old CSRF middleware name (`ValidateCsrfToken`), but Laravel 13 runs `PreventRequestForgery`, so both would have been rejected with "419" outside tests. They now exempt `PreventRequestForgery`. A test checks the exemption on the routes, because Laravel skips CSRF checks while tests run. Found by the S8.3 load smoke test against a real server; nothing had been deployed.

### S8.2 — Operations

#### Added

- `platform:ops-check` every five minutes: background jobs backing up, scheduler or workers stopped, no WhatsApp notifications in the daytime, notifications failing after retries, AI providers or WhatsApp sends failing, backups missing or failed, restore drill failed or overdue. Each problem opens an incident: one email to `OPS_ALERT_EMAIL` when it starts, a reminder every two hours, one when it clears (sent directly, not queued).
- Backups (`docker/backup/backup.sh`): copied off-site to S3-compatible storage when `BACKUP_S3_URI` is set, kept 14 daily, 8 weekly and 6 monthly; every result is recorded in the application (`backup:record`).
- Restore drill (`docker/backup/verify-restore.sh`, monthly): restores the latest backup into a temporary database and checks it with `backup:verify-restored` (every migration present, core data present, encrypted values readable with this server's key), records the result and drops the temporary database.
- Platform → Pilot dashboard (7, 14 or 30 days, per hotel): replies within a minute, conversations handled without staff, booking requests, median time to decide, requests that became bookings, draft outcomes, handovers by reason, safety blocks, AI cost (total, per conversation, per booking), WhatsApp replies and templates; open operations problems; last backup and restore drill.
- Optional error tracking with Sentry (`SENTRY_LARAVEL_DSN`).
- `docs/operations.md`: what each ops alert means and what to do, backups, restore drill, disaster recovery, rollback.
- English and French text.

### S8.1 — Go-live readiness, supervised mode, telling guests

#### Added

- Go-live readiness page (`/hotel/go-live`, Owners and Managers) with every check green or red and a link to fix it. Required: WhatsApp connected, a notification received in the last 24 hours, booking/alert templates (and deposit and stay templates when used) approved in the hotel's language, approved AI models with a passing test-suite run, information pack published, rooms and a rate for every category, reception hours, deposit instructions when deposits are on, someone receiving WhatsApp alerts, background jobs and scheduler running, a backup in the last 26 hours. Recommended: review link, owner summary, stay messages, privacy link.
- Platform → Go-live readiness: every hotel's missing checks; a super admin can allow go-live without every required check, with a written reason (audited, removable).
- Assistant mode: Off, Supervised or Automatic (replaces on/off; hotels that had it on keep Automatic). It cannot be switched on until the required checks pass, unless overridden.
- Supervised mode: the assistant's reply (with all its checks and handover rules) is kept as a draft in the inbox; staff send it, edit and send it, or discard it, and the outcome is recorded. Nothing reaches the guest without approval (handovers send nothing either); booking requests are still created; drafts nobody approves alert staff after the waiting time. The go-live page shows the share of drafts sent unchanged.
- The first assistant reply in a conversation says it is the hotel's virtual assistant, how to reach a person, and links the hotel's privacy notice (new setting). Words such as « réception », "human" or « personne » (editable per hotel, accents and case ignored) always pass the conversation to staff without asking the AI.
- English and French text.

### S7.3 — Stay messages

#### Added

- Stay messages (Assistant settings), each switched on or off with its own time in the hotel's time zone: arrival reminder the day before (check-in time and directions), check-out reminder on the departure morning (for guests checked in), and a thank-you on the departure day (for guests checked out) with the hotel's review link. Free text when the guest wrote in the last 24 hours, otherwise the approved template in the guest's language. `stay-messages:send` runs every five minutes.
- Each booking gets each message at most once. Messages go only to guests who contacted the hotel on WhatsApp or whose consent reception recorded; cancelled bookings and no-shows are never targeted. Every message sent or skipped (no consent, no phone, template not approved, no review link) is recorded and shown in the booking's history.
- "Guest agrees to WhatsApp messages about this stay" on the new-booking form, recorded with date and time on the booking and the guest.
- The waiting time before a holding message is sent to a guest waiting for staff is now set in the Assistant settings.
- Three starter templates in French and English: `stay_reminder`, `checkout_reminder`, `stay_thank_you`.
- A full reception-day test: complaint, staff alert, take over, note, translation, reply, arrival reminder and the owner's summary.
- English and French text.

### S7.2 — Staff alerts and the owner's daily summary

#### Added

- "My alerts" (`/hotel/my-alerts`): each person sets the WhatsApp number to alert them for this hotel and chooses which alerts they want (new WhatsApp booking request, conversation passed to staff, guest waiting for a reply, request expired); only alerts they can act on are offered. A test button sends a sample alert. Owners see the team's alert choices. Changes are audited.
- WhatsApp alerts from the hotel's number: free text to staff who wrote to the hotel number in the last 24 hours, otherwise the approved `staff_alert` template; never stored in the guest inbox; counted in the usage records. At most one alert per person and subject every 10 minutes and a daily maximum per hotel (default 50); every alert sent or skipped is recorded with the reason. Email and in-app alerts are unchanged.
- Owner's daily summary at each owner's chosen time (hotel time zone), by WhatsApp (`owner_daily_summary` template or free text) and/or email, once a day: arrivals and departures today, rooms occupied tonight, new bookings and their value, WhatsApp requests approved/declined/expired, conversations needing attention, AI cost today (`owners:daily-summary`, every five minutes).
- Two starter templates in French and English: `staff_alert`, `owner_daily_summary`.
- English and French text.

#### Fixed

- Template values: a value of "0" was sent as "-", and whitespace clean-up could damage accented text and non-breaking spaces. Values are now cleaned in one place, safely for UTF-8.

### S7.1 — Staff working together

#### Added

- Assignment: taking over a conversation assigns it to the person who clicked; it can be reassigned to anyone who can answer conversations, or unassigned; handing back to the assistant removes the assignment. The inbox shows who owns each conversation and has a "Mine" filter. Changes are audited.
- Internal notes in the conversation thread, shown in their own style with author and time, stored encrypted, never sent to the guest, audited.
- No guest left in silence: when a conversation is with staff and the guest has waited longer than the hotel's limit (default 15 minutes), the guest gets one holding message (with reception's opening time when it is closed), the conversation is marked "needs attention" and staff are alerted again (`conversations:check-waiting`, every minute). A staff reply, from the dashboard or the WhatsApp Business app, resets the wait.
- Translation with the AI models: staff translate a draft reply into the guest's language and check it before sending; guest messages (and voice-note transcripts) can be shown in the staff member's language, and the translation is kept, encrypted. Metered as `ai.translation`, with the same backup model, off switches and spending limits; when AI is unavailable staff see why.
- `ConversationNeedsStaff` event when a conversation is passed to staff or a guest is waiting (staff alerts in S7.2).
- English and French text.

#### Security

- Symfony components updated to 7.4.23 for advisories published on 4 November 2026 (http-foundation, http-kernel, routing, serializer, yaml).

### S6.3 — Telling the guest, deposits

#### Added

- The guest is told what happened to their WhatsApp request automatically (`TellGuestTheDecision`, on approval, decline and expiry): free text within 24 hours of their last message, in their language, otherwise the approved template in that language (or the hotel's main language). When neither is possible the conversation is marked "needs attention" so staff contact the guest. Each outcome is audited and recorded on the request.
- Deposits (Assistant settings): when required, approval creates a tentative booking held for the hotel's time to pay, with a payment note, and the guest receives the deposit amount (percentage of the total, rounded up), the deadline and the payment instructions; reception confirms the booking when the money arrives. Instructions are required in the hotel's main language.
- Decision message wording per language for confirmed, deposit, declined and expired, with placeholders ({name}, {reference}, {room}, {check_in}, {check_out}, {total}, {deposit}, {deposit_deadline}, {payment_instructions}, {hotel}) and French and English defaults.
- Four starter templates in French and English for Meta approval: `booking_confirmed`, `booking_deposit_request`, `booking_declined`, `request_expired` (no template starts or ends with a value, values never sit side by side, every value has an example).
- Request hold time is now set in the Assistant settings.
- Tests can no longer reach real services: every HTTP call must be faked.
- An end-to-end test: guest asks, the assistant quotes and sends the request, reception approves, the booking is made and the guest receives the confirmation.
- English and French text.

### S6.2 — WhatsApp booking requests

#### Added

- `request_booking` tool: once the guest has given the room category, dates, guests and their full name, the assistant sends a booking request to reception and the rooms are held. It only works for stays the quote engine says can be booked; it never confirms a booking (the instructions and the S5 confirmation guardrail still apply). One open request per conversation: a new one replaces the old and releases its rooms. The last room cannot be held twice. In the practice screen nothing is created.
- Requests hold their rooms for the hotel's hold time (default 2 hours) and at least until one hour after reception next opens.
- "WhatsApp booking requests" on the Today screen (refreshed every 15 seconds, with an optional browser alert) and on the conversation in the inbox: guest, stay, price quoted to the guest and price now, time waiting and hold expiry. Reception (`booking_requests.decide`) can approve, edit then approve (price changes need `bookings.override_price` and a reason), or decline with a reason. A price that changed since the quote must be accepted before approving.
- Approving makes a confirmed WhatsApp booking from the request's held rooms in one transaction and links it to the request; declining releases the rooms.
- Requests nobody answers expire every minute: rooms released, conversation marked "needs attention", staff emailed. Staff are also emailed for every new request. Every step is audited.
- English and French text.

### S6.1 — The assistant answers guests on WhatsApp

#### Added

- Conversation modes: the assistant answers, staff answer, or paused. A handover switches to staff, marks the conversation "needs attention" with the reason, and sends the hotel's handover message. The inbox shows the mode and reason, a "Needs attention" filter and badge, "Take over" and "Hand back to the assistant" buttons (`conversations.reply`), and labels the assistant's replies. Handing back never re-answers messages staff already dealt with. Every change is audited.
- `ReplyToGuest`: 8 seconds after a guest message (or once a voice note is transcribed) the assistant answers, only when the hotel has switched it on, the conversation is in assistant mode and WhatsApp is connected. Only the job for the newest message answers, so a burst of messages gets one combined reply; a lock per conversation and the last-answered marker mean each guest message is answered exactly once, even when Meta delivers it twice.
- The assistant stays silent for 10 minutes after a staff reply (from the dashboard or the WhatsApp Business app) and never answers messages older than 24 hours.
- Voice notes that cannot be downloaded or understood pass the conversation to staff; photos, documents and locations are described to the assistant.
- Safety limits: more than 30 assistant replies in an hour, or 5 identical replies in a row, pass the conversation to staff.
- Staffed hours: the assistant is told whether reception is staffed and, if not, when it opens, so it can tell guests when bookings will be confirmed (`StaffedHours`, hotel time zone).
- A guest's first message records that they chose to contact the hotel (booking messages only, never marketing).
- English and French text.

### S5.3 — Voice notes, language and the assistant test suite

#### Added

- Voice notes are transcribed as soon as they are downloaded, through the gateway (off switches, spending limit, main and backup transcription models, recorded calls with audio seconds and cost). The transcript is encrypted, its language detected, and it is shown under the voice note in the inbox. A failure is recorded on the message and never blocks the conversation.
- Guest language: text messages get a language (French or English, or left unknown when unsure) and the guest's preferred language is remembered from their first message.
- Assistant test suite (`resources/ai-evals/assistant.json`): 65 French and English conversations, 27 of them safety cases (confirmation requests, instructions hidden in guest messages, invented or negotiated prices, complaints, requests for a person, groups and events, cancellations, card and passport numbers, other guests' privacy, unsupported languages, threats, urgent out-of-scope requests). Dates are written relative to the day of the run.
- `ai:eval {model} [--suite] [--case] [--approve]`: runs the suite against a real model on a throw-away test hotel (everything rolled back), scores it, stores the run and approves the model only with at least 95% of all cases and 100% of safety cases. Never runs in CI.
- Platform → AI: "Run test suite" per model (in the background) and the last runs with scores, cost and every failed case with the reply given.
- English and French text.

#### Changed

- The AI gateway runs prompts and transcriptions through one attempt loop, so failover, limits and records behave the same for both.

### S5.2 — The assistant: information pack, engine, guardrails, practice

#### Added

- Information pack (`/hotel/assistant/knowledge`, `assistant.manage`): sections per language (about, directions, policies, payments, parking, transfers, restaurant, house rules, questions and answers), saved as a draft and published as numbered versions (audited); the assistant only uses the latest published version. Facts the platform already holds (address, contact, check-in and check-out times, languages, room categories with capacity, amenities and breakfast) are added automatically; prices never are. Preview of exactly what the assistant sees.
- Assistant settings (`/hotel/assistant`): on/off for WhatsApp (used from S6), name, tone, languages, message when staff take over per language, daily spending limit, off switch.
- `AssistantEngine` with the `HotelAssistantAgent` (laravel/ai, structured result: reply, language, intent, handover, reason, confidence) and four read-only tools: room categories, availability, quote (S2 quote engine) and hotel information. Tools only see the current hotel.
- Guardrails checked by code on every reply: every amount must come from a quote returned in that reply (or the published pack); no wording saying a booking is confirmed or reserved (French and English); no requests for card, passport or ID numbers; at most 1,000 characters. A blocked reply is retried once with the reason, then handed to staff.
- Handover to staff when the AI asks for it, for complaints, requests for a person, groups and special requests, changes or cancellations, languages the hotel does not answer in, low confidence, no published pack, or AI unavailable (off, limit reached, providers failing), with the hotel's message.
- Card-like numbers are removed from guest text before it reaches an AI provider; guest text is always sent as guest input.
- Practice screen (`/hotel/assistant/practice`): staff write as a guest and see the reply, decision, checks, tools used, model, time and cost. Nothing is sent to anyone.
- English and French text.

### S5.1 — AI platform: providers, models, failover, limits

#### Added

- `laravel/ai` (v1.3) behind the platform's own `AiGateway`, the only code that talks to AI providers.
- Platform → AI (super admins): AI provider accounts (Anthropic, OpenAI, Gemini, OpenAI-compatible) with encrypted, write-only keys registered with laravel/ai at run time; the approved model list (capabilities, status testing / approved / retired, prices in micro-dollars, approval date); main and backup model per task (assistant, voice notes); request time limit; platform off switch (audited); a "Test" button per model; this month's AI cost across hotels.
- Failover in the gateway: any failure of the main model (timeout, server error, refusal) moves to the backup; retired models and inactive providers are skipped; when every model fails the caller gets a clear "unavailable" reason to hand over to staff.
- Per-hotel assistant settings (stored now, screen in S5.2): off switch and daily spending limit (default US$5). Spending is counted in the hotel's day; at 80% the people who manage the assistant get one email a day and the alert is audited; at 100% AI stops for the day.
- Every AI call is recorded (model, tokens, cost, time, outcome, error) with the request and reply encrypted and deleted after 30 days; usage records `ai.assistant` and `ai.cost_micro_usd` are written once per call.
- Permission `assistant.manage` (Owners and Managers).
- English and French text.

### S4.3 — WhatsApp templates and usage

#### Added

- Message templates (Owners, WhatsApp → Message templates): add from the platform's starter library in the hotel's languages (first starter: "We received your message", English and French), submitted to Meta with example values; Meta's refusal reason is shown and the template can be resubmitted; delete removes it at Meta too.
- Template approval, rejection, pause and disable decisions arrive from Meta's webhooks and update the template.
- Inbox: when the 24-hour window has closed, staff pick an approved template, fill in its values with a live preview and send it. Templates are refused unless approved and complete.
- Usage: every sent reply (`whatsapp.service`) and template (`whatsapp.template.<category>`) is counted once in the usage records; the Usage page shows this month's WhatsApp counts.
- Tests covering the WhatsApp permissions for every system role.
- English and French text.

### S4.2 — Conversations and staff inbox

#### Added

- Guest WhatsApp messages become conversations: one per contact, linked to the guest with the same phone number (the guest is created if new), so WhatsApp and bookings share one guest. Text, voice notes, photos, documents, video, locations and button replies are handled; a message delivered twice is stored once.
- Message text is encrypted in the database. Voice notes, photos and documents are downloaded at once (Meta's links expire), up to 16 MB, into storage private to the hotel and served only to staff who can view conversations.
- Delivery statuses (sent, delivered, read, failed with Meta's error) never move backwards, whatever order Meta's webhooks arrive in.
- Inbox (`/hotel/inbox`): conversations with unread counts, search by name or phone, filters (unread, reply window closing), thread with voice notes and photos in place, delivery ticks, who sent each reply, links to the guest's bookings; refreshes every 10 seconds; unread badge in the sidebar.
- Staff replies (`conversations.reply`) are queued with an idempotency key and sent through the Cloud API: never sent twice, server errors retried, Meta's rejections explained in plain language, a revoked token marks WhatsApp "Reconnect needed". Free text is allowed only within 24 hours of the guest's last message (enforced on the server).
- For numbers shared with the WhatsApp Business app, messages reception sends from the app appear in the inbox.
- `whatsapp:replay` processes stored webhook events that were not processed yet.
- English and French text.

### S4.1 — WhatsApp connection and webhooks

#### Added

- WhatsApp connection page (Owners, `whatsapp.connect`): Embedded Signup v4 with Meta's JavaScript SDK, offering the number already used in the WhatsApp Business app (coexistence, chat history kept) or a new number. The server exchanges the code for the hotel's business token, registers new numbers with a generated two-step PIN (not shared numbers), subscribes the app to the account's webhooks and fetches the number's details. Token and PIN are encrypted and never sent to the browser. A number can belong to one hotel only.
- Connection card: number, name shown to guests, quality rating, messaging limit, check connection, reconnect, disconnect (password confirmation). Daily `whatsapp:check` marks accounts whose token stopped working as "Reconnect needed" and records it.
- Webhook endpoint `/webhooks/whatsapp` for every hotel: Meta's verification handshake, `X-Hub-Signature-256` check against the app secret, each change stored encrypted (kept 30 days), immediate response, background processing with retries, routing to the hotel by phone number (or account). Quality, messaging-limit, name and restriction updates are applied; kinds without a handler yet are kept for replay.
- Platform → WhatsApp: which Meta settings are present, the webhook address, last webhook received, failed events and every connected number.
- Permissions `whatsapp.connect`, `conversations.view`, `conversations.reply` in the role matrix.
- Content Security Policy allows Meta's SDK and signup window. Meta setup documented in `docs/deployment.md`.
- English and French text.

### S3.3 — Today screen, rooms board, register check and export

#### Added

- Today screen (`/hotel/today`), the home screen for staff who handle bookings: rooms free tonight per category, arrivals with one-tap check-in using suggested room numbers, departures (including overdue) with one-tap check-out, possible no-shows, tentative bookings ending within 24 hours, and guests in house.
- Rooms board: every room tonight shown as free, occupied, arriving or out of service, with guests leaving today marked.
- Evening register check: staff confirm tonight's occupied rooms match the paper register or describe the differences; saved with the room list and audited.
- CSV export of bookings overlapping a date range (`bookings.export`, at most a year at a time, rate-limited): UTF-8 for spreadsheets, free text protected against formula injection, every export audited.

#### Fixed

- "Today" is now the hotel's local calendar date everywhere (quotes, bookings, calendar, rooms, taxes). Comparing a time-zoned "now" with plain stay dates was off by a day for hotels ahead of UTC late in the evening.

### S3.2 — Booking lifecycle

#### Added

- Confirm a tentative booking (its hold becomes booked rooms; after the deadline only if the rooms are still free).
- Tentative bookings not confirmed in time are cancelled automatically and free their rooms (`bookings:expire-tentative`, every minute).
- Change a booking's dates, room category, number of rooms or guests: the stay is priced again and its rooms reserved in the same locked transaction that gives back the old nights, so the booking can grow into its own nights and nothing changes if the new rooms are taken. An agreed price must be given again; room numbers that no longer fit are removed.
- Cancel (reason required) and mark no-shows (from the arrival date), freeing all nights at once.
- Room numbers: only rooms of the booked category that are in service, not closed and not given to another current booking on any of the same nights can be chosen; the room row is locked while it is given; the system suggests a free room. Moves during a stay are recorded.
- Check-in on the arrival date with a room number for every room (all-or-nothing), and check-out; leaving before the departure date shortens the stay (the first night always counts) and puts the unused nights back on sale.
- Actions on the booking page according to the booking's status and the user's permissions; refused actions come back as clear messages. Every action is audited and shown in the booking history.
- English and French text.

### S3.1 — Guests and bookings

#### Added

- Guests per hotel, found by phone number (the key WhatsApp will use), with language, notes and WhatsApp and promotional consent flags (off by default). No identity documents are stored.
- Bookings: one guest, one room category, one or more rooms (each room is its own line for room numbers), channel and the other site's reference, group label, notes and a payment or deposit note.
- References such as `FCL-26-0042`: hotel prefix (from the hotel's initials, or set in Hotel settings), year and a locked sequence per hotel and year.
- Quick booking (`/hotel/bookings/new`): live price from the quote engine as the stay is typed, returning guests filled in from their phone number, confirmed or tentative, optional agreed price with a required reason (`bookings.override_price`).
- Confirmed bookings book their nights at once; tentative bookings hold their rooms until their deadline (hours set in Hotel settings). Both use the S2.3 locking, so a booking can never take a room that is not free.
- The full quote (nights, extras, taxes, total) is saved with each booking, so later rate changes never alter it.
- Bookings list with search (reference, name, phone) and filters, and a booking page with the stay, guest, saved price and history.
- Permissions `bookings.view`, `bookings.create`, `bookings.manage`, `bookings.frontdesk`, `bookings.override_price` and `bookings.export`, added to the system roles as agreed.
- `inventory:rebuild` now recounts booked rooms from bookings.
- English and French text.

### S2.3 — Availability, holds and calendar

#### Added

- Availability per room category and night: rooms in service minus closures, rooms held back, active holds and booked rooms; nothing available on stop-sell nights. `AvailabilityService` answers any range in a fixed number of queries (four for any number of categories and nights).
- Room holds while a booking is in progress (`HoldService`): place, extend, release, expire and convert to booked rooms (used by bookings in S3). Writers lock each night's inventory row in date order inside one transaction, so two requests for the last room are served one after the other and only one succeeds. A lapsed hold stops counting immediately; `holds:expire` (every minute) keeps statuses accurate; a lapsed hold can be revived only if the rooms are still free.
- `inventory:rebuild` recalculates booked counts from their source records (converted holds until bookings arrive in S3).
- Availability calendar (`/hotel/calendar`): categories against 14 or 30 nights with rooms free / in service and the nightly price, colour-coded; range actions to stop or reopen sales, hold rooms back, or set a price (as a rate override); manual holds with extend and release; and "Try a stay".
- Quotes now use live availability, including holds, held-back rooms and calendar stop-sell.
- A room category with holds can no longer be deleted.
- A MySQL concurrency test where two database connections compete for the last room.
- English and French text for the new screens.

#### Changed

- Nightly prices and restrictions after overrides are worked out in one place (`NightlyRates`) for quotes and the calendar; "Try a stay" is a shared component on the rates and calendar pages.

### S2.2 — Rates and quote engine

#### Added

- Rates page (`/hotel/rates`): per room category a nightly rate (covering the category's standard number of guests), extra adult, child and extra-bed charges per night, breakfast (included, optional with a price per person per night, or not offered) and a minimum stay. Amounts are stored in minor units of the hotel currency.
- Date overrides for one or all categories, optionally on chosen weekdays only: a different nightly rate, a minimum stay, stop-sale or no arrivals. When overrides overlap, the most recently changed one sets the price (overlaps are flagged); restrictions from all of them apply.
- Hotel-wide pricing settings: up to three child age bands (free, child rate or adult rate; older children pay as adults) and how long rooms are held while a booking is in progress.
- `QuoteService`: the single source of every price. Prices each night, spreads guests across the rooms asked for, charges extras, applies room taxes (rooms scope) and breakfast taxes (food & beverage scope) with the tax calculator, shows rooms free per night, and reports stable issue codes (minimum stay, no arrivals, stop-sale, not enough rooms, too many guests, one adult per room, invalid or past dates, stays over 30 nights, no rate, category not on sale, extra bed or breakfast not offered).
- "Try a stay" on the rates page, showing exactly what a guest would be quoted.
- Audit entries for rates, overrides and pricing settings; reference rates for First Class Lodge in the demo data; English and French text.

#### Changed

- The hotel currency can no longer be changed once room rates exist.

#### Fixed

- Dates without times (room closures, tax and override date ranges) are stored as plain dates, so date-range queries are correct on SQLite as well as MySQL.

### S2.1 — Room categories, rooms and closures

#### Added

- Rooms page (`/hotel/rooms`): room categories with names and descriptions per enabled language, a short code, guests included in the price, maximum adults, children and guests, extra bed, amenities from a fixed list, on-sale switch and order. Categories with rooms cannot be deleted (switch them off instead).
- Rooms: unique number per hotel, category, floor, status (in service, out of order, maintenance), notes; add one room or a numbered range (up to 200 at a time, existing numbers skipped).
- Room closures for date ranges with a reason (both dates are closed nights); overlapping closures are refused.
- `RoomInventory`: sellable rooms per category and night (rooms in service minus closures) in two queries for any date range; the base for availability in S2.3.
- New permissions: `rooms.view`, `rooms.manage`, `rates.view`, `rates.manage`, `availability.view`, `availability.manage`. Reception can view rooms, rates and availability and manage availability; Read-only can view them.
- All changes audited; English and French text (496 strings).
- Local demo data: First Class Lodge with its 4 categories and 26 rooms.

### S1.4 — Email platform and usage

#### Added

- Email providers managed by super admins in platform admin (`/platform/mail-providers`): Brevo, Amazon SES, Postmark, Mailgun, Resend and any SMTP server, all over authenticated SMTP. Enable or disable, order of use, sending address on the verified platform domain, test email with the last result. Credentials are encrypted and never sent back to the browser (a saved secret is kept when its field is left blank). Changes are audited.
- `platform` mailer: tries enabled providers in order and fails over to the next; sends from the provider's address while keeping the display name; uses `MAIL_FALLBACK_MAILER` when no provider is enabled (Mailpit locally, array in tests).
- Email categories (security, account, guest, operational, marketing). Security and account emails are never counted or blocked; the others are checked against the hotel's allowance (unlimited until packages arrive in S13) and logged as blocked when refused.
- Email log of every attempt (sent, failed, blocked) with hotel, provider and recipients; subjects of security emails are not stored; entries are pruned after 90 days.
- Append-only usage ledger (`usage_records`) recording each sent countable email once per recipient; the basis for credits and billing in S13.
- Hotel email identity in Hotel settings: sender name (defaults to the hotel name) and a reply-to address that is used only after it is confirmed by email (48-hour link); until then replies go to the hotel's contact email.
- Usage page (`/hotel/usage`, new `usage.view` permission for Owner, Manager and Read-only): emails this month by type, failures, the last 30 days, and the limit ("Unlimited (pilot)").
- Platform-wide email totals for the last 30 days on the providers page.
- English and French text for all new screens and emails.

#### Changed

- `MAIL_MAILER` defaults to `platform`; staff invitations are tagged as security emails.

### S1.3 — Languages, formatting, hotel audit log and platform users

#### Added

- English and French throughout the interface and emails. Translations live in `lang/en.json` and `lang/fr.json` (English text as the key) and are shared by Laravel (`__()`) and React (`t()`); French framework messages (validation, sign-in, passwords) from Laravel Lang (MIT) with field names translated.
- Language chosen per request: the user's own choice, then the visitor's session choice, then the hotel's default language, then the browser's preferred language, then English. Language switcher in the user menu (saved on the account). Emails and notifications follow the recipient's language.
- `php artisan translations:check` fails when a string used in code is missing from `en.json` or another language misses, adds or leaves empty a key; runs in CI.
- `useFormat()` for dates, times, money and numbers in the user's language and the hotel's time zone and currency.
- Hotel audit log page (`/hotel/audit`, permission `audit.view`): filter by event, person and dates; current hotel only.
- Platform users (`/platform/users`): search, deactivate and reactivate accounts (sessions end immediately, sign-in refused), invite MCS staff as super admin or support (password link by email; two-factor required).
- Role choice when adding an existing user to a hotel in platform admin.

#### Changed

- `/` now sends visitors to sign-in and signed-in users to the dashboard; the starter-kit welcome page is removed.
- Users without their own language choice follow the hotel's default language (`preferred_locale` is now optional).
- The current hotel is cleared at the start of every request before being resolved.

### S1.2 — Hotel settings, money and taxes

#### Added

- Hotel settings page (`/hotel/settings`): name and legal name, contact details (phone and WhatsApp number in international format, email, address, map link), check-in and check-out times, latest late check-out, staffed hours per weekday (up to three periods a day), time zone, currency, default and enabled languages, and a preview of dates and money. Viewing needs `hotel.settings.view`; changing needs `hotel.settings.manage`. Changes are audited.
- One `hotel_settings` row per hotel, created with every new hotel (and for existing hotels by the migration).
- Money handled with `brick/money` in minor units; `MoneyFormatter` formats amounts with the locale's conventions.
- Taxes (`/hotel/taxes`): percentage or fixed per night, per person per night or per stay; included in prices or added on top; applies to rooms, food & beverage and/or events; active switch, start and end dates, order; names per enabled language. Audited.
- `TaxCalculator`: included taxes are worked back from the price (fixed first, then percentages, no tax on tax), added taxes are calculated on the net price; each tax is rounded to the currency's minor unit and the net absorbs rounding so totals always reconcile. Tested in FCFA, euro and rupee.
- "Try it" preview on the taxes page using the same calculator as the server.
- Hotel settings and Taxes appear in the sidebar for users allowed to see them.

### S1.1 — Hotel roles, staff management and invitations

#### Added

- Permissions per hotel with `spatie/laravel-permission` in teams mode (the hotel is the team; ULID keys). The current hotel sets the permission team automatically.
- Permission catalogue in code (`App\Domains\Access\Permission`) and six system roles copied into every hotel: Owner, Manager, Reception, Restaurant & Bar, Events coordinator, Read-only. `php artisan permissions:sync` keeps them in line with the code and runs on every deployment.
- Custom roles: hotels create roles by ticking permissions; system roles cannot be edited; roles in use cannot be deleted.
- Staff page: roles, suspend and restore, remove, primary-owner transfer (password confirmation). Safety rules: no changes to your own access, only owners grant or remove the Owner role, the primary owner cannot be removed, and a hotel always keeps an active owner.
- Email invitations valid for 7 days, stored as token hashes, rate-limited per hotel; new people create an account from the link (email counts as verified), existing accounts sign in and accept; resend and cancel. Owner invitees go straight to two-factor setup.
- The current hotel's permissions are shared with the frontend; the Staff menu appears for users who can see staff.
- Audit entries for invitations, role changes, custom roles and primary-owner changes.

#### Changed

- Hotel owners assigned in platform admin and demo data receive the Owner role; platform admin can choose a role when adding an existing user.
- Two-factor authentication is required for anyone holding the Owner role in any hotel.
- Role and permission relations are reloaded whenever the current hotel is resolved, so rights from one hotel are never reused in another.

### S0.3 — App shell, platform admin, security headers, monitoring and deployment

#### Added

- App shell: hotel switcher in the sidebar, Hotel and Platform admin menus (platform menu only for MCS staff); starter-kit links removed.
- Platform admin (`/platform`): hotel list with search and pagination, create and edit hotels (slug generated and kept unique, owner by email), add existing users to a hotel and remove them. Support staff can view; only super admins can change. All changes are audited with the acting user.
- Security headers on every page: Content Security Policy with per-request nonce (report-only, switchable to enforcing with `CSP_ENFORCE`), HSTS over HTTPS, frame blocking, no MIME sniffing, referrer and permissions policies; `/csp-report` endpoint logs violations.
- Structured JSON log channel (`LOG_CHANNEL=json`); every log line carries `request_id`, `user_id` and `hotel_id`.
- `/health` (token-protected): database, Redis, queue-worker heartbeat and scheduler heartbeat; returns 503 with details when anything fails. Scheduler and queue heartbeats run every minute.
- Production images: PHP (`prod`) and Nginx with the release's assets (`web`), built per commit; PHP-FPM container health check.
- `compose.prod.yaml` (Caddy with automatic HTTPS, Nginx, PHP-FPM, Horizon, scheduler, MySQL 8.4, Redis 7), `deploy.sh` (build, pre-migration backup, migrate, swap, health check, automatic rollback) and `docker/backup/backup.sh` (encrypted, compressed MySQL dumps with 14-day retention).
- `docs/deployment.md`: server setup, releases, monitoring, backups and restore.

#### Changed

- Trusted proxy ranges configured for the Docker network so HTTPS is detected behind Caddy.
- CI builds and smoke-tests the `prod` and `web` images when Docker or deployment files change.
- CI publishes the last lines of a failing build or test step as an error annotation on the PR, so failures are readable without downloading logs.

### S0.2 — Hotel data separation, sign-in security and audit log

#### Added

- Hotels (`hotels`) and memberships (`hotel_user`), with ULID keys, hotel status (trial, active, suspended, closed) and an optional owner.
- `TenantContext` (scoped per request and per job) and the `BelongsToHotel` trait: every hotel-owned query is limited to the current hotel and **fails closed** when no hotel is set; `hotel_id` is always taken from the current hotel; records cannot move between hotels.
- Current hotel resolved from the session and verified against an active membership before route-model binding; hotel switcher endpoint; `hotel` route middleware for hotel-only pages; current hotel and switchable hotels shared with the frontend.
- Queued jobs carry and restore their hotel; jobs implementing `RequiresHotelContext` cannot be dispatched without one. `TenantContext::run()`, `eachHotel()` and an audited `bypass()` for platform staff.
- Mandatory two-factor authentication for hotel owners and platform staff.
- Password policy: at least 12 characters with letters and numbers, checked against known breached passwords.
- Append-only audit log (`spatie/laravel-activitylog`, extended with hotel, request ID, IP address and browser): sign-ins, failed sign-ins, lockouts, sign-outs, password resets, two-factor changes, hotel changes, membership changes and scoping bypasses.
- Request ID on every request (`X-Request-Id`), added to logs and audit entries.
- Platform roles (`super_admin`, `support`); Horizon dashboard limited to super admins.
- Local demo seeder: platform admin and two hotels with owners and receptionists.
- ADR-001 (shared database with hotel separation) and ADR-004 (fail-closed separation).
- Tests: hotel scoping, 404 on other hotels' records, queue context, membership and switching, an automated check that every model with `hotel_id` is scoped, two-factor enforcement, recovery codes, password policy, audit immutability and request IDs.

#### Changed

- Users have ULID IDs, first and last names (a full `name` is still provided), phone, preferred language, platform role, last sign-in time and soft deletes.
- Sign-in throttling now records a lockout entry in the audit log.

#### Fixed

- Test-only fixture migration now always runs after the application's migrations (MySQL rejected its foreign key to `hotels`).

### S0.1 — Skeleton, tooling and CI

#### Added

- Laravel 13 application from the official React starter kit (React 19, Inertia 3, TypeScript, Tailwind 4, shadcn/ui) with Fortify authentication: login, password reset, email verification, password confirmation and two-factor authentication. Public registration and passkeys are disabled.
- Pest 4 test runner with an Arch suite: no debugging statements, strict types in `App`, controller naming, `env()` only in config.
- Larastan raised to level 8; Pint enforces `declare(strict_types=1)` and strict comparisons.
- Vitest with React Testing Library for frontend component tests (`npm test`).
- Laravel Horizon (dashboard closed outside local until platform roles arrive in S0.2).
- Docker Compose development stack: PHP 8.4 FPM, Nginx, MySQL 8.4, Redis 7, Horizon, scheduler, Mailpit and an optional Vite service; `Makefile` shortcuts.
- GitHub Actions CI: draft PRs run quick checks only; ready PRs add only the affected suites; single required **CI gate** check; gitleaks secrets scan; Composer and npm audits; pinned action SHAs.
- Documentation: README, CONTRIBUTING (sprint and CI rules), ADR-002, ADR-003 and ADR-005.

#### Changed

- Fonts are self-hosted (Inter via Fontsource) instead of fetched from a third-party CDN at build time.
- Feature tests run without Vite, so backend CI does not need a frontend build.
