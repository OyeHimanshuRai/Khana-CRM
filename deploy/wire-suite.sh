#!/usr/bin/env bash
#
# Connects the three apps once they have each had a first deploy: makes
# Accounts' sign-in and machine clients, hands CRM and ERP what they need to
# sign people in through Accounts, and prints the test logins. Safe to run
# again. Run as root:  sudo bash wire-suite.sh

set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run this as root: sudo bash $0" >&2; exit 1; }

ETC=/etc/khana
LAYOUT="$(cat $ETC/layout 2>/dev/null || echo subdomains)"
BASE_DOMAIN="$(cat $ETC/base-domain)"

art() { sudo -u "khana-$1" php "/var/www/khana-$1/current/artisan" "${@:2}"; }
env_file() { echo "/var/lib/khana-$1/.env"; }
set_env() { # app key value
  local f; f="$(env_file "$1")"
  if grep -qE "^$2=" "$f"; then sed -i "s|^$2=.*|$2=$3|" "$f"; else printf '%s=%s\n' "$2" "$3" >> "$f"; fi
}

for app in accounts crm erp; do
  [ -f "/var/www/khana-$app/current/artisan" ] || {
    echo "Khana $app has not been deployed yet. Run its workflow in GitHub > Actions first." >&2; exit 1; }
done

echo "==> Accounts signing keys"
[ -f /var/lib/khana-accounts/storage/oauth-public.key ] || art accounts passport:keys --force
install -m 644 /var/lib/khana-accounts/storage/oauth-public.key $ETC/accounts-public.key

echo "==> OAuth clients"
OUT="$(art accounts khana:clients)"
echo "$OUT"

# The block of lines under "── CRM" / "── ERP" holds that product's ids.
pick() { # product key
  printf '%s\n' "$OUT" | awk -v p="── $1" -v k="$2=" 'index($0,p)==1{on=1;next} /^── /{on=0} on&&index($0,k)==1{sub(k,"");print;exit}'
}

for app in crm erp; do
  P="$(echo "$app" | tr a-z A-Z)"
  set_env "$app" KHANA_ACCOUNTS_PUBLIC_KEY $ETC/accounts-public.key
  set_env "$app" KHANA_SSO_CLIENT_ID "$(pick "$P" KHANA_SSO_CLIENT_ID)"
  set_env "$app" KHANA_MACHINE_CLIENT_ID "$(pick "$P" KHANA_MACHINE_CLIENT_ID)"
  secret="$(pick "$P" KHANA_MACHINE_CLIENT_SECRET)"
  case "$secret" in ''|'(unchanged'*) ;; *) set_env "$app" KHANA_MACHINE_CLIENT_SECRET "$secret" ;; esac
  art "$app" optimize >/dev/null
  systemctl restart "khana-$app-queue"
done
systemctl reload php8.3-fpm

if [ "$LAYOUT" = paths ]; then
  ACC="https://$BASE_DOMAIN/accounts"; ERP="https://$BASE_DOMAIN/erp"; CRM="https://$BASE_DOMAIN"
else
  ACC="https://accounts.$BASE_DOMAIN"; ERP="https://erp.$BASE_DOMAIN"; CRM="https://crm.$BASE_DOMAIN"
fi

cat <<EOF

================================================================================
 The three apps are connected.

 Accounts   $ACC/login        admin@khana.test / password
 CRM        $CRM/admin/login  $(grep -E '^ADMIN_EMAIL=' "$(env_file crm)" | cut -d= -f2-) / $(grep -E '^ADMIN_PASSWORD=' "$(env_file crm)" | cut -d= -f2-)
 ERP        $ERP/login        sign in through Accounts

 These are test passwords on a public server - change them once you have real
 customers.
================================================================================
EOF
