# Brevon POS — User Acceptance Test (UAT) Checklist

For the shop's owner and staff to try every main task on the real tablets **before go-live** and sign off.
Run it on a **training copy** (never on the live shop data), set up as in [`DEPLOYMENT.md`](DEPLOYMENT.md).
Limits that are known and accepted are listed in [`KNOWN_LIMITATIONS.md`](KNOWN_LIMITATIONS.md).

**How to use:** do each step as the role shown. Write ✅ when the result matches, or ❌ plus a short note.
Use the order or shift number in the notes so the problem can be found.

Tester(s): ____________________ Date: ____________ Device(s): ____________________ Version: ________

---

## A. Sign-in and accounts (Owner)

| #   | Step                                                                          | Expected result                                                                       | ✅ / ❌ | Notes |
| --- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- | ------- | ----- |
| A1  | Sign in with username and password                                            | Lands on the Dashboard                                                                |         |       |
| A2  | Sign in with a wrong password                                                 | One general "credentials do not match" message that does not say which part was wrong |         |       |
| A3  | Settings → Security: change your password, sign out, sign in with the new one | Works; old password refused                                                           |         |       |
| A4  | Users → create a Cashier at the main branch                                   | Cashier appears in the list                                                           |         |       |
| A5  | Sign in as that Cashier                                                       | Lands on the POS; no Administration menu                                              |         |       |
| A6  | As Owner, deactivate the Cashier, then try to sign in as them                 | Sign-in refused                                                                       |         |       |
| A7  | Roles: Owner, Manager, Cashier listed first                                   | Order is Owner, Manager, Cashier, then others                                         |         |       |
| A8  | "Forgot password" with a real e-mail                                          | E-mail arrives; link sets a new password                                              |         |       |

## B. Shift and selling (Cashier)

| #   | Step                                                                                  | Expected result                                                                         | ✅ / ❌ | Notes |
| --- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ------- | ----- |
| B1  | Open a shift: choose the terminal, count opening cash                                 | POS shows products for that location                                                    |         |       |
| B2  | Tap three different doughnuts, one twice                                              | Order shows the items, quantities and total                                             |         |       |
| B3  | Choose Dine-in, Takeout, then Advance Order                                           | All three are offered                                                                   |         |       |
| B4  | Pay **Cash** with more than the total                                                 | Change is shown; sale completes; receipt opens                                          |         |       |
| B5  | Print the receipt on the counter printer                                              | 80 mm receipt with shop name, address, items, total, "This is not an official receipt." |         |       |
| B6  | Sell and pay **GCash**: enter a reference, tick "I checked that this payment arrived" | Sale completes; reference printed                                                       |         |       |
| B7  | Try the same GCash reference again                                                    | Refused as already used                                                                 |         |       |
| B8  | Try to sell more of an item than is in stock                                          | Refused with a clear message                                                            |         |       |
| B9  | Orders: find today's sales and reprint one receipt                                    | The same receipt prints again; the reprint is in the audit log                          |         |       |
| B10 | Apply a preset discount under the approval limit                                      | Discount shows on the order and receipt                                                 |         |       |
| B11 | Apply a discount over the limit                                                       | Asks a manager to type their username and password                                      |         |       |
| B12 | SC/PWD discount: enter name, ID number, address; tick the beneficiary's items         | 12% off those items; details masked on the receipt                                      |         |       |
| B13 | Leave the tablet idle past the sign-in timeout, then tap                              | Asked to sign in again; nothing lost from completed sales                               |         |       |

## C. Refunds (Cashier + Manager)

| #   | Step                                                       | Expected result                                  | ✅ / ❌ | Notes |
| --- | ---------------------------------------------------------- | ------------------------------------------------ | ------- | ----- |
| C1  | Open a completed cash sale → Refund one item with a reason | Asks a manager's approval (with default limits)  |         |       |
| C2  | Manager approves at the counter                            | Refund saved; cash comes out of the drawer total |         |       |
| C3  | Refund a GCash sale                                        | Asks for the reference of the money sent back    |         |       |
| C4  | Refund with "put back in stock" ticked, and once without   | Stock goes back only when ticked                 |         |       |

## D. Cash and closing the shift (Cashier + Manager)

