#!/usr/bin/env bash
#
# Puts an uploaded build of one app live on the test server. GitHub Actions
# copies the build to /var/www/<name>/releases/<commit>, then runs this as the
# app's own Linux user:
#
#   ssh <name>@<server> 'bash -s -- <app> <commit>' < deploy/activate.sh
#
# <app> is accounts, crm or erp, each living as khana-<app>. "legacy" is
# today's single app (user khana, /var/www/khana), which stays up until the
# new CRM replaces it.
#
# Migrations and caches run before the switch, so a build that cannot migrate
# or compile never goes live. A build that goes live but fails its health
# check is swapped back for the previous one and the run fails, so the push
# shows a red cross on GitHub. Migrations are not undone by that swap.

set -euo pipefail

app="${1:?usage: activate.sh <app> <commit>}"
sha="${2:?usage: activate.sh <app> <commit>}"

case "$app" in
  legacy) name=khana ;;
  accounts|crm|erp) name="khana-$app" ;;
  *) echo "Unknown app \"$app\": expected accounts, crm, erp or legacy." >&2; exit 1 ;;
esac

base="/var/www/$name"
shared="/var/lib/$name"
release="$base/releases/$sha"
previous="$(readlink -e "$base/current" || true)"

if [ ! -d "$release" ]; then
  echo "No build uploaded at $release" >&2
  exit 1
fi

switch_to() {
  ln -sfn "$1" "$base/current.next"
  mv -Tf "$base/current.next" "$base/current"
  sudo -n /usr/bin/systemctl reload php8.3-fpm
  sudo -n /usr/bin/systemctl restart "$name-queue"
}

is_healthy() {
  local url
  url="$(grep -E '^APP_URL=' "$shared/.env" | cut -d= -f2- | tr -d '"')"
  for _ in $(seq 1 15); do
    if curl -fsS -o /dev/null --max-time 5 "$url/up"; then
      return 0
    fi
    sleep 2
  done
  return 1
}

echo "==> Linking storage and .env"
rm -rf "$release/storage"
ln -s "$shared/storage" "$release/storage"
ln -sfn "$shared/.env" "$release/.env"
ln -sfn "$shared/storage/app/public" "$release/public/storage"

cd "$release"

echo "==> Migrating the database"
php artisan migrate --force

if [ ! -f "$shared/.seeded" ]; then
  echo "==> First deploy: creating the admin account and base data"
  php artisan db:seed --force
  touch "$shared/.seeded"
fi

echo "==> Caching config, routes and views"
php artisan optimize

echo "==> Switching $name to $sha"
switch_to "$release"

if ! is_healthy; then
  echo "The new build failed its health check. Latest log lines:" >&2
  tail -n 40 "$shared/storage/logs/laravel-$(date -u +%F).log" >&2 2>/dev/null || true
  if [ -n "$previous" ] && [ "$previous" != "$release" ]; then
    echo "Putting $(basename "$previous") back." >&2
    switch_to "$previous"
  fi
  exit 1
fi

echo "==> Live: $name at $sha"

live="$(readlink -e "$base/current")"
ls -1dt "$base"/releases/*/ | tail -n +6 | while read -r dir; do
  if [ "$(readlink -e "$dir")" != "$live" ]; then
    rm -rf "$dir"
  fi
done
