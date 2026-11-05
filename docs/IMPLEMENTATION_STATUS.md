# Brevon POS — Implementation Status

Last updated: 2026-11-05 · Baseline: SRS **v1.1** (`docs/BREVON-SRS-v1.1.md`) · Prompt: `CLAUDE_CODE_PROMPT_BREVON_POS_v1.1.md` (v1.3)

| Phase                                         | Status                                          | Notes                                                                     |
| --------------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------- |
| 0 — Discovery and technical plan              | ✅ Passed (docs only), re-baselined to SRS v1.1 | Approved 2026-11-05                                                       |
| 1 — Project foundation                        | ✅ Passed                                       | Approved; committed by user as "Initial Commit"                           |
| 2 — Authentication and access control         | ✅ Passed                                       | Approved; committed by user ("Done Phase 2")                              |
| 3 — Catalog, settings and operating locations | ✅ Passed                                       | Approved; committed by user ("Done Phase 3")                              |
| 4a — Ledger core and shift open               | ✅ Passed — awaiting user review                | Not committed                                                             |
| 4b/4c — Checkout backend, cashier UI          | ⏳ Not started                                  | Sale at main + active pop-up                                              |
| 5 — Orders and preparation                    | ⏳ Not started                                  |                                                                           |
| 6 — Cashier shifts and cash control           | ⏳ Not started                                  |                                                                           |
| 7 — Inventory and purchasing                  | ⏳ Not started                                  | Includes main → pop-up doughnut dispatch (D-063); OI-018 deferred (FE-01) |
| 8 — Recipes and production                    | ⏳ Not started                                  | Production at the permanent branch only                                   |
| 9 — Refunds, discounts, tax configuration     | ⏳ Not started                                  | SC/PWD: name, card no., address; 12%, VAT disregarded (D-068)             |
| 10 — Dashboards and reports                   | ⏳ Not started                                  | Location filters incl. closed pop-ups                                     |
| 11 — Hardening and release readiness          | ⏳ Not started                                  |                                                                           |

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
