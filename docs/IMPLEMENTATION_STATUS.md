# Brevon POS — Implementation Status

Last updated: 2026-11-07 · Baseline: SRS **v1.1** (`docs/BREVON-SRS-v1.1.md`) · Prompt: `CLAUDE_CODE_PROMPT_BREVON_POS_v1.1.md` (v1.3)

| Phase                                         | Status                                          | Notes                                                                  |
| --------------------------------------------- | ----------------------------------------------- | ---------------------------------------------------------------------- |
| 0 — Discovery and technical plan              | ✅ Passed (docs only), re-baselined to SRS v1.1 | Approved 2026-11-05                                                    |
| 1 — Project foundation                        | ✅ Passed                                       | Approved; committed by user as "Initial Commit"                        |
| 2 — Authentication and access control         | ✅ Passed                                       | Approved; committed by user ("Done Phase 2")                           |
| 3 — Catalog, settings and operating locations | ✅ Passed                                       | Approved; committed by user ("Done Phase 3")                           |
| 4a — Ledger core and shift open               | ✅ Passed                                       | Approved; committed by user (with the seeder changes)                  |
| 4b — Checkout backend                         | ✅ Passed                                       | Approved 2026-11-06                                                    |
| 4c — Cashier UI and receipts                  | ✅ Passed                                       | Approved 2026-11-06 (with D-121, D-122)                                |
| 5 — Orders and preparation                    | ✅ Passed                                       | Approved 2026-11-06                                                    |
| 6 — Cashier shifts and cash control           | ✅ Passed                                       | Approved 2026-11-06; two limits to confirm (D-127, D-128)              |
| 7a — Stock control                            | ✅ Passed                                       | Approved 2026-11-06 (D-131–D-134)                                      |
| 7b — Purchasing                               | ✅ Passed                                       | Approved 2026-11-06; re-review at end of development (client changes)  |
| 8 — Recipes and production                    | ✅ Passed                                       | Approved 2026-11-06; re-review at end of development (client feedback) |
| 9a — Discounts and tax                        | ✅ Passed                                       | Approved 2026-11-06; SC/PWD seeded on since D-175                      |
| 9b — Refunds and voids                        | ✅ Passed                                       | Approved 2026-11-06; refund approval limit ₱0.00 TBC                   |
| 10a — Dashboard and sales reports             | ✅ Passed                                       | Approved 2026-11-06; definitions TBC (held for Phase 11)               |
| 10b — Operational reports                     | ✅ Passed                                       | Approved 2026-11-06                                                    |
| 11a — Feature removals                        | ✅ Built — awaiting approval                    | Tax, options, lots, GCash check, preparation removed                   |
| 11b — Hardening and release readiness         | ⏳ Not started                                  | Security, a11y, performance, backup, deploy                            |

## Phase 0 log

### Run 1 — SRS v1.0 (2026-11-05)

**Commands run (read-only):** `php -v` / `php -m`, `composer --version`, `node -v`, `npm -v`, `mysql --version`, `mysqladmin ping`, `SHOW DATABASES`, `brew services list`, `laravel --version`, Packagist and npm version lookups, fetch of the official Vue starter kit `composer.json`/`package.json`/`config/fortify.php`, fetch of the live landing page CSS.
**Files created:** `docs/BREVON-SRS.md` (v1.0), `IMPLEMENTATION_PLAN.md`, `DECISIONS.md`, `ERD.md`, `TEST_PLAN.md`, `IMPLEMENTATION_STATUS.md`.

### Run 2 — re-baseline to SRS v1.1 (2026-11-05)

**Trigger:** SRS updated to v1.1 (customer data deferred; one permanent branch + temporary pop-up locations) and prompt updated to v1.3.
**Commands run (read-only):** PHP/Composer/Node/npm/MySQL/Laravel-installer version checks, `mysqladmin ping`, Packagist lookups for laravel/framework, spatie/laravel-permission, inertia-laravel, fortify, larastan. Results unchanged (PHP 8.5.4, Composer 2.9.5, Node 22.20.0, MySQL 8.4.8 alive, Laravel v13.40.0, spatie 8.3.2, inertia-laravel 3.7.0, fortify 1.41.0, larastan 3.14.0). No new blockers.
**Files changed:**

- `docs/BREVON-SRS.md` ← replaced with SRS v1.1; v1.0 moved to `docs/archive/BREVON-SRS-v1.0.md` (file later renamed `docs/BREVON-SRS-v1.1.md`)
- `DECISIONS.md` — amended D-023, D-033, D-054; D-053 superseded; new sections L (locations, D-057–D-066), M (customer data, D-067–D-069), N (SRS v1.1 discrepancies N-01–N-12); new open items OI-016, OI-017
- `ERD.md` — location columns and guards on `branches`; `customers` removed; terminal open-shift guard; transfer types reserved; migration order updated
- `IMPLEMENTATION_PLAN.md` — v1.1 delta table, `Locations` domain module, FR-103–FR-108 mapped to phases 2/3/4/10, risks R-09–R-11
- `TEST_PLAN.md` — T-LOC-1..9, T-SHF-3/4, T-INV-8/9, T-POS-15..17, T-PRIV-1..3, T-RPT-4

**No application code, no databases created, no destructive commands.**

**Awaiting user decisions:**

- From run 1: D-001 (project root), D-014 (brand tokens), schema additions D-020/D-025/D-026/D-027, phase adjustment D-051.
- New in run 2: schema additions D-057/D-058/D-062, permission D-061, D-064 (pop-ups start with no products), N-01 (is there an SRS v1.2?).
- For the client (not blocking Phase 1): OI-016 pop-up stock allocation (D-063), OI-017 pop-up period-end policy (D-059), SC/PWD identity data (D-068).

**Resolution (2026-11-05):** user approved the proposals and answered: no SRS v1.2 exists (N-01); doughnuts are baked at the main branch and delivered to pop-ups, no coffee at pop-ups (OI-016 → D-063); no operating period for now (OI-017 → D-059, dates optional); SC/PWD discounts accepted (D-068). Recorded in DECISIONS §O.

## Phase 1 log — Project foundation (2026-11-05)

**Objective:** bootable Laravel 13 + Vue/Inertia/TS/Tailwind app on MySQL with safety rails, brand theme, layout shells, money primitives and a passing quality gate.
**SRS IDs:** NFR-002, NFR-005, NFR-007, NFR-015, NFR-017, NFR-018, NFR-020, FR-014 (config defaults), FR-001 partial (email login only; username login is Phase 2).

**Implemented**

- Scaffolded with `laravel new --vue --phpunit` (official starter kit) in a scratch folder, merged into `Brevon POS/` without overwriting `docs/`, SRS or prompt. `git init` (branch `main`, **nothing committed yet**).
- Composer `config.platform.php = 8.4.0` (D-003); package renamed `brevon/pos`.
- Installed: laravel/framework 13.40.0, inertia-laravel 3.7.0, fortify 1.41.0, wayfinder 0.1.23, phpunit 12.5.43, larastan 3.14.0, pint 1.32.1; vue 3.5.44, @inertiajs/vue3 3.11.0, tailwindcss 4.3.4, typescript 5.9.3, vite-plus 1.4.0.
- MySQL: created `brevon_pos` and `brevon_pos_test` (utf8mb4_unicode_ci) and user `brevon` restricted to them (D-072). `.env` (git-ignored) holds the generated password; `.env.example` has placeholders only.
- `config/brevon.php` (business timezone Asia/Manila, PHP currency, shift enforcement, negative-stock flag, destructive guard config); `APP_TIMEZONE` stays UTC (D-031).
- `DestructiveCommandGuard` refuses destructive artisan commands unless env ∈ {local, testing}, driver = mysql and database ∈ {brevon_pos, brevon_pos_test} (D-012).
- Removed registration, passkeys, mandatory email verification and self-delete (D-070); `/` → `/login`; Fortify home `/admin/dashboard`.
- Brand theme (D-014): CSS tokens, Poppins + DM Serif Display (build-time self-hosted, D-010), provisional logo + favicons (D-016), navy focus rings, contrast checked (gold never used as text on cream). Light mode default (D-073).
- Layout shells: admin sidebar (`/admin/dashboard`), full-screen POS (`/pos`), preparation (`/staff/preparation`) — all behind `auth`, each showing a "Not implemented yet · Phase N" panel and no fake data.
- `App\Domain\Shared\Money` (integer centavos, half-up rounding, strict decimal parsing, no floats) and `Quantity` (bcmath, 3 dp).
- `composer check` = build → lint/format → vue-tsc → Pint → Larastan L7 → PHPUnit. CI workflow moved to PHP 8.4 + MySQL service (not run, D-075). README written. Seeder seeds nothing (no known-password accounts).

