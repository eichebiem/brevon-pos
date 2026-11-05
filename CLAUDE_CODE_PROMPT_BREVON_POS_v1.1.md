# Claude Code Build Prompt — Brevon Doughnuts & Coffee POS

**Prompt version:** 1.3  
**Requirements baseline:** `BREVON_SRS_v1.2.md`  
**Prepared for:** Brevon Doughnuts & Coffee

You are a senior full-stack engineer, software architect, database designer, QA engineer, and pragmatic technical lead. Build a production-minded Point of Sale and Store Management System for **Brevon Doughnuts & Coffee** using the approved requirements below.

## 1. Primary objective

Build a working web application, not just a mockup or static UI. Implement real authentication, database persistence, server-side validation, authorization, POS transactions, payments, inventory ledger, cashier shifts, reporting, and automated tests.

The product starts with exactly one permanent branch and must also support temporary pop-up stand locations at different physical sites during selected months. Represent both as operating-location records (permanent or pop-up) so transactions, shifts, stock movements, and reports are attributed to the correct location. Do not overbuild multi-permanent-branch enterprise features.

## 1.1 Client-approved scope updates (SRS v1.2)

Apply these client decisions throughout the implementation:

- **Document owner:** The SRS is prepared by Harris.
- **Customer data:** Customer management is deferred to a future release. Version 1 must not create customer profiles or collect/store customer names, phone numbers, email addresses, or addresses. Do not create a `customers` table, customer foreign keys, or customer contact fields on orders. Advance pickup may be recorded using date/time and order notes without customer contact details. Payment reference numbers are transaction metadata, not customer profiles. If a tax, invoice, or discount workflow appears to require customer-identifying information, stop and raise it as an explicit business/legal decision; do not silently add customer data collection.
- **Operating locations:** Initial deployment has exactly one permanent branch. During selected months, the business may operate temporary pop-up stands at different physical locations. Model each pop-up as a temporary operating-location record with type `pop_up`, address, approved operating dates, and activation state. A pop-up is not a second permanent branch. The permanent branch and an approved pop-up may operate concurrently. Assign each POS terminal/cashier session to the correct operating location; every sale, shift, inventory balance/movement, and report must be attributed to that location. Prevent new sales and shift openings at a closed/deactivated pop-up or outside its approved operating period. Preserve historical records after closure/deactivation. Do not implement inter-location stock transfers until the client confirms the stock-allocation process.

Use the latest SRS version supplied by the user (currently `BREVON_SRS_v1.2.md`) as the requirements source of truth. If it conflicts with this prompt, follow the SRS and document the discrepancy for review.

## 2. Required technology

- Laravel **13.x** (`^13.0`)
- PHP **8.4 recommended**, with Laravel 13 minimum PHP 8.3. Check the actual runtime and Composer compatibility before scaffolding.
- Vue 3
- TypeScript
- Inertia.js
- Tailwind CSS
- MySQL 8.4
- Laravel authentication starter kit compatible with Laravel 13 and Inertia/Vue
- Spatie Laravel Permission only if a Laravel 13-compatible release is available; verify package compatibility before installing.
- Redis optional for cache/queues; the app must also run without Redis in local development.
- PHPUnit or Pest, using versions compatible with Laravel 13.
- Use stable, maintained dependencies. Do not blindly install the newest major versions.

Do not downgrade Laravel to an unsupported version merely to satisfy an incompatible package. If a dependency is incompatible, find a maintained alternative or implement the small required capability natively.

## 3. Project workflow — mandatory

Before writing application code:

1. Inspect the repository, existing files, Git status, installed PHP/Composer/Node versions, and current application state.
2. If an existing Laravel project is present, preserve it and assess its architecture. Do not overwrite working code.
3. If the repository is empty, scaffold Laravel 13 and configure Vue 3 + Inertia + TypeScript + Tailwind using the current official starter-kit approach.
4. Create `docs/` and save the SRS as `docs/BREVON-SRS.md`. If the SRS was provided as a separate file, inspect it and use it as the authoritative baseline. The current approved baseline filename is `BREVON_SRS_v1.2.md`.
5. Create `docs/IMPLEMENTATION_PLAN.md`, `docs/DECISIONS.md`, and `docs/TEST_PLAN.md`.
6. Identify requirement IDs and map each implementation task to one or more requirement IDs.
7. Present a concise implementation plan and important assumptions before making broad architectural changes.
8. Implement in small vertical slices. After each slice, run migrations, tests, static checks, and frontend build.
9. Do not claim a feature is complete unless it is implemented and tested.
10. Keep a progress checklist in `docs/IMPLEMENTATION_STATUS.md`.

