> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 08 — Test Checklist (required)

Write these tests as you build the related feature. CI must be green before merging.

## A. Tenant isolation (CI-blocking, build in S1)
- [ ] Two businesses A and B each with products; querying as A returns only A's rows, as B only B's; with no tenant set a query returns 0 rows.
- [ ] Creating a model sets `business_id` from the current tenant; a client-supplied `business_id` is ignored.
- [ ] Every model whose table has `business_id` uses `BelongsToBusiness` (test enumerates models).
- [ ] `withoutGlobalScopes` appears only in allowed namespaces (test greps the codebase).
- [ ] For every tenant API route, fetching another tenant's resource id returns 404 (never 403, never the data).
- [ ] A token issued for business A is rejected on business B's subdomain.
- [ ] Queue job without tenant context fails; with `businessId` runs scoped.
- [ ] Cache keys and file paths include the business id.

## B. Auth
- [ ] Login success, wrong PIN, locked after 5 failures, unlock after 15 min.
- [ ] New device pending -> blocked until approved; blocked device cannot log in.
- [ ] Permission matrix: each role vs 5 representative endpoints.
- [ ] Deactivated user's token stops working immediately.

## C. Stock and FEFO
- [ ] Rules §3 example: batches A(10, 2026-12-31), B(20, 2027-03-31), C(50, expired) sell 15 -> A=0, B=15, items (A,10),(B,5).
- [ ] Expired batch never sold online; selling more than available -> `INSUFFICIENT_STOCK` with `available`.
- [ ] Every stock change writes a `stock_movements` row and `qty_after` equals the batch qty.
- [ ] Concurrency: two simultaneous sales of the last unit -> exactly one succeeds.
- [ ] Refund returns qty to the same batch; to quarantine if batch expired.

## D. Sales and money
- [ ] Total 11,800 at 18% -> VAT 1,800. Total 8,500 -> VAT 1,297.
- [ ] Cash 10,000 for 8,500 -> change 1,500. Split 5,000 + 3,500 -> change 0. Split 5,000 + 3,000 -> `PAYMENT_MISMATCH`.
- [ ] Discounts cannot make totals negative; price override without `give_discount` is ignored.
- [ ] Failure mid-sale (force an exception after the first item) leaves no sale, no stock change.
- [ ] Same `Idempotency-Key` twice -> one sale.
- [ ] Rx item without prescription/approval -> `PRESCRIPTION_REQUIRED`; controlled item creates register rows; register rows cannot be updated or deleted.

## E. Till and debt
- [ ] Till worked example: expected 165,000, variance -1,000, closes.
- [ ] Variance -15,000 needs approval; closed session rejects new sales.
- [ ] Credit limit 50,000 / balance 30,000: 20,000 ok, 25,000 rejected; overpayment rejected.
- [ ] Aging buckets for sales 10/45/75/120 days old.

## F. M-Pesa
- [ ] Success, failure, timeout, duplicate callback (no second record), unknown id (200 + nothing created).
- [ ] Callback sets tenant from the stored transaction, not the payload.
- [ ] A `checkout_request_id` cannot be reused by a second sale.

## G. Frontend
- [ ] Cart maths (qty, discount, total, change) with Vitest.
- [ ] 401 clears the token and redirects to login; 30-min inactivity logs out.
- [ ] Playwright: login -> open till -> sell (cash) -> close till; error message shown for insufficient stock.

## H. Offline (Phase 3)
- [ ] Two devices sell the last 2 units offline -> both commit, stock -2, `NEGATIVE_STOCK` conflict.
- [ ] Duplicate `operation_id` ignored; price change > 5% flagged; unapproved device quarantined.