**Verification (all run 2026-11-05, all passed)**

| Command                                                                                                                  | Result                               |
| ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------ |
| `php artisan migrate --force` / `migrate:fresh --seed` on `brevon_pos`                                                   | pass                                 |
| `DB_DATABASE=brevon_pos_test php artisan migrate:fresh --seed --force`                                                   | pass                                 |
| `composer check` → `npm run build`                                                                                       | pass (fontaine fallback notice only) |
| → `vp check` (format + lint, 73 files)                                                                                   | pass, 0 warnings                     |
| → `vue-tsc --noEmit`                                                                                                     | pass                                 |
| → `pint --test`                                                                                                          | pass                                 |
| → `phpstan analyse` (level 7)                                                                                            | pass, 0 errors                       |
| → `php artisan test` (MySQL `brevon_pos_test`)                                                                           | **67 passed, 209 assertions**        |
| Guard manual checks: `db:wipe` on another DB name, `migrate:fresh --database=sqlite`, `APP_ENV=production migrate:fresh` | all refused                          |

**Manual checks (browser, built assets, `php artisan serve`, 800×600):** login page branding; login with a temporary local account → `/admin/dashboard`; `/pos` and `/staff/preparation` shells; no console errors; logout from POS header → `/pos` redirects to login. Temporary account deleted afterwards (users table empty).

**Issues found and fixed during the phase:** guard initially read `--database` before input binding (fixed with raw option parsing) and accepted SQLite named like a Brevon DB (fixed: MySQL driver required); a stray SQLite file created by that check was deleted. Larastan needed `--memory-limit=1G`; numeric-string typing fixed in Money/Quantity without ignores.

**Known issues / deferred**

- Low: login copy says "email" — username login is Phase 2 (FR-001).
- Low: logo is the landing-page JPEG; official vector assets pending (D-016).
- Low: CI workflow untested (no remote, D-075).
- Info: shells are auth-protected only; permission middleware arrives in Phase 2 (no data is exposed by them).
- Info: optional `fontaine` package not installed (font fallback metrics); cosmetic.

**Next step:** Phase 2 — Authentication and access control (see IMPLEMENTATION_PLAN §4), after user review.

## Phase 2 log — Authentication and access control (2026-11-05)

**Objective:** staff sign-in by email or username, account administration, roles and permissions with privilege-escalation guards, operating-location assignment, and an append-only audit trail.
**SRS IDs:** FR-001–FR-010, FR-011 (location data), FR-098–FR-100, FR-103 (seed), FR-104–FR-106 (location rules, partial), NFR-003, NFR-004.
**User decisions recorded first:** OI-018 deferred as FE-01 (D-063); SC/PWD stores beneficiary full name, card number and address, 12 % of the beneficiary's bill, VAT disregarded (D-068, flagged against RA 9994/10754); CI branch is `master`.

**Implemented**

- Schema: Spatie permission tables; `branches` (permanent/pop-up, generated `permanent_guard` UNIQUE so only one permanent location can exist, CHECKs on type and operating period, never deleted); `employees`; users + `username`, `employee_id`, `is_active`, `last_login_at`, soft deletes; `user_branch` (with default); `terminals`; append-only `audit_logs` and `login_events`. Enforced morph map.
- Seeders: 42 permissions, 5 roles (D-076), location `BRV01` (provisional name) + terminal `POS-1`. Idempotent. No accounts.
- Auth: email-or-username login (D-078), generic errors, inactive message only after a correct password, throttling recorded (D-079), immediate session end on deactivation (D-080), `last_login_at`.
- Access: `AccessGuard` (D-077), `UserService`/`RoleService` with audited diffs, permission middleware on every protected route, `/home` landing per role, admin location switcher (D-081).
- `brevon:create-owner` (D-082). Local-only seeded Owner `harris` (D-084, added at user request after the phase report).
- UI: Users (list with search/role/status/location filters, create/edit with roles, locations + default, optional employee profile, activate/deactivate with confirmation), Roles (list, grouped permission editor; ungrantable boxes disabled; Owner read-only), Audit log (filters, before/after values) and Login history. Sidebar shows only permitted areas.

**Verification (all run 2026-11-05)**

| Command                                                                  | Result                                             |
| ------------------------------------------------------------------------ | -------------------------------------------------- |
| `php artisan migrate:fresh --seed` on `brevon_pos` and `brevon_pos_test` | pass; 1 location, 5 roles, 42 permissions, 0 users |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7       | all pass, 0 errors                                 |
| → `php artisan test` (MySQL)                                             | **122 passed, 498 assertions** (was 67)            |

**Manual checks (browser, built assets):** created a temporary Owner with `brevon:create-owner` and a generated password; signed in by username → dashboard; created a Cashier through the Users form → toast, list updated, `user.created` audit entry with diff; Roles list and Manager permission grid; Audit log; signed in as the cashier → landed on `/pos`, `/admin/users` → 403. No console errors. Afterwards `brevon_pos` was re-created with `migrate:fresh --seed` (0 users) and the temporary password file deleted.

**Issues found and fixed during the phase**