If a business decision is unknown, use the explicit defaults in this prompt, mark it as configurable/TBC, and document it. Do not invent official prices, recipes, tax treatment, BIR compliance, or external payment integrations.

## 4. Brand and user experience

Use Brevon's landing page as visual reference: https://brevon-doughnuts.vercel.app/

The application should feel like a premium, friendly doughnut and coffee shop.

Proposed theme tokens (pending confirmation against official logo assets):

- Deep blue: `#17365D`
- Warm gold: `#D5A64A`
- Warm cream: `#FFF8EB`
- Charcoal: `#292D33`
- White/surface: `#FFFFFF`

Build two intentionally different experiences:

### Admin/Owner

- Left sidebar navigation.
- Dashboard KPI cards and charts.
- Searchable/filterable tables.
- Clear forms and confirmation dialogs.
- Reports and export actions.
- Responsive desktop/tablet layout.

### Cashier POS

- Full-screen or near-full-screen cashier layout.
- Large product tiles and category tabs.
- Fast product search.
- Persistent cart panel.
- Clear quantity controls.
- Obvious subtotal, discount, total, tendered amount, and change.
- Fast Cash and GCash payment workflows.
- Minimal navigation and minimal clicks.
- Touch-friendly controls.
- Clear success/error states.

### Preparation view

- Active orders grouped by status.
- Large, easy-to-read order cards.
- Status update controls restricted by permission.

Do not use generic dashboard template styling without adapting it to Brevon. Maintain accessibility, keyboard usability, visible focus states, and adequate contrast. Do not use emoji as the main icons; use a consistent icon library compatible with the installed stack.

## 5. Roles and authorization

Implement server-enforced role/permission checks. Suggested roles:

- Owner
- Admin
- Manager
- Cashier
- Preparation Staff

Suggested permission groups:

- dashboard.view
- pos.access, pos.create-sale, pos.apply-discount, pos.override-price
- orders.view, orders.update-status, orders.cancel
- payments.record, payments.verify-ewallet
- refunds.request, refunds.approve, voids.approve
- products.view, products.create, products.update, products.archive, products.manage-prices
- inventory.view, inventory.receive, inventory.adjust, inventory.approve-adjustment
- recipes.manage, production.create, production.post
- suppliers.manage, purchase-orders.create, purchase-orders.approve, purchase-orders.receive
- shifts.open, shifts.close, shifts.review, cash-movements.create
- reports.sales, reports.inventory, reports.financial, reports.export
- users.manage, roles.manage, settings.manage, audit.view

Seed initial roles and permissions. Seed a development-only owner account using environment variables or a documented local-only credential setup. Never hardcode a production password. Do not allow users to grant permissions they do not have.

## 6. Modules to implement

### 6.1 Authentication and users

- Login/logout.
- Secure password hashing and reset flow.
- User activation/deactivation.
- Employee profiles.
- Role/permission management.
- Branch assignment.
- Login audit events.

### 6.2 Branch and settings

- Branch master.
- Branch-aware transactions.
- Store settings: business name, logo, currency, time zone, receipt footer.
- Initial timezone `Asia/Manila`; currency `PHP`.
- Seed exactly one permanent branch. Support creating/scheduling temporary pop-up locations with approved operating dates and activation/deactivation. Treat pop-ups as temporary operating locations, not additional permanent branches. Permit the permanent branch and an approved pop-up to be active concurrently. Assign terminals/cashier sessions to a specific operating location; block new sales and shift openings for inactive/closed pop-ups or outside their approved operating period. Preserve all historical records after deactivation. Do not implement inter-location stock transfers until the client confirms the stock-allocation process.

