# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### S16.4 kit — staging ready in two commands (0.17.10)

Staging servers do not exist yet; this makes S16.4 a matter of running two scripts once they do.

#### Added

- `scripts/server/provision.sh`: one-time setup of an Ubuntu 24.04 server for staging or production — firewall (SSH, HTTP, HTTPS), SSH keys only, fail2ban, automatic security updates, swap, Docker and Compose, `deploy` user, the application with a generated `.env` (random secrets; simulation mode on staging only). Safe to run again.
- `scripts/drills/staging-drills.sh`: the launch drills on staging in one command — full-size load test (k6 `SCALE=full`), workers stopped, Redis and MySQL restarts, AI providers down, Meta errors on send, **e-mail server unreachable** (the worker-stopping case found in S17.3a), duplicate notifications, backup restore, deploy and rollback. Each makes something fail, lets simulated guests write, then checks every guest was answered (or handed to staff) once. Refuses to run outside staging.
- `php artisan simulation:check`, `simulation:guests --duplicate`, `drills:record`, `drills:status`.
- Drill results stored (`drill_runs`); the launch checklist's "Load test and outage drills on staging" is now **checked automatically** (every drill passed in the last 30 days).
- Content Security Policy reports counted by directive and blocked source (`csp_reports`), listed by `drills:status`, to decide when to set `CSP_ENFORCE=true`.

#### Changed

- `docs/deployment.md` and `docs/failure-drills.md` point to the scripts; the drill table names the production queue service (`horizon`).

### S17.3e — Launch documents (0.17.9)

#### Added

- Branded launch documents in `docs/launch/` with PDFs in `docs/launch/pdf/` (rebuild with `python3 scripts/build_launch_pdfs.py`): **sales brochure** (French and English), **price sheet** (launch defaults: plans, packs, other currencies, what credits mean, Meta fees), **20-minute demo script** for CNH sales (with the demo hotel, the return-on-investment calculator, proposals and common objections), **support playbook** for CNH (hours, first-answer targets, priorities, method, frequent questions, escalation to MCS), **owner quick-start** (one-hour setup).
- Owner and reception guides updated with help, support, support sessions, What's new, ideas and the status page.

#### Changed

- PDF footer now names the product and both partners instead of the pilot.

### S17.3d — Launch defaults, launch checklist and launch-week watch (0.17.8)

#### Added

- **Launch defaults** (`php artisan launch:defaults`, run by `deploy.sh`; never overwrites a choice already made; `config/launch.php`, `docs/launch-defaults.md`): plans and packs if none exist; prices in XAF, GHS and NGN next to XOF, EUR and USD; first-answer targets Essentiel 24 h · Pro 8 h · Complet 4 h; support hours Monday–Saturday 8:00–18:00 GMT (editable in Platform → Website → Brand and shown on the support page); invoice retention 10 years (`INVOICE_RETENTION_YEARS`; invoices are never deleted). Working name, no analytics, Sentry for error tracking, legal v1 and country tax templates stay as they are until changed.
- **Support sessions ask the owner first** for new hotels (owners can switch to "allowed with notification").
- **I — Launch checklist** (Platform → Launch, every platform role can see it): 22 items in eight areas, each with an owner role, due date and proof. 14 are **checked automatically** (legal texts published, live payment provider, approved WhatsApp templates, approved AI model, email provider, production on HTTPS, backup in the last 26 h, restore drill in 90 days, error tracking, Content Security Policy enforced, help centre, website, demo hotel, pilot live) and cannot be ticked by hand; the others need a link or note as proof, set by their owner (only a super admin can mark a required item not needed). Readiness score and "ready for 1.0.0".
- `php artisan launch:check` fails while a required item is open; **`deploy.sh` refuses to release version 1.x to production** until it passes.
- **J — Launch-week watch** on the same page: sign-ups, new hotels, guest messages, failed sends, failed jobs, support requests and payments, by hour over 7 days (counts only).

### S17.3c — What's new and ideas board (0.17.7)

#### Added

- **E — What's new**: release notes written for hotels (French and English, Markdown, a picture or short GIF, a **"Try it"** button that opens the feature), newest first, with a dot in the menu until read. **Monthly email digest** (`whats-new:digest`, the 1st at 09:00) of last month's notes to owners and managers of live and trial hotels; anyone can turn it off (in What's new, or from the email's signed link).
- **F — Ideas board** (everyone signed in): suggest an idea (similar ideas are shown first so votes gather), vote and unvote (one vote per person), see the status (under review, planned, being built, available, not planned) and the platform's answer.
- Platform → What's new and ideas (Operations, Sales, super admin): write and publish release notes; ideas ranked by votes and by number of hotels; set the status, answer publicly, merge duplicates (votes move, nobody counted twice). Voters are emailed when an idea is planned or available (unless they turned product emails off).
- Browser test: What's new and suggesting an idea.

### S17.3b — Return on investment and proposals (0.17.6)

#### Added

- **G — Return-on-investment calculator** (Platform → Leads, per lead): WhatsApp enquiries a day, share missed today, share that would book, average nights and price → bookings and revenue recovered a month, the plan's monthly cost, how many times it pays for itself and in how many days. Computed as you type; the same transparent formula appears in the proposal.
- **H — One-click proposals**: plan, currency, monthly or yearly, discount (up to 20% for sales, 50% for super admins), language, a personal word. A private page `/proposal/{token}` (and a branded PDF) shows the return on investment, the recommended plan and price (VAT note from the issuing company), what is included and the trial. **"Start my trial"** creates a one-use 30-day invitation code linked to the lead and opens the sign-up with it; the lead moves to Trial when it is used. Optional email to the prospect; the link is shown to copy into WhatsApp. Valid 30 days; viewed and accepted dates shown on the lead.

