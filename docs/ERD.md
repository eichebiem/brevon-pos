# Brevon POS — Entity Relationship Design

Source of truth for field definitions: `docs/BREVON-SRS-v1.1.md` §8 (SRS **v1.1**). This document records the relationships, the additions/changes proposed in `docs/DECISIONS.md`, migration order, and the phase in which each table is created. Migrations must match this document; update it first when the schema changes.

Conventions: BIGINT unsigned PKs; money `DECIMAL(12,2)`; unit cost `DECIMAL(14,4)`; quantity `DECIMAL(14,3)`; timestamps UTC; FKs `RESTRICT` on delete unless stated; `(+)` = addition to SRS schema.

---

## 1. Organization and access

```mermaid
erDiagram
    branches ||--o{ user_branch : "assigns"
    users ||--o{ user_branch : "works at"
    employees |o--o| users : "profile of (users.employee_id)"
    branches ||--o{ employees : "default branch"
    branches ||--o{ terminals : has
    users ||--o{ model_has_roles : has
    roles ||--o{ model_has_roles : ""
    roles ||--o{ role_has_permissions : ""
    permissions ||--o{ role_has_permissions : ""
    users ||--o{ login_events : "(+) logs"

    branches { bigint id PK
      varchar code UK "assigned by the app, POP01… for pop-ups (D-121)"
      varchar name
      varchar location_type "(+) permanent | pop_up, immutable"
      date operating_starts_on "(+) optional, pop-up only"
      date operating_ends_on "(+) optional, pop-up only"
      tinyint permanent_guard "(+) generated, UK"
      varchar timezone
      char currency
      bool is_active
      datetime deactivated_at "(+)"
      bigint deactivated_by FK "(+)"
      text deactivation_reason "(+)" }
    users { bigint id PK
      varchar username UK
      varchar email UK
      bigint employee_id FK
      bool is_active
      timestamp deleted_at }
    employees { bigint id PK
      varchar employee_code UK
      bigint default_branch_id FK }
    user_branch { bigint user_id FK
      bigint branch_id FK
      bool is_default }
    terminals { bigint branch_id FK
      varchar terminal_code "assigned by the app: POS-1, POS-2… (D-122)" }
    login_events { bigint user_id FK "nullable"
      varchar identifier
      bool succeeded
      varchar ip_address }
```

Constraints: UNIQUE `user_branch(user_id, branch_id)`; UNIQUE `terminals(branch_id, terminal_code)` (new codes are numbered across all locations, so they never repeat, D-122); spatie tables per package migration (teams off).

**Operating locations (SRS v1.1, D-057–D-066, D-121).** `branches` holds both the single permanent branch and temporary pop-up stands. Address columns are name, street, barangay, city, province and postal code; there is no phone or email column (dropped in `2026_11_06_110000`, D-121). Constraints: UNIQUE `code`; UNIQUE `permanent_guard` (generated `IF(location_type='permanent',1,NULL)`) → at most one permanent location; CHECK `operating_ends_on >= operating_starts_on`; app rule: pop-up dates optional (enforced only when set), permanent has none. Phase 7 adds `stock_transfers` / `stock_transfer_items` for the main → pop-up doughnut dispatch (D-063). Locations and terminals are never deleted (no soft delete); every FK to `branches` is RESTRICT, so a closed pop-up keeps all orders, shifts, balances, movements, and audit rows. `branches.acceptsNewOperationsOn(date)` = active AND (permanent OR date within the operating period) (D-059).

## 2. Catalog and pricing

```mermaid
erDiagram
    categories |o--o{ categories : parent
    categories ||--o{ products : contains
    products ||--o{ product_variants : has
    products ||--o{ product_prices : "price history"
    product_variants |o--o{ product_prices : "variant price"
    branches |o--o{ product_prices : "branch price (NULL = global)"
    products ||--o{ product_branch : availability
    branches ||--o{ product_branch : ""
    products ||--o{ product_option_group : ""
    option_groups ||--o{ product_option_group : ""
    option_groups ||--o{ product_options : has
    inventory_items |o--o{ products : "(+) finished-good stock item"
    inventory_items |o--o{ product_variants : "(+) variant stock item"

    products { bigint id PK
      bigint category_id FK
      varchar sku UK
      varchar product_type
      varchar inventory_tracking_mode
      bigint inventory_item_id FK "(+) nullable"
      bool is_active
      bool is_available_for_pos }
    product_prices { bigint product_id FK
      bigint variant_id FK
      bigint branch_id FK
      decimal price
      datetime effective_from
      datetime effective_until
      bigint created_by FK }
```

