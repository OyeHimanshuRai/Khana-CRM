#!/usr/bin/env bash
#
# One command that puts Accounts, ERP and CRM on ONE domain, split by route:
#
#   https://<domain>/            CRM      (/admin/login)
#   https://<domain>/accounts    Accounts (/accounts/login)
#   https://<domain>/erp         ERP      (/erp/login)
#
#   curl -fsSL https://raw.githubusercontent.com/OyeHimanshuRai/Khana-CRM/main/deploy/go-live.sh -o go-live.sh
#   sudo bash go-live.sh khana.factualtechnologies.tech
#
# Run as root, once. After it, every push to main deploys by itself. It takes
# today's single-app site out of Caddy (a copy is kept in /root), so only run
# it on a server that holds no data you need.

set -euo pipefail

DOMAIN="${1:?usage: sudo bash go-live.sh <domain>}"
BASE="https://raw.githubusercontent.com/OyeHimanshuRai/Khana-CRM/main/deploy"

[ "$(id -u)" -eq 0 ] || { echo "Run this as root: sudo bash $0 $DOMAIN" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
curl -fsSL "$BASE/server-setup.sh" -o "$WORK/setup.sh"

# Two Caddy sites cannot both own the domain. The single-app site goes aside.
if [ -f /etc/caddy/sites/khana.caddy ]; then
  mv /etc/caddy/sites/khana.caddy "/root/khana.caddy.$(date +%s).bak"
  echo "==> The old single-app Caddy site is saved in /root"
fi

for app in accounts erp crm; do
  KHANA_LAYOUT=paths bash "$WORK/setup.sh" "$app" "$DOMAIN"
done

cat <<EOF

================================================================================
 The server is ready for $DOMAIN.

 Next: deploy the three apps (GitHub > Actions > Run workflow on Deploy
 Accounts, Deploy ERP and Deploy CRM - or just tell Claude), then run:

   curl -fsSL $BASE/wire-suite.sh -o wire-suite.sh
   sudo bash wire-suite.sh
================================================================================
EOF