#### Fixed

- The private demo link from S17.3a was not shown on the Leads page after sending it (it now is, with the proposal link).

### S17.3a — Demo hotel and live demos (0.17.5)

#### Added

- Demo hotels: a realistic hotel (four room types and 26 rooms with prices, information pack in French and English, restaurant with an orderable menu, seminar room, a guest in the house). **The real assistant answers; WhatsApp is simulated** (numbers starting `DEMO-` never reach Meta). Demo logins are managers (no two-step login); demo hotels have no subscription or credit limits.
- **A — Live scenarios** (Hotel → Demo): "A guest asks for a room tonight", "A guest writes in English", "A guest orders from their room", "A company asks about a seminar", or write as a guest. Messages go through the same path as real WhatsApp messages; the conversation is shown live, and the booking request, order or quote appears in the usual screens.
- **B — "Your hotel"**: the prospect's hotel name, room types, prices and room counts in a minute.
- **C — Private demo for a lead** (Platform → Leads, "Send a demo link"): a 48-hour private demo hotel named after the prospect, emailed as a signed link (also shown to copy into WhatsApp); the lead shows when it was opened; expired demos are deleted (data and logins).
- **D — "Try it on your own phone"**: when the demo hotel has a real WhatsApp number connected, a link and a QR code (drawn on the server) let the prospect write from their phone. Off until such a number exists.
- Platform → Demo hotel (Sales, super admin): create the sales demo hotel, open it, new password (shown once), reset. `demo:maintain` hourly (expired private demos) and nightly at 03:15 with `--reset`.
- Browser test: sales opens the demo hotel and plays a scenario.

#### Fixed

- The sign-in pages' titles and descriptions ("Log in to your account"…) were always in English; they are now translated.

### S17.2c — Status page and alerts (0.17.4)

#### Added

- Public status page at `/status` and `/en/status`: application, WhatsApp messages (Meta), assistant (AI), emails, online payments — each operational, maintenance, slower than usual, or not working; filter by country; current incidents with their updates; the last 90 days. States follow the operations checks automatically (open incidents for queues, WhatsApp and AI) and the notices the team posts.
- Platform → Service status (Operations, super admin): post an incident or planned maintenance (French and English, services, countries, start and planned end), post updates (investigating, cause found, fixed and watching, resolved).
- Email alerts: anyone subscribes from the status page (choosing a country or all), with a confirmation email; updates go to confirmed subscribers concerned; one-click unsubscribe.
- In the app: a banner for active incidents and maintenance concerning the hotel's country, linking to the status page.
- "Service status" in the website footer. Browser test for the status page.

### S17.2b — Support requests (0.17.3)

#### Added

- Contact support (every staff role; new permission `support.contact`, run `permissions:sync` when deploying): subject, topic, message, up to three screenshots **annotated in the browser** (box, arrow, pen). The page, screen, app version, role, language, browser and screen size are attached automatically. While the subject is typed, **matching help articles are suggested**, with "That solved it" (searches recorded for the help gaps report). Requests are followed at Hotel → Contact support (`/hotel/requests`): thread, reply (reopens a solved request), **one-tap rating** when solved. "Still stuck? Contact support" in the help panel carries the current screen.
- Platform → Support requests (Operations, super admin): filters (in progress, late, mine, solved), priority, assignee, status, **internal notes**, the hotel's context, **saved replies** in French and English filled with the person, hotel and agent names, **AI-drafted replies** from the help articles and the hotel's state (paid by the platform, not the hotel's credits; marked in the thread; always reviewed and sent by a person; can be switched off), satisfaction over 90 days.
- First-answer targets by plan (default 24 h, Pro and Complet 8 h; editable); late requests are flagged in the list and on the platform home.
- Emails: new request and hotel replies to the team (assignee, or Operations and super admins); answers and "solved — how did we do?" to the requester.
- **WhatsApp notices** of support answers, from the support team's own WhatsApp number (an account connected like a hotel's, with an approved `support_reply` template): off until both are set, then the requester can choose them; email always goes out.
- Browser test: a support request with suggested articles.

### S17.2a — Help centre (0.17.2)

#### Added

- Help centre: articles in French and English (Markdown, pictures and GIF animations), public at `/help` and `/en/help`, searchable (titles first, then text), with related articles and a "Did this answer your question?" vote. Eleven first articles written from the launch guides; a test checks every hotel screen has one.
- "?" help panel in every screen's header: the article for that screen in a side panel, a search, and the list of all articles, without leaving the screen.
- Platform → Help centre (Operations, Sales, super admin): write and edit articles, choose the screens each helps with, upload pictures and animations; **searches with no answer** (60 days) and "not helpful" comments, so the team knows what to write next.
- "Help" in the website menu.
- Browser tests: the help panel on the inbox; help centre search and article.

### S17.1 — Brand, public website, pricing, leads (0.17.1)

#### Added