Rules: current price = latest row with `effective_from ≤ now < coalesce(effective_until, ∞)`, in the order variant + location → variant → location → base (D-087). New price closes the previous row (`effective_until`) — rows are never updated otherwise. UNIQUE `product_branch(product_id, branch_id)`, `product_option_group(product_id, option_group_id)`. **Built in Phase 3:** generated columns `product_prices.scope_key` / `open_guard` (UNIQUE: one open price per scope), `product_variants.default_guard` (one default variant per product), `settings.branch_scope` (UNIQUE with `key`); CHECKs `products_stock_link_check` (finished_stock ⇒ stock item, D-085/D-089), price ≥ 0, `effective_until > effective_from`, option `price_delta ≥ 0`, single-choice groups max 1.

## 3. Orders, payments, refunds, discounts, tax

```mermaid
erDiagram
    branches ||--o{ orders : ""
    terminals |o--o{ orders : ""
    cashier_shifts |o--o{ orders : "cash sales"
    users ||--o{ orders : "created_by"
    orders ||--|{ order_items : contains
    products |o--o{ order_items : "snapshot source"
    product_variants |o--o{ order_items : ""
    order_items ||--o{ order_item_options : ""
    product_options |o--o{ order_item_options : ""
    orders ||--o{ order_status_histories : ""
    orders ||--o{ payments : ""
    payment_methods ||--o{ payments : ""
    orders ||--o{ refunds : ""
    payments |o--o{ refunds : ""
    refunds ||--|{ refund_items : ""
    order_items ||--o{ refund_items : ""
    orders ||--o{ order_discounts : ""
    discounts |o--o{ order_discounts : ""
    orders ||--o{ order_tax_lines : ""
    tax_rules |o--o{ order_tax_lines : ""

    orders { bigint id PK
      bigint branch_id FK
      varchar order_number
      date business_date
      varchar order_type
      varchar status
      varchar payment_status
      decimal grand_total
      varchar idempotency_key
      char idempotency_request_hash "(+)" }
    payments { bigint order_id FK
      bigint payment_method_id FK
      decimal amount
      decimal amount_tendered
      decimal change_amount
      varchar reference_number
      varchar status }
```

**No customer data (D-067):** there is no `customers` table and `orders` has no customer FK, name, phone, email or address columns. `orders.branch_id`, `terminal_id`, `cashier_shift_id` are copied from the cashier's open shift (D-060).

Constraints: UNIQUE `orders(branch_id, order_number)`, UNIQUE `orders(branch_id, idempotency_key)`; INDEX `orders(branch_id, business_date, status)`, `orders(cashier_shift_id)`, `payments(order_id, status)`, UNIQUE `payments(payment_method_id, reference_number)`, `order_items(product_id)`. All FKs from orders/payments/refunds RESTRICT. Order items keep `sku_snapshot`, `product_name_snapshot`, `variant_name_snapshot`, `unit_price` (FR-022, BR-015).

`payment_status` values: `unpaid`, `pending_verification`, `paid`, `partially_refunded`, `refunded`.

**Phase 4b (built, D-103–D-111):** `orders.idempotency_request_hash` CHAR(64); `order_items.product_type_snapshot` and `order_item_options.option_group_name_snapshot` added to the SRS columns. CHECKs: order type, status, payment status, non-negative money on orders and lines, `payments.amount > 0`, `amount_tendered >= amount`; UNIQUE `payments(payment_method_id, reference_number)` — a GCash reference is recorded once, at any location (replaces the plain index above). Every FK RESTRICT; models refuse delete(). Sales post `sale` movements referencing the order (`reference_type = 'order'`).

## 4. Cash management

