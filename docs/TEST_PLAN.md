# Brevon POS — Test Plan

Baseline: SRS v1.1. Framework: PHPUnit 12 (Laravel feature + unit tests) against MySQL 8.4 database `brevon_pos_test` (D-013). Frontend: `vue-tsc` type check and production build; browser workflows verified manually per phase (Playwright/Dusk may be added in Phase 11).

## 1. Quality gate (every phase)

| Command                                                           | Purpose                          |
| ----------------------------------------------------------------- | -------------------------------- |
| `php artisan migrate:fresh --seed` on `brevon_pos_test` (guarded) | Migrations + seeders run cleanly |
| `php artisan test`                                                | All unit/feature tests           |
| `vendor/bin/pint --test`                                          | Formatting                       |
| `vendor/bin/phpstan analyse`                                      | Larastan static analysis         |
| `npm run types:check`                                             | TypeScript                       |
| `npm run build`                                                   | Production frontend build        |

A phase is not complete unless all pass, or a failure is environmental, documented, and approved.

## 2. Test inventory (by phase)

ID format `T-<area>-<n>`; each maps to SRS requirements.

### Foundation (Phase 1)

- T-MNY-1..n Money: add/subtract/multiply by decimal quantity, half-up rounding, no float input accepted, formatting `₱1,234.50` (NFR-007)
- T-SYS-1 `/up` health check responds 200
- T-SYS-2 DB guard refuses destructive commands on non-Brevon DB names

### Auth & access (Phase 2)

- T-AUTH-1 login by email; T-AUTH-2 login by username (FR-001)
- T-AUTH-3 wrong password / unknown user → same generic error (FR-002)
- T-AUTH-4 deactivated user cannot log in; active session invalidated (FR-008)
- T-AUTH-5 logout invalidates session (FR-003); T-AUTH-6 password reset flow (FR-004)
- T-AUTH-7 login throttling (UC-001 A3); T-AUTH-8 success/failure login events recorded (FR-009)
- T-ACL-1 each role → allowed/denied route matrix returns 200/403 (FR-007, NFR-003)
- T-ACL-2 user cannot grant a permission they lack; non-owner cannot assign Owner (FR-010)
- T-ACL-3 last active owner cannot be deactivated
- T-ACL-4 branch scoping: user cannot read another branch's records (FR-012, NFR-016) — **deferred to Phase 3/4** (no branch-owned records exist yet, D-083)
- T-AUD-1 role/permission change writes audit log; audit records not updatable/deletable (FR-098–FR-100)
- T-LOC-1 seeders create **exactly one** location, of type `permanent`, and no pop-ups (FR-103, prompt §13)
- T-LOC-2 users can only select locations assigned via `user_branch`; `locations.manage` cannot be granted by someone lacking it (FR-010, D-061)

**Phase 2 implementation map:** `tests/Feature/Access/LoginTest.php` (T-AUTH-1..4, 7, 8), `RouteAuthorizationTest.php` (T-ACL-1, home routing), `UserAdministrationTest.php` (T-ACL-2, T-ACL-3, audit of user changes), `RoleAdministrationTest.php` (role matrix, T-ACL-2 for roles, T-AUD-1), `AuditLogTest.php` (T-AUD-1, viewer filters by Manila day), `tests/Feature/Locations/LocationAccessTest.php` (T-LOC-1, T-LOC-2, DB constraints, no-delete), `CreateOwnerCommandTest.php`. Starter-kit `Auth/*` tests cover T-AUTH-5/6.

### Catalog (Phase 3)

- T-CAT-1 category CRUD/archive/reactivate (FR-015)
- T-PRD-1 product create/update/archive; duplicate SKU rejected (FR-016, FR-017, UC-002 A1)
- T-PRD-2 negative/malformed price rejected (UC-002 A2)
- T-PRD-3 price change creates new history row with effective dates and user; old row closed (FR-019)
- T-PRD-4 price resolver precedence: variant + location > variant > location > base (D-087)
- T-PRD-5 archived product hidden from POS but kept in history (FR-020, FR-021, BR-014)
- T-PRD-6 product/price changes audited (FR-098)
- T-SET-1 settings update requires `settings.manage`, audited