| #   | Step                                                            | Expected result                                      | ✅ / ❌ | Notes |
| --- | --------------------------------------------------------------- | ---------------------------------------------------- | ------- | ----- |
| D1  | Manager records a paid-out (e.g. ice ₱150)                      | Shows on the shift                                   |         |       |
| D2  | Record a cash out of ₱600                                       | Counts at once; no approval step                     |         |       |
| D3  | Cashier closes the shift with a blind count                     | The expected amount is **not** shown before counting |         |       |
| D4  | Close with a count that is ₱20 short                            | Shift marked "Needs review"                          |         |       |
| D5  | Another manager reviews the shift and adds a note or correction | Shift marked reviewed; closed figures unchanged      |         |       |

## E. Stock (Owner + Manager)

| #   | Step                                                   | Expected result                                                                                          | ✅ / ❌ | Notes |
| --- | ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- | ------- | ----- |
| E1  | Owner: set a doughnut's reorder level                  | Saved                                                                                                    |         |       |
| E2  | Opening stock: post quantities                         | Stock on hand shows them (no average cost column)                                                        |         |       |
| E3  | Counts & adjustments: count an item, save a difference | A Manager's adjustment posts at once; one entered by someone without approval rights waits for a manager |         |       |
| E4  | Record waste for 2 doughnuts with a reason             | Stock drops; Waste report values them at the selling price                                               |         |       |
| E5  | Dispatches: send 20 doughnuts from main to a pop-up    | Main goes down 20; pop-up goes up 20                                                                     |         |       |
| E6  | Lower an item below its reorder level                  | Low-stock count on the Dashboard                                                                         |         |       |
| E7  | Stock movements: find each movement above              | Every change is listed with who and why                                                                  |         |       |

## F. Reports (Owner)

| #   | Step                                                             | Expected result                                                  | ✅ / ❌ | Notes |
| --- | ---------------------------------------------------------------- | ---------------------------------------------------------------- | ------- | ----- |
| F1  | Dashboard for today                                              | Gross, net, orders and average match the sales made today        |         |       |
| F2  | Sales report: by day, product, category, cashier, payment method | Totals agree with each other and with the Dashboard              |         |       |
| F3  | Refunds & voids, Discounts given, Cash differences               | Show the refunds, discounts and short shift from the tests above |         |       |
| F4  | Stock in & out, Waste                                            | Show the stock tests above                                       |         |       |
| F5  | Export one report to Excel and to CSV; print one                 | Files open correctly; print fits the page                        |         |       |
| F6  | Filter a report to a pop-up                                      | Only that location's figures                                     |         |       |

## G. Administration and safety (Owner)

| #   | Step                                                                     | Expected result                                                    | ✅ / ❌ | Notes |
| --- | ------------------------------------------------------------------------ | ------------------------------------------------------------------ | ------- | ----- |
| G1  | Store settings: change the receipt footer                                | New footer prints on the next receipt                              |         |       |
| G2  | Locations: add a pop-up and a terminal; assign a cashier                 | Cashier can open a shift there                                     |         |       |
| G3  | Products: add a product with a photo; set its price; hide it at a pop-up | Shows at main only                                                 |         |       |
| G4  | Payment methods: try to untick Cash                                      | Not possible; Cash shows Default and stays on                      |         |       |
| G5  | Audit log: find the changes made above                                   | Each shows who, what and when                                      |         |       |
| G6  | System logs: page opens                                                  | Shows health, last backup and recent entries; no passwords visible |         |       |
| G7  | As a Cashier, open an Administration address directly                    | "You don't have access" page, not an error                         |         |       |
| G8  | Open the site with `http://`                                             | Moves to `https://`                                                |         |       |

## H. On the tablet

| #   | Step                                  | Expected result                                                | ✅ / ❌ | Notes |
| --- | ------------------------------------- | -------------------------------------------------------------- | ------- | ----- |
| H1  | POS in portrait and landscape         | Products and the order are readable; nothing cut off           |         |       |
| H2  | Buttons are easy to tap with a finger | No mis-taps on +, −, Remove, Cash, GCash                       |         |       |
| H3  | Turn Wi-Fi off and try to sell        | Clear error; no sale is recorded; works again when back online |         |       |

---

## Sign-off

Problems found (❌) and how they were settled:

| #   | Problem | Agreed action | Done |
| --- | ------- | ------------- | ---- |
|     |         |               |      |

We confirm that Brevon POS was tested as above and is accepted for go-live, with the limits listed in
`KNOWN_LIMITATIONS.md`.

| Role      | Name | Signature | Date |
| --------- | ---- | --------- | ---- |
| Owner     |      |           |      |
| Manager   |      |           |      |
| Developer |      |           |      |