- Brand settings (Platform → Website, super admin): product name (working name "Assistant Hôtel"), tagline, main colour, logo (SVG checked for scripts and links, PNG, WebP), support email and WhatsApp, privacy-friendly analytics (Plausible or Matomo). The name is used in screens, page titles, emails, PDFs and error pages; the starter kit's logo and name are gone (a test checks no visible "Laravel" text remains).
- Public website, French at `/` and English at `/en`: home (with an example WhatsApp conversation), features, pricing, countries, FAQ, demo request, legal links. Fast Blade pages, light and dark, accessible; canonical and alternate-language links, Open Graph tags, `sitemap.xml`. Page texts are edited in Platform → Website (Sales and super admin).
- Pricing page built from Billing's plans and packs, in the visitor's currency (from their country when known, or chosen), with the VAT note from the issuing company and Meta fees explained.
- Demo requests → Platform → Leads (Sales, super admin): statuses (not contacted yet, contacted, demo done, trial, won, lost with reason), sales contact, follow-up date, encrypted notes and messages; "Create an invitation code" makes a one-use 30-day code linked to the lead, which moves to Trial when used. Daily email (`leads:digest`, 07:30) to sales and super admins with new leads and follow-ups due.
- Browser tests for the website (French and English, prices, demo request).

#### Changed

- `/` shows the website to visitors; signed-in users still go to their dashboard.

#### Fixed

- **Rate limits were shared between routes**: the framework counts per user or IP across every route with a numeric limit, so browsing the website used up the demo form's allowance, and guests on one hotel Wi-Fi shared limits across unrelated pages. Each route now has its own counter (`ThrottlePerRoute`); a test covers it.

### S16.3b — Browser tests and accessibility (0.16.4)

#### Added

- Playwright browser tests (`tests/e2e`, `playwright.config.ts`) in French on a phone (Pixel 7) and a desktop: reception's Today and bookings, a guest's WhatsApp message answered from the inbox, creating a booking, the platform home and sign-ups with a go-live request after a two-step login, and the branded 404 page. Every page visited is checked with axe (WCAG 2.1 A and AA; serious and critical problems fail). Sessions are prepared once per role (login is rate limited).
- `php artisan e2e:prepare`: simulated staging plus a platform admin with a known two-step secret and a go-live request (refuses to run in production).
- `.github/workflows/e2e.yml`: on ready pull requests that touch screens, routes or translations, and nightly.

#### Fixed (found by the browser tests)

- Room plan on Today said "Gratuit" (free of charge) for vacant rooms; it now says "Libre".
- The inbox showed the internal handover code (e.g. `ai_unavailable:no_credits`) to staff; it now explains it in plain words.
- The inbox filter buttons overflowed into the conversation on narrow desktops; they now wrap.
- Low-contrast time stamps on guest messages (accessibility).
- Scrollable tables (15 screens) could not be scrolled with the keyboard (accessibility).
- A link inside an expandable section on the sign-ups page (accessibility).
- Platform staff without a hotel landed on an empty placeholder page after login; they now land on the platform home.

### S16.3 — Error pages, error tracking, versions, migration safety (0.16.3)

#### Added

- Branded error pages (403, 404, 419, 429, 500, 503) in French and English, light and dark, with the request reference to quote to support. Unknown addresses use the visitor's language.
- Error tracking ready for Sentry or self-hosted GlitchTip (`SENTRY_LARAVEL_DSN`, off when empty): reports carry the version; request bodies, cookies, auth and signature headers, user email and IP are removed; phone numbers, emails and secrets are redacted from messages and exceptions.
- `VERSION` file (`0.16.3`), shown by `/health` and sent with error reports.
- Migration safety test: destructive steps in `up()` (drop, rename, column change, truncate) need a reviewed `// migration-safety:` reason; three existing column widenings annotated.
- `docs/release-process.md`: versions, merging rules, releasing, rolling back, error tracking.

### S16.2 — Speed and reliability

#### Added

- Lazy-loading guard (fails in development and tests, logged in production); query budgets for Today, inbox, conversation, bookings, booking page, calendar, orders board and platform home.
- Indexes: WhatsApp notifications per number and date, audit log by event and date, subscriptions by status and period end.
- AI circuit breaker: a model failing 3 times in 2 minutes is skipped for a minute; the next model answers; if all fail, staff take over.
- Retry, back-off and time limits on every background job (test enforces it); queued emails retried 4 times; SMTP timeout 15 s; a test enforces a timeout on every outside HTTP call.
- Outage tests: Meta down (reply waits, sent once), email down (retried), payment provider down (clear message, nothing counted), unconfirmable payment (pending, then counted once).
- Load test at scale (`tests/load/scale.js`): weekly small run in CI (`load-weekly.yml`), full size on staging; checks that every conversation is answered with no failed or duplicated sends.
- `docs/reliability.md`.

#### Changed

- Today page: room suggestions for all arrivals in one pass (one availability check per category and dates; never the same room for two arrivals); inbox loads assignees up front.

#### Fixed (before merge)

- The load test lost its staff session after each iteration (k6 clears cookies between iterations), so most page checks failed; it now restores the session every iteration.
- The load test now sends Inertia's asset version, so it also works where the frontend is built (staging).
- Starting an online payment while the provider is down showed an error page; the owner now gets a clear message and the attempt is recorded as failed.

### S16.1 — Security hardening

#### Added