**Phase 3 implementation map:** `tests/Feature/Catalog/ProductManagementTest.php` (T-PRD-1, T-PRD-5, T-PRD-6, D-085 matrix, auto-linked stock item, variants, pop-up assortment, `CatalogQuery`), `PricingTest.php` (T-PRD-2..4, append-only rows, DB guards), `CategoryAndOptionsTest.php` (T-CAT-1, two-level limit, option groups), `StoreSettingsTest.php` (T-SET-1, logo upload/removal), `tests/Feature/Locations/PopUpManagementTest.php` (T-LOC-4..7, T-LOC-9, shared location prop regression), `TerminalManagementTest.php` (T-LOC-8 without the open-shift check), `RouteAuthorizationTest.php` (new routes in the role matrix). The open-shift parts of T-LOC-5/T-LOC-8 and the sale-date part of T-LOC-7 move to Phase 4a with shifts and sales.

- T-LOC-3 second permanent location rejected by service **and** by DB unique `permanent_guard`; `location_type` cannot be changed (FR-103, D-058)
- T-LOC-4 pop-up create/schedule requires `locations.manage`; dates optional but end ≥ start when both set; unique code format (FR-104, D-057, D-065)
- T-LOC-5 pop-up activate/deactivate/reactivate audited with before/after; deactivation needs a reason and is blocked while a shift is open there (FR-104, D-062)
- T-LOC-6 `acceptsNewOperationsOn`: permanent+active → true; active pop-up with no dates → true; inactive pop-up → false; pop-up with dates: before start / after end → false, boundaries inclusive in Asia/Manila (FR-107, D-059)
- T-LOC-7 locations have no delete route and model delete throws; operating dates cannot be moved to exclude existing sales (FR-108, D-062)
- T-LOC-8 terminal reassignment requires `locations.manage`, is blocked while the terminal has an open shift, and is audited (FR-106, D-060)
- T-LOC-9 per-location product availability and price: pop-up starts with nothing available; "copy from permanent" works; location price overrides global (D-064)

### POS checkout (Phase 4)

- T-INV-1 ledger: every movement updates balance; balance = Σ movements (BR-011, invariant 12)
- T-INV-2 negative stock blocked (FR-062, BR-013)
- T-INV-3 **concurrency**: two parallel processes sell the last unit → exactly one succeeds (separate DB connections/processes)
- T-SHF-1 cannot open second shift for same cashier/branch (FR-074)
- T-SHF-2 cash checkout without open shift rejected when enforcement on (BR-017)
- T-SHF-3 shift opening rejected at an inactive pop-up and outside its operating period; allowed at an active in-period pop-up; one open shift per terminal (FR-107, D-060)
- T-SHF-4 a shift left open past the pop-up end date can still be closed, but cannot take new sales (D-059)
- T-INV-8 stock balances/movements are per location; selling at the pop-up never touches main-branch balances (FR-105)
- T-INV-9 until Phase 7, the ledger rejects `transfer_in`/`transfer_out` and no transfer route exists (D-063)
- T-POS-1 line/subtotal/total computed server-side; tampered client price ignored (FR-026, BR-003)
- T-POS-2 inactive/unavailable/unbranched product blocks checkout (FR-027, UC-003 A1)
- T-POS-3 successful cash checkout creates order, items (snapshots), payment, status history, stock movements, shift link atomically (FR-032)
- T-POS-4 cash change = tendered − due; tendered < due rejected (FR-038, BR-006)
- T-POS-5 GCash requires reference; duplicate reference blocked at every location and by the database (D-109); verified only with permission; otherwise pending_verification (FR-039, BR-019)
- T-POS-6 **idempotency**: same key+payload returns same order, no duplicates; same key different payload → 409 (FR-031, UC-003 A4)
- T-POS-7 order numbers unique & sequential per branch/business date incl. Manila midnight boundary (FR-030, BR-016, NFR-015)
- T-POS-8 **rollback**: injected failure after payment insert leaves no order/payment/movement/sequence consumption visible (NFR-006, invariant 5)
- T-POS-9 insufficient stock → 422, nothing written (UC-003 A2)
- T-POS-10 historical snapshot unchanged after product rename/price change (BR-015, FR-022)
- T-POS-11 finished_stock product deducts finished good only, never ingredients (FR-071)
- T-POS-12 completed orders/payments have no delete route; model delete throws (FR-035, NFR-019)
- T-POS-13 receipt reprint creates nothing new (FR-034)
- T-POS-14 cashier without `pos.create-sale` → 403
- T-POS-15 complete sale at the permanent branch **and** at an active assigned pop-up; order `branch_id`/`terminal_id`/`cashier_shift_id` come from the shift even if the request body supplies a different `branch_id` (FR-012, FR-106)
- T-POS-16 checkout rejected when the pop-up was deactivated (or its period ended) after the shift opened — nothing written (FR-107)
- T-POS-17 order numbers are independent per location (main and pop-up both start at 0001 on the same day) (FR-030, D-065)
- T-PRIV-1 schema: no `customers` table; no column matching `customer%` on any table (FR-050, D-067)
- T-PRIV-2 checkout succeeds with no customer input; unknown fields such as `customer_name`/`customer_phone` in the payload are ignored and never persisted (FR-050)
- T-PRIV-3 advance pickup order saved with `pickup_at` and notes only, no contact fields (FR-049)