- Login listeners were registered twice (manual `Event::listen` + Laravel auto-discovery), so every login event was written twice. Manual registration removed; `event:list` shows one listener each.
- Throttled attempts were never recorded (Fortify's route limiter skips the `Lockout` event). Fixed via the limiter's response (D-079).
- Two location tests initially passed for the wrong reason (wrong column name; duplicate code). Both now assert the specific constraint name (`branches_permanent_guard_unique`, `branches_operating_period_check`).
- Usernames with dots were rejected; rule now allows `.` (D-078).

**Known issues / deferred**

- Info: `settings`, `number_sequences`, `notifications` tables deferred to their first use; branch-scoped record tests (T-ACL-4) deferred to Phase 3/4 (D-083).
- Info: locations can't be created or edited in the UI yet — pop-up management is Phase 3. Only `BRV01` exists.
- Low: on screens ≈800 px wide the Users table scrolls horizontally to reach the Edit button.
- Low: logo still the provisional JPEG (D-016); CI not yet run on GitHub (D-075).

**Next step:** Phase 3 — Catalog, settings and operating locations, after user review.

## Phase 3 log — Catalog, settings and operating locations (2026-11-05)

**Objective:** everything the POS needs to know before it can sell: what is sold, at which location, at what price, plus store settings and pop-up/terminal management.
**SRS IDs:** FR-011, FR-015–FR-022 (catalog, variants, options, availability, price history, archive), FR-101 (business settings; payment methods come with Phase 4b), FR-103, FR-104, FR-106, FR-107 (location switcher; shift/sale enforcement in Phase 4a/4b), FR-108 (no deletes), BR-014, FR-098 (audit).

**Implemented**

- Schema (6 migrations): `settings`; `categories`; `units_of_measure` + `inventory_items` (minimal, for the product link); `products`, `product_variants`; `product_prices` (append-only, generated `scope_key`/`open_guard` UNIQUE), `product_branch`; `option_groups`, `product_options`, `product_option_group`. CHECK constraints listed in ERD §2.
- Seed: units pc/box/g/kg/ml/l and categories Doughnuts, Coffee, Cold Drinks, Other. No products.
- Domain: `StoreSettings` (cached, audited), `LocationService` (create/edit/deactivate/reactivate pop-ups, copy main-branch doughnuts), `TerminalService` (create, rename, activate, reassign), `CategoryService`, `ProductService` (D-085 matrix, auto-linked stock item D-089, variants, option groups, location availability), `OptionGroupService`, `PriceService` + `PriceResolver` (D-087), `CatalogQuery::sellableAt()` for the Phase 4 POS, `BusinessClock` (Manila "today").
- UI: Locations (list with operating status, pop-up form with optional dates, deactivate with reason, reactivate, terminals, copy availability), Store settings (name, logo, receipt footer; currency/TZ read-only; logo shown in the sidebar), Categories, Options & add-ons, Products (filters by name/SKU, category, status, location; form with type/tracking, variants, options, locations, image), Prices (current prices, set new, remove special price, full history).
- Permissions: routes guarded by `locations.manage`, `settings.manage`, `products.view/create/update/archive/manage-prices`.
- Docs: DECISIONS §R (D-085–D-091), ERD, TEST_PLAN map, README (`storage:link`), SRS file references updated to `docs/BREVON-SRS-v1.1.md`.

**Verification (all run 2026-11-05)**

| Command                                                                                                        | Result                                   |
| -------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| `php artisan migrate` on `brevon_pos` and `brevon_pos_test`; `migrate:fresh --seed` on `brevon_pos` afterwards | pass                                     |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7                                             | all pass, 0 errors                       |
| → `php artisan test` (MySQL)                                                                                   | **163 passed, 795 assertions** (was 122) |

**Manual checks (browser, built assets, signed in as `harris`):** created product "Classic Glazed" (DN-GLAZED, ₱45, finished stock) — stock item linked automatically; created pop-up "Test Pop-up" (POP-TEST1) with "copy availability" → product list shows "BRV01, POP-TEST1"; added terminal POS-1; set a ₱55 POP-TEST1 price then removed it — history shows both rows with times and user; store settings, categories and new option group screens render. No console errors besides the expected Inertia asset-version reload (409) after a rebuild. Afterwards `brevon_pos` was re-created with `migrate:fresh --seed` (only `harris` and reference data remain).

**Issues found and fixed during the phase**

- The location edit page passed a prop named `location`, which silently replaced the shared location-context prop, so the sidebar said "No location assigned". Renamed to `site`; regression test added.
- Terminal rows on the location page were cramped; the location dropdown on the Prices page overflowed its card at ~1024 px. Both layouts fixed.
- TEST_PLAN T-PRD-4 listed the wrong price precedence; corrected (D-087).

**Known issues / deferred**

- Phase 4a: deactivating a location or moving a terminal is not yet blocked by an open shift; operating dates are not yet checked against existing sales (no shifts/sales exist). Hooks are marked in the services (D-090).
- Info: payment-method settings (FR-101) arrive with checkout in Phase 4b; stock quantities, units UI and dispatch in Phase 7.
- Low: no scheduled (future-dated) prices in V1 (D-087). Product images and the logo need `php artisan storage:link` on each install.
- Low: wide tables (Products, Users) scroll horizontally on screens around 800 px wide.

**Changes requested after the report (D-092):** creating a product now returns to the product list with the success notice. The category list briefly became alphabetical and was reverted at the user's request, so the sort order still controls the POS tab order.

**Next step:** Phase 4a — ledger core and shift open, after user review.

## Phase 4a log — Ledger core and shift open (2026-11-05)

**Objective:** the foundations checkout needs: a per-location stock ledger that cannot oversell, opening stock, payment methods, and cashier shifts on terminals.
**SRS IDs:** FR-053–FR-055, FR-062, FR-073, FR-074, FR-078, FR-079 (basic), FR-106, FR-107, BR-011, BR-013; prompt invariant 12. BR-017 (cash needs an open shift) is enforced at checkout in Phase 4b.

**Implemented**

- Schema (4 migrations): `number_sequences`, `payment_methods` (seeded Cash, GCash), `branch_inventory` + `inventory_movements` (CHECKs on type, non-zero delta, cost ≥ 0), `cashier_shifts` (generated `open_guard`; one open shift per cashier and per terminal; status/cash/closed-state CHECKs).
- Domain: `InventoryLedger` (locking, negative-stock block, transfers rejected, weighted average, `reconcile()`), `OpeningStock`, `NumberSequence`, `ShiftService` (open/close), `ExpectedCash` (opening float until 4b/6). Open-shift guards on location deactivation and terminal deactivate/move (D-098). Command `brevon:inventory:reconcile`.
- UI: Inventory → Stock on hand (low-stock badge), Stock movements (filters by item, type, Manila dates), Opening stock entry; My shift (open on a terminal, close with counted cash, recent shifts with difference); POS screen shows the shift state; the POS header shows location · terminal · shift, with Shift and Back-office links.
- Docs: DECISIONS §S (D-093–D-098), ERD, TEST_PLAN map, README.

**Verification (all run 2026-11-05)**

| Command                                                            | Result                                                                                     |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| `php artisan migrate` on `brevon_pos` and `brevon_pos_test`        | pass                                                                                       |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors                                                                         |
| → `php artisan test` (MySQL)                                       | **195 passed, 1,037 assertions** (was 164), including the forked-process concurrency tests |

**Manual checks (browser, built assets):** done on a scratch copy (`brevon_pos_test`, preview `brevon-pos-scratch` on port 8001) because the user's own `harris` password has changed and their local data must not be touched. Created a doughnut product; entered opening stock 36 pc at ₱12.50 (the stock screen shows 36 pc and ₱12.50 average; movements show +36 by Harris); POS showed "Open a shift to start selling"; opened shift BRV01-20261105-S01 with ₱1,000 (the header shows location, terminal and shift); closed it with ₱985.25 → expected ₱1,000.00, difference −₱14.75. No console errors.

**Issues found and fixed during the phase**

- **Deadlock under concurrency** in the ledger (shared lock from `INSERT IGNORE` on an existing row, then two processes upgrading). Found by the forked-process test; fixed by locking existing rows directly, plus deadlock retry (D-096).
- POS header buttons wrapped onto two lines when the location name is long; the header now truncates the name instead.
- After closing a shift, the terminal picker no longer pre-selected the only free terminal; fixed.

**Known issues / deferred**

- Owner to confirm (D-093): pop-up doughnuts entered as opening stock until dispatch exists; this does not reduce main-branch stock.
- Phase 4b: cash sales in expected cash, BR-017 at checkout, payment-method settings screen. Phase 6: cash movements, shift review/approval, corrections, admin shift list.
- Phase 7: adjustments (the only way to correct stock after opening), lots, audited negative-stock override.
- Info: the operating-date check against existing sales (D-062) lands with sales in Phase 4b.

**Change requested during review (D-099):** the login page asks for a username (email still accepted) and every account now requires a username.

**Added during review:** default Owners Harris, Chris and Desiree in every environment, starting password `password` — change at go-live (D-102); local-only Cashier 1 (D-100); `MenuSeeder` with the shop's 36 menu items from the menu boards (D-101, `MenuSeederTest`). Gate after these: 203 tests, 1,106 assertions. Checked on the scratch database: 36 products, sub-categories as planned. The menu is the starting catalog in every environment, including production (D-101).

**Reminders — user is asking the client (raise again before bundles / Phase 4b checkout):**

- Assorted boxes and Better Together: how doughnut stock is deducted and how the flavour choices are recorded.
- Better Together: which doughnuts (regular only or any) and which lemonade flavours are allowed.

Still to confirm: boxes/combo at pop-ups (D-089), hot vs iced coffee prices. Phase 11: allow the seeding run on staging/production; go-live checklist — each owner changes the `password` starting password and sets a real email (D-102).

**Next step:** Phase 4b — checkout backend, after user review.

## Phase 4b log — Checkout backend (2026-11-06)

**Objective:** a sale that is priced on the server, paid by cash or GCash, takes stock, links to the shift, and either fully commits or leaves nothing behind — safe against double clicks and simultaneous cashiers.
**SRS IDs:** FR-012, FR-023, FR-026–FR-028, FR-030–FR-032, FR-035–FR-039, FR-045 (payment audit), FR-071, FR-077 (cash sales), FR-106, FR-107, BR-002–BR-007, BR-009, BR-016, BR-017, BR-019; prompt invariants 1–7, 9–12, 15.

**Implemented**

- Schema (1 migration): `orders`, `order_items`, `order_item_options`, `order_status_histories`, `payments` with CHECKs and RESTRICT FKs (D-111).
- Domain (`app/Domain/Sales`): `CartPricer` (server pricing and validation, D-107), `CheckoutService` (one transaction, fixed lock order, idempotency, payments, stock, status, audit; D-103–D-109), DTOs `CartLine`, `CheckoutData`, `PaymentInput`, `PricedCart`. `ExpectedCash` counts cash sales (D-110). `NumberSequence` locking fixed (D-106). `PaymentMethodService` (D-112). Location dates checked against sales (D-113).
- HTTP: `POST /pos/checkout` (`pos.create-sale` + `payments.record`, 60/min) and `POST /pos/quote` (JSON totals). The POS screen that uses them is Phase 4c.
- UI: Administration → Payment methods.
- Config: `BREVON_SHIFT_ENFORCEMENT` removed (D-103). GCash reference numbers are unique per method at every location, enforced in the database too; there is no setting to allow duplicates (user change during review, D-109).
- Docs: DECISIONS §T (D-103–D-114), ERD, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                                                                     |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| `php artisan migrate` on `brevon_pos_test` (fresh, with seeders)   | pass                                                                       |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors                                                         |
| → `php artisan test` (MySQL)                                       | **238 passed** (was 203), including 3 new forked-process concurrency tests |
| `CheckoutConcurrencyTest` four-way race, repeated                  | 10/10 passed after the D-106 fix                                           |

**Manual checks (browser, scratch DB `brevon_pos_test`, preview port 8001):** signed in as `harris`; Payment methods page shows Cash and GCash; turning GCash off and on saves, shows the toast, and the badge follows the saved value (confirmed against the database). Checkout itself has no screen until 4c, so it was verified by the feature and concurrency tests only. The user's `brevon_pos` was not touched and has not been migrated with the 4b tables yet.

**Problems found and fixed during the phase**

- **Deadlock with simultaneous checkouts** (four cashiers at once): a locking read that found no existing order took a gap lock. Fixed with a plain read under the shift lock (D-106).
- `NumberSequence` used INSERT IGNORE before locking, the same pattern that deadlocked the ledger in 4a; fixed (D-106).
- Advance-pickup time was saved 8 hours off (Manila time written as UTC); now converted before saving.
- Payment methods page: the status badge did not follow saves; fixed and re-checked in the browser.

**Known issues / deferred**

- Phase 4c: POS screen (cart, order type, cash/GCash dialogs), confirmation, 80 mm receipt and reprint (T-POS-13), POS order history.
- Phase 5: status changes after checkout and the preparation queue; GCash verification after checkout.
- Phase 8: recipe-at-sale deduction (D-114). Phase 9: discounts, tax, refunds, split tender.
- "Enabled order types" setting (D-029) not built; all five types are accepted.
- Still to confirm: OI-002 (status flow), OI-005 (GCash reference format). D-101 menu questions for the client (box/combo stock and flavours, Better Together choices): client has not answered; raise again after Phase 9 (user, 2026-11-06).

**Next step:** Phase 4c — cashier UI and receipts, after user review.

## Phase 4c log — Cashier UI and receipts (2026-11-06)

**Objective:** a cashier can sell from the screen — pick items, take cash or GCash, see the change, print an order receipt, and find and reprint past orders.
**SRS IDs:** FR-024, FR-025, FR-029, FR-033, FR-034, FR-049, FR-050 (no customer fields), FR-104 (location scope); D-034, D-052, D-066.

**Implemented**

- Domain: `PosCatalog` (what the screen sells at a location, D-115), `OrderReceipt` (receipt from snapshots, D-118). `Category::topLevel()`.
- HTTP: `GET /pos` now passes the catalog, payment methods and order types; `POST /pos/quote` also returns `unit_total` per line and cash `change` (D-116); `GET /pos/orders`, `GET /pos/orders/{order}`, `GET /pos/orders/{order}/receipt`, `POST /pos/orders/{order}/reprint` (`orders.view`, D-119, D-120).
- UI: POS screen (tabs, search, tiles with stock, item dialog for size/add-ons/quantity/instructions, cart, order type and pickup time, order note, cash dialog with quick amounts, GCash dialog, sale-complete dialog with change and Print receipt, shortcuts); Orders page; order detail with Print again; printable 80 mm receipt page with no app header. "Sale" and "Orders" links in the POS header.
- Docs: DECISIONS §U (D-115–D-120), TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **247 passed** (was 238) |

**Manual checks (browser, scratch DB `brevon_pos_test`, preview port 8001, 1280×800):** signed in as `cashier1`, opened a shift, sold 2 × Choco Custard + Latte (Hot 12oz, "extra hot") for ₱208.00 cash with ₱500 received → change ₱292.00, stock 12 → 10; sold Chocolate Sprinkles by GCash → "waiting for verification", stock 3 → 2; the same GCash reference on a third sale was refused with the message under the field and the cart kept; Orders page listed both with Manila times; order detail and the standalone receipt printed correctly; Print again wrote one `order.receipt_reprinted` audit entry and no new order. The user's `brevon_pos` was not touched.

**Problems found and fixed during the phase**

- The cart briefly computed unit price + add-ons in the browser; replaced by the server's `unit_total` (D-116).
- Larastan: list return types in `PosCatalog` and an always-true shift check in the history query.

**Change during review (user, 2026-11-06, D-121):** the location form no longer asks for a code, phone or email. Pop-ups get the next code automatically (`POP01`, `POP02`, …); the `phone` and `email` columns were dropped from `branches`, and the receipt no longer prints a phone. `composer check` passes with **248 tests**; checked in the browser by creating a pop-up on the scratch database (got `POP01`). Run `php artisan migrate` on `brevon_pos` to apply it.

**Second change during review (user, 2026-11-06, D-122):** terminal codes are assigned automatically — adding a terminal asks only for a name and gets the next `POS-n`, numbered across all locations so a moved terminal keeps its code. No database change. `composer check` passes with 248 tests; checked in the browser (a new terminal at the main branch got `POS-2`).

**Known issues / deferred**

- Phase 5: order status changes and the preparation queue; verifying a pending GCash payment after checkout.
- The seeded menu has no add-on groups yet, so add-ons were checked by tests only.
- Receipt printing uses the browser's print dialog; choosing the 80 mm printer is a one-time setup on each counter computer (Phase 11 checklist).
- Still to confirm: OI-002, OI-005. D-101 client menu questions: raise again after Phase 9.

**Next step:** Phase 5 — orders and preparation, after user review.

## Phase 5 log — Orders and preparation (2026-11-06)

**Objective:** after the sale, staff can see what to make, move orders through preparation, cancel with a reason, and confirm GCash transfers.
**SRS IDs:** FR-039 (GCash verification), FR-041, FR-045, FR-046–FR-052, BR-019; UC-005 step 52 (stock back only when sellable).

**Implemented**

- Domain: `OrderWorkflow` (advance one step, cancel with refund and optional stock return, verify / reject a GCash payment — all under an order row lock), `PreparationBoard` (D-125). `OrderStatus` gained labels and the one-step `next()`. `User::worksAt()`.
- HTTP: `GET /staff/preparation`, `POST /orders/{order}/advance` (`orders.update-status`), `POST /orders/{order}/cancel` (`orders.cancel`), `GET /staff/payments`, `POST /payments/{payment}/verify|reject` (`payments.verify-ewallet`). Other locations' orders and payments answer 404.
- UI: preparation screen (three columns + Scheduled, auto-refresh, cancel from a card's menu), "GCash to check" page, order page with status, actions and history, shared cancel dialog, "CANCELLED — REFUNDED" on the receipt, Home button on the preparation header for managers, sidebar link.
- Docs: DECISIONS §V (D-123–D-125), TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **258 passed** (was 248) |

**Manual checks (browser, scratch DB `brevon_pos_test`, 1280×800, signed in as `harris`):** four test sales; the ready-made one completed at checkout and stayed off the board; two orders under New, the 4-hours-ahead pickup under Scheduled; Start preparing moved #0001 to Preparing; #0003 cancelled from its card with a reason (toast, history note, refunded, receipt marked cancelled); the GCash payment confirmed from "GCash to check" and the list emptied. The user's `brevon_pos` was not touched.

**Problems found and fixed during the phase**

- The Scheduled section overlapped the columns on the preparation screen; fixed the layout.
- Receipts printed the payment state "refunded" in lowercase and did not say the order was cancelled; fixed.

**Known issues / deferred**

- Refund records, refunds of completed orders and of cash from closed shifts: Phase 9 (D-123). Cash handed back is reflected only through the expected-cash total until cash movements arrive in Phase 6.
- No sound or notification for new orders; the screen refreshes every 8 seconds.
- Still to confirm: OI-002 (status flow), OI-005 (GCash reference format). D-101 client menu questions: raise again after Phase 9.

**Next step:** Phase 6 — shifts and cash, after user review.

## Phase 6 log — Cashier shifts and cash control (2026-11-06)

**Objective:** every peso in the drawer is accounted for: cash added or taken out with a reason and approval where needed, a blind count at closing, a manager's sign-off, and corrections that never rewrite a closed shift.
**SRS IDs:** FR-075–FR-082, BR-007, BR-008, UC-008.

**Implemented**

- Schema: `cash_movements` (CHECK rules, D-127/D-129); `cashier_shifts.reviewed_at`, `review_notes`. Morph name `cash_movement`.
- Domain: `ExpectedCash` with components (D-126), `CashDrawer` (record, approve/reject, correct, review — shift row locked first), `ShiftSummary` (D-130). `ShiftService::close` refuses while a movement is pending, and refuses a mismatched count without notes without revealing the amount (D-128). Model guards: a decided movement and a closed shift cannot change (only the one-time review stamp).
- Settings: Store settings › Cash drawer — "Approve cash out over" (₱500.00) and "Review shifts with a difference over" (₱0.00), both **to confirm with the owner**.
- HTTP: `POST /shifts/{shift}/cash-movements` (`cash-movements.create`), `POST /cash-movements/{movement}/approve|reject`, `GET /admin/shifts`, `GET /admin/shifts/{shift}`, `POST /admin/shifts/{shift}/review|corrections` (`shifts.review`). Other locations answer 404. A manager closing someone else's shift returns to its summary page.
- UI: "Cash in and out" on My shift (form when allowed, list with status, pending warning before closing); Shifts list (status / date filters, Needs review badge) and shift summary page (drawer figures, after-corrections box, non-cash totals, movements with Approve / Reject, close a forgotten shift, review, add a correction); sidebar "Shifts".
- Docs: DECISIONS §W (D-126–D-130), ERD §4, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **271 passed** (was 258) |

**Manual checks (browser, scratch DB `brevon_pos_test`, signed in as the scratch `cashier1` with the cash permission added, then `harris`):** a ₱600 cash out waited for approval and the close form refused while it was pending; Harris approved it from the shift page (expected cash ₱1,000 → ₱400); closed the shift with ₱395 and a note (−₱5.00, Needs review); added a +₱5.00 counted-cash correction (after corrections ₱0.00, closing figures unchanged); marked it reviewed; the settings page shows both limits. No console errors. The user's `brevon_pos` was not touched.

**Problems found and fixed during the phase**

- An older test closed a shift ₱2 over with no note; Phase 6 now refuses that, so the test gives a note.
- On a narrow window the movement table pushed its card wider and hid Approve / Reject; the table now scrolls inside the card and the buttons sit under the status.

**Known issues / deferred**

- `cash_refund` movements are created by the Phase 9 refund workflow; cancellations already leave expected cash through the payment status.
- Cashiers cannot record cash in/out by default (`cash-movements.create` is Owner/Admin/Manager); an Owner can add it to the Cashier role.
- To confirm with the owner: the ₱500.00 approval limit and the ₱0.00 review limit. Still open: OI-002, OI-005. D-101 client menu questions: raise again after Phase 9.
- The user's `brevon_pos` needs `php artisan migrate` (Phase 4b tables, D-121 and Phase 6).

**Next step:** Phase 7 — inventory and purchasing, after user review.

## Phase 7a log — Stock control (2026-11-06)

**Objective:** stock stays right between deliveries: counts and adjustments (approved where needed), waste write-offs, low-stock alerts, the stock items list, and sending doughnuts from the main branch to a pop-up. Phase 7 is split like Phase 4; purchasing is 7b.
**SRS IDs:** FR-083–FR-088 (counts, adjustments, waste, low stock), T-TRF-1/T-TRF-2 (dispatch, D-063), T-INV-1–T-INV-3.

**Implemented**

- Schema: `stock_adjustments` / `stock_adjustment_items` (kind count/change, pending → posted/rejected, system/counted/variance quantities, unit cost), `stock_transfers` / `stock_transfer_items`. Morph names `stock_adjustment`, `stock_transfer`. Model guards: posted/rejected rows and transfers never change; deletes refused.
- Domain: `StockAdjustments` (create, approve/reject with no self-approval, post the **variance** so sales made while waiting are kept, D-131), `StockWaste`, `StockDispatch` (transfer out/in pair at main's average cost through the new `InventoryLedger::postTransfer`, D-133), `StockLocations` (ended pop-ups with leftover stock can still count or write off, D-132), `InventoryItemService` (linked finished-stock items: only reorder level, perishable and lot tracking can change; unit locked once there is history), `QuantityInput`.
- HTTP / permissions: stock items (`inventory.view` / `inventory.adjust`), counts and adjustments (`inventory.adjust`, approve/reject `inventory.approve-adjustment`), waste (`inventory.adjust`), dispatches (`inventory.view`, create `inventory.adjust`). Other locations answer 404.
- UI: Stock on hand — Count / Record waste / Dispatch buttons, low-stock alert, "Low stock only" filter, "reorder at" line; Counts & adjustments list, form and detail (Approve / Reject); Record waste; Stock items list and form; Dispatches list, form and detail; sidebar links.
- Docs: DECISIONS §X (D-131–D-134), ERD, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **281 passed** (was 271) |

**Manual checks (browser, scratch DB `brevon_pos_test`, signed in as `harris`):** dispatched 8 + 6 doughnuts from BRV01 to POP01 (BRV01-…-D01, both balances moved); a count of one item 4 → 3 posted straight away as ADJ01 for an Owner; the low-stock alert and filter showed the item under its reorder level; wrote off 2 Chocolate Sprinkles at POP01 ("Unsold at closing") and landed on the pop-up's waste movements (6 → 4), with the location switcher showing POP01. No console errors. The user's `brevon_pos` was not touched.

**Problems found and fixed during the phase**

- A page prop named `location` hid the shared working-location prop, so the switcher said "No location assigned"; the prop is now `site`.
- After writing off waste at a pop-up, the page went to the main branch's (empty) movements; it now switches to that location first, or stays on the form for an ended pop-up.
- A migration index name was too long for MySQL; indexes now have short names.

**Known issues / deferred**

- 7b still to do: suppliers, purchase orders, partial and full receiving (T-INV-4, T-PO-1), lots and expiry, valuation. OI-018 stays deferred (FE-01).
- Still to confirm with the owner: the Phase 6 limits (₱500.00 approval, ₱0.00 review). D-101 client menu questions: raise again after Phase 9.
- The user's `brevon_pos` needs `php artisan migrate` (Phase 4b tables, D-121, Phase 6 and Phase 7a).

**Next step:** Phase 7b — purchasing, after user review.

## Phase 7b log — Purchasing (2026-11-06)

**Objective:** buy stock properly: suppliers, purchase orders a manager approves, deliveries received in full or in parts (with damaged goods and over-deliveries handled), lot and expiry tracking, and the value of stock on hand.
**SRS IDs:** FR-056 (receiving), FR-058, FR-059, FR-060, FR-063, UC-006; T-INV-4, T-PO-1.

**Implemented**

- Schema (`2026_11_06_140000`): `suppliers`, `purchase_orders` / `purchase_order_items`, `goods_receipts` / `goods_receipt_items`, `inventory_lots`, and `inventory_movements.inventory_lot_id`. Additions to SRS §8.7: cancel fields on orders, `goods_receipts.supplier_id` and `over_receipt_approved_by`. CHECK rules on statuses, quantities, costs and conditions. Morph names `supplier`, `purchase_order`, `goods_receipt`, `inventory_lot`.
- Domain: `SupplierService` (codes SUP001…), `PurchaseOrders` (draft, edit, approve-and-send, cancel), `GoodsReceiving` (one transaction per delivery: receipt, lots, `purchase_receipt` movements at the delivered cost, order quantities and status), `PurchaseItems` (only ingredients, packaging and retail items, only at the main branch), `Lots` (expiry flags). `InventoryLedger` now links receipt movements to their lot and uses lots up first-expiring-first when stock leaves (D-138).
- HTTP / permissions: suppliers (`suppliers.manage`), purchase orders (view with any purchasing permission; drafts `purchase-orders.create`; send `purchase-orders.approve`; cancel a sent order needs approve), deliveries (`purchase-orders.receive` against an order, `inventory.receive` without one), Lots & expiry (`inventory.view`), Stock value (`reports.inventory`). Other locations answer 404.
- UI: Suppliers list and form; Purchase orders list, form (pick items, live totals), detail (approve, receive, cancel with reason, deliveries); Deliveries list, receive form (from an order with "Everything due arrived", or without an order; damaged/expired, lot and expiry, accept extra), detail; Lots & expiry; Stock value; expiry alert on Stock on hand; sidebar "Purchasing" section. Unit costs now show up to 4 decimal places.
- Docs: DECISIONS §Y (D-135–D-139), ERD §5, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **292 passed** (was 281) |

**Manual checks (browser, scratch DB `brevon_pos_test`, signed in as `harris`):** created a supplier-backed order for 10,000 g flour at ₱0.05 and 2,000 g cream at ₱0.30 (total ₱1,100.00), approved it (Ordered); received 6,000 g flour and 1,000 g cream (lot L-01, expiring in 3 days) plus 200 g damaged cream (Partly received; stock on hand showed the expiry alert); received 4,000 g flour at ₱0.06 and 1,200 g cream — the extra was refused until "Accept more than ordered" was ticked and a note given (Received; "accepted by Harris" on the receipt). Stock value showed flour at ₱0.054 average (₱540.00) and a ₱1,200.00 total; Lots & expiry listed L-01 (Expiring soon) and L-02 (In date). No console errors. The user's `brevon_pos` was not touched.

**Problems found and fixed during the phase**

- Average costs per gram showed as ₱0.05 instead of ₱0.054 because money is shown to 2 places; costs per unit now show up to 4 places (stock, value, orders, deliveries, adjustments).
- The over-delivery message showed "1000.000 g"; it now says "1,000 g".

**Known issues / deferred**

- To confirm with the owner: weighted average as the costing method (D-048, OI-014) and the 7-day "expiring soon" window (D-138). Lot quantities left are an estimate (stock is not picked by lot at the counter).
- No negative-stock override in V1 (D-047 stays "blocked").
- Still to confirm: the Phase 6 limits (₱500.00 approval, ₱0.00 review). D-101 client menu questions: raise again after Phase 9.
- The user's `brevon_pos` needs `php artisan migrate` (Phase 4b tables, D-121, Phase 6, 7a and 7b).

**Next step:** Phase 8 — recipes and production, after user review.

## Phase 8 log — Recipes and production (2026-11-06)

**Objective:** say what each doughnut and drink uses, record batches of doughnuts made at the main branch (ingredients out, good pieces in, rejects noted), and take drink ingredients out of stock when drinks are sold.
**SRS IDs:** FR-064–FR-072; T-PRD-B1–B6.

**Implemented**

- Schema (`2026_11_06_150000`): `recipes` (versioned; `product_variant_id` + generated `variant_key` for size recipes, a schema addition), `recipe_items`, `production_batches` (reject quantity and reason, cancel fields, cost per piece), `production_batch_items`. CHECK rules on versions, quantities, wastage and statuses. Morph names `recipe`, `production_batch`. `waste_records` is not created (D-143).
- Domain: `RecipeBook` (which products and items can have recipes, the recipe in use for a product and size, saving a new version, ingredients for a quantity with wastage and rounding), `ProductionBatches` (draft, edit, post in one ledger call with cost snapshots, cancel). `CheckoutService` now takes recipe-at-sale drink ingredients at checkout (D-141).
- HTTP / permissions: Recipes (`recipes.manage`), Production (view with `production.create` or `production.post`; drafts `production.create`; post `production.post`). Batches at other locations answer 404. "Save and post" runs in one transaction.
- UI: Recipes list (with a "Without a recipe only" filter), recipe history per product (each version and its lines, a button per size for drinks), recipe form; Production list, batch form (ingredient use worked out live from the recipe for good + rejected pieces, with on-hand shown and short items in red), batch detail (post, edit, cancel with reason, costs once posted); sidebar "Production" section.
- Docs: DECISIONS §Z (D-140–D-144), ERD §6, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **306 passed** (was 292) |

**Manual checks (browser, scratch DB `brevon_pos_test`, signed in as `harris`):** with 5,000 g flour at ₱0.05 and 30 eggs at ₱9.00 in stock, added a Choco Custard recipe (makes 12 pc: flour 1,000 g + 5% wastage, 4 eggs) — saved as version 1. New batch: planned 12, good 10, rejected 2 (Burnt); the form showed 1,050 g flour and 4 eggs; "Save and post" posted BRV01-20261106-PB01 with a cost of ₱8.85 per piece. A second batch of 100 was refused with "Not enough Bread flour in stock: 3,950 g on hand, 8,750 g needed" and left no draft; the Production list showed the one posted batch. No console errors. The user's `brevon_pos` was not touched.

**Problems found and fixed during the phase**

- "Save and post" first created the draft and posted it separately, so a failed post left a draft behind (and a retry made a duplicate); both now run in one transaction.
- The not-enough message showed "3950.000"; it now says "3,950 g".
- Editing a draft brought back every saved amount as a manual change, so changing the good count no longer recalculated; amounts equal to the recipe are now left to the recipe.

**Known issues / deferred**

- Add-on options (extra shot, oat milk) do not take ingredients in V1 (D-141). Drinks without a recipe take nothing until one is entered.
- Doughnut cost covers ingredients only, at weighted average (OI-014 still to confirm).
- Still to confirm: Phase 6 limits (₱500.00 approval, ₱0.00 review), the 7-day expiry window. Re-review Phase 7 at the end of development (client changes). D-101 client menu questions: raise again after Phase 9.
- The user's `brevon_pos` needs `php artisan migrate` (Phase 4b tables, D-121, Phase 6, 7a, 7b and 8).

**Next step:** Phase 9 — refunds, discounts and tax configuration, after user review.

## Phase 9a log — Discounts and tax (2026-11-06)

**Objective:** give discounts at the counter under control (presets, manager approval for big ones), record the Senior Citizen / PWD discount the way the owner decided (D-068), and have a tax framework ready but switched off. Phase 9 is split: refunds and voids are 9b.
**SRS IDs:** FR-044 (discounts), FR-083–FR-087, BR-018; T-DSC-1, T-SCD-1, T-SCD-2, T-TAX-1, T-PRIV-1 (amended).

**Implemented**

- Schema (`2026_11_06_160000`): `discounts` (+ `kind`, `product_id`, `category_id`), `order_discounts` (one per order; + type/value snapshots), `order_discount_beneficiaries` (encrypted SC/PWD details), `tax_rules`, `order_tax_lines` (+ `inclusive_snapshot`). CHECK rules. Morph names `discount`, `order_discount`, `tax_rule`. `DiscountSeeder` adds the SC/PWD discount switched off.
- Permissions: new `discounts.approve` (Owner, Admin, Manager) and `discounts.view-beneficiary` (Owner, Admin); Cashiers now get `pos.apply-discount` by default. Store setting "Approve discounts over" (₱100.00).
- Domain: `DiscountCalculator` (eligibility, maths, allocation, beneficiary checks), `DiscountApproval` (who must approve; manager sign-in at the counter, rate limited), `DiscountPresets`, `TaxCalculator`, `TaxRules`. `CartPricer` applies the discount then tax; `CheckoutService` locks limited discounts, checks approval, saves the order discount, beneficiary, per-line shares and tax lines.
- HTTP / UI: Discounts and Tax rules screens (sidebar › Administration); POS "Discount" button and dialog (presets, SC/PWD details and items), "Manager approval needed" box, subtotal / discount / tax breakdown; receipts show the discount (with masked beneficiary and approver) and tax lines; the order page shows the discount and, for owners, "Show full details (logged)".
- Docs: DECISIONS §AA (D-145–D-149), ERD §3, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **321 passed** (was 306) |

**Manual checks (browser, scratch DB `brevon_pos_test`):** as `cashier1`, 2 Choco Custard (₱88.00) with "Staff meal 20%" (needs a manager): the cart showed −₱17.60 and ₱70.40, and payment stayed blocked until `harris` approved in the approval box; paid ₱100, change ₱29.60. Then 2 Kitkat (₱98.00) with Senior Citizen / PWD for "Maria Clara Santos": −₱11.76, total ₱86.24, no approval needed. The cashier's order page and receipt showed "M*** C*** S***" and "••••9123" with no way to see more; signed in as `harris`, "Show full details" showed the name, ID and address. The Discounts screen showed one use each. No app errors in the console. The user's `brevon_pos` was not touched.

**Known issues / deferred**

- To confirm with the owner/accountant: the SC/PWD rate (12% chosen by the owner vs the 20% + VAT exemption in law) and its privacy notice and retention (OI-007, OI-019) — the discount stays off until then; the ₱100.00 discount approval limit; any tax rule (none is on).
- One discount per order; no typed-in amounts at the counter; one SC/PWD beneficiary per order.
- On the user's `brevon_pos`: run `php artisan migrate`, then `php artisan db:seed --class=RolesAndPermissionsSeeder` and `php artisan db:seed --class=DiscountSeeder`. Existing roles keep their customised permissions, so give Cashier `pos.apply-discount` and Manager `discounts.approve` on the Roles screen (Owner gets everything automatically).
- Still to confirm: Phase 6 limits (₱500.00, ₱0.00), the 7-day expiry window. Re-review Phases 7 and 8 at the end of development. D-101 client menu questions: raise after Phase 9 (9b).

**Next step:** Phase 9b — refunds and voids, after user review.

## Phase 9b log — Refunds and voids (2026-11-06)

**Objective:** give money back on completed sales under control (by item, approved, traceable, drawer and stock correct), and give cancellations a void record. This finishes Phase 9.
**SRS IDs:** FR-041–FR-045, FR-077, BR-005, BR-008, UC-005; T-RFD-1–T-RFD-4.

**Implemented**

- Schema (`2026_11_06_170000`): `refunds` (+ `kind`, `cashier_shift_id`, `reference_number`) and `refund_items` per SRS §8.4, CHECK rules, morph name `refund`. Store setting "Refunds without approval up to" (₱0.00).
- Domain: `Refundables` / `RefundableLine` (what is left per line, D-151), `RefundApproval` (manager sign-in at the counter, rate limited), `RefundService` (refund of a completed order; void record for cancellations), `CashDrawer::giveRefund` (cash refund movement), `RefundPanel` (order page). `OrderWorkflow::cancel` writes void records. `ExpectedCash` and the shift summary count refunded payments as taken and subtract cash refunds. Payments now refuse any change except status and checking.
- HTTP / UI: `POST /orders/{order}/refunds` (`refunds.request`); "Refund" button and dialog on the order page (steppers per item, "Everything left", "Put back in stock" for ready-made items, reason, GCash reference, manager approval box, live total); "Refunds and voids" list; receipts list refunds and print "REFUNDED IN FULL".
- Docs: DECISIONS §AB (D-150–D-155), ERD §3, TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **329 passed** (was 321) |

**Manual checks (browser, scratch DB `brevon_pos_test`):** as `cashier1`, a completed cash sale of 3 Chocolate Sprinkles and 1 Strawberry Sprinkles (₱176.00). Refund dialog: one Chocolate Sprinkles = ₱44.00, the manager approval box appeared, "Put back in stock" ticked, `harris` approved. The order page listed "Refund BRV01-20261106-R0001 −₱44.00 · Cash, approved by Harris, back in stock"; the receipt showed "Partly refunded" and the refund line. Expected drawer cash ₱1,132.00 (1,000 + 176 − 44); one `return_in` and one `cash_refund` linked to the refund. No app errors in the console. The user's `brevon_pos` was not touched.

**Known issues / deferred**

- `DiscountSeeder` had been changed after 9a to seed SC/PWD switched on; on the user's instruction it is back to off (D-148), which fixed the one failing 9a test.
- To confirm with the owner: the ₱0.00 refund approval limit (OI-011); refunds of cash go out of the refunder's own drawer.
- Refunds are by item and quantity only (no typed-in amounts or goodwill refunds); one payment per order, so one method per refund. Cancellations before 9b have no void record. Refund and void reports come with reporting (Phase 10).
- On the user's `brevon_pos`: run `php artisan migrate`. Refund permissions were already seeded in Phase 2.
- Still to confirm: Phase 6 limits (₱500.00, ₱0.00), the 7-day expiry window, the 9a items. Re-review Phases 7 and 8 at the end of development.

**Next step:** Phase 10 (reports), after user review — and the D-101 client menu questions now that Phase 9 is done.

## Phase 10a log — Dashboard and sales reports (2026-11-06)

**Objective:** give the owner and managers sales figures they can trust: a dashboard, sales by period, product, category, cashier and payment method, with clear definitions, location and other filters, CSV export and print. Phase 10 is split: operational reports are 10b.
**SRS IDs:** FR-088–FR-092, FR-095–FR-097, FR-105, FR-108, UC-009, NFR-009; T-RPT-1–T-RPT-4.

**Implemented**

- Domain `app/Domain/Reports`: `ReportScope` (locations a user may report on, filter validation), `ReportFilters` (Manila business dates, UTC range), `SalesFigures` (one definition of gross/discounts/refunds/net/tax/orders/average), `SalesReport` (summary, by period/product/category/cashier, collections — all summed in SQL from order lines), `Dashboard`. No schema change.
- HTTP / UI: `/admin/dashboard` (was a placeholder) and `/admin/reports/sales` with quick dates and filters, the definitions on screen, five tables, CSV export per table (`/admin/reports/sales/export/{section}`, audited) and print; sidebar › Reports › Sales report.
- Docs: DECISIONS §AC (D-156–D-159), TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **334 passed** (was 329) |

**Manual checks (browser, scratch DB `brevon_pos_test`):** signed in as `harris` after two ₱176.00 cash sales by `cashier1`. Dashboard: gross and net ₱352.00, 2 orders, average ₱176.00, low stock 0, one day bar, best sellers Chocolate Sprinkles (6, ₱264.00) and Strawberry Sprinkles (2, ₱88.00). Sales report: every table adds up to ₱352.00; Week grouping shows "Week of Nov 2, 2026"; the products CSV downloaded with the right header, rows and file name. Zero discounts showed as "−₱0.00" and were fixed to "₱0.00". No app errors. The user's `brevon_pos` was not touched.

**Known issues / deferred**

- To confirm with the accountant: the definitions (D-156), especially counting sales on the sale date and refunds against the sale date (the refunds report in 10b will add the refund-date view).
- 10b: refunds/voids/discounts, cash variance, inventory movement, production and waste reports; XLSX; print layouts. NFR-009 (5 s) not load-tested yet; queries are single grouped SQL statements on indexed `orders (branch_id, business_date, status)`.
- Category reporting uses each product's current category.
- Nothing to migrate on the user's `brevon_pos` for 10a (apart from 9b's migration if not run yet).

**Next step:** Phase 10b — operational reports (approved 2026-11-06).

## Phase 10b log — Operational reports (2026-11-06)

**Objective:** finish the reporting module with the operational reports managers use to check the day: refunds and voids, discounts, cash differences, stock in and out, production and waste, with CSV and Excel export and print.
**SRS IDs:** FR-093, FR-094, FR-096, FR-097, UC-009; T-RPT-1–T-RPT-3.

**Implemented**

- Domain `app/Domain/Reports`: `OperationsReports` (the six reports and their permissions, D-160–D-162), `ReportTable` (columns with types, rows, totals), `ReportExporter` (CSV with BOM and formula guard; XLSX via `openspout/openspout` ^5.13, text always as text cells, D-163). No schema change.
- HTTP / UI: `/admin/reports/{report}` (one shared `Operations.vue` page: cards, notes, tables, CSV/Excel, print) and `/admin/reports/{report}/export/{table}` (needs `reports.export`, throttled, audited). The sales report's exports now offer Excel too. Six new sidebar entries under Reports.
- Docs: DECISIONS §AD (D-160–D-163), TEST_PLAN map, README.

**Verification (all run 2026-11-06)**

| Command                                                            | Result                   |
| ------------------------------------------------------------------ | ------------------------ |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors       |
| → `php artisan test` (MySQL)                                       | **340 passed** (was 334) |

**Manual checks (browser, scratch DB `brevon_pos_test`):** signed in as `harris` after a ₱176.00 cash sale by `cashier1` (opening stock 12 each). Stock in & out: 14 items, 16 movements; Chocolate Sprinkles 0 → 12 received → 3 sold → 9, the others 12. The Excel export downloaded (correct content type, file name and zip signature). The discounts page showed its empty state ("No discounts in these dates", ₱0.00). Refunds, cash differences, production, waste and sales all loaded without errors. There were no console errors. The user's `brevon_pos` was not touched.

**Known issues / deferred**

- Waste is valued at today's average cost (D-162).
- NFR-009 (5 s) is not load-tested yet. Each report runs grouped SQL over indexed columns.
- New dependency: `openspout/openspout`. Run `composer install` on the user's copy. Nothing to migrate.

**Change after review (2026-11-06): tax rules made a later feature (D-164).** The client does not need tax now. A new setting, `BREVON_FEATURE_TAX_RULES` (default off; `EnsureFeatureEnabled` middleware with the `feature:` alias), hides the Tax rules screen (404 and no sidebar link) and makes checkout ignore every tax rule. The sales report and its exports drop the Tax column. The code and tables stay for later. `composer check`: **341 passed**. Browser (scratch): no Tax rules link, `/admin/tax-rules` 404, sales report without tax text.

**Next step:** Phase 11 (10b approved 2026-11-06). Split into **11a** feature removals and **11b** hardening and release readiness. 11a covers removing tax (D-165), options & add-ons (D-166), lots & expiry (D-167), the GCash check (D-168) and preparation (D-169); plan in IMPLEMENTATION_PLAN.md.

## Phase 11a log — Feature removals (2026-11-07)

**Objective:** remove five features the client does not need: tax (D-165), options & add-ons (D-166), lots & expiry (D-167), the GCash check (D-168), and preparation with the Cancel action (D-169). Each removal was followed by the full test suite.
**Approved:** "Approve, start 11" (2026-11-06).

**Implemented**

- **Tax (D-165):** deleted `app/Domain/Tax`, `TaxRule`, `OrderTaxLine`, `TaxRuleController`, the tax screen, routes and sidebar link, the D-164 feature flag (`config`, `.env.example`, `EnsureFeatureEnabled`, shared `features` prop). Grand total = subtotal − discount. Receipts, sales report and exports have no tax. Migration `2026_11_07_100000_remove_tax`.
- **Options & add-ons (D-166):** deleted option groups, product options, `OptionGroupService`, their screen, routes and sidebar link. The POS item dialog has sizes, quantity and instructions only; line total = unit price × quantity. Migration `2026_11_07_100100_remove_options`.
- **Lots & expiry (D-167):** deleted `InventoryLot`, `Lots`, the Lots & expiry screen and the expiry alert; deliveries take no lot or expiry; "Track lots" is gone from stock items. `is_perishable`, the "expired" rejection and waste write-offs stay. Migration `2026_11_07_100200_remove_lots`.
- **GCash check (D-168):** deleted `PaymentCheckController`, the GCash to check page and routes, `payments.verify-ewallet`, the "waiting" figures in reports and the shift summary. The POS payment dialog needs "I checked that this payment arrived" (D-172). Migration `2026_11_07_100300_remove_gcash_check`.
- **Preparation and Cancel (D-169):** deleted `PreparationBoard`, `PreparationController`, `OrderStatusController`, `OrderWorkflow`, the preparation page, `PrepOrderCard`, `CancelOrderDialog`, `StaffLayout`, the routes, `orders.update-status` and `orders.cancel`. Every sale is `completed` at checkout; no pickup time; the order page offers Refund only. Preparation Staff land on Production (D-173). Migration `2026_11_07_100400_remove_preparation`.
- **Migrations (D-170, D-171):** each refuses to run if data would be lost (tax lines, sales with add-ons, GCash waiting, orders in progress); lots are dropped as accepted. `orders.pickup_at` is dropped only if empty.
- Docs: DECISIONS (D-165–D-169 done, replaced decisions marked, §AE D-170–D-173), ERD, TEST_PLAN 11a map, README.

**Verification (all run 2026-11-07)**

| Command                                                            | Result                                                   |
| ------------------------------------------------------------------ | -------------------------------------------------------- |
| `composer check` → build, `vp check`, `vue-tsc`, Pint, Larastan L7 | all pass, 0 errors                                       |
| → `php artisan test` (MySQL)                                       | **330 passed** (was 341; removed-feature tests replaced) |

**Manual checks (browser, scratch DB `brevon_pos_test`, freshly migrated and seeded):** as `cashier1`, the coffee item dialog shows sizes, quantity and "How to make it" only. An Advance pickup order shows no pickup field. GCash needed the reference and the "payment arrived" tick, and the sale finished as **Completed** with no "Sent for preparation" line. The order page offered only **Refund**, the status history had one step (Completed), and the receipt had no tax or pickup line. As `harris`, the sidebar has no Preparation, GCash to check, Options, Lots or Tax rules links. The sales report showed ₱286.00 (₱176.00 + ₱110.00). No console errors on a fresh load. The user's `brevon_pos` was not touched.

**For the user's `brevon_pos` (not run by Claude):** the five migrations stop with a message if data would be lost. A read-only check found no add-ons, tax lines, lots or pickup times, but **3 orders still New** (BRV01-20261106-0002, -0003, -0004) and **1 GCash payment waiting** (-0004, ₱110.00). The old screens are gone, so the new one-off command `brevon:finish-open-orders` settles them (D-174). The list-only run shows exactly those four changes. Steps: `composer install` → `php artisan brevon:finish-open-orders --user=harris --force` → `php artisan migrate`.

**Change (2026-11-07): SC/PWD discount seeded on (D-175).** `DiscountSeeder` now creates it switched on; an existing discount is left as it is, so the user's `brevon_pos` keeps its current setting (turn it on under Discounts). `DiscountTest::test_the_pos_offers_discounts_that_are_on_and_the_seeded_sc_pwd_starts_on` checks it starts on and that re-seeding keeps an owner's off switch.

**Change (2026-11-07): three built-in roles (D-176).** Admin merged into Owner and Preparation Staff removed (`PermissionCatalog`, `AccessGuard::SYSTEM_ROLES`, migration `2026_11_07_100500_merge_admin_into_owner_and_remove_preparation_staff`: Admin holders become Owners; refuses while anyone holds Preparation Staff). Tests that used those roles now use custom roles from `InteractsWithStaff` (`officeRole()` = the old Admin permissions, `bakerRole()` = the old Preparation Staff permissions), so the Owner protections and permission checks stay covered; `RoleAdministrationTest` checks the three roles and the migration. `composer check`: **331 passed** (the role matrix lost two roles). Browser (scratch): Roles shows Cashier, Manager, Owner; the new-user form offers the same three. The user's `brevon_pos` has no Admin or Preparation Staff users, so the migration will run cleanly there; it runs with the other 11a migrations.

**Change (2026-11-07): sidebar.** Catalog moved just above Administration.

**Change (2026-11-07): seven categories (D-177).** Regular, Premium, Assorted boxes, Coffee, Matcha series, Lemonade series and Combos, all top level; Doughnuts, Cold Drinks and Other removed (`CatalogReferenceSeeder`, `MenuSeeder`, migration `2026_11_07_100600_flatten_categories`). The POS shows one tab per category; on All and in search results each category gets its own heading (`pos/Index.vue`). Browser (scratch): tabs All, Regular, Premium, Assorted boxes, Coffee, Matcha series, Lemonade series, Combos; All shows the seven headings in that order.

**Change (2026-11-07): local SM Rosales pop-up and Cashier 2 (D-178).** New `LocalLocationsSeeder` (local only: pop-up SM Rosales and terminal SM Rosales POS, with the main branch's doughnuts copied); `LocalUsersSeeder` adds Cashier 2 at SM Rosales; `DatabaseSeeder` runs them after `MenuSeeder`. `AccountSeedersTest` checks the location, terminal, copied doughnuts, audit entries and both cashiers' locations and sign-in, and that none of it is created outside local. `composer check`: **332 passed**. Browser (scratch, `migrate:fresh --seed`): seeding printed `SM Rosales (POP01)` and `SM Rosales POS (POS-2)`; `cashier2` was offered only POS-2, opened a shift and the POS showed `POP01 · SM Rosales` with the 14 doughnuts (Regular, Premium), sold out until stock is dispatched. The user's `brevon_pos` was not touched; run `php artisan db:seed --class=LocalUsersSeeder` there to add them.

**Change (2026-11-07): copy skips archived products (D-179).** `copyAvailabilityFromMain` adds `is_active = true`. `PopUpManagementTest::test_copying_availability_only_brings_active_finished_stock_products_on_sale_at_main` checks that archived, switched-off-at-main, not-at-main and non-stock products are skipped, both when a pop-up is created and by the button. `composer check`: **332 passed**. `MenuSeederTest` checks the seven and the migration (flattens an older layout, keeps an owner's own category, refuses while an old group holds a product); `ProductManagementTest` checks the reference seed. The user's `brevon_pos` has nothing in the old groups, so the migration will run cleanly there with the others.

**Change after review (2026-11-07): finish command (D-174).** `app/Console/Commands/FinishOpenOrdersCommand.php` plus `tests/Feature/Orders/FinishOpenOrdersCommandTest.php` (3 tests: list-only by default; waiting GCash received and paid orders completed with history and audit; needs an Owner or Manager and leaves unpaid orders). `composer check`: **333 passed**.

**Known issues / deferred:** none new. Held client questions are raised in the 11b report.

**Next step:** approval of 11a, then 11b (hardening and release readiness).