```mermaid
erDiagram
    branches ||--o{ cashier_shifts : ""
    users ||--o{ cashier_shifts : cashier
    terminals |o--o{ cashier_shifts : ""
    cashier_shifts ||--o{ cash_movements : ""
    cashier_shifts { bigint id PK
      bigint branch_id FK
      bigint user_id FK
      varchar shift_number
      decimal opening_cash
      decimal expected_cash
      decimal counted_cash
      decimal variance
      varchar status
      tinyint open_guard "(+) 1 while open, NULL when closed"
      bigint approved_by FK "(+) reviewer, D-128"
      datetime reviewed_at "(+) Phase 6"
      text review_notes "(+) Phase 6" }
    cash_movements { bigint id PK
      bigint cashier_shift_id FK
      bigint branch_id FK
      varchar movement_type "cash_in, cash_out, cash_paid_out, cash_refund, correction"
      decimal amount "positive; signed for corrections"
      varchar corrects "(+) expected or counted, corrections only"
      text reason
      varchar status "pending, approved, rejected"
      varchar reference_type
      bigint reference_id
      bigint created_by FK
      bigint approved_by FK
      datetime decided_at "(+)"
      text decision_notes "(+)"
      datetime created_at }
```

Constraints (as built, D-094): UNIQUE `cashier_shifts(user_id, open_guard)` (at most one open shift per cashier anywhere); `business_date` DATE (Manila) added; UNIQUE `cashier_shifts(terminal_id, open_guard)` (at most one open shift per terminal, D-060); `terminal_id` required for new shifts and `branch_id` copied from the terminal; UNIQUE `cashier_shifts(branch_id, shift_number)`; INDEX `(branch_id, user_id, status)`.

`cash_movements` (Phase 6, D-126–D-129): CHECK type and status values; CHECK `(movement_type = 'correction' AND amount <> 0 AND corrects IN ('expected','counted')) OR (movement_type <> 'correction' AND amount > 0 AND corrects IS NULL)`; INDEX `(cashier_shift_id, status)`, `(branch_id, created_at)`, `(reference_type, reference_id)`. No `updated_at`: rows change only from pending to a decision, then never again; never deleted. Corrected shift figures are calculated, not stored.

## 5. Inventory, purchasing

```mermaid
erDiagram
    units_of_measure ||--o{ inventory_items : ""
    inventory_items ||--o{ branch_inventory : balance
    branches ||--o{ branch_inventory : ""
    inventory_items ||--o{ inventory_lots : ""
    suppliers |o--o{ inventory_lots : ""
    inventory_items ||--o{ inventory_movements : ledger
    inventory_lots |o--o{ inventory_movements : ""
    branches ||--o{ stock_adjustments : ""
    stock_adjustments ||--|{ stock_adjustment_items : ""
    branches ||--o{ stock_transfers : "from (main)"
    branches ||--o{ stock_transfers : "to (pop-up)"
    stock_transfers ||--|{ stock_transfer_items : ""
    suppliers ||--o{ purchase_orders : ""
    purchase_orders ||--|{ purchase_order_items : ""
    purchase_orders |o--o{ goods_receipts : ""
    goods_receipts ||--|{ goods_receipt_items : ""
    purchase_order_items |o--o{ goods_receipt_items : ""

    branch_inventory { bigint branch_id FK
      bigint inventory_item_id FK
      decimal quantity_on_hand
      decimal average_unit_cost }
    inventory_movements { bigint branch_id FK
      bigint inventory_item_id FK
      varchar movement_type
      decimal quantity_delta "signed"
      decimal balance_after
      varchar reference_type "morph-map alias"
      bigint reference_id }
    stock_adjustments { bigint id PK
      bigint branch_id FK
      varchar adjustment_number UK
      varchar kind "(+) count | change"
      varchar status "pending | posted | rejected"
      text reason
      bigint created_by FK
      bigint approved_by FK
      datetime decided_at "(+)"
      text decision_notes "(+)"
      datetime posted_at }
    stock_adjustment_items { bigint stock_adjustment_id FK
      bigint inventory_item_id FK
      decimal system_quantity
      decimal counted_quantity
      decimal variance_quantity
      decimal unit_cost }
    stock_transfers { bigint id PK
      varchar transfer_number UK
      bigint from_branch_id FK
      bigint to_branch_id FK
      text notes
      bigint created_by FK
      datetime posted_at }
    stock_transfer_items { bigint stock_transfer_id FK
      bigint inventory_item_id FK
      decimal quantity
      decimal unit_cost }
    suppliers { bigint id PK
      varchar supplier_code UK "SUPnnn"
      varchar name
      boolean is_active }
    purchase_orders { bigint id PK
      bigint branch_id FK
      bigint supplier_id FK
      varchar po_number UK
      varchar status
      date expected_at
      decimal subtotal
      bigint approved_by FK
      datetime cancelled_at "(+)"
      bigint cancelled_by FK "(+)"
      text cancel_reason "(+)" }
    purchase_order_items { bigint purchase_order_id FK
      bigint inventory_item_id FK
      decimal quantity_ordered
      decimal quantity_received
      decimal unit_cost
      decimal line_total }
    goods_receipts { bigint id PK
      bigint branch_id FK
      bigint purchase_order_id FK "nullable"
      bigint supplier_id FK "(+) nullable"
      varchar receipt_number UK
      bigint received_by FK
      bigint over_receipt_approved_by FK "(+)" }
    goods_receipt_items { bigint goods_receipt_id FK
      bigint purchase_order_item_id FK
      bigint inventory_item_id FK
      bigint inventory_lot_id FK
      decimal quantity_received
      decimal unit_cost
      varchar condition "accepted | damaged | expired" }
    inventory_lots { bigint id PK
      bigint branch_id FK
      bigint inventory_item_id FK
      varchar lot_number
      date expires_at
      decimal quantity_received
      decimal quantity_remaining "FEFO estimate"
      bigint supplier_id FK }
```

