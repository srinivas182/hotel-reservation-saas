#!/usr/bin/env bash
# ---------------------------------------------------------------------
# MySQL backup for the Docker stack: compressed and encrypted, with
# retention. Run nightly from the host's cron, and by deploy.sh before
# migrations.
#
#   ./docker/backup/backup.sh [label]
#
# Needs BACKUP_ENCRYPTION_KEY in .env (a long random string). Keep a copy
# of the key outside the server: without it backups cannot be restored.
# Restore steps: docs/deployment.md.
# ---------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/../.."
# shellcheck disable=SC1091
set -a; source .env; set +a

: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY must be set in .env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/hotel-saas}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
label="${1:-nightly}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
file="${BACKUP_DIR}/${stamp}-${label}.sql.gz.enc"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

docker compose -f compose.prod.yaml exec -T mysql \
    sh -c 'exec mysqldump --single-transaction --quick --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip -9 \
    | openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_ENCRYPTION_KEY \
    > "$file"

chmod 600 "$file"
echo "Backup written: ${file} ($(du -h "$file" | cut -f1))"

find "$BACKUP_DIR" -name '*.sql.gz.enc' -mtime +"$RETENTION_DAYS" -delete
