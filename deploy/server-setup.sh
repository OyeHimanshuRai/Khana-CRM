#!/usr/bin/env bash
#
# One-time setup of one Khana app on the test server (Ubuntu 24.04). Run as
# root, once per app:
#
#   curl -fsSL https://raw.githubusercontent.com/OyeHimanshuRai/Khana-CRM/main/deploy/server-setup.sh -o setup.sh
#   sudo bash setup.sh accounts khana.example.com   # https://accounts.khana.example.com
#   sudo bash setup.sh crm      khana.example.com   # https://crm.khana.example.com
#   sudo bash setup.sh erp      khana.example.com   # https://erp.khana.example.com
#
# The three apps share one base domain and live on its subdomains; a single
# wildcard DNS record (*.khana -> this server) covers all three, and Caddy
# fetches each certificate itself. Each app gets its own Linux user, database
# and database user, PHP-FPM pool, queue worker and Caddy file, so none can
# read another's data.
#
# "legacy" is today's single app, kept exactly as it was until the new CRM
# replaces it:
#
#   sudo bash setup.sh legacy                        # http://<server-ip>
#   sudo bash setup.sh legacy khana.example.com      # https://khana.example.com
#
# Safe to run again: the .env, app key, database password, event secrets,
# deploy key and domain are all kept. NEW_DEPLOY_KEY=1 issues a new deploy key
# for every app, after which KHANA_TEST_SSH_KEY in GitHub has to be updated.

set -euo pipefail

APP="${1:-}"
DOMAIN_ARG="${2:-}"
PHP_VERSION=8.3
KHANA_ETC=/etc/khana

case "$APP" in
  legacy)
    NAME=khana
    DB_NAME=khana
    LABEL="Khana CRM (test)"
    ;;
  accounts|crm|erp)
    NAME="khana-$APP"
    DB_NAME="khana_$APP"
    LABEL="Khana ${APP^^} (test)"
    [ "$APP" = accounts ] && LABEL="Khana Accounts (test)"
    ;;
  *)
    cat >&2 <<EOF
Usage: sudo bash $0 <app> [domain]

  <app>     accounts, crm or erp - or legacy for today's single app
  [domain]  the base domain for accounts/crm/erp (each gets <app>.<domain>),
            or the full domain for legacy
EOF
    exit 1
    ;;
esac

APP_USER="$NAME"
WWW="/var/www/$NAME"
DATA="/var/lib/$NAME"
ENV_FILE="$DATA/.env"

if [ "$(id -u)" -ne 0 ]; then
  echo "Run this as root: sudo bash $0 $APP $DOMAIN_ARG" >&2
  exit 1
fi

install -d -m 700 "$KHANA_ETC"

# Work out the site's domain. A re-run without one keeps what is configured.
if [ "$APP" = legacy ]; then
  DOMAIN="$DOMAIN_ARG"
  if [ -z "$DOMAIN" ] && [ -f "$ENV_FILE" ]; then
    DOMAIN="$(grep -E '^APP_URL=https://' "$ENV_FILE" | cut -d/ -f3 || true)"
  fi
else
  BASE_DOMAIN="$DOMAIN_ARG"
  if [ -z "$BASE_DOMAIN" ] && [ -f "$KHANA_ETC/base-domain" ]; then
    BASE_DOMAIN="$(cat "$KHANA_ETC/base-domain")"
  fi
  if [ -z "$BASE_DOMAIN" ]; then
    echo "accounts, crm and erp need the base domain, e.g.: sudo bash $0 $APP khana.example.com" >&2
    exit 1
  fi
  echo "$BASE_DOMAIN" > "$KHANA_ETC/base-domain"

  # KHANA_LAYOUT=paths puts all three apps on the one domain, split by route:
  #   https://<domain>/accounts   https://<domain>/erp   https://<domain>/ (CRM)
  # The default, subdomains, gives each app <app>.<domain>. Kept once chosen.
  LAYOUT="${KHANA_LAYOUT:-}"
  if [ -z "$LAYOUT" ] && [ -f "$KHANA_ETC/layout" ]; then
    LAYOUT="$(cat "$KHANA_ETC/layout")"
  fi
  LAYOUT="${LAYOUT:-subdomains}"
  case "$LAYOUT" in subdomains|paths) ;; *) echo "KHANA_LAYOUT must be subdomains or paths" >&2; exit 1 ;; esac
  echo "$LAYOUT" > "$KHANA_ETC/layout"

  if [ "$LAYOUT" = paths ]; then
    DOMAIN="$BASE_DOMAIN"
  else
    DOMAIN="$APP.$BASE_DOMAIN"
  fi