### 6.3 Product catalog

- Categories with optional parent category.
- Products, SKU, description, image, status, product type.
- Product variants.
- Product option groups/add-ons where applicable.
- Branch product availability.
- Price history and effective dates.
- Archive rather than hard-delete products used by transactions.

### 6.4 POS and checkout

- Product search and category filters.
- Cart add/remove/update quantity.
- Product variants/options if enabled.
- Order types: walk-in, dine-in, takeout, advance pickup, bulk.
- Order notes and item preparation instructions only. Do not collect customer names, phone numbers, email addresses, addresses, or customer profiles in Version 1. Do not build customer lookup, customer selection, customer-linked order history, or a `customers` table. A cashier must be able to complete a sale without entering customer information. Customer management is a future feature requiring separate client approval.
- Server-authoritative pricing, discount, tax, and total calculations.
- Checkout with Cash and GCash.
- Cash tendered and change calculation.
- GCash reference number and manual verification status.
- Unique branch/date transaction number generated safely.
- Idempotent checkout to prevent duplicate orders.
- Receipt view and reprint.
- Completed sales cannot be deleted.
- Do not trust price, totals, permissions, or stock values sent by the browser.

### 6.5 Order and preparation management

- Order list/detail.
- Status lifecycle: pending, confirmed, preparing, ready, completed, cancelled, refunded.
- Status history with actor and timestamp.
- Advance pickup date/time without requiring customer contact details.
- Preparation queue.
- Cancellation reason and permission checks.

### 6.6 Inventory

- Inventory items, units of measure, branch balances.
- Inventory ledger/movements.
- Stock receiving and opening stock.
- Stock adjustments with reason and approval.
- Low-stock alert.
- Lot and expiration tracking where enabled.
- Waste/spoilage.
- Supplier master.
- Purchase orders and partial/full goods receiving.
- Negative stock blocked by default.

### 6.7 Recipes and production

- Product recipes/BOM with versions/effective dates.
- Ingredient quantities and units.
- Production batches.
- Post batch atomically: consume ingredients and add finished goods.
- Track planned, actual, rejected, and waste quantities.
- Prevent double deduction: for batch-produced doughnuts, deduct ingredients at production and finished-goods stock at sale. For made-to-order beverages, allow recipe deduction at sale/preparation based on configuration.
- Do not fabricate Brevon recipes; seed clearly marked examples only if necessary for UI demonstration.

### 6.8 Cashier shifts

- Open shift with opening cash.
- Associate cash sales with active shift.
- Cash-in/cash-out with reasons and approvals.
- Expected cash formula documented and tested.
- Close shift with counted cash and variance.
- Non-cash tender totals separate from physical cash.
- Closed shifts are immutable except controlled audited correction.

### 6.9 Discounts, tax and refunds

- Fixed and percentage discount framework.
- Permission/approval thresholds.
- Refund workflow linked to original sale and payment.
- Reversal records, never destructive edits.
- Tax configuration framework only.
- Senior Citizen/PWD workflow only after requirements are approved.
- Do not claim BIR compliance. Add clear TODO/configuration notes for accountant-approved invoice/tax requirements.

### 6.10 Dashboard and reports

- Today's sales, order count, average order value, low stock.
- Sales by date, product, category, cashier, and payment method.
- Refunds, voids, discounts, cash variance, inventory movement, production and waste reports.
- Date and branch filters.
- CSV/XLSX export and printable reports where feasible.
- Clearly define gross sales, discounts, refunds, net sales, tax, and collections.

### 6.11 Audit logs

Record actor, action, entity, entity ID, timestamp, and relevant before/after data for:

- price changes;
- product changes;
- inventory adjustments;
- goods receiving;
- production posting;
- voids and refunds;
- discounts/approvals;
- role and permission changes;
- shift closure and corrections;
- sensitive settings changes.

Audit history must not be editable/deletable through ordinary application screens.

## 7. Database design requirements

Create normalized Laravel migrations, Eloquent models, relationships, factories, and seeders for these logical tables. Review the SRS for full field definitions and constraints. Resolve migration order and FK cycles carefully.

### Organization and access

