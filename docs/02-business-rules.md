# 02 — Business Rules (with worked examples)

All amounts are integer TSh. If a rule here conflicts with code, the rule wins; tell the human.

## 1. Money and rounding
- Store and compute money as integers. Never floats.
- Prices are **VAT-inclusive**. VAT is computed on the sale total: `vat = round_half_up(total * rate / (100 + rate))`. Rate is per business (`vat_rate`, default 18; 0 if not VAT-registered). Confirm the rate before launch.
- Example: total 11,800 at 18% -> vat = 11,800 x 18 / 118 = **1,800**; net = 10,000.
- Exact quantities: counted goods use whole items; weighed goods use integer grams with up to three decimal places in kg entry (user selected 2026-10-09). Quantity JSON fields are decimal strings; no floats. Weighted line gross is rounded half-up once to whole TSh before the integer discount. Packs use whole package counts, their independent integer prices and snapshotted base-atom conversion. See `features/F02-exact-quantities.md`.
- Direct line net = rounded_exact_qty_charge minus the manual line discount. Sale subtotal is the sum of those direct line nets; total = subtotal minus cart discount. Cart discount shares use cumulative proportional half-up rounding and sum exactly to the cart discount (D086). Saved line_total is the final net after its cart share; saved lines sum to total and are the authority for refunds. Discounts cannot make a line or sale negative. Every discount requires bound supervisor approval, including owner/manager sales; managers approve their assigned branch only.

## 2. Stock and batches
- Stock is tracked per batch and product base unit (e.g. bottle or a defined weight unit). Carton/pack conversions and fractional weights are required (F10.1-2). S3 specifies signed BIGINT stock atoms (whole items/grams), exact quantity strings and checked conversion/rounding in features/F02-exact-quantities.md; examples below describe counted goods.
- `qty_remaining` changes only through `StockService`, inside a transaction, after `lockForUpdate()` on the batch, and every change writes a `stock_movements` row (type, qty_change, qty_after, reason, user, sale_id if any).
- Movement types: `receive`, `sale`, `refund_return`, `adjustment`, `stock_take`, `transfer_out`, `transfer_in`, `sync_sale`.
- Adjustments require a reason. Every stockkeeper correction requires owner approval (user decision D068); there is no quantity or value threshold. Online corrections must leave the batch balance non-negative. Offline sales retain section 9's accept-and-flag rule.

## 3. Batch allocation and expiry
- A batch is **sellable** if quantity is positive, it is not quarantined/recalled, and its expiry date is absent or is today/later in Africa/Dar_es_Salaam. Perishable products require an expiry date. The user confirmed selling through the expiry date (D064). New receipts whose expiry date has already passed are rejected without stock writes; there is no quarantine receipt override in S4d.
- Dated stock uses FEFO: earliest eligible expiry first, then id. Non-expiring stock uses received_at, then id. User selected mixed-stock allocation on 2026-10-10: all eligible dated batches first, then undated batches in received_at/id order (D076).
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
- Mobile-money part: sale is created only after the matching Selcom order is confirmed by a server status query and atomically allocated once (online). An accepted wallet push is unpaid. UI/transport timeouts leave the outcome uncertain; reconcile the same order, never assume failure or blindly retry the push. S12a provides transport only; live collection remains disabled. See F08/D084.
- Receipt number: `RCP-{year}-{business sale sequence, zero padded 6}` unique per business.
- Held sale: saved cart, no stock change, expires after 24h, belongs to branch.

## 5. Credit sales and customer debt
- A credit sale requires a customer. Credit amount = total - cash - mpesa.
- Allowed only if `outstanding_balance + credit_amount <= credit_limit`; else `CREDIT_LIMIT_EXCEEDED`.
- Example: limit 50,000, outstanding 30,000: credit of 20,000 OK (-> 50,000); credit of 25,000 rejected.
- Debt payment (`POST /customers/{id}/payments`) reduces `outstanding_balance`. Payment cannot exceed the balance; excess is rejected with `OVERPAYMENT` (MVP: no overpayment credit).
- Staff choose which unpaid sales and exact amounts a collection settles (D080). Allocation amounts must sum to the payment and cannot exceed any selected sale's remaining debt. Unselected sales retain their balances and aging dates.
- Aging buckets for reports: 0-30, 31-60, 61-90, 90+ days by sale date.

