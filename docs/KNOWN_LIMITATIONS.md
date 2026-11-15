# Brevon POS — Known Limitations (Version 1)

What Version 1 deliberately does **not** do, and the defaults that still need the owner's or accountant's
confirmation. Written in Phase 11b (2026-11-09). Decision numbers refer to [`DECISIONS.md`](DECISIONS.md); SRS
references to [`BREVON-SRS-v1.1.md`](BREVON-SRS-v1.1.md).

None of these is a defect. Each can be added later through a change request.

---

## 1. Outside Version 1 (SRS §1.3)

| Area            | Limitation                                                                                                                                |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Internet        | **Online only.** A sale cannot be made while the tablet cannot reach the server (SRS §11.4). No offline mode or later sync.               |
| Apps            | No native iOS or Android app. The POS runs in the tablet's browser.                                                                       |
| Customers       | No customer records, loyalty points or memberships (FR-050). The only personal data kept is the encrypted SC/PWD beneficiary row (D-068). |
| Online ordering | No link to the public website, delivery apps or marketplaces.                                                                             |
| GCash           | Manual only: the cashier checks the transfer and types the reference (D-172). No automatic confirmation or settlement.                    |
| Accounting      | No payroll, HR, tax filing or general ledger.                                                                                             |
| Receipts        | **Not BIR-accredited** official receipts or invoices (FR-087).                                                                            |
| Branches        | One permanent branch plus pop-up locations (D-063). No second permanent branch or group reporting.                                        |

## 2. Removed or held back at the client's request

| Feature                                    | Status                                                                                                                                                  |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tax (VAT and other taxes)                  | Removed; the business is non-VAT (D-165). Prices are final.                                                                                             |
| Options and add-ons (extra shot, oat milk) | Removed (D-166). Sizes and variants stay.                                                                                                               |
| Lots and expiry dates                      | Removed (D-167). Perishable items can still be written off as waste.                                                                                    |
| Preparation board and order steps          | Removed (D-169). Every order is completed at checkout; corrections are refunds.                                                                         |
| Separate GCash check after the sale        | Removed (D-168).                                                                                                                                        |
| Walk-in and Bulk order types               | Removed (D-181). Order types are Dine-in, Takeout and Advance Order.                                                                                    |
| Purchasing (suppliers, orders, deliveries) | Removed for now; a future feature (D-188, FE-02).                                                                                                       |
| Pop-up receive confirmation                | Not built (FE-01, OI-018). A dispatch to a pop-up posts at once; unsold doughnuts stay in the pop-up's stock and are handled with waste or adjustments. |

## 3. Selling and payments

- **One payment per order.** No split payment, e.g. part cash, part GCash (D-040).
- **Paid in full at order time**, including Advance Orders. No deposits or balance on pickup (D-043). An Advance
  Order has no pickup date or time field; write it in the order note.
- **Pop-ups sell doughnuts only.** Drinks cannot be offered at a pop-up (D-063).
- **One discount per order** (D-146).
- **One SC/PWD beneficiary per order.** A group of seniors pays in separate orders (D-148).
- **Refunds, not edits.** A finished sale is never changed. Mistakes are fixed with a refund (D-150, D-169).
- **Printing goes through the browser's print dialog**, on 80 mm paper (D-052). Printing straight to a Bluetooth
  printer without the dialog is not built yet (OI-009, see §6).
- **No cash drawer** is opened by the system.

## 4. Stock and costs

- **Stock cannot go below zero**, and there is no override (D-047, D-139).
- **Weighted average cost** is the only costing method (D-048, TBC OI-014).
- **Only finished-stock products are counted** (doughnuts and retail items). Drinks, boxes and combos take nothing
  from stock (D-189).
- **Reorder levels are set on the product form** by the Owner (D-189).
- **Average cost is not shown** anywhere; it is still kept for a later feature (D-191, FE-04).
- **Waste is valued at the selling price** on the day it was written off, not at cost. Items without a selling price
  count as ₱0.00 (D-191).
- **Costs do not update after opening stock.** Restocks are entered as adjustments, which keep the existing average
  cost (D-188, D-189).

## 5. Access and devices

- **Terminals are chosen when a shift opens.** A terminal is not locked to a physical tablet (D-060, OI-006).
- **Sign-in times out after 120 minutes without activity** (`SESSION_LIFETIME`). "Remember me" keeps a person
  signed in. There is no quick PIN switch between cashiers and no lock screen: each person signs out and the next
  signs in.
- After sign-in, people only return to the page they were on if their role may open it (D-186).
- Two-factor sign-in is available but optional.
- The screens are in English and amounts in Philippine pesos only.

## 6. Defaults still to confirm (TBC)

These work today with the default shown and can be changed on screen, except where noted.

| Item                                                | Default today                                                                                                               | Who confirms                                    | Ref                  |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- | -------------------- |
| SC/PWD discount rate                                | 12% of the beneficiary's items, VAT disregarded, switched **on**. The law (RA 9994 / RA 10754) sets 20% plus VAT exemption. | Accountant                                      | D-068, D-175, OI-007 |
| SC/PWD privacy notice and how long details are kept | Not written yet                                                                                                             | Owner                                           | OI-019               |
| Costing method                                      | Weighted average (code change to switch)                                                                                    | Accountant                                      | D-048, OI-014        |
| Sales report definitions                            | Net = gross − discounts − refunds, counted on the sale date                                                                 | Accountant                                      | D-156                |
| Receipt wording                                     | "Order Receipt — not an official receipt/invoice"                                                                           | Accountant                                      | D-037, OI-008        |
| Discount needing a manager                          | Over ₱100.00                                                                                                                | Owner                                           | D-146                |
| Refund needing a manager                            | Over ₱0.00 (every refund)                                                                                                   | Owner                                           | D-152, OI-011        |
| GCash reference format                              | 6–40 letters and digits, used once only                                                                                     | Owner                                           | D-109, OI-005        |
| Receipt printer                                     | Browser print, 80 mm                                                                                                        | Owner (tablet type, printer model, paper width) | D-052, OI-009        |

## 7. Operations

- **Backups stay on the server** until someone copies them elsewhere (see [`DEPLOYMENT.md`](DEPLOYMENT.md) §6).
- Report speed was tested with about 22,000 orders over 180 days. A 180-day sales report grouped by month took
  about 1.4 s; everything else was under 1 s.
- Accessibility was checked at tablet size (portrait and landscape) in light and dark mode: labels, headings,
  keyboard focus, touch targets and WCAG AA text contrast. It was not tested with a screen reader by a person who
  uses one.
