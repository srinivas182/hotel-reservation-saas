#!/usr/bin/env bash
# ---------------------------------------------------------------------
# Deploy a release to a single-server Docker host (staging/production).
#
#   ./deploy.sh            deploy origin/main
#   ./deploy.sh <git-ref>  deploy a specific commit, tag or branch
#   ./deploy.sh --rollback switch back to the previous release images
#
# Steps: fetch code -> build PHP and web images tagged with the commit ->
# back up the database -> run migrations -> swap containers -> health
# check. If the health check fails, the previous release is restored.
#
# Downtime: containers are replaced in place, so expect a few seconds of
# interruption. Blue-green deployment is planned for the DevOps sprint.
# Migrations must stay backward compatible with the previous release,
# because a rollback switches code but does not reverse migrations.
# ---------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")"

COMPOSE=(docker compose -f compose.prod.yaml)
STATE_DIR=".deploy"
mkdir -p "$STATE_DIR"

log() { printf '\033[36m[deploy]\033[0m %s\n' "$*"; }
fail() { printf '\033[31m[deploy]\033[0m %s\n' "$*" >&2; exit 1; }

[ -f .env ] || fail ".env is missing. See docs/deployment.md."
# shellcheck disable=SC1091
set -a; source .env; set +a
[ -n "${APP_DOMAIN:-}" ] || fail "APP_DOMAIN must be set in .env."
[ -n "${HEALTH_CHECK_TOKEN:-}" ] || fail "HEALTH_CHECK_TOKEN must be set in .env."

health_check() {
    local url="https://${APP_DOMAIN}/health"
    for attempt in $(seq 1 20); do
        if curl -fsS -H "X-Health-Token: ${HEALTH_CHECK_TOKEN}" "$url" >/dev/null 2>&1; then
            return 0
        fi
        log "Waiting for health check (${attempt}/20)..."
        sleep 6
    done
    return 1
}

swap_to() {
    local release="$1"
    log "Starting release ${release}"
    export RELEASE="$release"
    "${COMPOSE[@]}" up -d --remove-orphans app horizon scheduler web caddy
    "${COMPOSE[@]}" exec -T app php artisan optimize
    "${COMPOSE[@]}" exec -T horizon php artisan horizon:terminate || true
}

if [ "${1:-}" = "--rollback" ]; then
    [ -f "$STATE_DIR/previous" ] || fail "No previous release recorded."
    previous="$(cat "$STATE_DIR/previous")"
    swap_to "$previous"
    health_check || fail "Rollback release ${previous} is unhealthy. Investigate immediately."
    cp "$STATE_DIR/current" "$STATE_DIR/previous" 2>/dev/null || true
    echo "$previous" > "$STATE_DIR/current"
    log "Rolled back to ${previous}."
    exit 0
fi

ref="${1:-origin/main}"
log "Fetching ${ref}"
git fetch --quiet --tags origin
git checkout --quiet --detach "$ref"
release="$(git rev-parse --short HEAD)"
current="$(cat "$STATE_DIR/current" 2>/dev/null || true)"

log "Building images for ${release}"
docker build --target prod -f docker/php/Dockerfile -t "hotel-saas-php:${release}" .
docker build --target web  -f docker/php/Dockerfile -t "hotel-saas-web:${release}" .

"${COMPOSE[@]}" up -d --wait mysql redis

log "Backing up the database before migrating"
./docker/backup/backup.sh "pre-deploy-${release}"

log "Running migrations"
RELEASE="$release" "${COMPOSE[@]}" run --rm --no-deps app php artisan migrate --force

log "Syncing permissions and system roles"
RELEASE="$release" "${COMPOSE[@]}" run --rm --no-deps app php artisan permissions:sync

swap_to "$release"

if health_check; then
    if [ -n "$current" ] && [ "$current" != "$release" ]; then echo "$current" > "$STATE_DIR/previous"; fi
    echo "$release" > "$STATE_DIR/current"
    log "Release ${release} is live and healthy."
    exit 0
fi

log "Release ${release} failed its health check."
if [ -n "$current" ]; then
    log "Restoring previous release ${current}"
    swap_to "$current"
    health_check && fail "Rolled back to ${current}. Release ${release} needs fixing (backup: pre-deploy-${release})."
fi
fail "Release ${release} is unhealthy and no healthy previous release is available."
