# Load tests (k6)

Run against **staging in simulation mode** (`SIMULATION_MODE=true`): the
fake Meta API answers sends and the fake AI answers guests, so no real
guest, WhatsApp number or paid AI is involved. Never point these at
production.

## Prepare staging

```bash
php artisan simulation:prepare        # demo hotels, simulated number, assistant on
php artisan simulation:prepare --report   # counts after a run
```

## Run

```bash
k6 run -e BASE_URL=https://staging.example.com -e APP_SECRET=<WHATSAPP_APP_SECRET> \
       -e STAFF_EMAIL=reception.lodge@example.com -e STAFF_PASSWORD=password \
       tests/load/webhook-burst.js
```

| Script                 | What it simulates                                              | Targets                         |
| ---------------------- | -------------------------------------------------------------- | ------------------------------- |
| `webhook-burst.js`     | 50 guest messages per second for 2 minutes                     | no errors; webhook p95 < 300 ms |
| `conversations-day.js` | 1,000 guests × 3 messages (a busy day in 10 minutes)           | no errors; webhook p95 < 300 ms |
| `dashboard.js`         | 20 receptionists using Today, Inbox and Calendar for 5 minutes | no errors; pages p95 < 800 ms   |
| `smoke.js`             | a few messages and one receptionist (CI)                       | every check passes              |

After each run, check that nothing was lost or duplicated:
`php artisan simulation:prepare --report` (guest messages = messages sent by the
script; replies roughly match; no failed sends unless failures were injected)
and Horizon for failed jobs.

Inject failures with `SIMULATION_META_FAILURE_RATE` and
`SIMULATION_AI_FAILURE_RATE` (0 to 1); see `docs/failure-drills.md`.
