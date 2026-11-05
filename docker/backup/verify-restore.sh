#!/usr/bin/env bash
# ---------------------------------------------------------------------
# Restore drill: restores the latest local backup into a temporary
# database, lets the application check it (migrations, core data, and
# that encrypted values are readable with this server's key), records the
# result, then drops the temporary database. Run monthly from cron and
# whenever you want proof that backups can be restored.
#
#   ./docker/backup/verify-restore.sh [backup-file]
# ---------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/../.."
# shellcheck disable=SC1091
set -a; source .env; set +a

COMPOSE="docker compose -f compose.prod.yaml"
: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY must be set in .env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/hotel-saas}"
file="${1:-$(ls -1t "$BACKUP_DIR"/*.sql.gz.enc 2>/dev/null | head -n 1)}"
temp_db="restore_check_$(date -u +%Y%m%d%H%M%S)"

if [ -z "$file" ] || [ ! -f "$file" ]; then
    echo "No backup file found." >&2
    exit 1
fi

cleanup() {
    $COMPOSE exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`${temp_db}\`'" || true
}
trap cleanup EXIT

echo "Restoring $(basename "$file") into ${temp_db}…"
$COMPOSE exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'CREATE DATABASE \`${temp_db}\`; GRANT ALL ON \`${temp_db}\`.* TO \"'\"\$MYSQL_USER\"'\"@\"%\"'"
openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_KEY -in "$file" \
    | gunzip \
    | $COMPOSE exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" \"${temp_db}\""

$COMPOSE exec -T app php artisan backup:verify-restored "$temp_db" --file="$(basename "$file")"
