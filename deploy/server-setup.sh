#!/usr/bin/env bash
#
# One-time setup of the Khana-CRM test server (Ubuntu 24.04). Run as root:
#
#   curl -fsSL https://raw.githubusercontent.com/OyeHimanshuRai/Khana-CRM/main/deploy/server-setup.sh -o setup.sh
#   sudo bash setup.sh                     # site on http://<server-ip>
#   sudo bash setup.sh khana.example.com   # site on https://khana.example.com (its DNS must point here first)
#
# Installs Caddy, PHP 8.3-FPM and MariaDB, creates the khana user, database,
# queue worker and scheduler, and prints the three GitHub secrets the deploy
# workflow needs. Safe to run again: the .env, app key and database password
# are kept. Every run issues a new deploy key, so update KHANA_TEST_SSH_KEY.
#
# Other apps on this server get their own user, database and file in
# /etc/caddy/sites/, so nothing here needs to change when one is added.

set -euo pipefail

DOMAIN="${1:-}"
PHP_VERSION=8.3
APP_USER=khana
WWW=/var/www/khana
DATA=/var/lib/khana
ENV_FILE="$DATA/.env"

if [ "$(id -u)" -ne 0 ]; then
  echo "Run this as root: sudo bash $0 $DOMAIN" >&2
  exit 1
fi

. /etc/os-release
if [ "${VERSION_ID:-}" != "24.04" ]; then
  echo "Warning: written for Ubuntu 24.04; this is ${PRETTY_NAME:-unknown}." >&2
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Installing packages"
apt-get update -q
apt-get install -y -q curl ca-certificates gnupg openssl rsync unzip ufw cron \
  "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-opcache" \
  "php$PHP_VERSION-mysql" "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" \
  "php$PHP_VERSION-curl" "php$PHP_VERSION-intl" "php$PHP_VERSION-bcmath" \
  "php$PHP_VERSION-gmp" "php$PHP_VERSION-zip" "php$PHP_VERSION-gd" \
  mariadb-server mariadb-client

if ! command -v caddy >/dev/null 2>&1; then
  curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
    | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
    > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -q
  apt-get install -y -q caddy
fi

echo "==> Sizing MariaDB for a small shared server"
cat > /etc/mysql/mariadb.conf.d/60-small-server.cnf <<'EOF'
[mysqld]
innodb_buffer_pool_size = 256M
max_connections         = 60
performance_schema      = OFF
EOF
systemctl restart mariadb

echo "==> Creating the $APP_USER user and folders"
id "$APP_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$APP_USER"

install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$WWW" "$WWW/releases"
# 711: Caddy may pass through to storage/app/public, but nobody can list the folder.
install -d -o "$APP_USER" -g "$APP_USER" -m 711 "$DATA"
for dir in storage storage/app storage/app/public storage/framework \
           storage/framework/cache storage/framework/cache/data storage/framework/sessions \
           storage/framework/views storage/framework/testing; do
  install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$DATA/$dir"
done
for dir in storage/app/private storage/app/backups storage/logs; do
  install -d -o "$APP_USER" -g "$APP_USER" -m 750 "$DATA/$dir"
done

SERVER_IP="$(ip -4 route get 1.1.1.1 | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')"
if [ -n "$DOMAIN" ]; then
  APP_URL="https://$DOMAIN"
  SECURE_COOKIE=true
else
  APP_URL="http://$SERVER_IP"
  SECURE_COOKIE=false
fi

if [ ! -f "$ENV_FILE" ]; then
  echo "==> Writing $ENV_FILE"
  DB_PASSWORD="$(openssl rand -hex 16)"
  ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-16)"
  install -o "$APP_USER" -g "$APP_USER" -m 600 /dev/null "$ENV_FILE"
  cat > "$ENV_FILE" <<EOF
APP_NAME="Khana CRM (test)"
APP_ENV=staging
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=$APP_URL

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=khana
DB_USERNAME=khana
DB_PASSWORD=$DB_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=$SECURE_COOKIE
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=null

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@khana.test"
MAIL_FROM_NAME="Khana CRM"
SMS_DRIVER=log
WHATSAPP_DRIVER=log

ADMIN_EMAIL=admin@khana.test
ADMIN_PASSWORD=$ADMIN_PASSWORD
EOF
else
  echo "==> Keeping the existing $ENV_FILE"
  sed -i "s|^APP_URL=.*|APP_URL=$APP_URL|; s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=$SECURE_COOKIE|" "$ENV_FILE"
fi

env_value() { grep -E "^$1=" "$ENV_FILE" | cut -d= -f2- | tr -d '"'; }