- branches (operating locations; `location_type` = permanent/pop_up, optional operating start/end dates)
- users
- employees
- roles, permissions, model_has_roles, model_has_permissions, role_has_permissions (or compatible package tables)
- user_branch
- terminals

### Catalog

- categories
- products
- product_variants
- product_prices
- product_branch
- option_groups
- product_option_group
- product_options

### Orders and payments

- orders
- order_items
- order_item_options
- order_status_histories
- payment_methods
- payments
- refunds
- refund_items
- discounts
- order_discounts
- tax_rules
- order_tax_lines

### Cash management

- cashier_shifts
- cash_movements

### Inventory and purchasing

- units_of_measure
- inventory_items
- branch_inventory
- inventory_lots
- inventory_movements
- stock_adjustments
- stock_adjustment_items
- suppliers
- purchase_orders
- purchase_order_items
- goods_receipts
- goods_receipt_items

### Production

- recipes
- recipe_items
- production_batches
- production_batch_items
- waste_records

### Audit and system

- audit_logs
- settings
- number_sequences
- notifications
- required Laravel sessions/cache/queue/password reset tables as appropriate.

Database rules:

- Money: `DECIMAL(12,2)` or greater appropriate precision. Never store money as float/double.
- Quantities: `DECIMAL(14,3)` or suitable precision.
- Unit costs: use suitable decimal precision.
- Use foreign keys and indexes.
- Use restrictive deletes for historical financial/inventory records.
- Use soft deletes only for appropriate master data.
- Preserve transaction snapshots in order_items.
- Use a ledger for inventory. Do not silently overwrite stock.
- Use atomic sequence generation with row locking or another concurrency-safe approach.
- Add unique constraint for `(branch_id, order_number)` and idempotency key.
- Add unique constraint for `(branch_id, inventory_item_id)` on branch balances.
- Use UTC storage and Asia/Manila display where practical.
- Avoid polymorphic references for core financial relations where a normal FK can be used.
- For audit/reference polymorphism, validate allowed entity types.

Before implementing migrations, produce an ERD in `docs/ERD.md` and ensure the migrations reflect it.

## 8. Critical transaction and inventory invariants

These rules are mandatory:

1. Checkout is a database transaction.
2. Revalidate current product price/status and stock on the server at checkout.
3. Lock relevant branch inventory rows (`lockForUpdate` or equivalent) before stock-sensitive deduction.
4. Create order, order lines, payment, inventory movements, balance projection, and cash movement consistently.
5. If any critical operation fails, roll back the complete transaction.
6. Use idempotency to ensure retries do not create duplicate sales.
7. Completed orders/payments are not hard-deleted.
8. Refunds are separate records and cannot exceed the remaining refundable amount/quantity.
9. Cash change is not revenue.
10. GCash is non-cash and excluded from drawer cash.
11. Ingredient deduction timing must follow the product's tracking mode.
12. Inventory movement ledger is the audit source; branch_inventory is a transactional balance projection and must reconcile.
13. Stock adjustments and production posting require permissions and reasons.
14. Receipt printing failure must not reverse a successfully committed sale.
15. All money calculations must use decimal-safe arithmetic (integer minor units or robust decimal handling); avoid PHP float calculations for currency.

## 9. Suggested application architecture

Use a modular monolith. Suggested organization:

- `app/Domain/` or `app/Services/` organized by module: POS, Orders, Payments, Inventory, Production, Cash, Reports.
- Thin controllers; Form Requests for validation; Policies/Gates for authorization.
- Services/actions for critical business operations.
- DTOs/value objects where useful.
- Enums for order/payment/movement statuses.
- Eloquent models with explicit relationships and scopes.
- Events/listeners/jobs only where asynchronous behavior is appropriate.
- Inertia pages and reusable Vue components.
- Avoid putting complex business logic in Vue components or Eloquent model observers.
- Use API resources/DTOs for consistent page data where appropriate.
- Keep frontend state limited to UI/cart editing; server remains authoritative.

## 10. UI routes/pages (suggested)

### Admin

