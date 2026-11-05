#!/usr/bin/env bash
# ---------------------------------------------------------------------
# MySQL backup for the Docker stack: compressed and encrypted, copied
# off-site, with retention, and recorded in the application so the ops
# check and the go-live page know whether backups work. Run nightly from
# the host's cron, and by deploy.sh before migrations.
#
#   ./docker/backup/backup.sh [label]
#
# .env:
#   BACKUP_ENCRYPTION_KEY   long random string (keep a copy OFF the server:
#                           without it backups cannot be restored)
#   BACKUP_DIR              local folder (default /var/backups/hotel-saas)
#   BACKUP_RETENTION_DAYS   local copies kept (default 14)
#   BACKUP_S3_URI           off-site bucket, e.g. s3://mcs-backups/hotel-saas
#   BACKUP_S3_ENDPOINT      endpoint for S3-compatible storage (optional)
#   AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_DEFAULT_REGION
#
# Off-site retention: 14 daily, 8 weekly (Sundays), 6 monthly (1st).
# Restore steps and the monthly drill: docs/operations.md.
# ---------------------------------------------------------------------
set -Eeuo pipefail

cd "$(dirname "$0")/../.."
# shellcheck disable=SC1091
set -a; source .env; set +a

COMPOSE="docker compose -f compose.prod.yaml"
: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY must be set in .env}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/hotel-saas}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
label="${1:-nightly}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
name="${stamp}-${label}.sql.gz.enc"
file="${BACKUP_DIR}/${name}"

record() { # status [extra artisan options...]
    $COMPOSE exec -T app php artisan backup:record "$@" >/dev/null 2>&1 || echo "Warning: could not record the backup result in the application." >&2
}

on_error() {
    record failure --label="$label" --error="backup.sh failed at line $1"
    rm -f "$file"
    echo "Backup FAILED (line $1)." >&2
}
trap 'on_error $LINENO' ERR

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

$COMPOSE exec -T mysql \
    sh -c 'exec mysqldump --single-transaction --quick --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip -9 \
    | openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_ENCRYPTION_KEY \
    > "$file"

chmod 600 "$file"
size="$(stat -c %s "$file")"
echo "Backup written: ${file} ($(du -h "$file" | cut -f1))"

off_site=""
if [ -n "${BACKUP_S3_URI:-}" ]; then
    aws() {
        docker run --rm -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_DEFAULT_REGION \
            -v "$BACKUP_DIR:/backups:ro" amazon/aws-cli:2.22.35 \
            ${BACKUP_S3_ENDPOINT:+--endpoint-url "$BACKUP_S3_ENDPOINT"} "$@"
    }
    prune() { # prefix keep
        aws s3 ls "${BACKUP_S3_URI}/$1/" | awk '{print $4}' | sort -r | tail -n +"$(( $2 + 1 ))" \
            | while read -r old; do [ -n "$old" ] && aws s3 rm "${BACKUP_S3_URI}/$1/${old}" >/dev/null; done
    }

    aws s3 cp "/backups/${name}" "${BACKUP_S3_URI}/daily/${name}" --only-show-errors
    [ "$(date -u +%u)" = "7" ] && aws s3 cp "/backups/${name}" "${BACKUP_S3_URI}/weekly/${name}" --only-show-errors
    [ "$(date -u +%d)" = "01" ] && aws s3 cp "/backups/${name}" "${BACKUP_S3_URI}/monthly/${name}" --only-show-errors
    prune daily 14
    prune weekly 8
    prune monthly 6
    off_site="--off-site"
    echo "Copied off-site to ${BACKUP_S3_URI}."
fi

find "$BACKUP_DIR" -name '*.sql.gz.enc' -mtime +"$RETENTION_DAYS" -delete

trap - ERR
record success --label="$label" --file="$name" --size="$size" $off_site
