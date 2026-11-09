#!/usr/bin/env bash
# ---------------------------------------------------------------------
# One-time setup of a fresh Ubuntu 24.04 server for staging or production
# (S16.4). Run as root:
#
#   curl -fsSL https://raw.githubusercontent.com/<repo>/main/scripts/server/provision.sh -o provision.sh
#   sudo bash provision.sh staging staging.example.com ops@example.com
#
# What it does (safe to run again):
#   - deploy user in the docker group, SSH by key only, root login off
#   - firewall: SSH, HTTP, HTTPS only; fail2ban; automatic security updates
#   - Docker Engine and the Compose plugin; 2 GB swap; time sync
#   - the application in /srv/hotel-saas with a generated .env
#     (random passwords and keys; SIMULATION_MODE on staging only)
# It prints what is left to do by hand (DNS, Meta, providers).
# ---------------------------------------------------------------------
set -euo pipefail

ENVIRONMENT="${1:-}"; DOMAIN="${2:-}"; ACME_EMAIL="${3:-}"
REPO="${REPO:-https://github.com/srinivas182/hotel-reservation-saas.git}"
APP_DIR=/srv/hotel-saas
DEPLOY_USER=deploy

[ "$(id -u)" -eq 0 ] || { echo "Run as root." >&2; exit 1; }
case "$ENVIRONMENT" in staging|production) ;; *) echo "Usage: provision.sh staging|production <domain> <acme-email>" >&2; exit 1 ;; esac
[ -n "$DOMAIN" ] && [ -n "$ACME_EMAIL" ] || { echo "Domain and e-mail are required." >&2; exit 1; }

log() { printf '\n==> %s\n' "$*"; }
random() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-40}"; }

log "System packages and automatic security updates"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq ca-certificates curl git openssl ufw fail2ban unattended-upgrades chrony jq
dpkg-reconfigure -f noninteractive unattended-upgrades

log "Firewall: SSH, HTTP, HTTPS"
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

log "SSH: keys only, no root login"
sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication no/; s/^#\?PermitRootLogin .*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl reload ssh || systemctl reload sshd || true

log "Swap (2 GB)"
if ! swapon --show | grep -q /swapfile; then
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

log "Docker Engine and Compose"
if ! command -v docker >/dev/null; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
    apt-get update -q
    apt-get install -yq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

log "Deploy user"
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
usermod -aG docker "$DEPLOY_USER"
if [ -f /root/.ssh/authorized_keys ] && [ ! -f "/home/$DEPLOY_USER/.ssh/authorized_keys" ]; then
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /root/.ssh/authorized_keys "/home/$DEPLOY_USER/.ssh/authorized_keys"
fi

log "Application in $APP_DIR"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" /srv
[ -d "$APP_DIR/.git" ] || sudo -u "$DEPLOY_USER" git clone --quiet "$REPO" "$APP_DIR"

if [ ! -f "$APP_DIR/.env" ]; then
    log "Generating .env (secrets are random; keep a copy of BACKUP_ENCRYPTION_KEY off the server)"
    sudo -u "$DEPLOY_USER" cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    set_env() { sed -i "s|^$1=.*|$1=$2|" "$APP_DIR/.env"; grep -q "^$1=" "$APP_DIR/.env" || echo "$1=$2" >> "$APP_DIR/.env"; }
    set_env APP_ENV "$ENVIRONMENT"
    set_env APP_DEBUG false
    set_env APP_URL "https://$DOMAIN"
    set_env APP_DOMAIN "$DOMAIN"
    set_env ACME_EMAIL "$ACME_EMAIL"
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
    set_env DB_HOST mysql
    set_env DB_PASSWORD "$(random 40)"
    set_env DB_ROOT_PASSWORD "$(random 40)"
    set_env REDIS_HOST redis
    set_env SESSION_SECURE_COOKIE true
    set_env LOG_CHANNEL json
    set_env HEALTH_CHECK_TOKEN "$(random 40)"
    set_env BACKUP_ENCRYPTION_KEY "$(random 48)"
    set_env CSP_ENFORCE false
    if [ "$ENVIRONMENT" = "staging" ]; then
        set_env SIMULATION_MODE true
        set_env WHATSAPP_APP_SECRET "$(random 32)"
    else
        set_env SIMULATION_MODE false
    fi
    chmod 600 "$APP_DIR/.env"
fi

cat <<NEXT

Server ready. Next, as the deploy user (su - $DEPLOY_USER):
  1. DNS: point $DOMAIN to this server's IP (Caddy gets the certificate on first deploy).
  2. cd $APP_DIR && ./deploy.sh
  3. docker compose -f compose.prod.yaml exec app php artisan launch:defaults
  4. Staging: php artisan simulation:prepare, then ./scripts/drills/staging-drills.sh (S16.4).
  5. Production: Meta app secret and token, AI keys, e-mail and payment providers in the platform;
     SENTRY_LARAVEL_DSN in .env; CSP_ENFORCE=true after a week without unexpected reports
     (php artisan drills:status lists them).
Keep a copy of BACKUP_ENCRYPTION_KEY outside this server.
NEXT