## 6. Refunds and voids
- Refund needs supervisor PIN (Owner or authorised Manager with `refund` permission) and a reason.
- Full or partial (by item qty). Refunded qty returns to the **same batch** (`refund_return` movement) unless the batch was recalled/expired, in which case it is returned as quarantined stock (qty not sellable; flagged).
- Cash refund reduces expected till cash. M-Pesa refunds are manual in MVP (record only).
- D081: Cashiers/managers process receipts from their assigned branch with supervisor approval; owners may process receipts across the business. Cash refunds use the signed-in operator's own open till. Permissions and current branch assignments must be checked at review and commit.
- Void of a held sale needs no PIN. Void of a completed sale = refund.

## 7. Till management
- A cashier must have an open till session to make any sale (one simple rule).
- One open session per cashier per branch (`TILL_ALREADY_OPEN`).
- `expected_cash = opening_float + cash received on sales (net of change) + cash debt payments - cash refunds - cash paid-outs - cash drops`.
- `variance = counted_cash - expected_cash`.
- Example: float 20,000; cash sales 150,000; refunds 5,000; counted 164,000 -> expected 165,000, variance **-1,000** (OK).
- If `abs(variance) > 5,000` (per-business setting) closing requires supervisor approval; owner is notified.
- Closed sessions are immutable.
- D071: managers oversee sessions and approve variances only in their assigned branch; cashiers operate their own assigned-branch till, while owners oversee all tenant branches. An overview does not grant authority to close another cashier's session. Check fresh branch assignments when consuming approvals.

## 8. Users, PINs, devices, plans
- PIN: 4-6 digits, hashed. 5 wrong attempts -> locked 15 minutes (assumption D013).
- Login = phone + PIN on an **approved device**. First login on new hardware creates a pending device; Owner must approve. The first owner device is approved only after TOTP and any required PIN change; subsequent owner devices need approval from an existing owner session. Devices can serve multiple shop staff. See F01 for canonical phones and five-minute authentication challenges.
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

## 10. Supermarket operations
- Department assignments support stock counts and reporting; manager visibility remains permission-based.
- Packages and weighted quantities share the product's underlying stock balance; historical conversion factors are immutable.
- Returned damaged, expired or recalled goods never increase sellable stock. Returns cannot exceed the original sold quantity.
- Promotions and batch markdowns require recorded effective periods and approval where configured; stacking precedence must be specified before implementation.
- Wastage and supplier returns use StockService and retain reasons, actors and costs.
- Cash drops reduce drawer cash without reducing revenue or creating expenses.
- English/Kiswahili preferences change presentation without changing identifiers or calculations.

## 11. Audit
Every create/update/delete of: products, prices, stock, sales, refunds, users, roles, settings, till sessions, departments, packaging, promotions, wastage, returns, imports, supplier credits and cash drops -> activity log with user, device, IP, before/after.

## 12. Supermarket additions
F10 defines all 12 approved additions. Packaging and weight calculations must be exact; cash drops are internal transfers, not expenses; returned damaged stock is not sellable; margin warnings cannot disclose costs to unauthorised staff; markdowns never make expired/quarantined stock sellable. Define exchange payment allocation and supplier credit accounting before implementation.

### S15 finalized cash/credit return and exchange rules (D083)

Return base quantities against original receipt allocation snapshots, with cumulative half-up original discounted line entitlement differences. Prior invoice collections are paid cash. Reduce only the original invoice's remaining debt first; the rest refunds available original cash/collections less prior cash refunds. Zero-TSh partial returns still restore stock with approval. Good quantities restore original batches; damaged/expired/recalled/held quantities restore separate linked quarantined batches, never quarantine good original stock. Exchange return and current-price replacement are atomic; refund funds replacement cash and staff collect/pay only the net difference. Original customer credit uses availability after debt reduction. Cash uses the operator's open till. Every return needs a bound eligible supervisor grant and D081 branch checks, including manager assignment at consumption. Unconnected payment methods are unavailable, never simulated.
