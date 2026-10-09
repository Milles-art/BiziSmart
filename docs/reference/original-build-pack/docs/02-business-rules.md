> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 02 — Business Rules (with worked examples)

All amounts are integer TSh. If a rule here conflicts with code, the rule wins; tell the human.

## 1. Money and rounding
- Store and compute money as integers. Never floats.
- Prices are **VAT-inclusive**. VAT is computed on the sale total: `vat = round_half_up(total * rate / (100 + rate))`. Rate is per business (`vat_rate`, default 18; 0 if not VAT-registered). Confirm the rate before launch.
- Example: total 11,800 at 18% -> vat = 11,800 x 18 / 118 = **1,800**; net = 10,000.
- Line total = `qty * unit_price - discount`. Sale `subtotal` = sum of line totals. `total` = subtotal - sale-level discount. Discounts cannot make a line or sale negative.

## 2. Stock and batches
- Stock is tracked per **batch** in the smallest sellable unit (e.g. tablet). No unit conversion in MVP.
- `qty_remaining` changes only through `StockService`, inside a transaction, after `lockForUpdate()` on the batch, and every change writes a `stock_movements` row (type, qty_change, qty_after, reason, user, sale_id if any).
- Movement types: `receive`, `sale`, `refund_return`, `adjustment`, `stock_take`, `transfer_out`, `transfer_in`, `sync_sale`.
- Adjustments need a reason and (for Owner-defined threshold) supervisor PIN.

## 3. FEFO (First Expiry, First Out) — pharmacy
- A batch is **sellable** if `qty_remaining > 0` and `expiry_date >= today` (valid through its expiry date; assumption D010).
- The system picks batches ordered by `expiry_date ASC, id ASC`.
- Example: batch A (exp 2026-12-31, qty 10), batch B (exp 2027-03-31, qty 20), batch C (exp 2026-06-30, qty 50; today is 2026-10-08, so C is expired). Sell 15 -> C is skipped (expired); take 10 from A, 5 from B. Result: A=0, B=15. Sale items: (A,10), (B,5).
- If total sellable stock < requested qty -> online: `INSUFFICIENT_STOCK` (message states the available qty). Never sell from an expired batch online (`EXPIRED_STOCK` if the client explicitly requested one).
- Cashier may choose a specific batch only if the Owner enabled it; otherwise FEFO is automatic.
- Expiry alerts: list batches expiring within 30/60/90 days with `qty_remaining > 0`.

## 4. Sales
- A sale has items, payments, optional customer, cashier, branch, till session.
- Statuses: `completed`, `held` (no stock effect), `refunded` (fully), `partially_refunded`, `committed_with_conflict` (offline sync).
- Payment methods: `cash`, `mpesa`, `credit` (on customer account), `split` (>= 2 of the others). Store each payment as a row in `sale_payments`.
- Payments must satisfy: `sum(cash + mpesa + credit) >= total` for cash tendered; for non-cash methods the amount equals what is allocated. Change = cash tendered - cash allocated (cash only).
- Examples:
  - Cash: total 8,500; tendered 10,000 -> cash allocated 8,500, change 1,500.
  - Split: total 8,500; cash 5,000 + M-Pesa 3,500 -> change 0.
  - Invalid: total 8,500; cash 5,000 + M-Pesa 3,000 -> `PAYMENT_MISMATCH` (short by 500) unless the remainder is put on credit for a customer.
- M-Pesa part: sale is created **only after** the STK push is confirmed (online). Timeout 60s -> `MPESA_TIMEOUT`, no sale created.
- Receipt number: `RCP-{year}-{business sale sequence, zero padded 6}` unique per business.
- Held sale: saved cart, no stock change, expires after 24h, belongs to branch.

## 5. Credit sales and customer debt
- A credit sale requires a customer. Credit amount = total - cash - mpesa.
- Allowed only if `outstanding_balance + credit_amount <= credit_limit`; else `CREDIT_LIMIT_EXCEEDED`.
- Example: limit 50,000, outstanding 30,000: credit of 20,000 OK (-> 50,000); credit of 25,000 rejected.
- Debt payment (`POST /customers/{id}/payments`) reduces `outstanding_balance`. Payment cannot exceed the balance; excess is rejected with `OVERPAYMENT` (MVP: no overpayment credit).
- Aging buckets for reports: 0-30, 31-60, 61-90, 90+ days by sale date.

