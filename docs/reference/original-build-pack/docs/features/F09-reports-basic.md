> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# F09 — Basic Reports
**Goal:** owner sees how the business is doing.
**Reads:** API Reports section.

## Reports
Daily/weekly/monthly sales (total, cash/M-Pesa/credit split, count), top products, gross profit (selling vs cost of batches sold), low stock list, expiring list, debts with aging, till summary. Export CSV (PDF/Excel later). `view_reports` only.

## Rules
Heavy queries run against indexed columns with date ranges; paginate; never load whole tables. Profit uses `sale_items.cost_price`, copied from the batch when the sale is made.

## Acceptance criteria
- [ ] Totals equal the sum of the underlying sales in a seeded dataset.
- [ ] Cashier gets 403; owner of business A never sees business B's numbers.
