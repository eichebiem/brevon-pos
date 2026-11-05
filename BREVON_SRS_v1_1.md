**BREVON DOUGHNUTS & COFFEE**

**Software Requirements Specification (SRS)**

**Project:** Brevon POS and Store Management System

**Document ID:** BREVON-SRS-001

Version: 1.1

Status: Revised Draft for Client Review and Approval

Prepared: 05 November 2026**Prepared:** 04 November 2026

**Prepared for:** Brevon Doughnuts & Coffee

Prepared by: Harris

## Document Control

| **Item**           | **Value**                                                                          |
| ------------------ | ---------------------------------------------------------------------------------- |
| Document title     | Brevon POS and Store Management System — SRS                                       |
| Document ID        | BREVON-SRS-001                                                                     |
| Version            | 1.1                                                                                |
| Status             | Revised draft for client review                                                    |
| Date               | 05 November 2026                                                                   |
| Intended audience  | Business owner, store manager, project manager, developers, QA, deployment/support |
| Approval authority | Brevon business owner or authorized representative                                 |

### Revision History

| **Version** | **Date**    | **Description**                                                                                                                                                                                                                                                                                                                                                                    |
| ----------- | ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1.0         | 04-Nov-2026 | Initial client-review draft based on the project proposal.                                                                                                                                                                                                                                                                                                                         |
| 1.1         | 05-Nov-2026 | Consolidates client-reviewed revisions: prepared by Harris; customer management and customer information collection deferred from Version 1; initial deployment defined as one permanent branch with support for scheduled temporary pop-up selling locations; adds location assignment, activation/deactivation, historical retention, and location-based reporting requirements. |

### Approval

Approval of this document confirms agreement with the described scope, assumptions, priorities, and proposed business rules. Items marked **TBC** require confirmation before the affected feature is finalized or released.

| **Role**        | **Name** | **Signature** | **Date** |
| --------------- | -------- | ------------- | -------- |
| Business Owner  |          |               |          |
| Project Manager |          |               |          |
| Technical Lead  |          |               |          |

# 1. Introduction

## 1.1 Purpose

This Software Requirements Specification defines the functional, non-functional, data, interface, security, and operational requirements for the Brevon Doughnuts & Coffee Point of Sale (POS) and Store Management System. It is intended to establish a shared, testable baseline between Brevon and the delivery team.

## 1.2 Scope

The system will be a browser-based application for managing in-store sales and selected store operations. It will provide:

- POS checkout and payment recording;

- order and transaction history without customer profiles or customer contact-information collection; Customer profiles and contact information are excluded from Version 1.

- product, category, pricing, and availability management;

- finished-product and ingredient inventory;

- recipes, production batches, waste, and stock movements;

- cashier shifts and cash reconciliation;

- user accounts, roles, and permissions;

- operational dashboards, reports, and audit history.

The initial deployment is assumed to serve one Brevon branch. The data model will include branch references for future expansion, but multi-branch transfers, consolidated group reporting, and inter-branch stock workflows are not included in Version 1 unless separately approved.

## 1.3 Out of Scope for Version 1

Unless approved through change control, the following are excluded:

- native iOS or Android applications;

- guaranteed offline transaction processing and synchronization;

- customer-facing e-commerce checkout integration with the public website;

- automatic GCash payment confirmation or payment gateway settlement;

- payroll, HR, tax filing, and full accounting/general ledger;

- delivery fleet dispatch and third-party marketplace integrations;

- loyalty points, membership tiers, and marketing automation;

- multiple permanent branches, consolidated group reporting, and automated inter-location stock transfers unless separately approved;

- automated demand forecasting or AI recommendations.

## 1.4 Intended Audience

Business owner, store manager, project manager, software architects, Laravel/Vue developers, QA testers, deployment engineers, and support personnel.

## 1.5 Definitions and Acronyms

| **Term**           | **Definition**                                                                                             |
| ------------------ | ---------------------------------------------------------------------------------------------------------- |
| POS                | Point of Sale                                                                                              |
| SKU                | Stock Keeping Unit                                                                                         |
| BOM / Recipe       | Ingredients and quantities required to produce one unit or batch of a product                              |
| Finished good      | Saleable item tracked as ready-to-sell stock                                                               |
| Ingredient         | Raw material consumed during production or preparation                                                     |
| Shift              | A cashier's accountable cash-handling session                                                              |
| Void               | Cancellation of a transaction or item before completion, subject to controls                               |
| Refund             | Reversal/return of money for a previously completed sale                                                   |
| Stock movement     | Immutable record of inventory quantity change                                                              |
| UAT                | User Acceptance Testing                                                                                    |
| TBC                | To Be Confirmed by the business                                                                            |
| BIR                | Bureau of Internal Revenue (Philippines)                                                                   |
| Pop-up stand       | A temporary Brevon selling location operated at a different physical site during selected dates or months. |
| Operating location | A permanent branch or temporary pop-up stand where sales and store operations are recorded.                |

## 1.6 Reference

- Brevon public landing page: https://brevon-doughnuts.vercel.app/

- Laravel 13 release and support policy: https://laravel.com/docs/13.x/releases

- Laravel deployment requirements: https://laravel.com/docs/13.x/deployment

- Vue documentation: https://vuejs.org/guide/

- Inertia.js documentation: https://inertiajs.com/

# 2. Overall Description

## 2.1 Product Perspective

The solution is a modular monolithic web application. Laravel will own server-side routing, authorization, validation, business rules, database transactions, and reporting queries. Vue 3 will provide interactive screens. Inertia.js will connect the Laravel application and Vue pages without requiring a separately deployed frontend API for the initial release.

## 2.2 User Classes and Roles

| **Role**          | **Typical responsibilities**                                                   | **Default access**            |
| ----------------- | ------------------------------------------------------------------------------ | ----------------------------- |
| Owner             | Business oversight, all reports, configuration, user/role administration       | Full access                   |
| Admin             | Products, inventory, users, settings, reports, operations                      | Broad access, configurable    |
| Manager           | Daily operations, shift review, stock adjustments, approvals, refunds          | Operational access            |
| Cashier           | POS, payment recording, own shift, permitted order lookup                      | Restricted POS access         |
| Preparation Staff | View and update assigned preparation statuses; record production if authorized | Preparation/production access |

Roles are configurable. Permissions must be checked server-side. Hiding a button in Vue is not an authorization control.

## 2.3 Operating Environment

- Client: current supported versions of Chrome, Edge, and Safari on desktop/tablet.

- Server: Linux LTS distribution, Nginx, PHP-FPM.

- Application: Laravel 13.x, PHP 8.4 recommended (Laravel 13 minimum PHP 8.3; verify the selected PHP release and extensions against deployment requirements).

- Frontend: Vue 3, TypeScript, Inertia.js, Tailwind CSS.

- Database: MySQL 8.4 LTS.

- Optional cache/queue: Redis.

- Time zone: Asia/Manila.

- Currency: PHP / Philippine Peso (₱).

## 2.4 Design and Implementation Constraints