**Phase 4a implementation map:** `tests/Feature/Inventory/InventoryLedgerTest.php` (T-INV-1, T-INV-2, T-INV-8, T-INV-9, append-only movements, weighted average, reconcile command), `InventoryConcurrencyTest.php` (T-INV-3 with two forked processes on separate MySQL connections, plus four processes creating the same balance row; `@group concurrency`, uses `DatabaseTruncation`), `OpeningStockTest.php` (D-093, stock and movement screens per location), `tests/Feature/Shifts/ShiftTest.php` (T-SHF-1, T-SHF-3, T-SHF-4, shift numbers, location taken from the terminal, close and variance, T-LOC-5/T-LOC-8 open-shift guards), `RouteAuthorizationTest.php` (new routes). T-SHF-2 and the T-POS-* items belong to Phase 4b.

**Phase 4b implementation map:** `tests/Feature/Sales/CheckoutTest.php` (T-POS-1–T-POS-12, T-POS-14–T-POS-17, T-SHF-2, T-SHF-4 no sales after the pop-up ended, T-PRIV-1–T-PRIV-3: cash sale end to end, server pricing with variants/options and ignored client prices, snapshots, idempotent retry and 409, insufficient stock and multi-line stock totals, injected-failure rollback, GCash reference/duplicate (any location, any letter case, database UNIQUE)/verification, cash shortfall, inactive method, changed-price stop, unsellable items, option rules, no shift, pop-up sale and pop-up drinks refused, advance pickup, numbering, quote, permissions, no deletes, no customer columns); `CheckoutConcurrencyTest.php` (forked processes: last doughnut sold once; four simultaneous first-of-day sales get 0001–0004 with no deadlock; double-submit recorded once); `PaymentMethodSettingsTest.php`; `SalesAndShiftsTest.php` (expected cash = float + cash sales, closed shift takes no sales, pop-up dates vs sales). T-POS-13 (reprint) moves to Phase 4c with the receipt.

**Phase 4c implementation map:** `tests/Feature/Sales/PosScreenTest.php` (POS screen without a shift; catalog with prices, sizes, add-ons and stock left; only the shift location's products; quote `unit_total` and server change; order history by shift and order-number search; other location's orders 404 and hidden from history; receipt from snapshots after a product rename, `?auto=1`; **T-POS-13** reprint writes only an audit entry; `orders.view` required). Browser check on the scratch database: cash sale with change, GCash sale pending verification, duplicate GCash reference refused on screen, history, receipt and reprint.

### Orders & preparation (Phase 5)

- T-ORD-1 allowed/forbidden transitions; history rows with actor (FR-046, FR-051)
- T-ORD-2 prep staff can update status, cannot cancel (FR-048)
- T-ORD-3 cancellation requires reason + permission (FR-052, FR-041)
- T-ORD-4 branch-scoped active order list (FR-047)