fi

# Where an app answers: its own origin plus, in the paths layout, its prefix.
app_url() {
  if [ "${LAYOUT:-subdomains}" = paths ]; then
    case "$1" in
      crm) echo "https://$BASE_DOMAIN" ;;
      *) echo "https://$BASE_DOMAIN/$1" ;;
    esac
  else
    echo "https://$1.$BASE_DOMAIN"
  fi
}

# Read in a subshell: os-release sets NAME="Ubuntu", which would overwrite this script's NAME.
OS_VERSION="$(. /etc/os-release && echo "${VERSION_ID:-}")"
if [ "$OS_VERSION" != "24.04" ]; then
  echo "Warning: written for Ubuntu 24.04; this is $(. /etc/os-release && echo "${PRETTY_NAME:-unknown}")." >&2
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
max_connections         = 90
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
  if [ "$APP" != legacy ]; then APP_URL="$(app_url "$APP")"; fi
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
APP_NAME="$LABEL"
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
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_NAME
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
MAIL_FROM_NAME="Khana"
SMS_DRIVER=log
WHATSAPP_DRIVER=log

ADMIN_EMAIL=admin@khana.test
ADMIN_PASSWORD=$ADMIN_PASSWORD
EOF
else
  echo "==> Keeping the existing $ENV_FILE"
fi

env_value() { grep -E "^$1=" "$ENV_FILE" | cut -d= -f2- | tr -d '"'; }

# Replace a line in the .env, or add it.
set_env() {
  if grep -qE "^$1=" "$ENV_FILE"; then
    sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
  fi
}

set_env APP_URL "$APP_URL"
set_env SESSION_SECURE_COOKIE "$SECURE_COOKIE"

# On one shared domain each app keeps its own cookie, scoped to its prefix.
if [ "$APP" != legacy ] && [ "${LAYOUT:-subdomains}" = paths ]; then
  set_env SESSION_COOKIE "khana_${APP}_session"
  if [ "$APP" = crm ]; then set_env SESSION_PATH "/"; else set_env SESSION_PATH "/$APP"; fi
fi

if [ "$APP" != legacy ]; then
  echo "==> Event settings for $APP"

  # One secret per sender -> receiver pair, made once and shared through a
  # root-only file, so each app's .env holds only the secrets it is part of.
  SECRETS="$KHANA_ETC/event-secrets.env"
  if [ ! -f "$SECRETS" ]; then
    install -m 600 /dev/null "$SECRETS"
    for pair in ACCOUNTS_TO_CRM ACCOUNTS_TO_ERP CRM_TO_ERP ERP_TO_CRM; do
      echo "KHANA_SECRET_$pair=$(openssl rand -hex 32)" >> "$SECRETS"
    done
  fi
  secret() { grep -E "^KHANA_SECRET_$1=" "$SECRETS" | cut -d= -f2-; }

  set_env KHANA_SERVICE "$APP"
  set_env KHANA_EVENTS_VALIDATE false
  if [ "$APP" != accounts ]; then
    set_env KHANA_ACCOUNTS_URL "$(app_url accounts)"
  fi
  case "$APP" in
    accounts)
      set_env KHANA_CRM_URL "$(app_url crm)"
      set_env KHANA_ERP_URL "$(app_url erp)"
      set_env KHANA_SECRET_ACCOUNTS_TO_CRM "$(secret ACCOUNTS_TO_CRM)"
      set_env KHANA_SECRET_ACCOUNTS_TO_ERP "$(secret ACCOUNTS_TO_ERP)"
      ;;
    crm)
      set_env KHANA_ERP_URL "$(app_url erp)"
      set_env KHANA_SECRET_CRM_TO_ERP "$(secret CRM_TO_ERP)"
      set_env KHANA_SECRET_ERP_TO_CRM "$(secret ERP_TO_CRM)"
      set_env KHANA_SECRET_ACCOUNTS_TO_CRM "$(secret ACCOUNTS_TO_CRM)"
      ;;
    erp)
      set_env KHANA_CRM_URL "$(app_url crm)"
      set_env KHANA_SECRET_CRM_TO_ERP "$(secret CRM_TO_ERP)"
      set_env KHANA_SECRET_ERP_TO_CRM "$(secret ERP_TO_CRM)"
      set_env KHANA_SECRET_ACCOUNTS_TO_ERP "$(secret ACCOUNTS_TO_ERP)"
      ;;
  esac
