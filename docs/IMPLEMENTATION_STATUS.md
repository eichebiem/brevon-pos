# Brevon POS — Implementation Status

Last updated: 2026-11-05 · Baseline: SRS **v1.1** (`docs/BREVON-SRS.md`) · Prompt: `CLAUDE_CODE_PROMPT_BREVON_POS_v1.1.md` (v1.3)

| Phase                                                | Status                                          | Notes                                                                  |
| ---------------------------------------------------- | ----------------------------------------------- | ---------------------------------------------------------------------- |
| 0 — Discovery and technical plan                     | ✅ Passed (docs only), re-baselined to SRS v1.1 | Approved 2026-11-05                                                    |
| 1 — Project foundation                               | ✅ Passed                                       | Awaiting user review before Phase 2                                    |
| 2 — Authentication and access control                | ⏳ Not started                                  | Includes location columns on `branches` + location assignment          |
| 3 — Catalog, settings and operating locations        | ⏳ Not started                                  | Pop-up activation, terminals, optional operating dates                 |
| 4a/4b/4c — Ledger core, checkout backend, cashier UI | ⏳ Not started                                  | Sale at main + active pop-up                                           |
| 5 — Orders and preparation                           | ⏳ Not started                                  |                                                                        |
| 6 — Cashier shifts and cash control                  | ⏳ Not started                                  |                                                                        |
| 7 — Inventory and purchasing                         | ⏳ Not started                                  | Includes main → pop-up doughnut dispatch (D-063); confirm OI-018 first |
| 8 — Recipes and production                           | ⏳ Not started                                  | Production at the permanent branch only                                |
| 9 — Refunds, discounts, tax configuration            | ⏳ Not started                                  | SC/PWD in scope; ID-data + VAT decision needed first (D-068)           |
| 10 — Dashboards and reports                          | ⏳ Not started                                  | Location filters incl. closed pop-ups                                  |
| 11 — Hardening and release readiness                 | ⏳ Not started                                  |                                                                        |

## Phase 0 log

### Run 1 — SRS v1.0 (2026-11-05)

**Commands run (read-only):** `php -v` / `php -m`, `composer --version`, `node -v`, `npm -v`, `mysql --version`, `mysqladmin ping`, `SHOW DATABASES`, `brew services list`, `laravel --version`, Packagist and npm version lookups, fetch of the official Vue starter kit `composer.json`/`package.json`/`config/fortify.php`, fetch of the live landing page CSS.
**Files created:** `docs/BREVON-SRS.md` (v1.0), `IMPLEMENTATION_PLAN.md`, `DECISIONS.md`, `ERD.md`, `TEST_PLAN.md`, `IMPLEMENTATION_STATUS.md`.

### Run 2 — re-baseline to SRS v1.1 (2026-11-05)

**Trigger:** SRS updated to v1.1 (customer data deferred; one permanent branch + temporary pop-up locations) and prompt updated to v1.3.
**Commands run (read-only):** PHP/Composer/Node/npm/MySQL/Laravel-installer version checks, `mysqladmin ping`, Packagist lookups for laravel/framework, spatie/laravel-permission, inertia-laravel, fortify, larastan. Results unchanged (PHP 8.5.4, Composer 2.9.5, Node 22.20.0, MySQL 8.4.8 alive, Laravel v13.40.0, spatie 8.3.2, inertia-laravel 3.7.0, fortify 1.41.0, larastan 3.14.0). No new blockers.
**Files changed:**

- `docs/BREVON-SRS.md` ← replaced with SRS v1.1; v1.0 moved to `docs/archive/BREVON-SRS-v1.0.md`
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