### Shifts & cash (Phase 6)

- T-CSH-1 expected cash formula with all components (FR-077, BR-008)
- T-CSH-2 GCash excluded from drawer (FR-081, BR-007)
- T-CSH-3 variance = counted − expected (FR-079)
- T-CSH-4 closed shift immutable; correction audited (FR-082)
- T-CSH-5 cash movement requires reason; approval where configured (FR-076)

### Inventory & purchasing (Phase 7)

- T-INV-4 receiving creates movements/lots; weighted-average cost updated
- T-INV-5 adjustment needs reason + approval before posting (FR-061)
- T-INV-6 waste reduces stock with reason
- T-PO-1 partial then full receipt updates PO status; over-receipt needs approval (UC-006)
- T-INV-7 low-stock list respects reorder level (FR-057)
- T-TRF-1 dispatch main → active pop-up posts `transfer_out` + `transfer_in` atomically; insufficient main stock → nothing written (D-063)
- T-TRF-2 dispatch only from the permanent branch, only finished-good doughnut items, only to an operational pop-up; any other direction rejected
- T-TRF-3 beverage / `recipe_at_sale` products cannot be enabled at a pop-up

### Production (Phase 8)

- T-PRD-B1 posting consumes ingredients × (1 + wastage) and adds output atomically (FR-068, FR-069)
- T-PRD-B2 insufficient ingredient → nothing posted
- T-PRD-B3 posting twice (retry/parallel) posts once
- T-PRD-B4 sale of produced doughnut deducts finished good only (FR-071)
- T-PRD-B5 recipe_at_sale beverage deducts ingredients at sale (FR-072)
- T-PRD-B6 rejects/waste recorded separately (FR-070)

### Refunds/discounts/tax (Phase 9)

- T-RFD-1 refund ≤ remaining refundable amount/qty; cumulative partial refunds (invariant 8)
- T-RFD-2 refund requires permission, reason, original reference; audited (FR-042, FR-045)
- T-RFD-3 original payment untouched; refund record created (FR-043)
- T-RFD-4 cash refund hits shift drawer; return-to-stock creates return_in movement
- T-DSC-1 fixed/percentage discount math and caps; approval threshold (FR-083, FR-044)
- T-TAX-1 inclusive/exclusive tax framework math (disabled by default)
- T-SCD-1 SC/PWD discount = configured rate (default 12%) of the beneficiary's lines, no VAT computation; requires full name, card number, address (D-068)
- T-SCD-2 beneficiary details stored only on the order-discount row; no customer table, no lookup/reuse endpoint; masked for users without the view permission
- T-PRIV-1 (amended) the only personal-data columns allowed are the SC/PWD beneficiary fields on `order_discount_beneficiaries` (D-068)

### Reports (Phase 10)

- T-RPT-1 gross/discount/refund/net/collections reconcile with fixture orders (FR-097)
- T-RPT-2 date filter uses Manila day boundaries; branch filter isolates (FR-095)
- T-RPT-3 report permissions; export permission (FR-096)
- T-RPT-4 sales, shift, inventory-movement reports filter by location including a **deactivated** pop-up, whose history is still returned unchanged (FR-105, FR-108)

## 3. Concurrency testing approach

Concurrency tests (T-INV-3, T-POS-6, T-POS-7, T-PRD-B3) cannot rely on `RefreshDatabase` transactions. They use `DatabaseMigrations`/truncation on `brevon_pos_test` and spawn parallel PHP processes (or `pcntl_fork`, available locally) each with its own connection, then assert final DB state. Grouped as `@group concurrency`.

## 4. Manual verification per phase

Recorded in `IMPLEMENTATION_STATUS.md`: workflow exercised, browser, viewport, result. Automated vs manual results are reported separately.

## 5. Test data

Factories only; no production data. Demo catalog uses "[SAMPLE]" names. `BranchFactory` has `permanent()` and `popUp(start, end)` states; tests that need a second location always create a pop-up (the DB forbids a second permanent location). Time-dependent location tests freeze time (`Carbon::setTestNow`) at Manila boundary instants.
