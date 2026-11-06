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

**Phase 4c implementation map:** `tests/Feature/Sales/PosScreenTest.php` (POS screen without a shift; catalog with prices, sizes, add-ons and stock left; only the shift location's products; quote `unit_total` and server change; order history by shift and order-number search; other location's orders 404 and hidden from history; receipt from snapshots after a product rename, `?auto=1`; **T-POS-13** reprint writes only an audit entry; `orders.view` required). `PopUpManagementTest::test_pop_up_codes_are_assigned_in_order_and_never_taken_from_the_form` (D-121: next `POPnn`, form code/phone/email ignored, columns gone). `TerminalManagementTest::test_terminal_codes_are_assigned_in_order_across_all_locations` (D-122). Browser check on the scratch database: cash sale with change, GCash sale pending verification, duplicate GCash reference refused on screen, history, receipt and reprint.

### Orders & preparation (Phase 5)

**Phase 5 implementation map:** `tests/Feature/Orders/OrderWorkflowTest.php` — T-ORD-1 (one step at a time with actor; nothing after completed; stale second tap changes nothing), T-ORD-2 (preparation staff advance but cannot cancel or check payments; cashiers cannot advance or cancel), T-ORD-3 (reason required; cash refunded out of expected cash; stock returned or kept out by choice; completed orders and cash from closed shifts refused; GCash after close allowed; history note and audit), T-ORD-4 (board shows only this location's active orders; other location's order 404; Scheduled vs New by pickup time), GCash Received / Not received (D-124), order page actions per role. `RouteAuthorizationTest` now covers `/pos/orders` and `/staff/payments`.

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

**Phase 6 implementation map:** `tests/Feature/Shifts/ShiftCashTest.php` — T-CSH-1/2 (opening + cash sales + cash in − cash out − paid out; verified and pending GCash, and a cancelled cash sale, left out; summary figures), T-CSH-3 (difference without notes refused without revealing the amount; open-shift page has no expected figure; −12.50 stored), T-CSH-4 (closed shift guard; corrections by reviewers only, not on own or open shifts, audited, closing figures unchanged, corrected figures calculated), T-CSH-5 (reason/amount/type rules, drawer can't go below zero, over-limit outflow pending, pending blocks closing, cashiers can't approve, no self-approval, reject needs a note, rejected stays in the drawer, decided rows immutable, own shift only), review once with notes (D-128), limits from Store settings, manager closes a forgotten shift, other locations 404. `RouteAuthorizationTest` now covers `/admin/shifts`.

### Inventory & purchasing (Phase 7)

- T-INV-4 receiving creates movements/lots; weighted-average cost updated
- T-INV-5 adjustment needs reason + approval before posting (FR-061)
- T-INV-6 waste reduces stock with reason
- T-PO-1 partial then full receipt updates PO status; over-receipt needs approval (UC-006)
- T-INV-7 low-stock list respects reorder level (FR-057)
- T-TRF-1 dispatch main → active pop-up posts `transfer_out` + `transfer_in` atomically; insufficient main stock → nothing written (D-063)
- T-TRF-2 dispatch only from the permanent branch, only finished-good doughnut items, only to an operational pop-up; any other direction rejected
- T-TRF-3 beverage / `recipe_at_sale` products cannot be enabled at a pop-up

**Phase 7a implementation map:** `tests/Feature/Inventory/StockControlTest.php` — T-INV-5 (reason and quantity rules; pending until approved; difference posts on top of sales made meanwhile; approver's own posts at once; no self-approval; reject needs a note; approval below zero refused; decided adjustments immutable; reconcile), T-INV-6 (waste with reason, not more than on hand, audited; cashier forbidden; ended pop-up with stock can write it off, then disappears), T-INV-7 (low count and filter), T-TRF-1 (out + in at main's average cost, numbering, refused dispatch writes nothing, reconcile), T-TRF-2 (only assigned active pop-ups, not main or ended; doughnuts only; ledger refuses stray transfers), stock items (D-134), location scoping (404). T-TRF-3 is covered by the Phase 3 availability tests. `RouteAuthorizationTest` now covers items, adjustments and dispatches. T-INV-4 and T-PO-1 come with 7b.

**Phase 7b implementation map:** `tests/Feature/Purchasing/PurchasingTest.php` — T-INV-4 (receipt movement at delivered cost referencing the goods receipt; weighted average 0.05 → 0.054 after a dearer second delivery; lots created and linked; reconcile), T-PO-1 (partial → partly received → received; nothing more after; over-delivery refused without the box, refused for a non-approver, needs a note, then records the approver), suppliers (automatic codes, switched off not deleted, inactive gets no orders, `suppliers.manage` only), drafts (doughnuts refused, quantity and cost rules, subtotal rounding, edits; only an approver sends; fixed once sent), damaged/expired lines noted but not stocked and still due, lots (lot or expiry required, past/invalid expiry refused, first-expiring-first use, expiry flags on Stock on hand and Lots), receipts without an order (`inventory.receive`), cancelling (reason; sent orders need an approver; received stock kept), stock value (weighted average, uncosted flagged, `reports.inventory`), location scoping (404). `RouteAuthorizationTest` now covers suppliers, purchase orders, deliveries, lots and stock value.

### Production (Phase 8)

