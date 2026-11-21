#!/usr/bin/env bash
#
# Update the application on a CloudPanel server (no Docker): pull the latest
# code, install, build, migrate and restart the queue workers.
#
#   ssh <site-user>@<server-ip>
#   cd ~/htdocs/<domain>
#   bash scripts/cloudpanel/update.sh            # latest main
#   bash scripts/cloudpanel/update.sh v0.27.1    # a tag or branch
#
# Take a database export in CloudPanel (Databases → Export) before running it.
# Uses php8.4 when present; override with PHP_BIN=/usr/bin/php8.4.
set -euo pipefail

cd "$(dirname "$0")/../.."
REF="${1:-main}"
PHP="${PHP_BIN:-$(command -v php8.4 || command -v php)}"
COMPOSER="$(command -v composer || echo /usr/local/bin/composer)"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
    || fail "PHP 8.3 or newer is needed ($PHP is $("$PHP" -r 'echo PHP_VERSION;')). Set PHP_BIN, and the site's PHP version in CloudPanel."
[ -f .env ] || fail "No .env file here. Run this from the site folder (~/htdocs/<domain>)."
[ -z "$(git status --porcelain --untracked-files=no)" ] \
    || fail "Files were changed on the server (git status). Keep them elsewhere, then: git checkout -- ."

step "Maintenance mode on"
"$PHP" artisan down --retry=15 || true
trap '"$PHP" artisan up >/dev/null 2>&1 || true' EXIT

step "Getting $REF"
git fetch --tags origin
if git show-ref --verify --quiet "refs/remotes/origin/$REF"; then
    git checkout -q "$REF" 2>/dev/null || git checkout -q -b "$REF" "origin/$REF"
    git merge --ff-only "origin/$REF"
else
    git checkout -q "$REF"
fi
git log -1 --format='Now at %h %s'

step "PHP packages"
"$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress

step "Screens (frontend build)"
if command -v npm >/dev/null; then
    npm ci --no-audit --no-fund
    PHP_BIN="$PHP" npm run build
else
    fail "npm is not installed: install Node 22 (nvm) for this site user, or build elsewhere and upload public/build."
fi

step "Database changes"
"$PHP" artisan migrate --force
"$PHP" artisan permissions:sync

step "Caches"
"$PHP" artisan optimize:clear >/dev/null
"$PHP" artisan optimize

step "Restarting the queue workers"
"$PHP" artisan horizon:terminate 2>/dev/null || "$PHP" artisan queue:restart

"$PHP" artisan up
trap - EXIT
step "Done: $(cat VERSION 2>/dev/null || echo '?') is live"
echo "Check: $("$PHP" artisan tinker --execute='echo config("app.url");' 2>/dev/null)/up"