## 6. Refunds and voids
- Refund needs supervisor PIN (Owner or Pharmacist/Manager role with `can_refund`) and a reason.
- Full or partial (by item qty). Refunded qty returns to the **same batch** (`refund_return` movement) unless the batch was recalled/expired, in which case it is returned as quarantined stock (qty not sellable; flagged).
- Cash refund reduces expected till cash. M-Pesa refunds are manual in MVP (record only).
- Void of a held sale needs no PIN. Void of a completed sale = refund.

## 7. Till management
- A cashier must have an open till session to make any sale (one simple rule).
- One open session per cashier per branch (`TILL_ALREADY_OPEN`).
- `expected_cash = opening_float + cash received on sales (net of change) + cash debt payments - cash refunds - cash paid-outs`.
- `variance = counted_cash - expected_cash`.
- Example: float 20,000; cash sales 150,000; refunds 5,000; counted 164,000 -> expected 165,000, variance **-1,000** (OK).
- If `abs(variance) > 5,000` (per-business setting) closing requires supervisor approval; owner is notified.
- Closed sessions are immutable.

## 8. Users, PINs, devices, plans
- PIN: 4-6 digits, hashed. 5 wrong attempts -> locked 15 minutes (assumption D013).
- Login = phone + PIN on a **registered device**. First login on a new device creates a pending device; Owner must approve (Owner's own first device is auto-approved). Owner also needs TOTP 2FA.
- Auto-logout after 30 minutes inactivity (frontend) and tokens expire (see API contract).
- Plan limits: users, products, features. Exceeding -> `PLAN_LIMIT_REACHED`. Disabled features return `FEATURE_DISABLED` (403).
- Permissions per role are in the matrix in `features/F01-auth-devices-roles.md`.

## 9. Offline sales (Phase 3) — accept and flag
A sale that already happened at the counter is **always recorded** on sync.
| Situation at sync | Result |
|---|---|
| Stock goes negative | Commit; allow negative `qty_remaining`; `NEGATIVE_STOCK` conflict; owner alert |
| Batch expired/recalled | Commit; `EXPIRED_BATCH` / `RECALLED_BATCH` conflict |
| Price differs > 5% from current | Commit at offline price; `PRICE_CHANGED` conflict |
| Duplicate `operation_id` | Acknowledge; do nothing |
| Malformed payload | Reject (`VALIDATION_ERROR`) |
| Unapproved device / token outside offline window (24h) | `QUARANTINED`, owner review, never discard |
Example: stock 2; Device A sells 2, Device B sells 2 offline. A syncs: 2 -> 0 (`committed`). B syncs: 0 -> -2 (`committed_with_conflict`, `NEGATIVE_STOCK`). Both sales exist; the next stock take resolves it.

## 10. Pharmacy rules
- Product flags: `requires_prescription` (bool), `controlled_schedule` (null, 1, 2, 3), `tfda_reg` (string, recommended).
- Selling a `requires_prescription` product requires either a linked `prescription_id` or a pharmacist PIN override (logged). Cashier alone cannot.
- Selling a controlled product (`controlled_schedule` not null) **always** requires a prescription and writes a `controlled_substances_log` row (product, batch, qty, patient, prescriber, pharmacist, date). The log is append-only and printable.
- Prescription: patient name, prescriber, facility, date, prescription number (unique per business per prescriber), optional photo (R2, signed URLs, 1h expiry).
- Allergy alert (Phase 4): patient allergy list; show red warning at POS. Not in MVP.
- Every dispensing sale of an Rx item records the `pharmacist_id` on duty.

## 11. Audit
Every create/update/delete of: products, prices, stock, sales, refunds, users, roles, settings, till sessions, prescriptions -> activity log with user, device, IP, before/after.