Phase 7a constraints (D-131, D-133): CHECK `stock_adjustments.kind IN ('count','change')`, `status IN ('pending','posted','rejected')`; CHECK `variance_quantity = counted_quantity - system_quantity AND counted_quantity >= 0`; UNIQUE `(stock_adjustment_id, inventory_item_id)` and `(stock_transfer_id, inventory_item_id)`; CHECK `from_branch_id <> to_branch_id`, `quantity > 0`. Model guards: decided adjustments, their lines (except the cost stamped at posting) and every dispatch are never edited or deleted. `stock_transfers` is not in SRS §8.7 (schema addition from D-063).

Phase 7b constraints (D-135–D-138, migration `2026_11_06_140000`): CHECK `purchase_orders.status IN ('draft','submitted','partially_received','received','cancelled')`, `subtotal >= 0`; CHECK order lines `quantity_ordered > 0`, `quantity_received >= 0`, costs/totals `>= 0`; UNIQUE `(purchase_order_id, inventory_item_id)`; CHECK `inventory_lots` `quantity_received > 0`, `0 <= quantity_remaining <= quantity_received`; CHECK receipt lines `quantity_received > 0` and `condition IN ('accepted','damaged','expired')`; INDEX `inventory_lots(branch_id, inventory_item_id, expires_at)` for first-expiring-first use. `inventory_movements.inventory_lot_id` (nullable FK) is set on receipt movements. Model guards: sent orders keep their lines (only `quantity_received` moves), received/cancelled orders and all receipts are immutable, a lot changes only its remaining quantity; nothing here is deleted. Morph aliases `supplier`, `purchase_order`, `goods_receipt`, `inventory_lot`.

Invariant: for every (branch, item), `branch_inventory.quantity_on_hand = Σ inventory_movements.quantity_delta`. All writes go through one `InventoryLedger` service that locks the `branch_inventory` row (`FOR UPDATE`, rows locked in ascending item-id order to avoid deadlocks), checks negative stock, inserts the movement with `balance_after`, and updates the projection in the same transaction. Balances are per location, pop-ups included. `transfer_in`/`transfer_out` movement types are used only by the Phase 7 main → pop-up dispatch; rejected by the ledger before then (D-063). UNIQUE `branch_inventory(branch_id, inventory_item_id)`; INDEX `inventory_movements(branch_id, inventory_item_id, created_at)`, `purchase_orders(branch_id, status)`.