- Test: every hotel route checks a permission (5 reviewed exceptions, with reasons), and each of the six hotel roles opens exactly the screens its permissions allow.
- Test: secrets and personal notes are stored encrypted (14 models); a database check confirms ciphertext.
- Log redaction on every log channel: phone numbers, email addresses, tokens and keys removed before writing.
- Test: every public route that does work or shows data is rate limited.
- Guest files: only safe types are stored (refused types never downloaded, shown to staff as refused); documents are served as downloads, all files in a sandbox.
- Weekly OWASP ZAP baseline scan (`.github/workflows/security-scan.yml`), reporting only.
- `docs/security.md`.

#### Changed

- Guest notes and booking notes are now encrypted (existing values encrypted by the migration).
- The private file store is no longer served over HTTP (it was not used; all files go through access-checked pages).
- Secure session cookies by default outside local and testing.
- Password reset and two-factor management routes rate limited (30 a minute per IP); WhatsApp webhook verification rate limited.
- Horizon requires a signed-in user (plus its super-admin gate) and is rate limited.

#### Fixed

- A closed hotel's export and deletion now include its WhatsApp media (stored outside the hotel's main folder); a test covers both.
- Hotel exports show encrypted notes and messages readable, for the hotel itself.

### S15.3 — Several hotels, closing a hotel, guest data, operations page

#### Added

- My hotels (account menu): owners see all their hotels and **add another** (country template, trial on the default plan, setup checklist; the new hotel opens straight away). Hotel creation is shared with sign-up.
- Closing a hotel — by the owner (password, typed hotel name, two-step login required) or a super admin (with a reason): status closed (staff lose access), subscription cancelled (nothing new invoiced), assistant off, WhatsApp token removed. A ZIP export is prepared at once (one JSON and one CSV file per table plus uploaded files; WhatsApp credentials, platform notes and support records left out) and can be downloaded by the owner or a super admin. Emails on closure and 30 and 7 days before deletion; `hotels:closures` (daily 03:30) deletes the data 90 days after closure — children before parents, including rows without a hotel_id, so nothing is left behind — keeping invoices, the subscription record and the closure record; the hotel is renamed and the deletion is recorded on the platform side. Reopening before deletion restores the hotel's previous status.
- Guest data requests (Owner and Manager): find a guest by name, phone or email; download their data (guest, bookings, WhatsApp numbers, messages) as JSON; or anonymise them (name, contact details, notes, WhatsApp number, message text and media, stay phones removed; bookings and amounts kept). Typed confirmation; audited.
- Platform → Operations (Super admin, Operations): live ops checks, scheduler and queue heartbeats, jobs waiting, failed jobs with retry and discard (audited), latest backups and restore drills, recent incidents, WhatsApp connections of every hotel (status, quality, limit, last error, token check).
- English and French text.

### S15.2 — Support sessions, announcements, feature switches

#### Added

- Support sessions: from the platform's hotel page, Operations and Super admins start a 30-minute session (extendable once) with a reason, read-only by default or with changes. The platform user then sees the hotel's screens with a red banner (time left, extend, end). Read-only sessions cannot change anything; no session can manage roles, staff, the WhatsApp connection or billing. The owner is emailed; every action is recorded in the hotel's history with the session. Hotel → Support access (owner and managers): past and current sessions with their number of actions, end a session, and the setting "allowed with notification" (default) or "ask me first" (the owner approves or refuses each session before it can be entered).
- Announcements (Operations, Super admin): title and text in French and English, information, warning or maintenance, for all hotels or chosen countries, plans or hotels, between two dates, optionally emailed once to the owners (`announcements:send`, every 5 minutes). Shown as banners in every hotel page; information and warning banners can be hidden per user, maintenance banners cannot.
- Feature switches (Super admin): automatic mode, voice notes, restaurant tables/menu/orders on WhatsApp and event enquiries on WhatsApp, each on for everyone (default), off, or on for chosen countries, plans or hotels; checked together with plan modules. When automatic mode is off for a hotel, the assistant works in supervised mode and the hotel cannot switch to automatic; when voice notes are off, they go to staff.
- English and French text.

### S15.1 — Platform roles, platform home, hotel page, audit log

#### Added

- Four platform roles: Super admin (everything), Operations (hotels, go-live, readiness, sign-up codes, audit log; billing read-only), Finance (billing, invoices, payments, issuing companies; hotels read-only), Sales (sign-ups, invitation codes, funnel; hotels read-only). Existing Support users become Operations. Every platform route now checks an ability (`platform.can:…`, `PlatformAbility`); a test fails if a platform route is left without one. The sidebar shows only what the role can open.
- Platform home: needs attention (WhatsApp not connected, no WhatsApp notification for 24 hours on a live hotel, AI credits used up, overdue invoice, go-live request, open operations incidents), today across all hotels (guest messages, messages sent, bookings, AI calls and cost), business (hotels, live, trials ending this week, sign-ups this week, monthly revenue and overdue per currency); filters by country and issuing company.
- Hotel page: overview (owner, plan, trial, assistant mode, setup), health (WhatsApp status, quality and limit, last error, last notification, AI failure rate, AI and email credits left), this month (conversations, messages, bookings, passed to staff), staff, recent history, and internal notes (encrypted, platform-only; authors remove their own, super admins any). Hotels list links to it.
- Audit log viewer for all hotels and the platform: filter by hotel, user email, event and dates; CSV export for super admins (audited).
- English and French text.

