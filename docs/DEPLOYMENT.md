# Brevon POS — Deployment Guide

How to put Brevon POS on a server for the shop, keep it backed up and hand it over safely.
Written in Phase 11b (2026-11-09). Decisions referred to are in [`DECISIONS.md`](DECISIONS.md).

> Receipts produced by this system are **not** BIR-accredited official receipts or invoices (FR-087).

---

## 1. What the server needs

| Item        | Requirement                                                                                                                                         |
| ----------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP         | 8.4 (8.3–8.5 work) with `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`, `zlib` |
| Database    | **MySQL 8.4** (not MariaDB, not SQLite: the CHECK constraints and locking are tested on MySQL 8.4)                                                  |
| `mysqldump` | Same major version as the server, for backups                                                                                                       |
| Web server  | Nginx or Apache pointing at `public/`, with PHP-FPM                                                                                                 |
| HTTPS       | A valid certificate (Let's Encrypt or the host's). Required in production (NFR-001)                                                                 |
| Node.js     | 20 or newer, **only to build** the front end (not needed at run time if you build elsewhere)                                                        |
| Composer    | 2.x                                                                                                                                                 |
| Cron        | One entry for the Laravel scheduler (§5)                                                                                                            |
| Disk        | About 1 GB for the app plus backups (each compressed backup is small; 14 days are kept)                                                             |

No queue worker, Redis or mail server is required. The app has no background jobs. Mail is only used for
"forgot password", and that needs a working mailer (§3).

Tablets at the counter only need a current Chrome, Edge or Safari. They must reach the server over **HTTPS**.

---

## 2. Database and database user

Create one database and one user that can reach **only** that database:

```sql
CREATE DATABASE brevon_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'brevon'@'localhost' IDENTIFIED BY '<a long random password>';
GRANT ALL PRIVILEGES ON brevon_pos.* TO 'brevon'@'localhost';
FLUSH PRIVILEGES;
```

Never give this user rights on other databases. The backup command uses the same user
(`--single-transaction`, so no global `PROCESS` or `RELOAD` right is needed).

---

## 3. Environment (`.env`)

Copy `.env.example` to `.env` and change at least these:

```dotenv
APP_NAME="Brevon POS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pos.example.com        # the real address, with https

LOG_CHANNEL=stack
LOG_STACK=daily                        # one file per day
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

DB_DATABASE=brevon_pos
DB_USERNAME=brevon
DB_PASSWORD=<the password from step 2>

SESSION_DRIVER=database
SESSION_LIFETIME=120                   # minutes of inactivity before staff must sign in again
SESSION_SECURE_COOKIE=true             # on by default when APP_ENV=production

MAIL_MAILER=smtp                       # for "forgot password" e-mails
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@brevon..."

BREVON_BUSINESS_TIMEZONE=Asia/Manila
BREVON_CURRENCY=PHP

# HTTPS (D-183). Forced automatically when APP_ENV=production.
# BREVON_FORCE_HTTPS=true
# Only behind a load balancer / reverse proxy / Cloudflare that ends HTTPS:
# TRUSTED_PROXIES=*                    # or the proxy's IP addresses, comma separated

# Backups (D-185)
BREVON_BACKUP_PATH=/var/backups/brevon # outside the web root; default storage/app/private/backups
BREVON_BACKUP_KEEP_DAYS=14
BREVON_BACKUP_TIME=02:00               # Asia/Manila
# BREVON_MYSQLDUMP_PATH=/usr/bin/mysqldump

# Leave these EMPTY outside a developer's own machine
BREVON_DEV_OWNER_EMAIL=
BREVON_DEV_OWNER_PASSWORD=
```

Then generate the app key **once** and keep it safe. Encrypted SC/PWD details cannot be read without it:

```bash
php artisan key:generate
```

> **Never change or lose `APP_KEY`** after the shop starts selling. Keep a copy of `.env` somewhere safe
> (a password manager), apart from the database backups.

---

## 4. Install and build

