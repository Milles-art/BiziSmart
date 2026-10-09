# F05 — Customers and Debt

Applies to the single BiziSmart supermarket/mini-mart product. Multiple businesses remain isolated tenants.
**Goal:** sell on credit safely and collect debts.
**Reads:** rules §5; schema customers/customer_payments; API Customers section.

## Flow
Register customer (name, phone, credit limit) -> credit sales raise `outstanding_balance` -> debt payments reduce it -> debt screen shows balance, aging buckets and open sales.

## Edge cases
- Balance changes only via `DebtService` in a transaction (sale credit part, payment, refund of a credit sale reduces balance).
- Payment above balance -> `OVERPAYMENT`. Credit limit can be lowered below balance (blocks new credit only).
- Customer phone unique per business when present.

## Acceptance criteria
- [ ] Limit 50,000 / outstanding 30,000: credit 20,000 succeeds, 25,000 fails with `CREDIT_LIMIT_EXCEEDED`.
- [ ] Payment of 10,000 reduces balance by 10,000 and adds it to the open till's expected cash if cash.
- [ ] Aging report buckets are correct for sample sales dated 10, 45, 75 and 120 days ago.
