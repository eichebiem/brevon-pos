# Brevon POS

Point of Sale and Store Management System for **Brevon Doughnuts & Coffee**.
Requirements: [`docs/BREVON-SRS-v1.1.md`](docs/BREVON-SRS-v1.1.md) (SRS v1.1, prepared by Harris).
Progress: [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md).

> **Status:** Phase 8 (recipes and production). Staff sign-in, users, roles, audit log, catalog and prices, store
> settings, payment methods, pop-up locations, terminals, opening stock, stock on hand/movements and cashier shifts
> work. Cashiers sell from the POS screen and print 80 mm order receipts; preparation staff work orders through
> New → Preparing → Ready → Handed over; managers cancel orders with a reason and confirm GCash transfers.
> Managers record cash in / out / paid-outs (large ones need approval), cashiers close with a blind count, and
> managers review shifts and add corrections without changing the closed figures. Staff count stock, adjust
> it (with approval), write off waste, see low-stock alerts and dispatch doughnuts from the main branch to pop-ups.
> Managers order from suppliers, receive deliveries in full or in parts with lot and expiry dates, and see
> the value of stock at weighted average cost. Owners keep versioned recipes; bakers record doughnut batches
> (ingredients out, good pieces in, rejects noted) and a manager posts them; drinks with a recipe take their
> ingredients at the counter. The dashboard is still a labelled shell.
> Receipts produced by this system are **not** BIR-accredited official receipts/invoices (SRS FR-087).

## Stack

Laravel 13 · PHP 8.4 target (8.3–8.5 supported) · MySQL 8.4 · Vue 3 + TypeScript · Inertia 3 · Tailwind CSS 4 ·
Laravel Fortify (auth) · PHPUnit 12 · Pint · Larastan (level 7). Built from the official Laravel Vue starter kit.

## Local setup

Requirements: PHP 8.4+ with `bcmath`, `intl`, `pdo_mysql`, `mbstring`; Composer 2; Node 22; MySQL 8.4.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### Databases (important)

Use **only** two dedicated databases, ideally with a MySQL user that can see nothing else:

```sql
CREATE DATABASE brevon_pos      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE brevon_pos_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'brevon'@'127.0.0.1' IDENTIFIED BY '<choose-a-local-password>';
GRANT ALL PRIVILEGES ON brevon_pos.*      TO 'brevon'@'127.0.0.1';
GRANT ALL PRIVILEGES ON brevon_pos_test.* TO 'brevon'@'127.0.0.1';
```

Put the password in `.env` (`DB_PASSWORD`). `.env` is git-ignored; never commit credentials.

**Destructive-command guard:** `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`
and `db:seed` are refused unless `APP_ENV` is `local`/`testing`, the driver is MySQL and the database is
`brevon_pos` or `brevon_pos_test` (`config/brevon.php`, `App\Support\Database\DestructiveCommandGuard`).
In production Laravel's own `DB::prohibitDestructiveCommands()` also applies.

```bash
php artisan migrate --seed
php artisan storage:link   # needed for product images and the store logo
```

Seeding creates the five roles, their permissions, the permanent location `BRV01` (Brevon Doughnuts & Coffee — Main), units of
measure and four starter categories, plus the accounts and menu below. Re-seeding only adds what is missing: it never
resets an existing account's password or overwrites a product.

**Default Owners — every environment, including production (D-102):**

| Name    | Username  | Starting password | Role  |
| ------- | --------- | ----------------- | ----- |
| Harris  | `harris`  | `password`        | Owner |
| Chris   | `chris`   | `password`        | Owner |
| Desiree | `desiree` | `password`        | Owner |

> **Security:** on any shared or live server, each owner must change this password right after the first sign-in
> (Settings → Password). Their emails (`…@brevon.test`) are placeholders: set real ones in **Administration → Users**,
> or password resets cannot reach them.

**Local only (D-100):** when `APP_ENV=local`, seeding also creates Cashier 1 (`cashier1` / `password`). It is never
created in testing, staging or production.

**Menu (D-101):** all environments get the shop's 36 menu items, with Harris recorded as the one who set the prices. Running `MenuSeeder` on its own also creates the default Owners if they are missing.

To add only what is missing to an existing database: `php artisan db:seed --class=DefaultOwnersSeeder`,
`--class=LocalUsersSeeder` or `--class=MenuSeeder`.

More Owners can be created with `php artisan brevon:create-owner --name="Your Name" --username=yourname`. It asks
for the username (if `--username` is not given), the email and a password of at least 12 characters (or reads
`BREVON_DEV_OWNER_EMAIL` / `BREVON_DEV_OWNER_PASSWORD` from `.env`), refuses to run in production, and is recorded in
the audit log.
Further accounts are created in **Administration → Users**. Every account has a username, which staff use to sign in
(their email address also works); the email is kept for password resets.

## Run

```bash
composer run dev        # Laravel server, queue, logs and Vite together
# or
php artisan serve       # after `npm run build`
```

Open http://127.0.0.1:8000. `/up` is the health check.

## Test and quality gate

Tests run against the MySQL database `brevon_pos_test` (set in `phpunit.xml`), never SQLite, because locking and
DECIMAL behaviour must be tested on the production engine.

```bash
composer check   # build, lint/format, vue-tsc, Pint, Larastan, PHPUnit - the full phase gate
php artisan test # PHPUnit only (includes forked-process concurrency tests; needs the pcntl extension)
php artisan brevon:inventory:reconcile  # check every stock balance against its movements
composer lint    # fix PHP formatting
npm run check:fix # fix JS/Vue lint + formatting
```

## Conventions

- Timestamps stored in **UTC**; business dates and display use **Asia/Manila** (`config/brevon.php`).
- Money is handled with `App\Domain\Shared\Money` (integer centavos, half-up rounding) and quantities with
  `App\Domain\Shared\Quantity` (bcmath, 3 decimals). Never use floats for money.
- Business logic lives in `app/Domain/<Module>`; controllers stay thin.
- Decisions and assumptions: [`docs/DECISIONS.md`](docs/DECISIONS.md). Schema: [`docs/ERD.md`](docs/ERD.md).
  Tests: [`docs/TEST_PLAN.md`](docs/TEST_PLAN.md).

## Deployment

Documented in Phase 11. Production requires HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, PHP 8.4, daily database
backups with a tested restore, and a database user without access to other schemas.