#### Changed

- Platform routes previously open to any platform user (e.g. billing changes by Support) now follow the roles above.

### S14.3 — Information pack questionnaire, go-live request, onboarding emails

#### Added

- Information pack questionnaire (15 questions: description, location, directions, check-in, breakfast, restaurant, payment, cancellation, deposit, children, pets, parking, transfers, facilities, house rules) on the setup page. The AI drafts entries in French and English from the answers, using only the facts given; the draft replaces the entries of the sections it covers and is **not published** until the owner publishes it. Answers are kept.
- New setup step "Ask to go live" (cannot be skipped; the Today card stays until the hotel is live). The page lists what still blocks: the required go-live checks (lifted by a platform override, as in S8.1) and two-step login for the owner. The request emails the addresses set in Platform → Sign-ups; the platform approves (assistant switches to supervised mode) or refuses with a reason, and the owner is emailed either way. With "Going live needs platform approval" off, supervised mode starts at once.
- Onboarding emails (can be switched off): welcome after the email is confirmed; setup reminders on trial days 2, 5 and 9 while setup is unfinished (self sign-ups only, never a burst); trial ending in 3 days and on the last day. `onboarding:nudges` daily at 08:00.
- Platform → Sign-ups: funnel (signed up, email confirmed, rooms set, WhatsApp connected, information pack published, asked to go live, live, paying), setup progress per hotel, go-live approval and refusal.
- A full test from sign-up to going live.
- English and French text.

### S14.2 — Setup assistant and imports

#### Added

- Hotel → Set up your hotel (`hotel.settings.manage`): nine steps worked out from the hotel's real data — hotel details, rooms, prices and breakfast, taxes (confirmed by the owner), reception hours, team, WhatsApp, information pack, trying the assistant. Each step links to the existing screen; steps can be skipped and resumed (team and details are optional). A card on Today shows progress until every step is done or skipped.
- Quick room creation: categories with a count and maximum adults; room numbers are created from a first number, skipping numbers already used; category codes are unique.
- File imports (CSV, comma or semicolon, UTF-8 or Excel's Windows encoding, up to 1,000 lines) with a downloadable template and a preview that lists every problem before anything is saved: rooms (category code or name, number, floor) and upcoming bookings (guest, dates as YYYY-MM-DD or DD/MM/YYYY, category, rooms, adults, optional total and old reference). Bookings go through the normal booking rules: lines that no longer fit are refused and reported, never forced; a total from the old system is kept with its reason. Imports are audited.
- English and French text.

#### Decided

- When email credits run out, guest emails are not sent and are logged as blocked (the owner is warned at 80% and 100%), as recommended.

### S14.1 — Sign-up, country templates, legal texts

#### Added

- Public sign-up page for hotel owners (`/signup`, French and English): owner, hotel, country, city, number of rooms, invitation code, acceptance of the terms, privacy notice and data processing agreement. Platform setting: open, invitation code only (default) or closed; invitation codes with optional use limit and expiry. Protection: hidden spam field, throwaway email domains blocked (setting), 5 attempts per hour per IP, password rules. Login page links to sign-up when it is open.
- The hotel is created only after the email address is confirmed, from the country template: currency, time zone, language, check-in/out times and taxes, with the trial on the default plan (S13). The platform team is emailed (addresses set in Platform → Sign-ups).
- 32 country templates: UEMOA and other West African countries, CEMAC, North, East and Southern Africa, with the usual VAT rates as starting values (to be checked with a local accountant; included in prices by default). The platform can edit them or add countries.
- Versioned legal texts (terms of service, privacy notice, data processing agreement, French and English, Markdown) with default drafts for legal review; public pages `/legal/{terms|privacy|dpa}`. Acceptances are recorded (version, time, IP). Publishing a new version asks owners (all three texts) and staff (privacy notice) to accept it at their next request.
- Platform → Sign-ups: sign-up rules and notification addresses (also used for go-live requests in S14.3), invitation codes, new hotels (email confirmed, status, trial end) with suspend and reactivate (suspended hotels: no access, no AI; data kept), country templates, legal texts.
- English and French text.

### S13.3 — Online payment (Paystack, Flutterwave, CinetPay, PayDunya)

#### Added

- Online payment providers set by the platform (Platform → Billing): Paystack, Flutterwave, CinetPay and PayDunya, each with test or live mode, its keys (stored encrypted, write-only), the currencies it accepts and on/off; the notification URL to give each provider is shown.
- Hotel → Billing (`billing.manage`, owner only): pay an open invoice or buy a credit pack with any active provider in the hotel's currency (redirect to the provider's page), and change plan (an upgrade is invoiced at once).
- Payments count only after a server-to-server confirmation from the provider, for exactly the expected amount and currency; notifications are authenticated (Paystack HMAC-SHA512 signature, Flutterwave secret hash, CinetPay site ID, PayDunya master-key hash) and only trigger that check. A payment is applied once whatever the number of notifications; mismatches are refused and audited. Paying lifts reduced mode at once.
- Credit packs bought online: credits are added only after payment, with their own paid invoice.
- `docs/payments.md`: set-up per provider and how payments are counted.
- English and French text.

### S13.2 — Invoices, payments, reduced mode

#### Added

