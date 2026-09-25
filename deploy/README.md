# Test server deploys

Every push to `main` is built on GitHub and put live on the test server in a
few minutes. Pushes that only change `*.md` files or `docs/` are skipped. The
push shows a green tick when the new build is live, and a red cross if it
failed — in which case the previous build stays (or is put back) live.

## One-time setup

1. On the server (Ubuntu 24.04), as root:

   ```bash
   curl -fsSL https://raw.githubusercontent.com/OyeHimanshuRai/Khana-CRM/main/deploy/server-setup.sh -o setup.sh
   sudo bash setup.sh                    # site on http://<server-ip>
   sudo bash setup.sh khana.example.com  # site on https://, once the domain points at the server
   ```

2. Copy the three values it prints into GitHub > Settings > Secrets and
   variables > Actions: `KHANA_TEST_HOST`, `KHANA_TEST_KNOWN_HOSTS`,
   `KHANA_TEST_SSH_KEY`.

3. GitHub > Actions > **Deploy to test server** > **Run workflow**. The first
   deploy creates the admin account the setup script printed.

If the firewall in Hostinger's panel is switched on, allow ports 22, 80 and 443
there as well.

## On the server

| What | Where |
|---|---|
| Live build | `/var/www/khana/current`, a link to `releases/<commit>`; the last five are kept |
| `.env`, uploads, logs, backups | `/var/lib/khana` — deploys never touch it |
| Queue worker | `systemctl status khana-queue` |
| Scheduler | `/etc/cron.d/khana-scheduler`, every minute |
| Today's log | `tail -f /var/lib/khana/storage/logs/laravel-$(date -u +%F).log` |

Config is cached, so after editing `/var/lib/khana/.env` run:

```bash
sudo -u khana php /var/www/khana/current/artisan optimize
```

Demo data. The demo users sign in with the password `password`, and this
server is on the internet, so change those passwords afterwards:

```bash
sudo -u khana bash -c 'cd /var/www/khana/current && php artisan db:seed --class=DemoDataSeeder --force'
```

Putting an older build back by hand:

```bash
sudo -u khana ln -sfn /var/www/khana/releases/<commit> /var/www/khana/current
sudo systemctl reload php8.3-fpm && sudo systemctl restart khana-queue
```