- T-PRD-B1 posting consumes ingredients × (1 + wastage) and adds output atomically (FR-068, FR-069)
- T-PRD-B2 insufficient ingredient → nothing posted
- T-PRD-B3 posting twice (retry/parallel) posts once
- T-PRD-B4 sale of produced doughnut deducts finished good only (FR-071)
- T-PRD-B5 recipe_at_sale beverage deducts ingredients at sale (FR-072)
- T-PRD-B6 rejects/waste recorded separately (FR-070)

**Phase 8 implementation map:** `tests/Feature/Production/ProductionTest.php` — T-PRD-B1 (12 pieces: flour 1000 g × 1.05 = 1050 g, 4 eggs, 10 good added; 3 movements referencing the batch; cost per piece 8.85; snapshots; reconcile), ingredient use changed per item and unknown cost leaves the piece cost unknown, T-PRD-B2 (not enough flour: save-and-post leaves no draft; posting a draft fails with nothing moved), T-PRD-B3 (posts once; posted batches cannot be edited or cancelled; model guard), drafts edited (ingredients worked out again) and cancelled with a reason, T-PRD-B6 (reject reason required; whole batch rejected uses ingredients and adds nothing), recipe versioning (old version closed, batches keep theirs, never deleted), recipe line rules, T-PRD-B4 (doughnut sale takes only the doughnut), T-PRD-B5 (drink takes ingredients at checkout, size recipe first; short milk refuses the sale; cancelling returns them; no recipe takes nothing), permissions (prep drafts, manager posts, only Owner/Admin edit recipes), main branch only (404), pages render. `RouteAuthorizationTest` now covers recipes and production.

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

**Phase 9a implementation map:** `tests/Feature/Sales/DiscountTest.php` — T-DSC-1 (12.5% of ₱167 = ₱20.88 spread ₱16.50 / ₱4.38; fixed capped at the lines it covers; product and category scope; snapshots survive preset edits; allocation always adds up; percent rounding), validity (off, not started, ended, unknown, used up; a cancelled order gives its use back), FR-044 approvals (over the store limit or flagged needs a manager; wrong password, the cashier themself, or a manager from another location refused; 5 tries a minute; approver recorded; a manager at the counter approves their own; the limit is a setting), T-SCD-1 (12% of the senior's items only, details required and validated, stored encrypted, audit masked, immutable), T-SCD-2 (masked on the order page and receipt; full details only to `discounts.view-beneficiary`, audited; 403 otherwise), T-PRIV-1 (schema scan: personal-data columns only on `order_discount_beneficiaries`, besides staff and supplier contacts), T-TAX-1 (off by default; 12% included = ₱12.00 on ₱112 with the total unchanged; 5% added on top after a discount; rules off or outside their dates not charged; snapshots), idempotent retry with a discount, presets and tax rules screens (validation, codes unique, SC/PWD fields fixed, never deleted, `settings.manage` only), POS offers only discounts that are on, SC/PWD seeded off. `RouteAuthorizationTest` now covers discounts and tax rules.

**Phase 9b implementation map:** `tests/Feature/Orders/RefundTest.php` — T-RFD-1 (3 Choco + 1 Glazed less 12.5% = ₱146.12: one Choco ₱38.50, the other two exactly ₱77.00, the Glazed ₱30.62; refunds add up to ₱146.12; too many refused; order and payment become Refunded; nothing left after), T-RFD-2 (403 without `refunds.request`, 404 at another location, reason required, manager approval required and checked — wrong password, another location's manager refused; approver recorded; audited `refund.processed` / `payment.refunded`; the Store settings limit lets small refunds through; a stale screen cannot refund twice; orders still being made are cancelled instead), T-RFD-3 (payment amount, cash received and change unchanged; payment `partially_refunded`; refund linked to order and payment; payment and refund rows refuse edits), T-RFD-4 (expected cash ₱1,146.12 → ₱1,038.50 with the sale kept in cash sales and a `cash_refund` linked to the refund; only the ticked Choco goes back to stock as `return_in`; made drinks cannot; no open shift here or a closed shift → refused; a manager's own shift drawer pays out), GCash refunds (not before the payment is checked; the reference sent back is required and validated; no drawer effect), cancellations write a void record and the drawer counts once, the order page shows what is left and the history, the receipt lists refunds.

**Phase 10a implementation map:** `tests/Feature/Reports/SalesReportTest.php` — T-RPT-1 (fixture: discounted sale with a refund, a plain sale, a GCash sale waiting, a cancelled and a not-received order left out → gross ₱281.00, discounts ₱20.88, refunds ₱38.50, net ₱221.62, 3 orders, average ₱73.87; products, categories, cashier and period rows add up; collections cash ₱216.12 received − ₱38.50 given back = ₱177.62, matching the drawer; GCash ₱44.00 waiting; category, method and cashier filters; dashboard shows the same), T-RPT-2 (business-date filter and month grouping; from after to refused; Manila day → UTC range for refunds; main and pop-up kept apart; a manager only sees assigned locations), T-RPT-3 (cashier and prep refused for dashboard, report and export; report without `reports.export` hides and refuses CSV; CSV header and rows; formula-like names neutralised; unknown section 404; export audited), T-RPT-4 (a deactivated pop-up's sales still reported, labelled "(closed)"; dashboard per location), low-stock count. `RouteAuthorizationTest` covers the sales report.

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