- Issuing companies set by the platform (Platform → Billing): legal name, country, address, RCCM, tax number (NCC), contact, invoice prefix, taxes (e.g. TVA 18%), whether plan prices include tax, payment terms, how to pay (bank, mobile money) and legal footer; one is the default (CNH Global Tech for now), and each hotel can be assigned another. Hotels' billing details (invoice name, address, tax number, email for invoices) on the platform's hotel billing page.
- Invoices from what the subscription records (new periods, pro-rata upgrades, credit packs): `billing:invoice` (hourly) or "Invoice now". Numbering per issuing company and year with no gaps (`CNH-2026-00001`); credit notes share the sequence (`CNH-AV-2026-00002`) and are the only way to correct an invoice (invoices can never be deleted). Issuer and customer details are frozen on each invoice. PDF (DomPDF, `barryvdh/laravel-dompdf` 3.1) emailed to the hotel's billing email and downloadable by the owner (Hotel → Billing) and the platform.
- Payments recorded by the platform (cash, mobile money, card, transfer, with reference and date), partial or full; audited.
- `billing:dunning` (daily): reminders 3, 7 and 10 days after the due date; reduced mode after 14 days unless the platform granted a delay. In reduced mode the assistant is paused (guests are passed to staff), set-up is read-only, and day-to-day work (inbox, bookings, front desk, orders, payments, billing) goes on, with a banner on every page. Paying (or a credit note) lifts it at once.
- Revenue report on Platform → Billing: monthly recurring revenue per currency, overdue totals, and per hotel plan, status, monthly amount, AI cost this month and overdue amount.
- English and French text.

### S13.1 — Plans, subscriptions, credits and limits

#### Added

- Platform → Billing: plans (name per language, modules, staff limit, monthly AI and email credits, prices per currency monthly and yearly, trial days, default plan for new hotels), credit packs (AI or email, valid 12 months by default), and how AI credits are counted (assistant reply and voice note 1 credit, translation 0.5 by default; 5% buffer). Example plans Essentiel, Pro and Complet and three packs are seeded; the platform can change everything.
- Subscriptions per hotel: trial (14 days, trial credits), monthly or yearly periods with the period's credits, immediate pro-rata upgrades (with the extra credits for the rest of the period), downgrades at the end of the period only when the hotel fits the lower plan (staff count, restaurants, upcoming events), trial extension, packs and credit adjustments with a reason. `billing:renew` (hourly) starts due periods and applies scheduled downgrades. Every change is recorded for invoicing (S13.2) and audited. New hotels start a trial on the default plan when one is set.
- Credits from the existing usage counts: the month's credits first, then packs; warnings to the owner at 80% and 100% (new staff alert "AI or email credits running low"), re-armed when credits are added. When AI credits are used up (after the buffer), the assistant passes guests to staff with the hotel's handover message; staff translation also stops. Guest, operational and marketing emails stop and are logged as blocked; security and system emails are never counted or blocked. Hotels without a subscription are not limited.
- Modules (restaurant & bar, orders, events): screens, routes and assistant tools of modules the plan does not include are hidden (data is kept); staff accounts limited by the plan.
- Hotel → Billing (`billing.view`): plan, period, staff accounts, AI and email credits left, credit packs, WhatsApp messages this period (billed by Meta to the hotel), plans available.
- English and French text.

#### Fixed (before merge)

- A credit pack created without an explicit validity expired immediately; packs now default to 12 months.

### S12.3 — Events: attendee rooms, WhatsApp, test suite

#### Added

- Rooms for attendees: block N rooms of a category for the event's nights at a group rate, until a cut-off date. The block is an inventory hold, so the rooms leave sale at once; reception books attendees from the block (`bookings.create`), each booking marked with the event and priced at the group rate (with the reason recorded); after the cut-off day the unused rooms are back on sale by themselves, and `events:release-room-blocks` (hourly) records the release. Staff can release early.
- `event_enquiry` assistant tool: collects the date, type, people, time of day, needs, contact name and company, and creates the enquiry for the events team (time block matched by name, WhatsApp number and conversation linked); new staff alert "New event enquiry". Nothing is created from the practice screen. The assistant never gives an event price (no tool returns one, so the price check blocks any amount) and never promises a space is free.
- WhatsApp messages to the client: the quote link when a quote is sent (template `event_quote_ready` after 24 hours, French and English) and a thank-you when it is accepted; the job carrying the link is encrypted on the queue.
- The information pack lists event spaces and capacities (no prices); the assistant's rules cover events.
- Assistant test suite: 10 event cases (100 cases, 39 safety); the test hotel has a conference room and time blocks.
- A full event scenario test: WhatsApp enquiry, quote, option, link on WhatsApp, online acceptance, deposit, confirmation, event sheet, attendee rooms booked and the rest released at the cut-off.
- English and French text.

### S12.2 — Events: quotes, online acceptance, accounts, event sheet

#### Added

