#!/usr/bin/env bash
#
# Puts an uploaded build live on the test server. GitHub Actions copies the
# build to /var/www/khana/releases/<commit>, then runs this as the khana user:
#
#   ssh khana@<server> 'bash -s -- <commit>' < deploy/activate.sh
#
# Migrations and caches run before the switch, so a build that cannot migrate
# or compile never goes live. A build that goes live but fails its health
# check is swapped back for the previous one and the run fails, so the push
# shows a red cross on GitHub. Migrations are not undone by that swap.

set -euo pipefail

sha="${1:?usage: activate.sh <commit>}"
base=/var/www/khana
shared=/var/lib/khana
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
  sudo -n /usr/bin/systemctl restart khana-queue
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

echo "==> Switching to $sha"
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

echo "==> Live: $sha"

live="$(readlink -e "$base/current")"
ls -1dt "$base"/releases/*/ | tail -n +6 | while read -r dir; do
  if [ "$(readlink -e "$dir")" != "$live" ]; then
    rm -rf "$dir"
  fi
done