## 6. Recipes and production

```mermaid
erDiagram
    products ||--o{ recipes : versions
    product_variants |o--o{ recipes : "size (drinks)"
    recipes ||--|{ recipe_items : ""
    inventory_items ||--o{ recipe_items : ingredient
    units_of_measure ||--o{ recipe_items : ""
    branches ||--o{ production_batches : "main branch"
    products ||--o{ production_batches : ""
    recipes ||--o{ production_batches : "version used"
    production_batches ||--o{ production_batch_items : consumption
    inventory_items ||--o{ production_batch_items : ""
    production_batches ||--o{ inventory_movements : "reference"
```

UNIQUE `recipes(product_id, variant_key, version)`, where `variant_key` is generated as `COALESCE(product_variant_id, 0)` (0 = every size). Recipes and recipe lines are never edited or deleted; saving makes a new version (D-140). `production_batches`: `planned_quantity`, `actual_quantity` (good), `rejected_quantity` + `reject_reason`, `status` draft/posted/cancelled, `unit_cost` (per good piece, nullable), `posted_by/at`, `cancelled_by/at`, `cancel_reason`; CHECK planned > 0 and good + rejected > 0. `production_batch_items`: `quantity_consumed`, `unit_cost_snapshot` (set when posted). Posting a batch: one transaction on the locked draft row, ingredient movements `production_consumption`, output movement `production_output` on the product's finished-good item (D-142). The SRS `waste_records` table is not created: batch rejects and `waste` movements cover FR-070 (D-143).

## 7. Audit and system

| Table              | Notes                                                                                                                                                                     |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `audit_logs`       | branch_id, user_id nullable; event; auditable_type (morph alias) + id; old/new JSON; ip; UA; created_at. INDEX `(auditable_type, auditable_id, created_at)`. Append-only. |
| `settings`         | UNIQUE `(branch_id, key)`; NULL branch = global. No secrets.                                                                                                              |
| `number_sequences` | UNIQUE `(branch_id, sequence_type, business_date)`; locked increment.                                                                                                     |
| `notifications`    | Laravel standard (UUID PK).                                                                                                                                               |
| Framework          | `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.                                                                        |

## 8. Migration order (FK-safe)

1. Framework: users (base), password_reset_tokens, sessions, cache, jobs — Phase 1
2. branches (with location columns, D-057/D-058/D-062 — `deactivated_by` FK added after users exist; users table already exists from step 1), employees, alter users (+employee_id, username, is_active, last_login_at, soft deletes), user_branch, terminals, spatie permission tables, login_events, audit_logs — **Phase 2 (done)**; `settings` → Phase 3, `number_sequences` → Phase 4, `notifications` → when first needed (D-083). Pop-up management UI and operating-date rules in Phase 3
3. settings, categories, units_of_measure, inventory_items, products (+inventory_item_id), product_variants, product_prices, product_branch, option_groups, product_option_group, product_options — **Phase 3 (done)** (units/inventory_items stubs needed for the product link; full inventory UI in Phase 7)
4. number_sequences, branch_inventory, inventory_movements (no `inventory_lot_id` until Phase 7) — **Phase 4a (done)**, D-051/D-096
5. payment_methods, cashier_shifts — **Phase 4a (done)**; orders, order_items, order_item_options, order_status_histories, payments — **Phase 4b (done)**
6. cash_movements — **Phase 6 (done)**
7. stock_adjustments(+items), stock_transfers(+items) — **Phase 7a (done)**; suppliers, purchase_orders(+items), inventory_lots, goods_receipts(+items), inventory_movements.inventory_lot_id — **Phase 7b (done)**
8. recipes, recipe_items, production_batches, production_batch_items, waste_records — Phase 8
9. discounts, order_discounts, tax_rules, order_tax_lines, refunds, refund_items — Phase 9

(`inventory_lots` references `suppliers`, so both land in Phase 7; `inventory_movements.inventory_lot_id` FK is added by an alter migration in Phase 7.)