- Versioned event quotes built from the space's price for the time block, packages and extras from the catalogue, free lines, a discount (needs `events.discount` and a reason) and the Events tax; valid for a number of days set by the hotel; the hotel's event terms are copied onto each quote. Earlier versions stay readable; sending a new version replaces the previous link.
- Private quote link (`/q/…`, a 40-character key of which only a hash is stored): a mobile page with the lines, totals, deposit and terms, where the client accepts online with their name, title and a tick for the terms; time and IP are recorded. Expired, replaced or already accepted quotes cannot be accepted. Staff can record an acceptance made on paper.
- Confirming an event now needs an accepted quote and the deposit (hotel setting, 30% by default) recorded on the event's account; on confirmation the quote total is posted to the account.
- Event accounts: the room-bill ledger now belongs to a stay or an event (`folio_lines.event_id`; existing room-bill lines are untouched). Extras on the day, payments, corrections and a printable statement work as for room bills.
- Event sheet for the teams (timings, menu, equipment, notes for kitchen, setup and reception), saved as versions; versions saved after confirmation are flagged and the printed sheet says it was updated.
- Settings: deposit %, option length, quote validity and event terms per language (Events → Spaces, prices and packages).
- English and French text.

#### Changed

- The spec proposed renaming `folio_lines`; adding `event_id` instead gives the same result with no data moved, so nothing can be lost.

### S12.1 — Events: spaces, time blocks, options and enquiries

#### Added

- Event spaces with a name per language and a capacity per layout (theatre, classroom, U-shape, banquet, cocktail); combined spaces (e.g. two rooms opened into one) use their parts. Time blocks set by the hotel (morning, afternoon, evening…); a block can also take others (a full day takes the morning and afternoon). Prices per space and block, and packages (per person) and extras (per person, per unit or per event) for quotes (Events → Spaces, prices and packages).
- Events (`E-YY-NNNN`): enquiries by phone, email or in person with contact, company or family, type, date, time block, space, layout, people and encrypted notes; statuses enquiry, quoted, option, confirmed, done, lost (with reason), cancelled; every step audited.
- Options hold a space until a date (7 days by default). Two events clash when they use a common physical space at overlapping blocks on the same day; holds lock the hotel's spaces, so two events can never hold the same space at once (MySQL two-connection test). A second option for a held space joins a waiting list, and moves up automatically when the space is freed (lost, cancelled or option expired). `events:expire-options` (every 5 minutes) returns expired options to "quoted" and alerts the team (new staff alert). Confirming checks the space is free, big enough for the layout and the date is ahead.
- Events calendar (month, spaces × days: confirmed, on option, waiting list) and the list of open enquiries and options.
- Permissions `events.view` (Reception, Read-only), `events.manage` and `events.discount`; the Events coordinator role views and manages events.
- English and French text.

### S11.3 — Ordering on WhatsApp

#### Added

- `place_order` assistant tool: dishes are matched to what can be ordered now (unknown dishes get the list of what exists); the first call returns the dishes and exact total to repeat to the guest, and the order is sent only when called again after the guest agrees. Room delivery and the room bill only for numbers that belong to a stay; other people can order at the outlet and pay on delivery, unless the hotel turned that off; room-bill limits are explained so the guest can pay on delivery instead. Nothing is sent from the practice screen. New staff alert "New food or drink order".
- `get_my_bill` assistant tool: the room bill (lines, charges, payments, balance) only for a number that belongs to the stay; any other number learns nothing.
- The system, never the AI, tells the guest when the kitchen accepts the order (with the waiting time), declines or cancels it, or has it ready at the outlet. The "never say confirmed" check now also covers "order accepted" (French and English).
- Assistant test suite: 10 ordering and bill cases (90 cases, 35 safety); the test hotel's restaurant takes orders. A test checks that every tool the suite expects exists.
- The information pack says which outlets take orders; the assistant's rules cover orders and bills.
- A full stay scenario test: order, preview and agreement, kitchen, delivery, the bill on the room and on WhatsApp, mobile money payment and check-out.
- English and French text.

### S11.2 — Room bill

#### Added