fi

echo "==> Creating the $DB_NAME database"
mysql <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_NAME'@'localhost' IDENTIFIED BY '$(env_value DB_PASSWORD)';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_NAME'@'localhost';
FLUSH PRIVILEGES;
EOF

echo "==> PHP-FPM pool for $APP_USER"
cat > "/etc/php/$PHP_VERSION/fpm/pool.d/$NAME.conf" <<EOF
[$NAME]
user = $APP_USER
group = $APP_USER
listen = /run/php/$NAME.sock
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

echo "==> Caddy site for $NAME"
install -d /etc/caddy/sites
cat > /etc/caddy/Caddyfile <<'EOF'
# Each app on this server has its own file in /etc/caddy/sites/.
import /etc/caddy/sites/*.caddy
EOF
if [ "$APP" != legacy ] && [ "${LAYOUT:-subdomains}" = paths ]; then
  # One site for the whole domain. Every app already set up on this server
  # gets its route; the prefix is stripped before PHP sees it, and the app
  # builds its links from APP_URL, which carries the prefix. CRM answers
  # everything the others do not, so it is written last.
  rm -f "/etc/caddy/sites/$NAME.caddy"
  {
    echo "$DOMAIN {"
    echo "	encode zstd gzip"
    echo
    for a in accounts erp crm; do
      [ "$a" = "$APP" ] || [ -f "/etc/php/$PHP_VERSION/fpm/pool.d/khana-$a.conf" ] || continue
      # The app's own home link is /<app> with no slash, which handle_path /<app>/* does not match.
      [ "$a" = crm ] || echo "	redir /$a /$a/ 308"
      if [ "$a" = crm ]; then echo "	handle {"; else echo "	handle_path /$a/* {"; fi
      # PHP still gets the full /<app>/... URI, so tell Laravel its base path through SCRIPT_NAME.
      script_name=""
      [ "$a" = crm ] || script_name="env SCRIPT_NAME /$a/index.php"
      cat <<BLOCK
		root * /var/www/khana-$a/current/public

		# Service-to-service traffic only comes from this server itself.
		@outside_internal {
			path /internal/*
			not remote_ip 127.0.0.0/8 ::1 $SERVER_IP
		}
		respond @outside_internal 403

		php_fastcgi unix//run/php/khana-$a.sock {
			resolve_root_symlink
			$script_name
		}
		file_server {
			hide .ht*
		}
	}

BLOCK
    done
    echo "}"
  } > /etc/caddy/sites/khana-suite.caddy
else
cat > "/etc/caddy/sites/$NAME.caddy" <<EOF
${DOMAIN:-:80} {
	root * $WWW/current/public
	encode zstd gzip

	# Service-to-service traffic only comes from this server itself.
	@outside_internal {
		path /internal/*
		not remote_ip 127.0.0.0/8 ::1 $SERVER_IP
	}
	respond @outside_internal 403

	php_fastcgi unix//run/php/$NAME.sock {
		# Deploys swap the "current" symlink; resolve it so PHP always runs one whole build.
		resolve_root_symlink
	}
	file_server {
		hide .ht*
	}
}
EOF
fi
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
systemctl enable caddy >/dev/null 2>&1
systemctl reload caddy || systemctl restart caddy

echo "==> Queue worker and scheduler"
cat > "/etc/systemd/system/$NAME-queue.service" <<EOF
[Unit]
Description=$LABEL queue worker, which also sends its events
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
systemctl enable "$NAME-queue" >/dev/null 2>&1

cat > "/etc/cron.d/$NAME-scheduler" <<EOF
# Laravel scheduler for $LABEL: reminders, alerts, the event retry sweeps, nightly backup.
* * * * * $APP_USER [ -f $WWW/current/artisan ] && cd $WWW/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 "/etc/cron.d/$NAME-scheduler"

echo "==> Letting deploys reload PHP and restart the worker"
cat > "/etc/sudoers.d/$NAME" <<EOF
$APP_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP_VERSION-fpm, /usr/bin/systemctl restart $NAME-queue
EOF
chmod 440 "/etc/sudoers.d/$NAME"
visudo -cf "/etc/sudoers.d/$NAME" >/dev/null

echo "==> Deploy key for GitHub Actions"
# One key for every app user, so the same three GitHub secrets deploy all of
# them. Its public half is kept in /etc/khana so the next app can be given it.
KEY_DIR="$(mktemp -d)"
trap 'rm -rf "$KEY_DIR"' EXIT
PUBLIC_KEY_FILE="$KHANA_ETC/deploy-key.pub"
KEY_OPTIONS="no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding"

install_key_for() {
  local user="$1" keys="/home/$1/.ssh/authorized_keys"
  install -d -o "$user" -g "$user" -m 700 "/home/$user/.ssh"
  {
    grep -vs 'github-actions-khana$' "$keys" || true
    echo "$KEY_OPTIONS $(cat "$PUBLIC_KEY_FILE")"
  } > "$KEY_DIR/authorized_keys"
  install -o "$user" -g "$user" -m 600 "$KEY_DIR/authorized_keys" "$keys"
}

# A server set up before this script knew about several apps has the key only
# in the khana user's authorized_keys; adopt it from there.
if [ ! -f "$PUBLIC_KEY_FILE" ]; then
  existing="$(grep -hs 'github-actions-khana$' /home/khana*/.ssh/authorized_keys | head -n 1 | sed "s/^$KEY_OPTIONS //" || true)"
  if [ -n "$existing" ]; then
    echo "$existing" > "$PUBLIC_KEY_FILE"
  fi