echo "==> Creating the database"
mysql <<EOF
CREATE DATABASE IF NOT EXISTS khana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'khana'@'localhost' IDENTIFIED BY '$(env_value DB_PASSWORD)';
GRANT ALL PRIVILEGES ON khana.* TO 'khana'@'localhost';
FLUSH PRIVILEGES;
EOF

echo "==> PHP-FPM pool for $APP_USER"
cat > "/etc/php/$PHP_VERSION/fpm/pool.d/khana.conf" <<EOF
[khana]
user = $APP_USER
group = $APP_USER
listen = /run/php/khana.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 20M
php_admin_value[max_execution_time] = 60
EOF
if [ -f "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" ]; then
  mv "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf.disabled"
fi
systemctl restart "php$PHP_VERSION-fpm"

echo "==> Caddy site"
install -d /etc/caddy/sites
cat > /etc/caddy/Caddyfile <<'EOF'
# Each app on this server has its own file in /etc/caddy/sites/.
import /etc/caddy/sites/*.caddy
EOF
cat > /etc/caddy/sites/khana.caddy <<EOF
${DOMAIN:-:80} {
	root * $WWW/current/public
	encode zstd gzip
	php_fastcgi unix//run/php/khana.sock {
		# Deploys swap the "current" symlink; resolve it so PHP always runs one whole build.
		resolve_root_symlink
	}
	file_server {
		hide .ht*
	}
}
EOF
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl enable caddy >/dev/null 2>&1
systemctl reload caddy || systemctl restart caddy

echo "==> Queue worker and scheduler"
cat > /etc/systemd/system/khana-queue.service <<EOF
[Unit]
Description=Khana-CRM queue worker
After=network.target mariadb.service
# Skipped until the first deploy has put a build in place.
ConditionPathExists=$WWW/current/artisan

[Service]
User=$APP_USER
Group=$APP_USER
WorkingDirectory=$WWW/current
ExecStart=/usr/bin/php $WWW/current/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=90
MemoryMax=512M

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable khana-queue >/dev/null 2>&1

cat > /etc/cron.d/khana-scheduler <<EOF
# Laravel scheduler for Khana-CRM: reminders, alerts, campaigns, nightly backup.
* * * * * $APP_USER [ -f $WWW/current/artisan ] && cd $WWW/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/khana-scheduler

echo "==> Letting deploys reload PHP and restart the worker"
cat > /etc/sudoers.d/khana <<EOF
$APP_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP_VERSION-fpm, /usr/bin/systemctl restart khana-queue
EOF
chmod 440 /etc/sudoers.d/khana
visudo -cf /etc/sudoers.d/khana >/dev/null

echo "==> Deploy key for GitHub Actions"
KEY_DIR="$(mktemp -d)"
trap 'rm -rf "$KEY_DIR"' EXIT
ssh-keygen -q -t ed25519 -N "" -C "github-actions-khana" -f "$KEY_DIR/key"
install -d -o "$APP_USER" -g "$APP_USER" -m 700 "/home/$APP_USER/.ssh"
touch "/home/$APP_USER/.ssh/authorized_keys"
{
  grep -v 'github-actions-khana$' "/home/$APP_USER/.ssh/authorized_keys" || true
  echo "no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding $(cat "$KEY_DIR/key.pub")"
} > "$KEY_DIR/authorized_keys"
install -o "$APP_USER" -g "$APP_USER" -m 600 "$KEY_DIR/authorized_keys" "/home/$APP_USER/.ssh/authorized_keys"

echo "==> Firewall"
SSH_PORT="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')"
SSH_PORT="${SSH_PORT:-22}"
ufw allow "$SSH_PORT/tcp" >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

cat <<EOF

================================================================================
 Khana-CRM test server is ready for its first deploy.

 Site:         $APP_URL
 Admin login:  $(env_value ADMIN_EMAIL)  /  $(env_value ADMIN_PASSWORD)
               (created by the first deploy - change the password after signing in)

 Add these in GitHub: Khana-CRM > Settings > Secrets and variables > Actions >
 New repository secret.

 KHANA_TEST_HOST
$SERVER_IP

 KHANA_TEST_KNOWN_HOSTS
$SERVER_IP $(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)

 KHANA_TEST_SSH_KEY  (copy all of it, including the BEGIN and END lines)
$(cat "$KEY_DIR/key")

 Then: GitHub > Actions > "Deploy to test server" > Run workflow.
================================================================================
EOF

if [ "$SSH_PORT" != "22" ]; then
  echo "Note: SSH listens on port $SSH_PORT, but the deploy workflow connects on 22." >&2
fi