On the server, in the project folder:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan optimize
```

`db:seed` creates the roles and permissions, the menu, payment methods, the main branch, the SC/PWD discount and
the three default Owner accounts (D-102). It does **not** create the local test cashiers or the SM Rosales
pop-up, which only exist when `APP_ENV=local` (D-178).

File permissions: the web server user must be able to write `storage/` and `bootstrap/cache/`.

Web server root: `public/`. Example Nginx block:

```nginx
server {
    listen 443 ssl http2;
    server_name pos.example.com;
    root /var/www/brevon-pos/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/pos.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pos.example.com/privkey.pem;

    client_max_body_size 5m;          # product photos

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
server { listen 80; server_name pos.example.com; return 301 https://$host$request_uri; }
```

The app itself also redirects plain HTTP to HTTPS in production and sends HSTS and security headers (D-183).

### Updating to a new version

```bash
php artisan down
git pull                                  # or copy the new release
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan brevon:backup                 # a fresh backup before migrating
php artisan migrate --force
php artisan optimize
php artisan up
```

From go-live, schema changes come as **new** migrations. The development practice of rebuilding with
`migrate:fresh` (D-182) must **never** be run on the shop's database: it deletes everything.

---

## 5. Scheduler (backups)

Add one cron entry for the web server user:

```cron
* * * * * cd /var/www/brevon-pos && php artisan schedule:run >> /dev/null 2>&1
```

This runs `brevon:backup` every day at `BREVON_BACKUP_TIME` (Asia/Manila). Check it with:

```bash
php artisan schedule:list
```

---

## 6. Backups and restore (NFR-011, NFR-012)

`php artisan brevon:backup` writes `brevon_pos-YYYYMMDD-HHMMSS.sql.gz` to `BREVON_BACKUP_PATH` (folder mode 0700),
and deletes this database's backups older than `BREVON_BACKUP_KEEP_DAYS`. The **System logs** page
(Administration) shows the newest backup and warns when it is more than two days old.

**Copy backups off the server** every day (another machine, cloud storage, or an external drive). A backup that
only lives on the same server is lost with it. Keep `.env` (for `APP_KEY`) in a separate safe place.

### Restore

1. Put the app in maintenance mode: `php artisan down`.
2. Restore into the app's database (this **replaces** its contents):

    ```bash
    gunzip -c /var/backups/brevon/brevon_pos-20261109-020000.sql.gz | mysql -u brevon -p brevon_pos
    ```

3. `php artisan optimize:clear && php artisan up`.
4. Sign in and check the dashboard, the last orders and stock on hand.

Practise a restore into a **spare** database at least once before go-live and after major updates. A restore was
tested in development on 2026-11-09: all 52 tables matched by checksum.

---

## 7. Logs and monitoring

- Daily log files are in `storage/logs/` (14 days kept with the settings above).
- Owners see recent entries on **Administration → System logs**, with passwords and keys masked (FR-102, D-184).
- `/up` returns 200 when the app is running: use it for an uptime monitor.
- Error pages never show code or stack traces when `APP_DEBUG=false`.

---

## 8. Sharing a development copy on the shop's Wi-Fi (not for real sales)

`php artisan serve --host=0.0.0.0` lets tablets on the same network open a developer's copy for a demo.
Do **not** run the shop like this:

- it is plain HTTP, so passwords and session cookies travel unencrypted;
- `APP_ENV=local` turns on developer tools and seeds test accounts (`cashier1` / `cashier2` with password `password`);
- the default Owner passwords may still be `password`;
- it stops when the laptop sleeps or closes, and laptop security software may block it.

Use a real server set up as above for real sales.

---

## 9. Go-live checklist

Tick each item before the first real sale.

**Server**

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` is the https address
- [ ] HTTPS works on every tablet; `http://` redirects to `https://`
- [ ] `APP_KEY` generated once and stored safely with a copy of `.env`
- [ ] Database user can reach only `brevon_pos`
- [ ] Cron entry added; `php artisan schedule:list` shows the backup
- [ ] `php artisan brevon:backup` run by hand once; the file is there; it is copied off the server
- [ ] A restore practised into a spare database
- [ ] Mail settings tested with "Forgot password"

**Accounts and access**

- [ ] Each default Owner (`harris`, `chris`, `desiree`) signs in and **changes the password `password`** (Settings → Security)
- [ ] Each Owner sets a real e-mail address (Settings → Profile), so "forgot password" works
- [ ] Owners turn on two-factor sign-in (recommended)
- [ ] Unused default Owners are deactivated
- [ ] Real staff accounts created with the right role and location; nobody shares an account
- [ ] Role permissions reviewed on the Roles screen (e.g. Cashier `pos.apply-discount`, Manager `discounts.approve`)

**Shop setup**

- [ ] Store settings: business name and receipt footer, approval limits (cash out, shift difference, discount, refund)
- [ ] Locations: the main branch's address (printed on receipts)
- [ ] Menu, prices and categories checked against the real menu; photos added
- [ ] Payment methods: Cash and GCash on
- [ ] SC/PWD discount rate and on/off confirmed with the accountant
- [ ] Pop-up locations and terminals created; staff assigned to them
- [ ] Opening stock posted **with unit costs** at every location (otherwise stock value and waste show ₱0.00)
- [ ] Ingredients and reorder levels entered under Inventory → Stock items
- [ ] Receipt printer chosen once on each counter tablet and a test receipt printed
- [ ] Any test orders, shifts and stock from training removed by restoring a clean backup taken before training,
      or by a fresh install

**Day one**

- [ ] Cashiers open shifts with the counted opening cash
- [ ] One real sale and one refund done end to end, receipt printed
- [ ] Shift closed and reviewed by a manager
- [ ] The next morning: the backup ran and the System logs page shows no errors