fi

NEW_KEY=no
if [ "${NEW_DEPLOY_KEY:-0}" = "1" ] || [ ! -f "$PUBLIC_KEY_FILE" ]; then
  NEW_KEY=yes
  ssh-keygen -q -t ed25519 -N "" -C "github-actions-khana" -f "$KEY_DIR/key"
  cp "$KEY_DIR/key.pub" "$PUBLIC_KEY_FILE"
  # Every app user gets the new key, or the one GitHub secret would stop
  # working for the apps set up earlier.
  for home in /home/khana /home/khana-*; do
    [ -d "$home" ] && install_key_for "$(basename "$home")"
  done
fi
install_key_for "$APP_USER"

if [ -f "$WWW/current/artisan" ]; then
  echo "==> Refreshing the live build's cached config"
  sudo -u "$APP_USER" php "$WWW/current/artisan" optimize >/dev/null
  systemctl reload "php$PHP_VERSION-fpm"
  systemctl restart "$NAME-queue"
fi

echo "==> Firewall"
# sshd -T fails until the first SSH login creates /run/sshd, so fall back to 22.
SSH_PORT="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2 }' || true)"
SSH_PORT="${SSH_PORT%%$'\n'*}"
SSH_PORT="${SSH_PORT:-22}"
ufw allow "$SSH_PORT/tcp" >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

if [ "$NEW_KEY" = no ]; then
  cat <<EOF

================================================================================
 $LABEL is set up.

 Site:            $APP_URL
 Linux user:      $APP_USER
 GitHub secrets:  unchanged - nothing to update in GitHub.
================================================================================
EOF
  exit 0
fi

cat <<EOF

================================================================================
 $LABEL is ready for its first deploy.

 Site:         $APP_URL
 Admin login:  $(env_value ADMIN_EMAIL)  /  $(env_value ADMIN_PASSWORD)
               (created by the first deploy - change the password after signing in)

 Add these in GitHub: Khana-CRM > Settings > Secrets and variables > Actions >
 New repository secret. The same three serve every app on this server.

 KHANA_TEST_HOST
$SERVER_IP

 KHANA_TEST_KNOWN_HOSTS
$SERVER_IP $(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)

 KHANA_TEST_SSH_KEY  (copy all of it, including the BEGIN and END lines)
$(cat "$KEY_DIR/key")

 Then: GitHub > Actions > the app's deploy workflow > Run workflow.
================================================================================
EOF

if [ "$SSH_PORT" != "22" ]; then
  echo "Note: SSH listens on port $SSH_PORT, but the deploy workflow connects on 22." >&2
fi
