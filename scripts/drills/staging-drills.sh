#!/usr/bin/env bash
# ---------------------------------------------------------------------
# S16.4: the launch drills on STAGING, one command, results recorded for
# the launch checklist (php artisan drills:status shows them).
#
#   ./scripts/drills/staging-drills.sh            all drills (~45 minutes)
#   ./scripts/drills/staging-drills.sh mysql_restart ai_down   some drills
#
# Needs: staging deployed with SIMULATION_MODE=true and simulation:prepare
# done. Refuses to run on production. Each drill makes something fail,
# lets simulated guests write, then checks that every guest was answered
# (or handed to staff) once, with nothing lost or duplicated.
# ---------------------------------------------------------------------
set -uo pipefail

cd "$(dirname "$0")/../.." || exit 1
COMPOSE=(docker compose -f compose.prod.yaml)
PHONE=SIM-5550001
REPORT="storage/drills-$(date +%Y%m%d-%H%M).md"

art() { "${COMPOSE[@]}" exec -T app php artisan "$@"; }
log() { printf '\n==> %s\n' "$*"; }
now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
env_get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2-; }
env_set() { sed -i "s|^$1=.*|$1=$2|" .env; grep -q "^$1=" .env || echo "$1=$2" >> .env; }
restart_php() { "${COMPOSE[@]}" up -d --force-recreate --no-deps app horizon scheduler >/dev/null; sleep 15; }
healthy() { curl -fsS -o /dev/null "$(env_get APP_URL)/up"; }
wait_healthy() { for _ in $(seq 1 60); do healthy && return 0; sleep 5; done; return 1; }
record() { # drill, pass|fail, notes
    art drills:record "$1" "$2" --notes="$3" >/dev/null || true
    printf '| %s | %s | %s | %s |\n' "$1" "$2" "$(now)" "$3" >> "$REPORT"
    log "$1: $2 — $3"
}
check() { art simulation:check --since="$1" --expect="${2:-replies}" >/dev/null; }

[ "$(env_get APP_ENV)" = "staging" ] || { echo "Staging only (APP_ENV=$(env_get APP_ENV))." >&2; exit 1; }
[ "$(env_get SIMULATION_MODE)" = "true" ] || { echo "SIMULATION_MODE must be true." >&2; exit 1; }
wait_healthy || { echo "Staging is not healthy." >&2; exit 1; }

printf '# Staging drills — %s\n\n| Drill | Result | When (UTC) | Notes |\n| --- | --- | --- | --- |\n' "$(now)" > "$REPORT"

drill_load_full() {
    local since; since=$(now)
    if docker run --rm -i --network host -v "$PWD/tests/load:/scripts:ro" grafana/k6:1.4.2 run \
        -e SCALE=full -e BASE_URL="$(env_get APP_URL)" -e APP_SECRET="$(env_get WHATSAPP_APP_SECRET)" -e INERTIA_JSON=1 /scripts/scale.js; then
        sleep 120; check "$since" && record load_full pass "k6 thresholds met; every conversation answered once" || record load_full fail "thresholds met but conversations missing, failed or duplicated"
    else
        record load_full fail "k6 thresholds not met (see output)"
    fi
}

drill_workers_stop() {
    local since; since=$(now)
    "${COMPOSE[@]}" stop horizon >/dev/null
    art simulation:guests "$PHONE" --conversations=10 --messages=1 >/dev/null
    sleep 300 # ops alert "workers stopped" is expected within 5 minutes
    "${COMPOSE[@]}" start horizon >/dev/null; sleep 120
    check "$since" && record workers_stop pass "guests waited, then each got one reply" || record workers_stop fail "replies missing or duplicated after restart"
}

drill_redis_restart() {
    local since; since=$(now)
    art simulation:guests "$PHONE" --conversations=10 --messages=2 >/dev/null &
    sleep 3; "${COMPOSE[@]}" restart redis >/dev/null; wait
    sleep 120
    check "$since" && record redis_restart pass "queued work resumed" || record redis_restart fail "messages lost or duplicated"
}

