# F05 — Customers and Debt

Applies to the single BiziSmart supermarket/mini-mart product. Multiple businesses remain isolated tenants.
**Goal:** sell on credit safely and collect debts, including verified Selcom collections under F08.
**Reads:** rules §5; schema customers/customer_payments; API Customers section.

## Flow
Register customer (name, phone, credit limit) -> credit sales raise `outstanding_balance` -> debt payments reduce it -> debt screen shows balance, aging buckets and open sales.

## Edge cases
- Balance changes only via `DebtService` in a transaction (sale credit part, payment, refund of a credit sale reduces balance).
- Payment above balance -> `OVERPAYMENT`. Credit limit can be lowered below balance (blocks new credit only).
- Customer phone unique per business when present.
- D080: Staff explicitly choose the unpaid sales and amounts settled by a collection. DebtService validates each sale belongs to this customer/business, rejects over-allocation and requires the allocation sum to equal the payment. Recheck balances under locks; no automatic oldest-first allocation.

## Acceptance criteria
- [x] Limit 50,000 / outstanding 30,000: credit 20,000 succeeds, 25,000 fails with `CREDIT_LIMIT_EXCEEDED`.
- [x] Payment of 10,000 reduces balance by 10,000 and adds it to the open till's expected cash if cash.
- [x] Aging report buckets are correct for sample sales dated 10, 45, 75 and 120 days ago.

## S9 connected contract (implemented and verified)
- Customers are business-wide under manage_customers (approved owner/manager/cashier matrix). Name and optional canonical Tanzania phone are encrypted; keyed phone lookup is unique per tenant. Profile writes cannot set outstanding_balance. Lowering a limit below existing debt is allowed but does not forgive debt. Search streams encrypted tenant records server-side; page counts describe matches, not global financial reporting.
- Existing /sales/preview and /sales gain optional customer_id and credit_amount. A positive credit portion needs a customer and its remaining limit. Server preview binds customer balance/limit and chosen credit to the review hash. Cash allocation = total minus credit; cash_tendered must cover this allocation, including zero for full credit. Mobile payments/discounts remain rejected. Legacy cash-only inputs and replay fingerprints remain valid.
- Sale snapshots/payments stay immutable. Additive sale_debts stores original and remaining credit; only DebtService changes customer and invoice balances inside the atomic sale/collection transaction. Collections have immutable customer_payments and debt_allocations journals.
- Cash collection preview at POST /customers/{customer}/payments/preview takes {till_id,method:cash,amount,allocations:[{sale_id,amount}]}. Server validates positive amounts, unique selected invoices, exact sum, tenant/customer ownership and each remaining balance; no automatic oldest-first allocation. It returns expected invoice/customer balances, balance_after and review_hash. Commit at POST /customers/{customer}/payments adds review_hash and UUID Idempotency-Key. Same actor/device/customer/body returns the original payment; changed key use fails OPERATION_ID_REUSED. Changed review returns DEBT_CHANGED or an overpayment error without writes. Own assigned-branch open till is required, and cash debt payment commits atomically with balances/journals/audit.
- All shop financial writes lock actor/session/device, business, then resources. Business serialization prevents cross-domain debt races. GET debt reads customer and invoice balances consistently and returns age buckets by original sale day in Tanzania time, keeping unselected invoices unchanged. Money remains bounded integer TSh; React collects inputs and displays server totals only.
- Customer/debt/cart operations remain memory-only and are cleared on sign-out. Uncertain financial writes freeze the original UUID/body through screen navigation, warn on reload and never show a successful result without Laravel confirmation. No new browser persistence or external package.

## S12 mobile extension
Collections can preview method `mpesa`, with the same explicitly selected invoices, amount and own till. Start the reviewed collection through `/payments/selcom`, including its customer public ID and payer details. Only matching authenticated Selcom status evidence permits `/payments/{payment}/complete` to settle those invoices once. Mobile collection writes no drawer cash entry. Changed invoice balances leave confirmed money recorded and unallocated for owner review or approved external-refund recording. Customers provides memory-only uncertain retries and server-record recovery. Previous mobile invoice allocations contribute to the mobile refund pool when their original receipt is returned; they must never be treated as cash. Merchant configuration and external verification remain required before real collection.
