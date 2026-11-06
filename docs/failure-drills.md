# Failure drills (staging, simulation mode)

Run on **staging** with `SIMULATION_MODE=true` after `php artisan simulation:prepare`.
Each drill has an expected result; record the outcome in the table at the end
and attach it to the launch report. Never run drills on production.

Before each drill: `k6 run tests/load/conversations-day.js` running in the
background (or `php artisan simulation:guests SIM-5550001 --conversations=20`),
so the system is busy when the failure happens.

| #   | Drill                                    | How                                                                                                                  | Expected result                                                                                                                                                                                                           |
| --- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Background workers stop                  | `docker compose stop worker` for 10 minutes, then start it                                                           | Webhooks still answered 200 (stored); no reply while stopped; ops alert "workers stopped" within 5 min; after restart every waiting guest gets **one** reply; nothing lost or duplicated (`simulation:prepare --report`). |
| 2   | Redis restarts                           | `docker compose restart redis`                                                                                       | Brief errors at most; queued jobs resume; sessions may log staff out (acceptable); no lost messages.                                                                                                                      |
| 3   | MySQL restarts                           | `docker compose restart mysql`                                                                                       | `/health` red for the restart, then green; webhooks during the outage are retried by Meta (simulate with `simulation:guests` after restart); no partial bookings.                                                         |
| 4   | AI providers down                        | `SIMULATION_AI_FAILURE_RATE=1`, restart app                                                                          | Every guest is passed to staff with the hotel's message (`ai_unavailable:all_failed`); ops alert "AI providers failing"; inbox shows "needs attention". Set back to 0: replies resume.                                    |
| 5   | AI slow                                  | `SIMULATION_AI_LATENCY_MIN=8000 SIMULATION_AI_LATENCY_MAX=20000`                                                     | Replies arrive late but once; no duplicate replies to bursts; timeouts fall back to the backup model.                                                                                                                     |
| 6   | Meta errors on send                      | `SIMULATION_META_FAILURE_RATE=0.5`                                                                                   | Failed sends retried (5xx); none sent twice; failures shown in the inbox with the reason; ops alert "WhatsApp messages failing".                                                                                          |
| 7   | Access token expired                     | Edit the simulated account's token, make the fake return error 190 (`SIMULATION_META_FAILURE_RATE=1` + manual check) | Hotel asked to reconnect WhatsApp; nothing retried in a loop.                                                                                                                                                             |
| 8   | Duplicate and out-of-order notifications | Replay the same webhook twice (`php artisan whatsapp:replay --event=<id>`), send a "read" receipt before "delivered" | One message stored, one reply; receipt status never goes backwards.                                                                                                                                                       |
| 9   | Last room race                           | Two k6 VUs request the last room via the practice tool / two `request_booking` calls at once                         | One request holds the room; the other is told it is no longer available.                                                                                                                                                  |
| 10  | Disk almost full                         | Fill the backup volume to 95%                                                                                        | Backup fails, ops alert "backup failed"; app keeps working. Clean up afterwards.                                                                                                                                          |
| 11  | Restore drill                            | `./docker/backup/verify-restore.sh`                                                                                  | All checks pass; result on the pilot dashboard.                                                                                                                                                                           |
| 12  | Switch off                               | Hotel → AI assistant → Off during load                                                                               | Guests stop receiving AI replies within a minute; staff answer in the inbox.                                                                                                                                              |

## Results

| #   | Date | Who | Result (pass / fail) | Notes, follow-up |
| --- | ---- | --- | -------------------- | ---------------- |
| 1   |      |     |                      |                  |
| 2   |      |     |                      |                  |
| 3   |      |     |                      |                  |
| 4   |      |     |                      |                  |
| 5   |      |     |                      |                  |
| 6   |      |     |                      |                  |
| 7   |      |     |                      |                  |
| 8   |      |     |                      |                  |
| 9   |      |     |                      |                  |
| 10  |      |     |                      |                  |
| 11  |      |     |                      |                  |
| 12  |      |     |                      |                  |