- `/admin/dashboard`
- `/admin/products`
- `/admin/categories`
- `/admin/product-prices`
- `/admin/orders`
- `/admin/inventory/items`
- `/admin/inventory/movements`
- `/admin/inventory/adjustments`
- `/admin/inventory/suppliers`
- `/admin/purchase-orders`
- `/admin/production/batches`
- `/admin/shifts`
- `/admin/reports/sales`
- `/admin/reports/inventory`
- `/admin/users`
- `/admin/roles`
- `/admin/settings`
- `/admin/audit-logs`

### POS and staff

- `/pos`
- `/pos/orders`
- `/pos/orders/{order}`
- `/staff/preparation`
- `/staff/shift`

Route names and grouping can be refined, but route access must be permission-protected.

## 11. UI quality and usability rules

- Build real empty/loading/error/success states.
- Use pagination on large tables.
- Use server-side filtering/sorting for large datasets.
- Confirm destructive and financial actions.
- Show clear validation near fields.
- Prevent double-click duplicate submission.
- Preserve cart state during safe navigation where practical, but never treat browser cart state as committed sale data.
- Provide keyboard support for common POS operations.
- Provide printer-friendly receipt layout.
- Use accessible labels and semantic HTML.
- Do not seed fake business KPIs as if they are actual sales. Demo data must be clearly labeled.

## 12. Testing requirements

Write automated tests for:

- login and disabled users;
- role/permission enforcement;
- product CRUD and price history;
- order total and discount calculations;
- cash change calculation;
- GCash reference and verification;
- successful checkout;
- duplicate checkout/idempotency;
- concurrent stock deduction and insufficient stock;
- rollback after injected checkout failure;
- inventory movement and balance reconciliation;
- production batch ingredient deduction and finished goods output;
- no double ingredient deduction on finished-good sale;
- refund limits and audit events;
- shift expected cash and variance;
- report date/branch filtering;
- historical snapshot persistence after product edits;
- no customer-profile/contact data is collected or stored, no customer table or customer-linked order fields exist, and checkout works without customer identification;
- exactly one permanent branch is seeded; temporary pop-up locations can be scheduled, activated, assigned to terminals/cashier sessions, and deactivated/closed;
- a pop-up cannot accept new sales or shift openings when inactive/closed or outside its approved operating period; historical sales/shifts/inventory/reporting remain available after closure;
- sales, shifts, inventory balances/movements, and reports are correctly scoped to the operating location;
- no inter-location stock transfer workflow is implemented until its business process is confirmed.

Run:

- `php artisan test`
- Pint or the chosen PHP formatter
- TypeScript type check
- frontend production build
- migration fresh/seed verification in a disposable test database

Do not use production data in tests. Do not run destructive database commands against an unknown configured database. Inspect `.env` and confirm the environment is local/test before using `migrate:fresh`.

## 13. Seed data

Seed only safe, clearly identified demo data:

- Exactly one permanent Brevon branch (mark address/name as provisional if not owner-confirmed); pop-up locations are created as needed and must not be seeded as permanent branches. Pop-up dates and activation must be managed without deleting historical data.
- Roles and permissions.
- Payment methods Cash and GCash (GCash manual verification).
- Categories Doughnuts, Coffee, Cold Drinks, Other.
- Order types listed above.
- Optional sample products with clearly labeled sample prices, not asserted as official Brevon pricing.
- No fabricated recipes or supplier details.

Create a documented local-only admin account setup. Never commit production credentials.

## 14. Delivery expectations

At completion, provide:

1. Working application code.
2. Migrations, models, factories, and seeders.
3. Functional Vue/Inertia screens.
4. Automated tests and test results.
5. `.env.example` with safe placeholders.
6. `README.md` with setup, migration, seed, run, test, build, and deployment instructions.
7. `docs/ERD.md`.
8. `docs/IMPLEMENTATION_PLAN.md`.
9. `docs/IMPLEMENTATION_STATUS.md`.
10. `docs/DECISIONS.md` with all assumptions/TBC items.
11. `docs/TEST_PLAN.md`.
12. A list of known limitations and incomplete features.

Never report success for commands that were not run. If a tool, dependency, printer, payment integration, or environment is unavailable, document the limitation and provide a safe fallback.

## 15. Definition of Done

A feature is done only when:

- it satisfies the mapped SRS requirement(s);
- validation and authorization are implemented server-side;
- database constraints and migrations are in place;
- automated tests cover normal, invalid, and edge cases;
- UI has loading, empty, success, and error states;
- no sensitive data is exposed;
- documentation is updated;
- the relevant test/build commands pass.

## 16. Mandatory phased implementation and quality gates

**This is a strict development rule. Do not build the entire application in one pass. Do not skip phases, combine multiple major phases into one large implementation, or mark a phase complete merely because its files have been generated.** Build and verify the system incrementally as a sequence of working, testable deliverables.

### 16.1 Phase execution rules

1. Work on **one phase at a time**. At the start of each phase, state its objective, included SRS requirement IDs, dependencies, planned files/modules, and acceptance criteria.
2. Before coding, inspect the existing implementation and reuse or improve it where appropriate. Do not overwrite working features just to match a preferred structure.
3. Implement a complete vertical slice for the phase: database changes, backend logic, authorization, frontend UI, validation, and tests as applicable. Avoid creating placeholder screens that appear functional but have no working backend.
4. After implementation, run the relevant migrations against a disposable local/test database, automated tests, PHP formatting/static checks available in the repository, TypeScript checks, and frontend build. Never run destructive migration commands against an unknown or production database.
5. Fix failures introduced by the phase before proceeding. Do not knowingly carry failing tests, broken builds, unresolved migration errors, or security/authorization gaps into the next phase. If a failure is environmental or cannot safely be resolved, document it clearly and stop for a decision.
6. Manually verify the phase's main user workflow where practical. Report what was actually tested and distinguish automated verification from manual verification.
7. Update `docs/IMPLEMENTATION_STATUS.md`, `docs/IMPLEMENTATION_PLAN.md`, `docs/DECISIONS.md`, and `docs/TEST_PLAN.md` as relevant. Record completed requirement IDs, changed files, commands run, test outcomes, known issues, and the exact next step.
8. At the end of each phase, provide a concise phase report: completed work, requirement IDs covered, migrations/routes/pages added, tests and build results, outstanding issues, and next phase.
9. **Stop at the phase boundary.** Do not automatically start the next major phase in the same run unless the user explicitly asks you to continue. Present the result and wait for the user's review/approval before proceeding. Small fixes needed to make the current phase pass its quality gate are allowed.
10. If the user asks you to continue in a later session, first read the implementation status, plan, decisions, and relevant tests/code. Resume from the first incomplete phase; do not restart or regenerate completed work.
11. If the context window or available time is running low, finish the smallest safe unit of work, update the progress documents, and leave clear handoff instructions. Never leave the repository in an undocumented half-migrated or knowingly broken state.
12. Do not claim that a phase or feature is complete until its acceptance criteria and quality gate have passed. If something is only scaffolded, label it **scaffolded / not implemented**.

### 16.2 Recommended phase sequence

Use this order unless repository inspection reveals a concrete dependency that requires a documented adjustment. Keep each phase independently reviewable.