- Room bill for each stay (booking page): the nights (always the booking's current total, so it follows date changes), orders delivered and charged to the room (posted once), extra charges (minibar, laundry…, with food and drink or room taxes added if chosen), and payments (cash, Orange Money, MTN MoMo, Moov Money, Wave, card, bank transfer, as the hotel chooses, with a reference). Balance = charges − payments. Lines are never edited or deleted: a correction adds the opposite amount with a reason. Every line is audited.
- Room charges: on or off, a limit per order and per stay (orders not yet delivered count against the stay limit); above a limit the order is paid on delivery. Setting for WhatsApp orders from people not staying (used in S11.3). Set in Restaurant & Bar → Outlets and opening hours (`restaurant.manage`).
- Check-out with a balance asks staff to record the payment or give a reason (audited) — on the booking page and the Today screen.
- Printable statement of account (A4 and phone), clearly marked "not a tax invoice".
- Permissions `folio.view` (Reception, Read-only, Manager, Owner), `folio.charge` and `folio.payments` (Reception, Manager, Owner).
- English and French text.

### S11.1 — Orders during the stay

#### Added

- Assistant test suite: 15 restaurant cases (free times, small and large parties, occasions, closed date, menu and prices, vegetarian and allergy questions, "confirmed" wording, invented menu prices); the test hotel now has a restaurant with lunch, dinner, a closed date and a short menu. 80 cases, 31 safety cases.
- Guests staying: a WhatsApp number counts as staying when it matches a checked-in booking's guest or an extra number reception added to the stay (booking page, `bookings.frontdesk`, audited).
- Food and drink orders (`O-YY-NNNN`): outlets with ordering switched on, dishes marked "can be ordered", available today and served now; prices with food and drink tax copied onto the order; delivery to a room or at the outlet; paid on delivery, or on the room bill for a checked-in guest only. Statuses new → accepted (with a waiting time) → ready → delivered, or declined / cancelled; every step audited.
- Restaurant & Bar → Orders (`restaurant.orders`: Restaurant & Bar role, Reception, Manager, Owner): New / Accepted / Ready columns refreshed every 10 seconds, waiting time, optional browser alert, print, today's finished orders, and taking orders by phone or at the counter.
- English and French text.

### S10.3 — Restaurant & Bar on WhatsApp

#### Added

- Assistant tools: `restaurant_availability` (outlets, and free times for a party on a date), `book_table` (needs the guest's name; nothing is booked from the practice screen) and `get_menu` (dishes served now with the price the guest pays, labels, allergens and the full menu link). Menu prices returned by the tool pass the price check; invented prices are still blocked.
- Small ordinary parties (up to the outlet's limit, default 6) are confirmed automatically; larger parties, outlets without automatic confirmation, and notes about occasions, groups or private events become a request for the restaurant team, holding the seats for two hours and never later than one hour before the booking. New staff alert "New table request". `table-requests:expire` (every minute) releases unanswered requests, tells the guest the team will contact them and marks the conversation "needs attention".
- The system, never the AI, tells the guest a table is confirmed or declined: free text within 24 hours (sent a few seconds after the assistant's reply), otherwise the new `table_booking_confirmed` / `table_booking_declined` templates (French and English). The "never say confirmed" check now covers tables too.
- The information pack lists outlets with their services and the menu link; the assistant's rules include the restaurant.
- English and French text.

### S10.2 — Restaurant & Bar: menu

#### Added

- Menu per outlet (Restaurant & Bar → Menu, new permission `restaurant.menu` for Owner, Manager and the Restaurant & Bar role): sections and dishes with names and descriptions per language, price, labels (vegetarian, vegan, spicy, halal, house special), allergen labels (the 14 common allergens), the services a dish is served at, an optional photo (JPEG, PNG or WebP up to 3 MB, stored privately), and on/off. Price changes are audited.
- "Not available today": stores today's date (hotel time zone), so the dish comes back by itself the next morning with no scheduled job.
- The price the guest pays: the hotel's food and drink taxes applied as set up (added taxes included in the shown price).
- Public menu page per outlet (`/m/{hotel}/{outlet}`): mobile, light and dark, French/English switch, the dishes served now or the whole menu, photos, labels and allergens; no login, no personal data, separate per hotel. QR code (SVG) to print, from the menu screen.
- English and French text.

### S10.1 — Restaurant & Bar: outlets, opening hours, table bookings

#### Added

- Outlets (restaurant, bar, terrace, room service) with names per language, seats, how long a table is kept, an optional limit on guests arriving at one time, the automatic-confirmation party limit for WhatsApp (used in S10.3), and an on/off switch. Services per weekday (e.g. dinner 19:00–22:30, bookings every 15/30/60 minutes, last seating before closing) and closed dates for the whole day or one service (Restaurant & Bar → Outlets and opening hours, `restaurant.manage`).
- Table availability counted by seats in use over time: a time is offered when the seats in use throughout the booking stay within the outlet's seats and arrivals stay within the limit; pending requests hold seats until they expire; past times are not offered today (hotel time zone). Bookings lock the outlet row, so two bookings can never take the same seats (MySQL two-connection test).
- Table bookings (`T-YY-NNNN`) with guest (found or created from the phone number), number of people, source (WhatsApp, phone, walk-in), encrypted notes, and statuses (waiting for staff, confirmed, arrived, finished, no-show, cancelled, declined, expired) with allowed moves; arrival and no-show only on the day. Every change is audited.
- Restaurant & Bar screen (`restaurant.view`): outlet and day, seats in use per booking time, bookings by service with the next possible actions; quick booking for phone and walk-in guests showing only times that fit (`restaurant.bookings`).
- Permissions `restaurant.view`, `restaurant.bookings`, `restaurant.manage`: Owner and Manager all; Reception and the Restaurant & Bar role view and take bookings; Read-only views.
- English and French text.

### S8.3 — Simulation, load tests, failure drills, launch documents

#### Added

- Simulation mode for staging (`SIMULATION_MODE=true`; the application refuses to start with it in production): a fake Meta Graph API that accepts sends and plays back "sent", "delivered" and "read" receipts through the normal webhook processing, serves media, and injects failures and delay (`SIMULATION_META_*`); a fake AI that gives plausible replies without prices, translations and transcripts, with configurable delay and failure rate (`SIMULATION_AI_*`).
- `simulation:prepare` (demo hotels, a simulated WhatsApp number for First Class Lodge, published information pack, assistant on; `--report --expect-replies` checks every conversation was answered with nothing failed or duplicated) and `simulation:guests` (signed guest messages to the webhook).
- k6 load tests (`tests/load/`): 50 messages per second burst, a 1,000-guest day, 20 receptionists on the dashboard, and a smoke test. CI runs the smoke test against a real server with the simulated stack, only when those files change (k6 1.4.2, checksum verified).
- `docs/failure-drills.md`: twelve drills with expected results and a results table.
- Launch documents (`docs/launch/`, Markdown and PDF): reception guide and one-page cheat sheet (French), owner guide (French), cut-over plan, and drafts of the data processing agreement (French) and guest privacy notice (French and English) for review by CNH's legal adviser. `scripts/build_launch_pdfs.py` rebuilds the PDFs.

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
