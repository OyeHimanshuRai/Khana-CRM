# Test server deploys

The repository holds three apps - `apps/accounts`, `apps/crm`, `apps/erp` -
plus today's single app, which now lives in `apps/crm` and is deployed as
**legacy** to the current address until the new CRM matches it.

Each app is built on GitHub and put live on the test server in a few minutes.
A push to `main` redeploys only what it touched: a change under
`apps/<app>/` deploys that app, and a change to `packages/kit/` deploys all of
them. The push shows a green tick when the new build is live, and a red cross
if it failed - in which case the previous build stays (or is put back) live.

| Workflow | Deploys | Runs on push |
|---|---|---|
| Deploy to test server | today's app (legacy), from `apps/crm` | always |
| Deploy Accounts / CRM / ERP | that app | once the variable below is `on` |

## One-time setup

1. On the server (Ubuntu 24.04), as root. Point a wildcard DNS record
   (`*.khana` -> the server) at it first; each app gets its own subdomain.

   ```bash
   curl -fsSL https://raw.githubusercontent.com/OyeHimanshuRai/Khana-CRM/main/deploy/server-setup.sh -o setup.sh
   sudo bash setup.sh accounts khana.example.com   # https://accounts.khana.example.com
   sudo bash setup.sh crm      khana.example.com   # https://crm.khana.example.com
   sudo bash setup.sh erp      khana.example.com   # https://erp.khana.example.com
   ```

   Today's app, as before: `sudo bash setup.sh legacy [khana.example.com]`.

2. The first run prints three values. Copy them into GitHub > Settings >
   Secrets and variables > Actions: `KHANA_TEST_HOST`,
   `KHANA_TEST_KNOWN_HOSTS`, `KHANA_TEST_SSH_KEY`. One deploy key serves every
   app, so later runs print nothing new.

3. Under Settings > Secrets and variables > Actions > **Variables**, add
   `KHANA_SPLIT_DEPLOYS` with the value `on`. Until then the three app
   workflows skip on push; a manual **Run workflow** always goes ahead.

4. GitHub > Actions > **Deploy Accounts**, **Deploy CRM**, **Deploy ERP** >
   **Run workflow**. Each first deploy creates the admin account its setup run
   printed.

5. Check the apps can hear each other (Gate G0):

   ```bash
   sudo -u khana-crm php /var/www/khana-crm/current/artisan events:ping
   sudo -u khana-erp php /var/www/khana-erp/current/artisan inbox:status
   sudo -u khana-crm php /var/www/khana-crm/current/artisan outbox:replay erp --from=1
   ```

   The ping is stored once in ERP's inbox, and the replay stores nothing new.

If the firewall in Hostinger's panel is switched on, allow ports 22, 80 and 443
there as well.

## On the server

`<name>` is `khana-accounts`, `khana-crm` or `khana-erp` (and `khana` for
today's app).

| What | Where |
|---|---|
| Live build | `/var/www/<name>/current`, a link to `releases/<commit>`; the last five are kept |
| `.env`, uploads, logs, backups | `/var/lib/<name>` - deploys never touch it |
| Database and its user | `khana_accounts`, `khana_crm`, `khana_erp` (and `khana`) |
| Queue worker, which also sends events | `systemctl status <name>-queue` |
| Scheduler, including the event retry sweeps | `/etc/cron.d/<name>-scheduler`, every minute |
| Event secrets, one per sender and receiver | `/etc/khana/event-secrets.env` (root only) |
| Today's log | `tail -f /var/lib/<name>/storage/logs/laravel-$(date -u +%F).log` |

`/internal/*` - where the apps deliver events to each other - only answers
requests from the server itself.

Config is cached, so after editing `/var/lib/<name>/.env` run:

```bash
sudo -u <name> php /var/www/<name>/current/artisan optimize
```

Events that did not arrive or did not apply:

```bash
sudo -u <name> php /var/www/<name>/current/artisan outbox:status   # what each receiver still has to get
sudo -u <name> php /var/www/<name>/current/artisan inbox:status    # what arrived, and every failure
sudo -u <name> php /var/www/<name>/current/artisan inbox:retry --all
sudo -u <name> php /var/www/<name>/current/artisan inbox:skip <event-id> --reason="..."
```

Demo data for today's app. The demo users sign in with the password
`password`, and this server is on the internet, so change those passwords
afterwards:

```bash
sudo -u khana bash -c 'cd /var/www/khana/current && php artisan db:seed --class=DemoDataSeeder --force'
```

Putting an older build back by hand:

```bash
sudo -u <name> ln -sfn /var/www/<name>/releases/<commit> /var/www/<name>/current
sudo systemctl reload php8.3-fpm && sudo systemctl restart <name>-queue
```
