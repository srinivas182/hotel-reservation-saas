#!/usr/bin/env bash
# Nightly backup for a CloudPanel install (no Docker): the database and the
# uploaded files, gzipped into ~/backups, kept 14 days, and recorded so
# Platform → Operations and the go-live checks see it.
#
# Run as the site user, from the site folder or anywhere:
#   bash scripts/cloudpanel/backup.sh
# CloudPanel → Cron Jobs, every night (02:15):
#   15 2 * * *  bash /home/<site-user>/htdocs/<domain>/scripts/cloudpanel/backup.sh >> /home/<site-user>/logs/backup.log 2>&1
#
# The database password is read from .env and passed through MYSQL_PWD, so it
# never appears in the process list or the log.
set -uo pipefail

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${PHP:-/usr/bin/php8.4}"
DEST="${BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%Y-%m-%d-%H%M)"

cd "$APP_DIR" || exit 1

env_value() {
    grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

record_failure() {
    echo "Backup failed: $1"
    "$PHP" artisan backup:record failure --label=cloudpanel --error="$1" >/dev/null 2>&1 || true
    exit 1
}

mkdir -p "$DEST" && chmod 700 "$DEST" || record_failure "cannot create $DEST"

DB_FILE="$DEST/db-$STAMP.sql.gz"
FILES_FILE="$DEST/files-$STAMP.tar.gz"

PORT="$(env_value DB_PORT)"
MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump \
    --single-transaction --quick --routines --no-tablespaces \
    -h "$(env_value DB_HOST)" -P "${PORT:-3306}" \
    -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" \
    | gzip > "$DB_FILE"
[ "${PIPESTATUS[0]}" -eq 0 ] && [ -s "$DB_FILE" ] || { rm -f "$DB_FILE"; record_failure "mysqldump failed"; }

# Uploaded files (photos, logos, documents). Missing folder = nothing to keep.
if [ -d storage/app ]; then
    tar -czf "$FILES_FILE" -C storage app 2>/dev/null || record_failure "archiving storage/app failed"
fi
chmod 600 "$DEST"/*-"$STAMP".*

SIZE=$(( $(stat -c%s "$DB_FILE") + $( [ -f "$FILES_FILE" ] && stat -c%s "$FILES_FILE" || echo 0) ))
"$PHP" artisan backup:record success --label=cloudpanel --file="$DB_FILE" --size="$SIZE" >/dev/null

# Keep the last KEEP_DAYS days.
find "$DEST" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'files-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete

echo "Backup done: $DB_FILE ($SIZE bytes)"
