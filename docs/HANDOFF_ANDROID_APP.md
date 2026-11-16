# Handoff — Brevon POS Android app (written 2026-11-15)

For continuing this work in a new Claude Code session (e.g. on the Windows desktop with Android Studio).
Start the session in the project folder and say: _"Read docs/IMPLEMENTATION_STATUS.md, docs/DECISIONS.md and
docs/HANDOFF_ANDROID_APP.md, then continue the Android app."_

## Where the project stands

- All phases (0–11b) are built and approved; 11b approved 2026-11-15. See `IMPLEMENTATION_STATUS.md`.
- Quality gate: `composer check` (build, `vp check`, `vue-tsc`, Pint, Larastan, PHPUnit on MySQL `brevon_pos_test`).
  `composer check` passes (see IMPLEMENTATION_STATUS.md for the latest count).
- Hosting decided: **Oracle Cloud Always Free**, one Arm VM with the app and MySQL 8.4 (`DEPLOYMENT.md` §10).
  Vercel was rejected (Hobby plan is non-commercial; no PHP/MySQL/persistent files).
- A PWA was built and then **reverted** at the user's request (2026-11-15). Don't rebuild it unless asked.
- Client changes 2026-11-15: Cash is the default payment method and cannot be switched off (D-187); purchasing
  (suppliers, purchase orders, deliveries) removed as a future feature (D-188, FE-02); recipes, production, stock
  items and stock value removed (D-189, FE-03), reorder level now on the product form.

## Ground rules (from the user)

- Use only the databases `brevon_pos` (the user's own data, never reset or write test data) and
  `brevon_pos_test` (tests and browser checks). Never touch any other database on the server.
- Commit or push only when the user asks.
- Don't change the user's account credentials. Never write the production database password into any file except
  the server's `.env`.
- Held client questions are **parked**; don't raise them unless the user does: menu (D-101), Phase 7/8 re-review,
  SC/PWD rate and privacy, costing, report definitions and approval limits. (Printer model now known: D-194.)
- Never claim BIR compliance. No customer data except the encrypted SC/PWD row.

## The Android app — decided so far

- The **cashier's counter tablet is Android**. Owners have Android and iPad and use the POS **in the browser**;
  no iPad app, no Apple fee.
- Goal: the POS inside an Android app that prints receipts **straight to the paired Bluetooth printer, with no
  print dialog**. Browser printing stays for every other device and as the fallback.
- **Printer confirmed (2026-11-16, D-194): Xprinter XP-58IID**, Bluetooth + USB, **58 mm** paper, ESC/POS,
  384 dots a line = 32 characters. The counter tablet is Android (version unknown, so handle Android 12+
  Bluetooth permissions and older ones). The client says the printer prints `₱`; we draw it ourselves anyway
  (user-defined character), so it does not depend on the code page. Expect Bluetooth Classic (SPP).
- **Step 1 (server ESC/POS) is DONE (2026-11-16).** Steps 2–5 are not started.

## Proposed design (agreed in outline, not yet built)

1. **DONE — Server: receipts as ESC/POS** (`app/Domain/Printing/EscPosReceipt.php`;
   `GET /pos/orders/{order}/receipt/escpos?paper=58|80` and `GET /pos/printer/test?paper=58|80` return
   `{paper, columns, data}` with `data` = base64 bytes to write to the printer as they are; reprints are
   still audited by `POST /pos/orders/{order}/reprint`, which the app must call first on a reprint;
   tests in `tests/Unit/Domain/Printing` and `tests/Feature/Sales/EscPosReceiptEndpointTest.php`).
   Original plan: a Laravel class that turns a receipt (same data as
   `resources/js/components/pos/ReceiptView.vue`) into ESC/POS bytes, with the 58 mm (32 columns) layout for
   the XP-58IID first and 80 mm (48 columns) optional; an endpoint returning them (base64) for an order the user may reprint, audited like a
   reprint. Full PHPUnit coverage. Keep the "not an official receipt" line. Check how `₱` prints; fall back to `P`
   or `PHP`.
2. **POS: detect the app** — when running inside the app (Capacitor bridge present), after a sale and on reprint
   send the bytes to the app's printer bridge; otherwise keep today's browser print. If direct printing fails, the
   sale stays saved and browser print is offered. A sale must never depend on printing (invariant 14).
3. **Android shell (Capacitor)** inside this repo (`android/` + `capacitor.config.*`), loading the POS from the
   server URL (HTTPS in production; allow the LAN dev address only in debug builds). A small settings screen: pick a
   paired Bluetooth printer, paper width, test print.
4. **Bluetooth printing** — our own small native plugin (Bluetooth Classic SPP, plus BLE if needed) that sends raw
   bytes to the chosen printer, rather than a little-used third-party package. Handle Android 12+ Bluetooth
   permissions.
5. **Distribution** — sideload the signed APK on the shop's tablets (free); Google Play is optional ($25 once).
   Keep the signing key safe and out of git.

Done since: the `₱` glyph was checked on the real printer (looks right), and the logo now prints at the top, 32 mm
shaded (D-195). Still to check on the printer: the logo print and that 32 characters fit; layout fine-tuning.

## Setting up the Windows desktop

1. Install **Git**, **PHP 8.4** with the extensions in `DEPLOYMENT.md` §1 (Laravel Herd for Windows is the easiest
   way), **Composer**, **Node.js 20+**, **MySQL 8.4** and **Android Studio** (brings Java and the Android SDK).
2. `git clone git@github.com:eichebiem/brevon-pos.git` (or the HTTPS address).
3. Copy `.env` from the Mac by USB or a password manager (it is not in git and holds `APP_KEY`). Adjust `DB_*`
   for the Windows MySQL.
4. In MySQL create `brevon_pos` and `brevon_pos_test` and a `brevon` user with rights on only those two
   (`DEPLOYMENT.md` §2).
5. `composer install`, `npm install`, then `php artisan migrate:fresh --seed` on the **new, empty** Windows
   database (to bring the Mac's data instead, `mysqldump` it on the Mac and import it).
6. `composer check` should pass before any app work starts.