1.  Use a supported Laravel major version; target Laravel 13.x with Composer constraint \`^13.0\`.

2.  Use a modular monolith, not microservices, for Version 1.

3.  All authoritative calculations and validations must execute on the server.

4.  Use database transactions for sale completion, payment recording, stock deduction, and shift-affecting financial entries.

5.  Use decimal database types for money and quantities; do not use binary floating-point for financial calculations.

6.  Maintain immutable transaction snapshots and stock movement history.

7.  Use \`branch_id\` on location-scoped operational records. The branch/location model must distinguish the single permanent store from temporary pop-up stands, and every transaction must retain the operating location where it was created.

8.  Do not store payment card data or GCash credentials.

9.  Do not claim BIR compliance until the applicable registration, accreditation, invoicing, and receipt requirements have been confirmed and implemented.

## 2.5 Assumptions

- Initial deployment has exactly one permanent branch. Temporary pop-up operating locations may be created for selected months/date ranges and activated only for approved operating periods; the permanent branch and approved pop-up locations may operate concurrently.

- Prices and product examples shown on the public website are provisional until owner confirmation.

- Initial payment methods are cash and GCash, with other methods configurable later.

- GCash is initially recorded as a manually verified payment method; no automatic settlement integration is assumed.

- Products include doughnuts, coffee, and potentially other beverages/retail items.

- The owner will supply the authoritative menu, prices, recipes, units, suppliers, opening stock, and applicable tax/discount rules.

- The store will provide or approve compatible receipt printer hardware.

- The system is online-first. Loss of connectivity may prevent POS operations in Version 1.

## 2.6 Dependencies

- Hosting, domain, TLS certificate, database, and backup service.

- Approved product/menu and inventory master data.

- Approved staff list and role assignments.

- Printer model and printing method.

- Owner confirmation of tax, invoice, Senior Citizen/PWD, refund, and void policies.

- Reliable internet access at the store.

# 3. Functional Requirements

Priority definitions: **P0** = required for initial operational release; **P1** = important and included if confirmed in the MVP; **P2** = later enhancement.

## 3.1 Authentication and Access Control

| **ID** | **Requirement**                                                                                                                              | **Priority** |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-001 | The system shall allow an active user to authenticate using a unique username or email and password.                                         | P0           |
| FR-002 | The system shall reject invalid credentials with a generic error that does not disclose whether an account exists.                           | P0           |
| FR-003 | The system shall allow an authorized user to log out and invalidate the active session.                                                      | P0           |
| FR-004 | The system shall support password reset through a configured, secure reset mechanism.                                                        | P1           |
| FR-005 | An administrator with permission shall create, update, deactivate, and reactivate user accounts.                                             | P0           |
| FR-006 | The system shall support configurable roles and granular permissions.                                                                        | P0           |
| FR-007 | The system shall enforce permissions on the server for every protected action and resource.                                                  | P0           |
| FR-008 | The system shall prevent deactivated users from starting new sessions.                                                                       | P0           |
| FR-009 | The system shall record successful and failed login events with timestamp and available request metadata.                                    | P1           |
| FR-010 | The system shall prevent a user from granting permissions they do not possess, unless the user is explicitly authorized to administer roles. | P0           |

## 3.2 Branch and Store Settings

| **ID** | **Requirement**                                                                                                                                                                                                    | **Priority** |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------ |
| FR-011 | The system shall maintain operating-location records including location code, name, address, contact details, location type (permanent or pop-up), active status, and optional operating start/end dates.          | P0           |
| FR-012 | The system shall associate each operational transaction with the operating location selected for the active terminal/session.                                                                                      | P0           |
| FR-013 | An authorized administrator shall configure store name, logo, currency, time zone, receipt footer, and operating settings.                                                                                         | P0           |
| FR-014 | The system shall use Asia/Manila as the initial default time zone and PHP as the initial currency, subject to owner confirmation.                                                                                  | P0           |
| FR-103 | The system shall support exactly one permanent branch at initial deployment and the creation of temporary pop-up stand locations at different physical addresses.                                                  | P0           |
| FR-104 | An authorized user shall configure planned operating dates or the active period of a pop-up location and activate/deactivate it without deleting historical transactions.                                          | P0           |
| FR-105 | The system shall filter sales, cashier shifts, inventory movements, and operational reports by operating location, including pop-up locations.                                                                     | P0           |
| FR-106 | The system shall associate each POS terminal or cashier session with one operating location and prevent sales being recorded against a different location unless an authorized, audited reassignment is completed. | P0           |
| FR-107 | The system shall prevent new sales and shift openings at a deactivated/closed pop-up location or outside its approved operating period.                                                                            | P0           |
| FR-108 | The system shall retain historical sales, shifts, inventory movements, and reports for a pop-up location after closure/deactivation.                                                                               | P0           |

## 3.3 Product and Category Management

| **ID** | **Requirement**                                                                                                              | **Priority** |
| ------ | ---------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-015 | An authorized user shall create, update, archive, and reactivate product categories.                                         | P0           |
| FR-016 | An authorized user shall create, update, archive, and reactivate products.                                                   | P0           |
| FR-017 | Each product shall support a unique SKU, name, category, description, image, base unit, status, and inventory tracking mode. | P0           |
| FR-018 | The system shall support configurable product variants and option groups where required.                                     | P1           |
| FR-019 | The system shall support product price history with effective dates and the user who made the change.                        | P1           |
| FR-020 | The system shall allow authorized users to mark products unavailable without deleting historical records.                    | P0           |
| FR-021 | The POS shall display only active products available to the selected branch, subject to stock policy.                        | P0           |
| FR-022 | The system shall retain product name, SKU, variant, and unit-price snapshots on each order item.                             | P0           |

## 3.4 POS and Checkout

| **ID** | **Requirement**                                                                                                                                             | **Priority** |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-023 | An authorized cashier shall start a new sale for the currently selected branch.                                                                             | P0           |
| FR-024 | The POS shall support product search, category filtering, and product selection.                                                                            | P0           |
| FR-025 | The cashier shall add, remove, and change quantities of cart items before checkout.                                                                         | P0           |
| FR-026 | The system shall calculate line totals, subtotal, discounts, taxes/charges when configured, and final payable amount on the server.                         | P0           |
| FR-027 | The system shall validate product status, branch availability, price, and stock policy at checkout.                                                         | P0           |
| FR-028 | The system shall support order types configured by the business, initially walk-in, dine-in, takeout, and advance pickup.                                   | P0           |
| FR-029 | The system shall support order notes and item-level preparation instructions.                                                                               | P1           |
| FR-030 | The system shall generate a unique human-readable order/receipt number per branch and business date, without relying on a race-prone count-only mechanism.  | P0           |
| FR-031 | The system shall prevent duplicate completion caused by repeated browser submissions or retries using an idempotency key or equivalent server-side control. | P0           |
| FR-032 | The system shall commit order, order items, payment records, inventory effects, and applicable cash-shift effects atomically.                               | P0           |
| FR-033 | The system shall display a transaction confirmation after successful completion.                                                                            | P0           |
| FR-034 | The system shall allow authorized users to retrieve and reprint a receipt without creating a new sale.                                                      | P0           |
| FR-035 | The system shall not allow completed transactions to be physically deleted through normal application workflows.                                            | P0           |

## 3.5 Payments, Voids, and Refunds

| **ID** | **Requirement**                                                                                                                             | **Priority** |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-036 | The system shall support configurable payment methods, initially cash and GCash.                                                            | P0           |
| FR-037 | The system shall record payment amount, method, status, reference number when applicable, timestamp, and recording user.                    | P0           |
| FR-038 | For cash payments, the system shall calculate change due from the amount tendered.                                                          | P0           |
| FR-039 | For GCash payments, the system shall allow recording a transaction/reference identifier and manual verification status.                     | P0           |
| FR-040 | The system shall support split tender only if enabled by an authorized administrator.                                                       | P1           |
| FR-041 | The system shall allow an authorized user to void an unpaid or otherwise eligible order with a mandatory reason.                            | P0           |
| FR-042 | The system shall allow refunds only to users with the appropriate permission and shall require a reason and reference to the original sale. | P0           |
| FR-043 | The system shall preserve original payment records and record refund/reversal entries rather than overwriting or deleting them.             | P0           |
| FR-044 | The system shall enforce configurable approval thresholds for discounts, voids, and refunds.                                                | P1           |
| FR-045 | The system shall record all payment, void, and refund actions in the audit log.                                                             | P0           |

## 3.6 Order and Preparation Management

| **ID** | **Requirement**                                                                                                                               | **Priority** |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-046 | The system shall maintain order statuses including pending, confirmed, preparing, ready, completed, cancelled, and refunded where applicable. | P0           |
| FR-047 | Authorized staff shall view active orders for their branch.                                                                                   | P0           |
| FR-048 | Authorized preparation staff shall update preparation statuses according to their permissions.                                                | P1           |
| FR-049 | The system shall support advance pickup date/time without requiring customer name or contact details.                                         | P1           |
| FR-050 | The system shall not collect or store customer profiles, names, phone numbers, email addresses, or addresses in Version 1.                    | P1           |
| FR-051 | The system shall retain order status history, actor, timestamp, and notes.                                                                    | P1           |
| FR-052 | The system shall support cancellation with reason and appropriate authorization.                                                              | P0           |

## 3.7 Inventory and Stock Control

| **ID** | **Requirement**                                                                                                              | **Priority** |
| ------ | ---------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-053 | The system shall maintain inventory items with SKU/code, name, unit of measure, item type, reorder level, and active status. | P0           |
| FR-054 | The system shall maintain branch-specific on-hand inventory balances.                                                        | P0           |
| FR-055 | The system shall record every inventory change as a stock movement with type, quantity, reference, user, and timestamp.      | P0           |
| FR-056 | The system shall support stock receiving, stock issue, adjustment, waste, and count correction workflows.                    | P0           |
| FR-057 | The system shall display low-stock alerts based on configurable reorder thresholds.                                          | P0           |
| FR-058 | The system shall support supplier records.                                                                                   | P1           |
| FR-059 | The system shall support purchase orders and receiving against purchase order lines.                                         | P1           |
| FR-060 | The system shall support lot/batch number, received date, and expiry date for inventory items where required.                | P1           |
| FR-061 | The system shall require a reason for manual stock adjustments and waste records.                                            | P0           |
| FR-062 | The system shall prevent negative stock by default; any override must be explicitly configured and audited.                  | P0           |
| FR-063 | The system shall provide inventory valuation using a documented costing method approved by the owner.                        | P1           |

## 3.8 Recipes and Production

| **ID** | **Requirement**                                                                                                                     | **Priority** |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-064 | The system shall allow an authorized user to define recipes/BOMs for products.                                                      | P1           |
| FR-065 | A recipe shall specify ingredient item, quantity, unit, and optional wastage allowance per output quantity.                         | P1           |
| FR-066 | The system shall support recipe versions/effective dates so historical production costs can be understood.                          | P1           |
| FR-067 | The system shall record production batches including product, planned quantity, actual quantity, date, responsible user, and notes. | P1           |
| FR-068 | The system shall deduct recipe ingredients when a production batch is posted, using a single atomic inventory operation.            | P1           |
| FR-069 | The system shall add the produced quantity to finished-goods stock when a production batch is posted.                               | P1           |
| FR-070 | The system shall record production losses, rejects, and waste separately.                                                           | P1           |
| FR-071 | The system shall not deduct ingredients again when a finished good is sold if ingredients were already deducted at production.      | P0           |
| FR-072 | For made-to-order beverages, the business may configure recipe deduction at sale/preparation instead of batch production.           | P1           |

## 3.9 Cashier Shifts and Cash Management

| **ID** | **Requirement**                                                                                                     | **Priority** |
| ------ | ------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-073 | A cashier shall open a shift by recording opening cash and branch.                                                  | P0           |
| FR-074 | The system shall prevent conflicting simultaneous open shifts for the same cashier/terminal where policy requires.  | P0           |
| FR-075 | The system shall associate cash sales with the active shift.                                                        | P0           |
| FR-076 | The system shall record cash-in and cash-out movements with amount, reason, user, and timestamp.                    | P0           |
| FR-077 | The system shall calculate expected cash based on opening float, cash tenders, cash refunds, cash-in, and cash-out. | P0           |
| FR-078 | The cashier shall submit counted cash at shift closing.                                                             | P0           |
| FR-079 | The system shall calculate and record cash variance.                                                                | P0           |
| FR-080 | The system shall allow a manager to review and approve shift closure/discrepancy where configured.                  | P1           |
| FR-081 | Non-cash payment totals shall be reported separately from physical cash drawer totals.                              | P0           |
| FR-082 | A closed shift shall be immutable except through an audited authorized correction workflow.                         | P0           |

## 3.10 Discounts and Tax Configuration

| **ID** | **Requirement**                                                                                                                                                                                                                                                                                                               | **Priority** |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-083 | The system shall support fixed-amount and percentage discounts with configurable scope and validity.                                                                                                                                                                                                                          | P1           |
| FR-084 | The system shall record discount type, amount, reason/code, user, and approval where applicable.                                                                                                                                                                                                                              | P0           |
| FR-085 | The system shall support only the discount information necessary to record an approved transaction; it shall not create or retain a customer profile or customer-identifying information in Version 1. Any legally required identifying details remain subject to owner/accountant confirmation before enabling the workflow. | P1           |
| FR-086 | Tax and invoice calculation rules shall be configurable and validated against requirements provided by Brevon's accountant/tax adviser.                                                                                                                                                                                       | P1           |
| FR-087 | The system shall not advertise or represent generated receipts/invoices as BIR-compliant until the required compliance work is approved and completed.                                                                                                                                                                        | P0           |

## 3.11 Reports and Dashboard

| **ID** | **Requirement**                                                                                                                      | **Priority** |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------ | ------------ |
| FR-088 | The owner/admin dashboard shall display date-filtered gross sales, net sales, order count, average order value, and low-stock count. | P0           |
| FR-089 | The system shall provide daily, weekly, and monthly sales reports.                                                                   | P0           |
| FR-090 | The system shall provide sales by product and category.                                                                              | P0           |
| FR-091 | The system shall provide sales by payment method.                                                                                    | P0           |
| FR-092 | The system shall provide cashier/shift summaries subject to permissions.                                                             | P0           |
| FR-093 | The system shall provide void, refund, discount, cash variance, and inventory movement reports.                                      | P1           |
| FR-094 | The system shall provide waste and production reports.                                                                               | P1           |
| FR-095 | The system shall support date, branch, cashier, category, and payment-method filters where relevant.                                 | P0           |
| FR-096 | The system shall export authorized reports to CSV/XLSX and printable PDF where supported.                                            | P1           |
| FR-097 | Report definitions shall clearly distinguish gross sales, discounts, refunds, net sales, tax, and payment collections.               | P0           |

## 3.12 Audit and System Administration

| **ID** | **Requirement**                                                                                                                                             | **Priority** |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| FR-098 | The system shall log sensitive actions including price changes, product status changes, stock adjustments, refunds, voids, role changes, and shift closure. | P0           |
| FR-099 | Each audit record shall include actor, action, entity type, entity ID, timestamp, and relevant before/after data where appropriate.                         | P0           |
| FR-100 | Audit records shall not be editable or deletable through ordinary application interfaces.                                                                   | P0           |
| FR-101 | Authorized administrators shall configure business settings and payment methods.                                                                            | P1           |
| FR-102 | The system shall expose operational health/error logs to authorized technical administrators without exposing secrets to ordinary users.                    | P1           |

# 4. Use Cases

## UC-001: User Login

**Primary actor:** Owner, Admin, Manager, Cashier, Preparation Staff

**Preconditions:** User account exists and is active.

**Trigger:** User opens the application login page.

**Main flow:**

10. User enters username/email and password.

11. System validates credentials.

12. System checks account status and role/permissions.

13. System creates an authenticated session.

14. System redirects the user to the permitted landing page.

15. System records the successful login event.

**Alternative flows:**

- A1: Invalid credentials — show generic error and do not create session.

- A2: Inactive account — deny access and display an appropriate support message.

- A3: Rate limit reached — temporarily throttle further attempts.

**Postconditions:** User is authenticated or access is denied.

**Related requirements:** FR-001 to FR-010.

## UC-002: Maintain Products

**Primary actor:** Admin or Manager with product permission

**Preconditions:** User is authenticated and authorized.

**Trigger:** User opens Product Management.

**Main flow:**

16. User opens the product list.

17. User selects Add Product or edits an existing product.

18. User enters SKU, name, category, price, tracking mode, and optional image/variants.

19. System validates required fields and uniqueness.

20. System saves the product and applicable price-history record.

21. System records the change in the audit log.

22. Product becomes available to the POS if active and enabled for the selected operating location.

**Alternative flows:**

- A1: Duplicate SKU — reject and identify the conflicting field.

- A2: Invalid price — reject negative or malformed values.

- A3: Archive product already present in historical sales — archive only; preserve transaction history.

**Postconditions:** Product master data is saved.

**Related requirements:** FR-015 to FR-022, FR-098 to FR-100.

## UC-003: Complete a POS Sale

**Primary actor:** Cashier

**Supporting actors:** Customer, Manager (if approval needed)

**Preconditions:** Cashier is authenticated, has POS permission, and has an open shift if shift enforcement is enabled.

**Trigger:** Customer requests a purchase.

**Main flow:**

23. Cashier opens New Sale.

24. Cashier selects order type.

25. Cashier searches or selects products.

26. Cashier adjusts quantities and permitted options.

27. System displays the cart and estimated total.

28. Cashier optionally attaches customer information or order notes.

29. Cashier proceeds to checkout.

30. Server revalidates product status, prices, discounts, stock policy, and total.

31. Cashier selects payment method.

32. For cash, cashier enters amount tendered; system calculates change.

33. For GCash, cashier enters reference information and records verification according to policy.

34. Cashier confirms payment.

35. System atomically creates the order, order items, payment(s), inventory movement(s), and cash-shift effect(s).

36. System returns a unique receipt/order number.

37. Cashier prints or displays the receipt.

38. Order is shown in transaction history and, if applicable, preparation queue.

**Alternative flows:**

- A1: Product becomes unavailable before confirmation — system blocks completion and asks cashier to revise cart.

- A2: Insufficient stock — block or apply the approved backorder policy.

- A3: Payment validation fails — do not mark order paid.

- A4: Duplicate request — return the already-created transaction result using idempotency protection.

- A5: Database failure — rollback all transaction effects and show retry guidance.

- A6: Discount exceeds cashier authority — request manager approval or reject.

- A7: Printer unavailable — sale remains completed; allow reprint later.

**Postconditions:** A completed order and payment record exist exactly once; applicable stock and cash records are consistent.

**Related requirements:** FR-023 to FR-045, FR-053 to FR-063, FR-073 to FR-082.

## UC-004: Record GCash Payment

**Primary actor:** Cashier

**Preconditions:** An order is awaiting payment and GCash is enabled.

**Main flow:**

39. Cashier selects GCash.

40. System displays payable amount.

41. Customer completes payment using the business's approved GCash process.

42. Cashier enters reference number and optional notes.

43. Cashier marks payment as verified only if permitted and after checking the approved evidence/process.

44. System records payment method, amount, reference, verifier, and timestamp.

45. Order is marked paid when the full payable amount is covered.

**Alternative flows:** Reference is missing/duplicate; payment is not received; customer paid a different amount; manager verification is required.

**Postconditions:** Payment is recorded with an explicit verification state. The system does not claim direct GCash API verification.

**Related requirements:** FR-036 to FR-040.

## UC-005: Void or Refund a Sale

**Primary actor:** Manager or authorized Admin

**Preconditions:** Original order exists; user has permission.

**Main flow:**

46. User locates the original order.

47. User selects eligible item/order void or refund action.

48. System displays original lines, payment details, and prior reversals.

49. User enters reason and amount/items to reverse.

50. System validates refundable quantity/amount and approval rules.

51. System creates a reversal/refund record; it does not delete original sale/payment records.

52. System records any required inventory return movement only when returned goods are accepted back into sellable stock.

53. System records cash drawer or non-cash refund impact.

54. System writes audit history and updates reporting totals.

**Alternative flows:** Already fully refunded; refund exceeds remaining refundable amount; user lacks permission; manager approval missing.

**Postconditions:** Original sale remains traceable and reversal is recorded.

**Related requirements:** FR-041 to FR-045.

## UC-006: Receive Purchase Order

**Primary actor:** Admin or Manager

**Preconditions:** Supplier exists; user has purchasing permission.

**Main flow:**

55. User creates purchase order and selects supplier.

56. User adds inventory items, ordered quantities, and expected cost.

57. User submits/approves purchase order.

58. On delivery, user opens receiving screen.

59. User records actual quantities, unit costs, lot/expiry data where applicable.

60. System creates goods-receipt records and inventory movements.

61. System updates purchase-order received quantities/status.

62. System records audit history.

**Alternative flows:** Partial delivery, damaged/expired items, over-delivery requiring approval, supplier mismatch.

**Postconditions:** Accepted stock is reflected in inventory; discrepancies are recorded.

**Related requirements:** FR-053 to FR-063, FR-058 to FR-060.

## UC-007: Post Production Batch

**Primary actor:** Manager or authorized Preparation Staff

**Preconditions:** Product has an active recipe; ingredients are available; user has production permission.

**Main flow:**

63. User selects product and recipe version.

64. User enters planned and actual output quantity.

65. System calculates required ingredients from the recipe and output quantity.

66. User reviews ingredient consumption.

67. User confirms batch posting.

68. System atomically deducts ingredients and adds finished goods.

69. System records batch, recipe version, consumption, output, rejects, and responsible user.

70. System updates inventory balances and movement history.

**Alternative flows:** Ingredient stock insufficient; recipe inactive; quantity invalid; posting retried. System must not partially post a batch.

**Postconditions:** Batch is posted once and ingredient/finished-good balances are consistent.

**Related requirements:** FR-064 to FR-072.

## UC-008: Open and Close Cashier Shift

**Primary actor:** Cashier

**Supporting actor:** Manager

Preconditions: Cashier is authenticated and assigned to an operating location/terminal.

**Main flow:**

71. Cashier starts shift and enters opening cash.

72. System records shift start and opening float.

73. Cash transactions are associated with the shift.

74. Cashier initiates closing and enters counted cash.

75. System calculates expected cash and variance.

76. Cashier enters explanation if variance exists.

77. Manager reviews if policy requires.

78. System closes shift and produces a shift summary.

**Alternative flows:** Existing shift is open; shift has unresolved transactions; manager rejects closure for correction.

**Postconditions:** Shift is closed and immutable except through controlled audited correction.

**Related requirements:** FR-073 to FR-082.

## UC-009: Review Sales and Inventory Reports

**Primary actor:** Owner, Admin, Manager (permission-dependent)

**Preconditions:** User is authenticated and authorized.

**Main flow:**

79. User opens Reports.

80. User selects report and date range.

81. User applies branch, cashier, category, or payment filters.

82. System calculates figures using documented definitions.

83. System displays results and charts/tables.

84. User exports or prints the report if authorized.

**Postconditions:** Report is displayed/exported without changing transactional data.

**Related requirements:** FR-088 to FR-097.

# 5. External Interface Requirements

## 5.1 User Interface

- Admin interface: navigation sidebar, dashboard KPIs, searchable/filterable tables, forms, charts, and approval dialogs.

- POS interface: high-visibility product categories, search, product grid, persistent cart, clear total, payment selection, and minimal navigation.

- Preparation interface: active order queue and clear status controls.

- Use Brevon blue/gold visual direction with accessible contrast. Proposed colors pending brand confirmation: deep blue \`#17365D\`, warm gold \`#D5A64A\`, cream \`#FFF8EB\`, charcoal \`#292D33\`.

- Responsive design must support the agreed desktop/tablet viewport sizes.

- All destructive/financial actions must require confirmation and, where configured, reason/approval.

## 5.2 Hardware Interfaces

- Thermal receipt printer: initial target 80mm; exact model and driver/browser printing method TBC.

- Cash drawer: optional, normally triggered through compatible receipt printer hardware.

- Barcode scanner: optional keyboard-wedge scanner.

- Tablet/desktop touch input: supported for the agreed browser and viewport.

## 5.3 Software Interfaces

- MySQL 8.4 for relational persistence.

- Optional Redis for cache and queue.

- File/object storage for product images and generated exports.

- No external payment gateway integration in initial scope unless approved.

- CSV/XLSX export and PDF generation for reports.

## 5.4 Communications

- Production traffic shall use HTTPS.

- The application shall use the configured application time zone and store timestamps consistently (UTC in database is recommended; display in Asia/Manila).

- External integrations must use credentials stored in environment/secret configuration, never in source control.

# 6. Non-Functional Requirements

| **ID**  | **Requirement**                                                                                                                                       | **Acceptance measure**                                         |
| ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| NFR-001 | The system shall require HTTPS in production.                                                                                                         | HTTP requests redirect to HTTPS; secure cookies enabled.       |
| NFR-002 | Passwords shall be stored using Laravel's supported secure password hashing.                                                                          | No plaintext or reversible password storage.                   |
| NFR-003 | The system shall enforce server-side authorization for all protected routes/actions.                                                                  | Automated tests prove unauthorized roles receive 403/denial.   |
| NFR-004 | The system shall validate and normalize all user input on the server.                                                                                 | Validation tests cover invalid, boundary, and malicious input. |
| NFR-005 | The system shall protect against CSRF, SQL injection, XSS, and common web vulnerabilities using framework controls and secure coding.                 | Security review and automated tests completed.                 |
| NFR-006 | The system shall use database transactions for critical multi-record operations.                                                                      | Failure-injection tests confirm rollback.                      |
| NFR-007 | Financial amounts shall use decimal arithmetic and DECIMAL database columns.                                                                          | No float-based monetary persistence/calculation.               |
| NFR-008 | Normal POS interactions shall target a server response time of 2 seconds or less under agreed normal load, excluding external printer/network delays. | Load test with agreed concurrency and dataset.                 |
| NFR-009 | Dashboard/report pages shall target 5 seconds or less for standard date ranges under agreed normal load.                                              | Performance test and query review.                             |
| NFR-010 | The initial service availability target shall be 99.5% monthly, excluding planned maintenance and third-party outages.                                | Hosting monitoring report.                                     |
| NFR-011 | The system shall perform automated database backups at least daily.                                                                                   | Backup job logs and restore test.                              |
| NFR-012 | The team shall test restoration of a backup before go-live and periodically thereafter.                                                               | Documented successful restore test.                            |
| NFR-013 | The system shall record audit events for sensitive business actions.                                                                                  | Audit test cases verify actor, action, timestamp, and entity.  |
| NFR-014 | The UI shall support agreed desktop and tablet screen sizes and current supported browsers.                                                           | Cross-browser and responsive test matrix.                      |
| NFR-015 | The system shall use Asia/Manila for business display and reporting.                                                                                  | Date-boundary and daylight/time-zone tests.                    |
| NFR-016 | The system shall support future branches without requiring redesign of core transactional tables.                                                     | Branch foreign keys and branch-scoped query tests.             |
| NFR-017 | The system shall provide maintainable modular code with automated tests for critical business services.                                               | Code review and CI test suite.                                 |
| NFR-018 | The system shall not expose secrets, payment credentials, or sensitive configuration in frontend code or logs.                                        | Security review.                                               |
| NFR-019 | The system shall preserve completed financial transactions and their original snapshots.                                                              | Regression tests confirm no ordinary hard-delete path.         |
| NFR-020 | The system shall present actionable validation and error messages without exposing stack traces to end users.                                         | Production error-handling test.                                |

# 7. Business Rules

| **ID** | **Business rule**                                                                                                                                                    |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BR-001 | Every order belongs to one branch and is created by an authenticated user.                                                                                           |
| BR-002 | Every completed sale has one or more payment records whose successful total equals the amount due, subject to approved partial-payment rules.                        |
| BR-003 | The server is authoritative for product price, discount, tax, and payable total.                                                                                     |
| BR-004 | Completed sales and payments are not physically deleted through normal workflows.                                                                                    |
| BR-005 | Refunds and voids are represented by traceable reversal records linked to the original order/payment.                                                                |
| BR-006 | Cash change equals cash tendered minus cash amount due; change is not additional revenue.                                                                            |
| BR-007 | Non-cash payments are excluded from physical cash drawer expected balance.                                                                                           |
| BR-008 | Expected drawer cash = opening float + cash tenders + cash-in - cash-out - cash refunds - cash paid-outs, with the exact components confirmed during implementation. |
| BR-009 | Finished-goods stock is reduced when sold if finished-goods tracking is enabled.                                                                                     |
| BR-010 | Ingredient stock is reduced at production/preparation according to the configured recipe consumption point; the same ingredient must not be deducted twice.          |
| BR-011 | Every inventory balance change must have a corresponding stock movement record.                                                                                      |
| BR-012 | Manual inventory adjustments require a reason and authorized user.                                                                                                   |
| BR-013 | Negative inventory is blocked by default.                                                                                                                            |
| BR-014 | Archived products remain visible in historical transactions and reports.                                                                                             |
| BR-015 | Product/price changes do not rewrite historical order item snapshots.                                                                                                |
| BR-016 | Order/receipt identifiers must be unique and concurrency-safe.                                                                                                       |
| BR-017 | A cashier must have an open shift to accept cash payments if shift enforcement is enabled.                                                                           |
| BR-018 | Tax, Senior Citizen/PWD discounts, official invoice wording, and BIR compliance rules must be approved by the business and its tax adviser before production use.    |
| BR-019 | GCash payment is manually verified in Version 1 unless a separately approved integration is implemented.                                                             |
| BR-020 | Business date and reporting boundaries use Asia/Manila.                                                                                                              |

# 8. Complete Proposed Database Schema

## 8.1 Database Conventions

- Database: MySQL 8.4.

- Primary keys: unsigned BIGINT auto-increment unless a UUID/ULID is justified.

- Timestamps: \`created_at\`, \`updated_at\`; soft-deletable master data uses \`deleted_at\` where appropriate.

- Money: \`DECIMAL(12,2)\`; cost and unit price snapshots use decimal precision appropriate to the business.

- Quantities: \`DECIMAL(14,3)\` or higher precision where fractional units are needed.

- Foreign keys: indexed and constrained; use restrictive deletes for financial/stock history.

- Operational timestamps: store UTC where feasible and render in Asia/Manila.

- All branch-scoped records carry \`branch_id\` directly or through a clearly defined parent relationship.

- Status fields should use PHP enums/constants and database constraints where supported.

- \`created_by\`/\`updated_by\` should be included on sensitive master/operational records where practical.

## 8.2 Identity, Authorization, and Organization

### \`branches\`

- \`id\` BIGINT PK

- \`code\` VARCHAR(30) UNIQUE NOT NULL

- \`name\` VARCHAR(150) NOT NULL

- \`address_line\` VARCHAR(255) NULL

- \`barangay\` VARCHAR(100) NULL

- \`city_municipality\` VARCHAR(100) NULL

- \`province\` VARCHAR(100) NULL

- \`postal_code\` VARCHAR(20) NULL

- \`phone\` VARCHAR(30) NULL

- \`email\` VARCHAR(150) NULL

- \`timezone\` VARCHAR(64) DEFAULT 'Asia/Manila'

- \`currency\` CHAR(3) DEFAULT 'PHP'

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`created_at\`, \`updated_at\`

### \`users\`

- \`id\` BIGINT PK

- \`name\` VARCHAR(150) NOT NULL

- \`username\` VARCHAR(80) UNIQUE NULL

- \`email\` VARCHAR(150) UNIQUE NOT NULL

- \`password\` VARCHAR(255) NOT NULL

- \`employee_id\` BIGINT NULL FK employees.id (add FK after employees migration if needed)

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`last_login_at\` TIMESTAMP NULL

- \`email_verified_at\` TIMESTAMP NULL

- \`remember_token\` VARCHAR(100) NULL

- \`created_at\`, \`updated_at\`, \`deleted_at\`

### \`employees\`

- \`id\` BIGINT PK

- \`employee_code\` VARCHAR(40) UNIQUE NOT NULL

- \`first_name\` VARCHAR(100) NOT NULL

- \`last_name\` VARCHAR(100) NOT NULL

- \`middle_name\` VARCHAR(100) NULL

- \`phone\` VARCHAR(30) NULL

- \`email\` VARCHAR(150) NULL

- \`hire_date\` DATE NULL

- \`employment_status\` VARCHAR(30) DEFAULT 'active'

- \`default_branch_id\` BIGINT FK branches.id

- \`notes\` TEXT NULL

- \`created_at\`, \`updated_at\`, \`deleted_at\`

### Authorization tables

Use Spatie Laravel Permission-compatible tables:

- \`roles\`: \`id\`, \`name\`, \`guard_name\`, timestamps; unique (\`name\`,\`guard_name\`).

- \`permissions\`: \`id\`, \`name\`, \`guard_name\`, timestamps; unique (\`name\`,\`guard_name\`).

- \`model_has_roles\`: \`role_id\`, \`model_type\`, \`model_id\`; composite index/PK.

- \`model_has_permissions\`: \`permission_id\`, \`model_type\`, \`model_id\`; composite index.

- \`role_has_permissions\`: \`permission_id\`, \`role_id\`; composite PK.

If branch-scoped roles are required later, add a dedicated user-branch assignment model rather than assuming global roles solve branch scoping.

### \`user_branch\`

- \`id\` BIGINT PK

- \`user_id\` BIGINT FK users.id

- \`branch_id\` BIGINT FK branches.id

- \`is_default\` BOOLEAN DEFAULT FALSE

- \`created_at\`, \`updated_at\`

- UNIQUE (\`user_id\`,\`branch_id\`)

### \`terminals\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`terminal_code\` VARCHAR(40) NOT NULL

- \`name\` VARCHAR(100) NOT NULL

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`created_at\`, \`updated_at\`

- UNIQUE (\`branch_id\`,\`terminal_code\`)

## 8.3 Product Catalog and Pricing

### \`categories\`

- \`id\` BIGINT PK

- \`parent_id\` BIGINT NULL FK categories.id

- \`name\` VARCHAR(100) NOT NULL

- \`slug\` VARCHAR(120) UNIQUE NOT NULL

- \`description\` TEXT NULL

- \`sort_order\` INT DEFAULT 0

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`created_at\`, \`updated_at\`, \`deleted_at\`

### \`products\`

- \`id\` BIGINT PK

- \`category_id\` BIGINT FK categories.id

- \`sku\` VARCHAR(60) UNIQUE NOT NULL

- \`name\` VARCHAR(150) NOT NULL

- \`description\` TEXT NULL

- \`product_type\` VARCHAR(30) NOT NULL (\`finished_good\`, \`made_to_order\`, \`retail\`)

- \`inventory_tracking_mode\` VARCHAR(30) NOT NULL (\`finished_stock\`, \`recipe_at_sale\`, \`none\`)

- \`base_unit\` VARCHAR(30) DEFAULT 'piece'

- \`image_path\` VARCHAR(255) NULL

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`is_available_for_pos\` BOOLEAN DEFAULT TRUE

- \`created_by\` BIGINT NULL FK users.id

- \`updated_by\` BIGINT NULL FK users.id

- \`created_at\`, \`updated_at\`, \`deleted_at\`

### \`product_variants\`

- \`id\` BIGINT PK

- \`product_id\` BIGINT FK products.id

- \`sku\` VARCHAR(60) UNIQUE NULL

- \`name\` VARCHAR(100) NOT NULL

- \`attributes\` JSON NULL

- \`is_default\` BOOLEAN DEFAULT FALSE

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`created_at\`, \`updated_at\`

### \`product_prices\`

- \`id\` BIGINT PK

- \`product_id\` BIGINT FK products.id

- \`variant_id\` BIGINT NULL FK product_variants.id

- \`branch_id\` BIGINT NULL FK branches.id (NULL means global/default price)

- \`price\` DECIMAL(12,2) NOT NULL

- \`cost_price\` DECIMAL(12,4) NULL

- \`effective_from\` DATETIME NOT NULL

- \`effective_until\` DATETIME NULL

- \`created_by\` BIGINT FK users.id

- \`created_at\`, \`updated_at\`

- Index (\`product_id\`,\`variant_id\`,\`branch_id\`,\`effective_from\`)

### \`product_branch\`

- \`id\` BIGINT PK

- \`product_id\` BIGINT FK products.id

- \`branch_id\` BIGINT FK branches.id

- \`is_available\` BOOLEAN DEFAULT TRUE

- \`created_at\`, \`updated_at\`

- UNIQUE (\`product_id\`,\`branch_id\`)

### \`option_groups\`

- \`id\` BIGINT PK

- \`name\` VARCHAR(100) NOT NULL

- \`selection_type\` VARCHAR(20) DEFAULT 'single' (\`single\`,\`multiple\`)

- \`min_selections\` INT DEFAULT 0

- \`max_selections\` INT NULL

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps

### \`product_option_group\`

- \`id\` BIGINT PK

- \`product_id\` BIGINT FK products.id

- \`option_group_id\` BIGINT FK option_groups.id

- \`sort_order\` INT DEFAULT 0

- UNIQUE (\`product_id\`,\`option_group_id\`)

### \`product_options\`

- \`id\` BIGINT PK

- \`option_group_id\` BIGINT FK option_groups.id

- \`name\` VARCHAR(100) NOT NULL

- \`price_delta\` DECIMAL(12,2) DEFAULT 0

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps

## 8.4 Customers, Orders, and Payments

### Customer management is deferred from Version 1. No \`customers\` table shall be created in the initial release.

- \`id\` BIGINT PK

-

- \`name\` VARCHAR(150) NOT NULL

- \`phone\` VARCHAR(30) NULL

- \`email\` VARCHAR(150) NULL

- \`address\` TEXT NULL

-

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps, \`deleted_at\`

### \`orders\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`terminal_id\` BIGINT NULL FK terminals.id

- \`cashier_shift_id\` BIGINT NULL FK cashier_shifts.id

- \`order_number\` VARCHAR(50) NOT NULL

- \`business_date\` DATE NOT NULL

-

-

-

- \`order_type\` VARCHAR(30) NOT NULL (\`walk_in\`,\`dine_in\`,\`takeout\`,\`advance_pickup\`,\`bulk\`)

- \`status\` VARCHAR(30) NOT NULL

- \`payment_status\` VARCHAR(30) NOT NULL DEFAULT 'unpaid'

- \`subtotal\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`discount_total\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`tax_total\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`service_charge\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`grand_total\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`amount_paid\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`amount_due\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`notes\` TEXT NULL

- \`pickup_at\` DATETIME NULL

- \`created_by\` BIGINT FK users.id

- \`completed_at\` DATETIME NULL

- \`cancelled_at\` DATETIME NULL

- \`cancelled_by\` BIGINT NULL FK users.id

- \`cancellation_reason\` TEXT NULL

- \`idempotency_key\` VARCHAR(100) NULL

- timestamps

- UNIQUE (\`branch_id\`,\`order_number\`)

- UNIQUE (\`branch_id\`,\`idempotency_key\`)

### \`order_items\`

- \`id\` BIGINT PK

- \`order_id\` BIGINT FK orders.id

- \`product_id\` BIGINT NULL FK products.id

- \`variant_id\` BIGINT NULL FK product_variants.id

- \`sku_snapshot\` VARCHAR(60) NOT NULL

- \`product_name_snapshot\` VARCHAR(150) NOT NULL

- \`variant_name_snapshot\` VARCHAR(100) NULL

- \`quantity\` DECIMAL(14,3) NOT NULL

- \`unit_price\` DECIMAL(12,2) NOT NULL

- \`option_total\` DECIMAL(12,2) DEFAULT 0

- \`discount_amount\` DECIMAL(12,2) DEFAULT 0

- \`tax_amount\` DECIMAL(12,2) DEFAULT 0

- \`line_total\` DECIMAL(12,2) NOT NULL

- \`notes\` TEXT NULL

- timestamps

### \`order_item_options\`

- \`id\` BIGINT PK

- \`order_item_id\` BIGINT FK order_items.id

- \`option_id\` BIGINT NULL FK product_options.id

- \`option_name_snapshot\` VARCHAR(100) NOT NULL

- \`price_delta_snapshot\` DECIMAL(12,2) NOT NULL DEFAULT 0

- timestamps

### \`order_status_histories\`

- \`id\` BIGINT PK

- \`order_id\` BIGINT FK orders.id

- \`from_status\` VARCHAR(30) NULL

- \`to_status\` VARCHAR(30) NOT NULL

- \`changed_by\` BIGINT FK users.id

- \`notes\` TEXT NULL

- \`created_at\`

### \`payment_methods\`

- \`id\` BIGINT PK

- \`code\` VARCHAR(30) UNIQUE NOT NULL

- \`name\` VARCHAR(80) NOT NULL

- \`type\` VARCHAR(30) NOT NULL (\`cash\`,\`e_wallet\`,\`card\`,\`other\`)

- \`requires_reference\` BOOLEAN DEFAULT FALSE

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps

### \`payments\`

- \`id\` BIGINT PK

- \`order_id\` BIGINT FK orders.id

- \`payment_method_id\` BIGINT FK payment_methods.id

- \`amount\` DECIMAL(12,2) NOT NULL

- \`amount_tendered\` DECIMAL(12,2) NULL

- \`change_amount\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`reference_number\` VARCHAR(120) NULL

- \`status\` VARCHAR(30) NOT NULL (\`pending\`,\`verified\`,\`failed\`,\`refunded\`,\`partially_refunded\`)

- \`verified_by\` BIGINT NULL FK users.id

- \`verified_at\` DATETIME NULL

- \`recorded_by\` BIGINT FK users.id

- \`paid_at\` DATETIME NULL

- timestamps

- Index (\`reference_number\`); enforce duplicate reference policy per payment method/branch as approved.

### \`refunds\`

- \`id\` BIGINT PK

- \`order_id\` BIGINT FK orders.id

- \`payment_id\` BIGINT NULL FK payments.id

- \`branch_id\` BIGINT FK branches.id

- \`refund_number\` VARCHAR(50) UNIQUE NOT NULL

- \`amount\` DECIMAL(12,2) NOT NULL

- \`refund_method\` VARCHAR(30) NOT NULL

- \`reason\` TEXT NOT NULL

- \`status\` VARCHAR(30) DEFAULT 'pending'

- \`requested_by\` BIGINT FK users.id

- \`approved_by\` BIGINT NULL FK users.id

- \`processed_at\` DATETIME NULL

- timestamps

### \`refund_items\`

- \`id\` BIGINT PK

- \`refund_id\` BIGINT FK refunds.id

- \`order_item_id\` BIGINT FK order_items.id

- \`quantity\` DECIMAL(14,3) NOT NULL DEFAULT 0

- \`amount\` DECIMAL(12,2) NOT NULL

- \`return_to_stock\` BOOLEAN DEFAULT FALSE

- timestamps

## 8.5 Discounts and Tax

### \`discounts\`

- \`id\` BIGINT PK

- \`code\` VARCHAR(50) UNIQUE NULL

- \`name\` VARCHAR(100) NOT NULL

- \`discount_type\` VARCHAR(20) NOT NULL (\`percentage\`,\`fixed\`)

- \`value\` DECIMAL(12,2) NOT NULL

- \`scope\` VARCHAR(20) NOT NULL (\`order\`,\`product\`,\`category\`)

- \`starts_at\` DATETIME NULL

- \`ends_at\` DATETIME NULL

- \`max_uses\` INT NULL

- \`requires_approval\` BOOLEAN DEFAULT FALSE

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps

### \`order_discounts\`

- \`id\` BIGINT PK

- \`order_id\` BIGINT FK orders.id

- \`discount_id\` BIGINT NULL FK discounts.id

- \`discount_name_snapshot\` VARCHAR(100) NOT NULL

- \`discount_code_snapshot\` VARCHAR(50) NULL

- \`discount_amount\` DECIMAL(12,2) NOT NULL

- \`reason\` TEXT NULL

- \`applied_by\` BIGINT FK users.id

- \`approved_by\` BIGINT NULL FK users.id

- timestamps

### \`tax_rules\`

- \`id\` BIGINT PK

- \`name\` VARCHAR(100) NOT NULL

- \`code\` VARCHAR(40) NULL

- \`rate\` DECIMAL(7,4) NOT NULL

- \`calculation_method\` VARCHAR(30) NOT NULL

- \`inclusive\` BOOLEAN DEFAULT FALSE

- \`effective_from\` DATE NOT NULL

- \`effective_until\` DATE NULL

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps

### \`order_tax_lines\`

- \`id\` BIGINT PK

- \`order_id\` BIGINT FK orders.id

- \`tax_rule_id\` BIGINT NULL FK tax_rules.id

- \`tax_name_snapshot\` VARCHAR(100) NOT NULL

- \`tax_rate_snapshot\` DECIMAL(7,4) NOT NULL

- \`taxable_amount\` DECIMAL(12,2) NOT NULL

- \`tax_amount\` DECIMAL(12,2) NOT NULL

- timestamps

## 8.6 Cashier Shifts and Cash Management

### \`cashier_shifts\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`terminal_id\` BIGINT NULL FK terminals.id

- \`user_id\` BIGINT FK users.id

- \`shift_number\` VARCHAR(50) NOT NULL

- \`opened_at\` DATETIME NOT NULL

- \`opening_cash\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`closed_at\` DATETIME NULL

- \`expected_cash\` DECIMAL(12,2) NULL

- \`counted_cash\` DECIMAL(12,2) NULL

- \`variance\` DECIMAL(12,2) NULL

- \`status\` VARCHAR(20) DEFAULT 'open'

- \`closing_notes\` TEXT NULL

- \`closed_by\` BIGINT NULL FK users.id

- \`approved_by\` BIGINT NULL FK users.id

- timestamps

- Index (\`branch_id\`,\`user_id\`,\`status\`)

### \`cash_movements\`

- \`id\` BIGINT PK

- \`cashier_shift_id\` BIGINT FK cashier_shifts.id

- \`branch_id\` BIGINT FK branches.id

- \`movement_type\` VARCHAR(30) NOT NULL (\`cash_in\`,\`cash_out\`,\`cash_refund\`,\`cash_paid_out\`,\`correction\`)

- \`amount\` DECIMAL(12,2) NOT NULL

- \`reason\` TEXT NOT NULL

- \`reference_type\` VARCHAR(100) NULL

- \`reference_id\` BIGINT NULL

- \`created_by\` BIGINT FK users.id

- \`approved_by\` BIGINT NULL FK users.id

- \`created_at\`

## 8.7 Inventory, Suppliers, Purchasing, and Production

### \`units_of_measure\`

- \`id\` BIGINT PK

- \`code\` VARCHAR(20) UNIQUE NOT NULL

- \`name\` VARCHAR(60) NOT NULL

- \`precision\` TINYINT DEFAULT 3

- timestamps

### \`inventory_items\`

- \`id\` BIGINT PK

- \`sku\` VARCHAR(60) UNIQUE NOT NULL

- \`name\` VARCHAR(150) NOT NULL

- \`item_type\` VARCHAR(30) NOT NULL (\`ingredient\`,\`packaging\`,\`finished_good\`,\`retail\`)

- \`unit_of_measure_id\` BIGINT FK units_of_measure.id

- \`reorder_level\` DECIMAL(14,3) DEFAULT 0

- \`is_perishable\` BOOLEAN DEFAULT FALSE

- \`track_lot\` BOOLEAN DEFAULT FALSE

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps, \`deleted_at\`

### \`branch_inventory\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`quantity_on_hand\` DECIMAL(14,3) NOT NULL DEFAULT 0

- \`average_unit_cost\` DECIMAL(14,4) NULL

- \`updated_at\`

- UNIQUE (\`branch_id\`,\`inventory_item_id\`)

### \`inventory_lots\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`lot_number\` VARCHAR(80) NULL

- \`received_at\` DATETIME NULL

- \`expires_at\` DATE NULL

- \`quantity_received\` DECIMAL(14,3) NOT NULL

- \`quantity_remaining\` DECIMAL(14,3) NOT NULL

- \`unit_cost\` DECIMAL(14,4) NULL

- \`supplier_id\` BIGINT NULL FK suppliers.id

- timestamps

### \`inventory_movements\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`inventory_lot_id\` BIGINT NULL FK inventory_lots.id

- \`movement_type\` VARCHAR(30) NOT NULL (\`opening\`,\`purchase_receipt\`,\`production_consumption\`,\`production_output\`,\`sale\`,\`waste\`,\`adjustment_in\`,\`adjustment_out\`,\`return_in\`,\`transfer_in\`,\`transfer_out\`)

- \`quantity_delta\` DECIMAL(14,3) NOT NULL (positive or negative)

- \`unit_cost\` DECIMAL(14,4) NULL

- \`balance_after\` DECIMAL(14,3) NULL

- \`reference_type\` VARCHAR(100) NULL

- \`reference_id\` BIGINT NULL

- \`reason\` TEXT NULL

- \`created_by\` BIGINT FK users.id

- \`created_at\`

- Index (\`branch_id\`,\`inventory_item_id\`,\`created_at\`)

### \`stock_adjustments\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`adjustment_number\` VARCHAR(50) UNIQUE NOT NULL

- \`status\` VARCHAR(20) DEFAULT 'draft'

- \`reason\` TEXT NOT NULL

- \`created_by\` BIGINT FK users.id

- \`approved_by\` BIGINT NULL FK users.id

- \`posted_at\` DATETIME NULL

- timestamps

### \`stock_adjustment_items\`

- \`id\` BIGINT PK

- \`stock_adjustment_id\` BIGINT FK stock_adjustments.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`system_quantity\` DECIMAL(14,3) NOT NULL

- \`counted_quantity\` DECIMAL(14,3) NOT NULL

- \`variance_quantity\` DECIMAL(14,3) NOT NULL

- \`unit_cost\` DECIMAL(14,4) NULL

- timestamps

### \`suppliers\`

- \`id\` BIGINT PK

- \`supplier_code\` VARCHAR(40) UNIQUE NOT NULL

- \`name\` VARCHAR(150) NOT NULL

- \`contact_person\` VARCHAR(120) NULL

- \`phone\` VARCHAR(30) NULL

- \`email\` VARCHAR(150) NULL

- \`address\` TEXT NULL

- \`payment_terms\` VARCHAR(100) NULL

- \`is_active\` BOOLEAN DEFAULT TRUE

- timestamps, \`deleted_at\`

### \`purchase_orders\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`supplier_id\` BIGINT FK suppliers.id

- \`po_number\` VARCHAR(50) UNIQUE NOT NULL

- \`status\` VARCHAR(30) NOT NULL (\`draft\`,\`submitted\`,\`partially_received\`,\`received\`,\`cancelled\`)

- \`ordered_at\` DATETIME NULL

- \`expected_at\` DATETIME NULL

- \`subtotal\` DECIMAL(12,2) NOT NULL DEFAULT 0

- \`notes\` TEXT NULL

- \`created_by\` BIGINT FK users.id

- \`approved_by\` BIGINT NULL FK users.id

- timestamps

### \`purchase_order_items\`

- \`id\` BIGINT PK

- \`purchase_order_id\` BIGINT FK purchase_orders.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`quantity_ordered\` DECIMAL(14,3) NOT NULL

- \`quantity_received\` DECIMAL(14,3) NOT NULL DEFAULT 0

- \`unit_cost\` DECIMAL(14,4) NOT NULL

- \`line_total\` DECIMAL(12,2) NOT NULL

- timestamps

### \`goods_receipts\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`purchase_order_id\` BIGINT NULL FK purchase_orders.id

- \`receipt_number\` VARCHAR(50) UNIQUE NOT NULL

- \`supplier_delivery_reference\` VARCHAR(100) NULL

- \`received_at\` DATETIME NOT NULL

- \`received_by\` BIGINT FK users.id

- \`notes\` TEXT NULL

- timestamps

### \`goods_receipt_items\`

- \`id\` BIGINT PK

- \`goods_receipt_id\` BIGINT FK goods_receipts.id

- \`purchase_order_item_id\` BIGINT NULL FK purchase_order_items.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`inventory_lot_id\` BIGINT NULL FK inventory_lots.id

- \`quantity_received\` DECIMAL(14,3) NOT NULL

- \`unit_cost\` DECIMAL(14,4) NULL

- \`condition\` VARCHAR(30) DEFAULT 'accepted'

- \`notes\` TEXT NULL

- timestamps

### \`recipes\`

- \`id\` BIGINT PK

- \`product_id\` BIGINT FK products.id

- \`version\` INT NOT NULL

- \`output_quantity\` DECIMAL(14,3) NOT NULL DEFAULT 1

- \`output_unit\` VARCHAR(30) NOT NULL DEFAULT 'piece'

- \`effective_from\` DATETIME NOT NULL

- \`effective_until\` DATETIME NULL

- \`is_active\` BOOLEAN DEFAULT TRUE

- \`created_by\` BIGINT FK users.id

- timestamps

- UNIQUE (\`product_id\`,\`version\`)

### \`recipe_items\`

- \`id\` BIGINT PK

- \`recipe_id\` BIGINT FK recipes.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`quantity_required\` DECIMAL(14,3) NOT NULL

- \`unit_of_measure_id\` BIGINT FK units_of_measure.id

- \`wastage_percent\` DECIMAL(7,4) DEFAULT 0

- timestamps

### \`production_batches\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`batch_number\` VARCHAR(50) UNIQUE NOT NULL

- \`product_id\` BIGINT FK products.id

- \`recipe_id\` BIGINT FK recipes.id

- \`planned_quantity\` DECIMAL(14,3) NOT NULL

- \`actual_quantity\` DECIMAL(14,3) NOT NULL

- \`rejected_quantity\` DECIMAL(14,3) NOT NULL DEFAULT 0

- \`produced_at\` DATETIME NOT NULL

- \`status\` VARCHAR(20) DEFAULT 'draft'

- \`notes\` TEXT NULL

- \`created_by\` BIGINT FK users.id

- \`posted_at\` DATETIME NULL

- timestamps

### \`production_batch_items\`

- \`id\` BIGINT PK

- \`production_batch_id\` BIGINT FK production_batches.id

- \`inventory_item_id\` BIGINT FK inventory_items.id

- \`quantity_consumed\` DECIMAL(14,3) NOT NULL

- \`unit_cost_snapshot\` DECIMAL(14,4) NULL

- timestamps

### \`waste_records\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`inventory_item_id\` BIGINT NULL FK inventory_items.id

- \`product_id\` BIGINT NULL FK products.id

- \`production_batch_id\` BIGINT NULL FK production_batches.id

- \`quantity\` DECIMAL(14,3) NOT NULL

- \`reason_code\` VARCHAR(40) NOT NULL

- \`reason_notes\` TEXT NULL

- \`recorded_by\` BIGINT FK users.id

- \`recorded_at\` DATETIME NOT NULL

- timestamps

## 8.8 Audit, Configuration, and Supporting Tables

### \`audit_logs\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT NULL FK branches.id

- \`user_id\` BIGINT NULL FK users.id

- \`event\` VARCHAR(100) NOT NULL

- \`auditable_type\` VARCHAR(150) NOT NULL

- \`auditable_id\` BIGINT NOT NULL

- \`old_values\` JSON NULL

- \`new_values\` JSON NULL

- \`ip_address\` VARCHAR(45) NULL

- \`user_agent\` TEXT NULL

- \`created_at\`

- Append-only through application policy.

### \`settings\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT NULL FK branches.id

- \`key\` VARCHAR(120) NOT NULL

- \`value\` TEXT NULL

- \`value_type\` VARCHAR(30) DEFAULT 'string'

- \`is_sensitive\` BOOLEAN DEFAULT FALSE

- timestamps

- UNIQUE (\`branch_id\`,\`key\`); secrets should be stored in environment/secret manager, not this table.

### \`number_sequences\`

- \`id\` BIGINT PK

- \`branch_id\` BIGINT FK branches.id

- \`sequence_type\` VARCHAR(40) NOT NULL

- \`business_date\` DATE NOT NULL

- \`last_number\` BIGINT NOT NULL DEFAULT 0

- timestamps

- UNIQUE (\`branch_id\`,\`sequence_type\`,\`business_date\`)

- Increment atomically under a database lock/transaction.

### \`notifications\`

Use Laravel notifications table or an equivalent standard table:

- \`id\` UUID/ULID PK

- \`type\` VARCHAR(255)

- \`notifiable_type\` VARCHAR(255)

- \`notifiable_id\` BIGINT

- \`data\` TEXT/JSON

- \`read_at\` TIMESTAMP NULL

- \`created_at\`, \`updated_at\`

### Framework tables

Include Laravel-required tables as appropriate for the chosen authentication/session/queue drivers:

- \`password_reset_tokens\`

- \`sessions\` (if database session driver)

- \`jobs\`

- \`job_batches\`

- \`failed_jobs\`

- \`cache\`

- \`cache_locks\`

## 8.9 Relationship Summary

- Branch has many users through \`user_branch\`, terminals, orders, shifts, inventory balances, purchase orders, production batches, and audit records.

- Category has many products; categories may have a parent category.

- Product has many variants, prices, recipes, and order items.

- Order belongs to branch, cashier/user, terminal, and shift; order has many items, payments, discounts, tax lines, status histories, and refunds.

- Order item belongs to order and optionally references product/variant; item options are snapshotted.

- Payment belongs to order and payment method; refunds may reference a payment.

- Inventory item has balances per branch, lots, movements, recipe lines, purchase lines, and waste records.

- Recipe belongs to product and has many recipe items.

- Production batch belongs to branch, product, and recipe; it has ingredient consumption records.

- Purchase order belongs to supplier and branch; it has lines and goods receipts.

- Cashier shift belongs to branch, user, and optional terminal; it has cash movements and associated orders.

- User has roles/permissions and is referenced by created/approved/audited records.

## 8.10 Required Indexes and Constraints

At minimum:

- Unique: branch code, employee code, SKU, category slug, payment method code, order number per branch, idempotency key per branch, terminal code per branch, inventory balance per branch/item, recipe version per product, sequence key.

- Index: orders (\`branch_id\`,\`business_date\`,\`status\`), orders (\`cashier_shift_id\`), payments (\`order_id\`,\`status\`), order items (\`product_id\`), inventory movements (\`branch_id\`,\`inventory_item_id\`,\`created_at\`), shifts (\`branch_id\`,\`user_id\`,\`status\`), purchase orders (\`branch_id\`,\`status\`), audit logs (\`auditable_type\`,\`auditable_id\`,\`created_at\`).

- Use restrictive foreign-key deletion for orders, payments, refunds, inventory movements, and audit history.

- Validate that quantity and monetary amounts are non-negative where semantically required; use signed \`quantity_delta\` only for inventory movement ledger entries.

- Enforce unique active business references and idempotency keys.

- Avoid polymorphic references for core financial relationships where a normal FK is practical.

## 8.11 Schema Implementation Notes

This is a logical schema baseline, not a substitute for reviewed Laravel migrations. The technical lead must resolve migration ordering, FK cycles (notably users/employees), enum strategy, branch-level permissions, stock costing method, unit conversions, and deletion policies before implementation. Add database constraints and indexes in migrations. Do not create all tables blindly without validating workflows and concurrency behavior.

# 9. Reporting Definitions

- \*\*Gross sales:\*\* Sum of completed order item line totals before order-level discounts, refunds, and tax presentation adjustments, using the approved accounting definition.

- \*\*Discounts:\*\* Sum of applied discount amounts for completed orders.

- \*\*Refunds:\*\* Approved/processed refund amounts during the selected report period. Reports should support both sale-date and refund-date views.

- \*\*Net sales:\*\* Must be explicitly defined with the owner/accountant; recommended operational view is gross sales less discounts and refunds, excluding separately collected tax where applicable.

- \*\*Collections:\*\* Successful payment amounts grouped by method and payment date; this is not necessarily equal to net sales in a period due to timing, partial payments, and refunds.

- \*\*Average order value:\*\* Net sales divided by completed order count, with the denominator definition documented.

- \*\*Cash variance:\*\* Counted cash minus expected cash at shift close.

- \*\*Inventory on hand:\*\* Posted inventory movements aggregated into branch/item balances; the balance table is a transactional projection that must reconcile to the ledger.

# 10. Testing and Acceptance

## 10.1 Test Levels

- Unit tests for pricing, discounts, tax calculations, cash reconciliation, recipe consumption, and stock calculations.

- Feature/integration tests for authentication, authorization, POS checkout, payment, refunds, purchase receiving, production posting, and reports.

- Database tests for unique constraints, locking, rollback, and idempotency.

- UI tests for cashier workflows, responsive layout, validation, and printer fallback.

- Security tests for authorization bypass, CSRF, input validation, and session handling.

- UAT with owner, manager, cashier, and preparation staff.

## 10.2 Minimum Go-Live Acceptance

85. All P0 requirements pass their approved test cases.

86. Critical sale/payment/inventory operations are atomic and idempotent.

87. Role and permission tests pass.

88. Receipt printing is verified on the approved hardware, or a documented browser-print fallback is accepted.

89. Daily reports reconcile to test transactions.

90. Cash shift expected balance and variance are verified.

91. Backup and restore are demonstrated.

92. Initial master data has been reviewed and approved by Brevon.

93. Staff training and quick-reference instructions are completed.

94. Owner signs UAT acceptance.

# 11. Deployment, Backup, and Operations

## 11.1 Environments

- Local development

- Shared development/test

- Staging/UAT

- Production

Production data must not be copied into development without approved masking/anonymization.

## 11.2 Backup and Recovery

- Automated daily database backup at minimum.

- Retention period and offsite storage location TBC.

- Recommended initial target: RPO 24 hours or better; RTO 8 business hours or better, subject to hosting budget and owner approval.

- Restore test before go-live and on a scheduled basis.

- Product images and uploaded files must be included in backup strategy.

## 11.3 Monitoring

- Application error monitoring.

- Web server and PHP-FPM health.

- Database disk/connection monitoring.

- Queue monitoring if Redis/queues are enabled.

- Backup success/failure alerts.

- Login and suspicious activity review.

## 11.4 Offline Operation

Version 1 is online-first. The system is not required to accept sales while disconnected from the server. Offline POS, local transaction storage, synchronization, and conflict resolution require a separate approved design and acceptance criteria.

# 12. Project Constraints, Risks, and Open Decisions

| **ID** | **Decision / Risk**                                                                                      | **Owner action required** |
| ------ | -------------------------------------------------------------------------------------------------------- | ------------------------- |
| OI-001 | Confirm exact product menu, SKUs, and prices.                                                            | Owner                     |
| OI-002 | Confirm whether doughnuts are batch-produced, made-to-order, or both.                                    | Owner/Manager             |
| OI-003 | Confirm recipes, ingredient units, yields, and wastage.                                                  | Owner/Production          |
| OI-004 | Confirm whether finished goods are counted individually and how unsold stock is handled.                 | Manager                   |
| OI-005 | Confirm payment methods and manual GCash verification procedure.                                         | Owner                     |
| OI-006 | Confirm cash drawer/terminal/shift policy.                                                               | Owner/Manager             |
| OI-007 | Confirm Senior Citizen/PWD and tax treatment with accountant.                                            | Owner/Accountant          |
| OI-008 | Confirm BIR registration/accreditation/invoicing obligations and approved hardware/software process.     | Owner/Accountant          |
| OI-009 | Confirm printer model, paper width, and printing workflow.                                               | Owner/Technical           |
| OI-010 | Confirm number of branches, terminals, concurrent users, and expected transaction volume.                | Owner                     |
| OI-011 | Confirm refund, void, discount, and manager-approval thresholds.                                         | Owner                     |
| OI-012 | Confirm backup retention, hosting budget, RPO, and RTO.                                                  | Owner/Technical           |
| OI-013 | Confirm whether customer data collection is required and define privacy notice/retention.                | Owner                     |
| OI-014 | Confirm stock costing method (e.g., weighted average) and unit conversion rules.                         | Owner/Accountant          |
| OI-015 | Confirm whether advance/bulk orders require deposits, partial payments, or balance collection on pickup. | Owner                     |

# 13. Change Control

Any change that affects approved scope, business rules, delivery dates, cost, integrations, or acceptance criteria shall be documented as a change request. The project manager shall assess impact on design, database, security, testing, and schedule. The business owner or authorized representative must approve the change before implementation.

# 14. Client Sign-Off

By signing below, the parties acknowledge that this document represents the proposed Version 1 requirements baseline. TBC items remain open and shall be resolved before the affected functionality is finalized. Approval does not imply that tax/BIR compliance or third-party payment integration is included unless expressly documented.

| **Role**              | **Name** | **Signature** | **Date** |
| --------------------- | -------- | ------------- | -------- |
| Brevon Business Owner |          |               |          |
| Project Manager       |          |               |          |
| Technical Lead        |          |               |          |

## Appendix A — Recommended Default Roles and Permission Groups

- \`dashboard.view\`

- \`pos.access\`, \`pos.create-sale\`, \`pos.apply-discount\`, \`pos.override-price\`

- \`orders.view\`, \`orders.update-status\`, \`orders.cancel\`

- \`payments.record\`, \`payments.verify-ewallet\`

- \`refunds.request\`, \`refunds.approve\`, \`voids.approve\`

- \`products.view\`, \`products.create\`, \`products.update\`, \`products.archive\`, \`products.manage-prices\`

- \`inventory.view\`, \`inventory.receive\`, \`inventory.adjust\`, \`inventory.approve-adjustment\`

- \`recipes.manage\`, \`production.create\`, \`production.post\`

- \`suppliers.manage\`, \`purchase-orders.create\`, \`purchase-orders.approve\`, \`purchase-orders.receive\`

- \`shifts.open\`, \`shifts.close\`, \`shifts.review\`, \`cash-movements.create\`

- \`reports.sales\`, \`reports.inventory\`, \`reports.financial\`, \`reports.export\`

- \`users.manage\`, \`roles.manage\`, \`settings.manage\`, \`audit.view\`

## Appendix B — Suggested Seed Data

- One initial branch: Brevon Doughnuts & Coffee (exact registered business/branch name TBC).

- Roles: Owner, Admin, Manager, Cashier, Preparation Staff.

- Payment methods: Cash, GCash (manual verification).

- Categories: Doughnuts, Coffee, Cold Drinks, Other (confirm with owner).

- Order types: Walk-in, Dine-in, Takeout, Advance Pickup, Bulk.

- Time zone: Asia/Manila.

- Currency: PHP.

- Initial product/price data must be imported only after owner approval.

## Appendix C — Laravel 13 Technical Baseline

Laravel 13 was released on 17 March 2026. The official support table lists PHP 8.3–8.5, bug fixes through Q3 2027, and security fixes through 17 March 2028. Use PHP 8.4 as the conservative project target if supported by the chosen hosting image and dependencies; PHP 8.3 is the framework minimum. Confirm current patch versions and package compatibility at project initialization. See https://laravel.com/docs/13.x/releases.
