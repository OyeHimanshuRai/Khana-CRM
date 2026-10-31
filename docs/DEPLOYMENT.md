# Deploying the platform to a live server

The step-by-step for putting this on the internet as a SaaS. Written for an
Ubuntu 24.04 VPS (DigitalOcean, Hostinger VPS, AWS Lightsail, Hetzner). Shared
hosting will not do: the queue worker and the scheduler are long-running
processes, and the kitchen screens, reminders and print jobs depend on them.

**Size:** 2 vCPU / 4 GB RAM runs the first 30–50 restaurants comfortably.

---

## 1. Server packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mariadb-server redis-server supervisor unzip git \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-redis
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

`php8.3-gd` is not optional - every logo, slider and menu image upload uses it.

## 2. Database

```bash
sudo mysql_secure_installation
sudo mysql
```
```sql
CREATE DATABASE restaurant_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pos_app'@'localhost' IDENTIFIED BY '<strong-random-password>';
GRANT ALL PRIVILEGES ON restaurant_pos.* TO 'pos_app'@'localhost';
FLUSH PRIVILEGES;
```

Tune `/etc/mysql/mariadb.conf.d/50-server.cnf` under `[mysqld]`, then
`sudo systemctl restart mariadb`:

```ini
innodb_buffer_pool_size = 1G      # ~25-40% of RAM on a 4 GB server
innodb_log_file_size    = 256M
max_connections         = 200
```

The buffer pool is the single biggest performance setting. The default of
128 MB (16 MB on XAMPP) makes MySQL read from disk what should be in memory.

## 3. The code

```bash
sudo mkdir -p /var/www/pos && sudo chown $USER:www-data /var/www/pos
cd /var/www/pos
# upload the project here (git clone, or unzip the release archive)
composer install --no-dev --optimize-autoloader
cp .env.production.example .env      # then fill in every <...>
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force          # first deploy only
php artisan storage:link
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
```

Sign in as `ADMIN_EMAIL` and **change the password at once**.

## 4. Nginx

`/etc/nginx/sites-available/pos`:

```nginx
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/pos/public;
    index index.php;

    client_max_body_size 25M;      # slider videos and menu imports

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pos /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 5. HTTPS (free)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

Razorpay refuses live keys on a site without HTTPS, and the session cookie is
set `secure` in production.

## 6. Queue worker - Supervisor

`/etc/supervisor/conf.d/pos-worker.conf`:

```ini
[program:pos-worker]
command=php /var/www/pos/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/pos/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

After every deploy: `php artisan queue:restart`, or the workers keep running
the old code.

Every email - the sign-up welcome, the "new sign-up" note to you, and the
trial reminders - is **queued**. With no worker running they wait in the
`jobs` table and nobody receives anything.

## 7. Scheduler - cron

`sudo crontab -u www-data -e`, one line:

```
* * * * * cd /var/www/pos && php artisan schedule:run >> /dev/null 2>&1
```

What that one line runs (routes/console.php):

| When | Command | Why it matters |
|---|---|---|
| 03:45 daily | `backup:run --keep=14` | the nightly database dump |
| 07:30 daily | `subscriptions:sweep` | expires lapsed outlets |
| 09:00 IST daily | `subscriptions:remind` | "your trial/plan ends in 2 days / tomorrow / today / has ended" emails |
| 07:45, 15:00 | `alerts:sweep` | low stock, tills left open |
| 08:30 daily | `reminders:schedule` | decides who is owed a reminder |
| every 30 min | `reminders:dispatch` | sends them |
| scheduled | `campaigns:send` | SMS / WhatsApp campaigns |

## 8. Payments - Razorpay

Two accounts, kept apart on purpose (see `config/payments.php`):

| Account | What it takes | Webhook URL |
|---|---|---|
| `RAZORPAY_*` | guests paying a restaurant's bill | `https://your-domain.com/payments/webhook` |
| `PLATFORM_RAZORPAY_*` | restaurants paying you for the plan | `https://your-domain.com/billing/webhook` |

In the Razorpay dashboard: complete KYC, switch to **Live**, create both
webhooks with a secret, and put the secrets in `.env`.

Before Razorpay approves the account it checks the site publishes a Privacy
Policy, Terms and Refund Policy - fill those in under Settings → Pages.

## 9. SMS and WhatsApp

- **SMS** in India needs **DLT registration** (Jio, Airtel or Vodafone portal)
  for the sender id and every template, before any provider will deliver.
  Then set `SMS_DRIVER=http` and the provider's fields (MSG91, Fast2SMS...).
- **WhatsApp** needs a Meta Business account and approved templates. Set
  `WHATSAPP_DRIVER=cloud`, or `http` for a reseller (Gupshup, AiSensy, Wati).

Both stay on `log` until then - nothing is sent and nothing breaks.

## 10. Push notifications (Firebase)

Optional. Without it the admin bell still updates every 15 seconds while the
panel is open, and the guest's order page still updates itself. Firebase adds
notifications on a phone or laptop with the panel **closed** - a new table
order on the owner's phone, a sign-up on yours.

1. Create a free project at <https://console.firebase.google.com/>.
2. Project settings → General → Your apps → add a **Web app**. Copy its
   `firebaseConfig` values.
3. Project settings → Cloud Messaging → Web configuration → **Generate key
   pair** (the VAPID key).
4. Project settings → Service accounts → **Generate new private key**. A
   `.json` file downloads.
5. In the admin panel, as Super Admin: **Settings → Firebase Notifications**.
   Paste the values from 2 and 3, paste the whole JSON file from 4, tick
   *Send notifications*, Save.
6. Press **Turn on notifications on this device**, allow it, then **Send a
   test notification**.

Each restaurant's staff turn it on for their own device with the ⚡ button
beside the bell. What a device is sent follows the same rules as the bell: a
cashier's tablet does not buzz for a subscription payment.

Notifications need HTTPS (localhost is the one exception browsers allow).

## 11. Backups off the server

`backup:run` writes to `storage/app/backups` **on the same disk**. A dead disk
takes the backups with it. Copy them off nightly - rclone to Google Drive,
S3 or Backblaze:

```
30 4 * * * rclone copy /var/www/pos/storage/app/backups remote:pos-backups
```

Restore-test one of them once a month. A backup nobody has restored is a hope.

## 12. Deploying an update

```bash
cd /var/www/pos
php artisan down
# pull or upload the new code
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

---

## Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` is the https domain
- [ ] Database user is `pos_app`, not root, with a strong password
- [ ] Admin password changed from the seeded one
- [ ] HTTPS works and http redirects to it
- [ ] `php -m` lists `gd`, `redis`, `intl`
- [ ] Supervisor shows `pos-worker` RUNNING
- [ ] Cron line installed; `php artisan schedule:list` shows the jobs
- [ ] Both Razorpay webhooks created and a test payment succeeds
- [ ] Privacy, Terms and Refund pages filled in
- [ ] A backup has run and has been copied off the server
- [ ] Plans and prices checked under Settings → Plans
- [ ] One test restaurant signed up through `/register` end to end