| Phase                                        | Scope                                                                                                                                                                                              | Exit criteria                                                                                                                                                                                                                                                                                            |
| -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 0 — Discovery and technical plan             | Inspect repository/runtime; review SRS; confirm framework/package compatibility; identify risks and assumptions; create implementation plan, ERD, decisions, and test plan.                        | Architecture and phase plan documented; no destructive changes made; blockers identified.                                                                                                                                                                                                                |
| 1 — Project foundation                       | Laravel 13 setup or safe integration; Vue 3/Inertia/TypeScript/Tailwind; environment configuration; database connection; base layout/theme; health check; formatting and test tooling.             | App boots; frontend builds; database connection and initial migrations work in local/test; baseline tests pass.                                                                                                                                                                                          |
| 2 — Authentication and access control        | Login/logout, password flow, users/employees, roles, permissions, operating-location assignment, protected routes and navigation.                                                                  | Role/permission tests pass; unauthorized users cannot access protected actions or pages.                                                                                                                                                                                                                 |
| 3 — Catalog and settings                     | Permanent branch and temporary pop-up location settings, categories, products, variants/options, location availability, pricing and price history.                                                 | Exactly one permanent branch is seeded; authorized users can schedule/activate/deactivate pop-up locations; terminal/session assignment and operating-date restrictions work; admin can manage catalog; tests pass.                                                                                      |
| 4 — Core POS checkout                        | Cashier interface, product search, cart, order types, server-side totals, cash payment/change, GCash reference/manual verification, transaction numbering, idempotency, receipt and order history. | A complete sale can be processed end-to-end at the permanent branch and at an active assigned pop-up location; sales cannot be recorded against an inactive/out-of-period location; duplicate checkout is prevented; totals and payment handling are tested. This is the first major business milestone. |
| 5 — Orders and preparation                   | Order lifecycle, status history, preparation queue, advance pickup, cancellation controls.                                                                                                         | Staff can process orders according to permitted transitions; history and permissions are tested.                                                                                                                                                                                                         |
| 6 — Cashier shifts and cash control          | Open/close shifts, cash movements, expected cash, counted cash, variance and review.                                                                                                               | Shift calculations reconcile; GCash/non-cash is excluded from drawer cash; access and audit tests pass.                                                                                                                                                                                                  |
| 7 — Inventory and purchasing                 | Inventory items/units, ledger and balances, receiving, adjustments/approvals, suppliers, purchase orders, goods receipts, low-stock and optional lots/expiry.                                      | Stock movements reconcile with balances; insufficient stock and concurrency protections are tested.                                                                                                                                                                                                      |
| 8 — Recipes and production                   | Recipe versions, production batches, ingredient consumption, finished goods, waste and production posting.                                                                                         | Production posts atomically; no double deduction occurs; recipe/stock rules are tested.                                                                                                                                                                                                                  |
| 9 — Refunds, discounts and tax configuration | Discounts and approvals, refund workflow, reversals, tax configuration framework and relevant audit records.                                                                                       | Refund limits, approval rules, financial calculations and audit trails pass tests. Tax/legal behavior remains unapproved until confirmed by the client/accountant.                                                                                                                                       |
| 10 — Dashboards and reports                  | Operational KPIs, sales/inventory/production/cash reports, filters, exports and print layouts.                                                                                                     | Report totals reconcile to source transactions; date/branch filters and permissions are tested.                                                                                                                                                                                                          |
| 11 — Hardening and release readiness         | Full regression, security review, accessibility/usability pass, performance checks, backup/restore procedure, deployment documentation, UAT fixes and known-limitations review.                    | All release-critical tests/builds pass; backup/restore and deployment steps are documented; client UAT sign-off is recorded before production launch.                                                                                                                                                    |

### 16.3 Priority and scope control

- Treat the **core cashier checkout flow** as the first major business milestone. Do not spend time polishing advanced reports or production workflows before a reliable sale can be completed.
- Within every phase, prioritize correctness, security, and data integrity over visual polish.
- Separate must-have launch functionality from optional or future functionality. Keep uncertain business rules configurable and documented as TBC rather than guessing.
- If a phase is too large, split it into smaller numbered sub-phases and apply the same quality gates. Do not respond by generating the whole phase at once.
- Do not silently change the approved SRS. Propose changes in `docs/DECISIONS.md` and explain their impact before implementing material scope changes.

### 16.4 Required phase completion report

At every phase boundary, use this format:

- **Phase:** number and name
- **Status:** passed / blocked / partially complete
- **SRS requirements covered:** requirement IDs
- **Implemented:** concise list of working functionality
- **Verification:** exact commands run and pass/fail results
- **Manual checks:** workflows actually exercised, if any
- **Known issues / deferred items:** include severity and reason
- **Files and documentation updated:** key paths
- **Next phase:** objective and prerequisites
- **Approval needed:** state whether user review is required before continuing

## 17. First execution instruction

Start with **Phase 0 only**: inspect the repository and runtime, review the SRS, verify Laravel 13 and dependency compatibility, identify blockers, and create/update the planning documents and ERD. Present the Phase 0 report and proposed Phase 1 scope, then stop for user review. Do not begin Phase 1 or generate application code until the user approves continuing. In all subsequent work, follow the phase sequence and quality gates in Section 16.