drill_mysql_restart() {
    local since; since=$(now)
    "${COMPOSE[@]}" restart mysql >/dev/null
    wait_healthy || { record mysql_restart fail "health did not come back"; return; }
    art simulation:guests "$PHONE" --conversations=10 --messages=1 >/dev/null; sleep 120
    check "$since" && record mysql_restart pass "health back; guests answered" || record mysql_restart fail "guests not answered after restart"
}

drill_ai_down() {
    local since; since=$(now)
    env_set SIMULATION_AI_FAILURE_RATE 1; restart_php
    art simulation:guests "$PHONE" --conversations=5 --messages=1 >/dev/null; sleep 120
    if check "$since" handover; then
        local back; back=$(now); env_set SIMULATION_AI_FAILURE_RATE 0; restart_php
        art simulation:guests "$PHONE" --conversations=5 --messages=1 >/dev/null; sleep 90
        check "$back" && record ai_down pass "guests handed to staff, then replies resumed" || record ai_down fail "replies did not resume"
    else
        env_set SIMULATION_AI_FAILURE_RATE 0; restart_php
        record ai_down fail "guests not handed to staff"
    fi
}

drill_meta_errors() {
    local since; since=$(now)
    env_set SIMULATION_META_FAILURE_RATE 0.5; restart_php
    art simulation:guests "$PHONE" --conversations=10 --messages=1 >/dev/null; sleep 240
    env_set SIMULATION_META_FAILURE_RATE 0; restart_php; sleep 120
    art simulation:check --since="$since" >/dev/null \
        && record meta_errors pass "failed sends retried; nothing sent twice" || record meta_errors fail "sends failed for good or were duplicated"
}

drill_mail_down() {
    local since host; since=$(now); host=$(env_get MAIL_HOST)
    env_set MAIL_MAILER smtp; env_set MAIL_HOST unreachable.invalid; restart_php
    art tinker --execute="Illuminate\Support\Facades\Mail::raw('drill', fn (\$m) => \$m->to('drill@example.com')->subject('drill'));" >/dev/null 2>&1 || true
    art queue:failed >/dev/null 2>&1 || true
    art simulation:guests "$PHONE" --conversations=5 --messages=1 >/dev/null; sleep 120
    env_set MAIL_HOST "$host"; restart_php
    check "$since" && record mail_down pass "workers kept answering guests while e-mail was down" || record mail_down fail "guests not answered while e-mail was down (workers stopped?)"
}

drill_duplicate_webhooks() {
    local since; since=$(now)
    art simulation:guests "$PHONE" --conversations=5 --messages=2 --duplicate >/dev/null; sleep 90
    check "$since" && record duplicate_webhooks pass "each notification stored once, one reply" || record duplicate_webhooks fail "duplicates stored or replied twice"
}

drill_restore() {
    ./docker/backup/verify-restore.sh >/dev/null 2>&1 && record restore pass "latest backup restored and verified" || record restore fail "restore verification failed"
}

drill_rollback() {
    ./deploy.sh >/dev/null 2>&1 || { record rollback fail "deploy failed"; return; }
    ./deploy.sh --rollback >/dev/null 2>&1 || { record rollback fail "rollback failed"; return; }
    ./deploy.sh >/dev/null 2>&1 && wait_healthy && record rollback pass "deploy, rollback and redeploy healthy" || record rollback fail "redeploy after rollback failed"
}

DRILLS=("$@")
[ ${#DRILLS[@]} -gt 0 ] || DRILLS=(load_full workers_stop redis_restart mysql_restart ai_down meta_errors mail_down duplicate_webhooks restore rollback)
for drill in "${DRILLS[@]}"; do
    declare -F "drill_${drill}" >/dev/null || { echo "Unknown drill: $drill" >&2; continue; }
    log "Drill: $drill"
    "drill_${drill}"
done

log "Report: $REPORT"
art drills:status
