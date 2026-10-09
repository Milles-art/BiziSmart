# F10 - Supermarket Additions

Applies to the single BiziSmart supermarket/mini-mart product. Multiple businesses remain isolated tenants.

All 12 features approved by the user on 2026-10-08. Status: requirements only; not implemented.
These extend departments, multi-till, weighing scales, promotions, customer display, loss prevention and department stock takes.

S3 arithmetic foundation for additions 1–2 is now defined and tested in F02-exact-quantities.md: whole counted items, gram precision (up to three decimals in kg), checked package conversion and half-up whole-TSh line rounding. Persisted packaging/stock workflows, manual-weight checkout and hardware integration remain unimplemented.

## 1. Carton, pack and single-unit stock
Receive and sell packaging sizes against one stock balance, with packaging-specific barcodes and prices. Preserve the conversion used on each transaction.
- Acceptance: 10 cartons of 24 bottles = 240 bottles; sell one carton and two bottles -> 214 remain. Each barcode selects the correct packaging.

## 2. Weighted products without a connected scale
Manual weight entry and supported scale-label barcodes containing weight or price. Server validates labels and computes charges using exact arithmetic.
- Acceptance: 0.75 kg at TSh 4,000/kg costs TSh 3,000 and deducts 0.75 kg; manual entry works without Bluetooth; unsupported barcode formats show an error.

## 3. Fast checkout hardware
USB/Bluetooth scanners operating as keyboards, checkout shortcuts and thermal receipts through supported printing paths. Keep camera scanning and browser/PDF fallback; document pilot hardware compatibility.
- Acceptance: scans work without manual focus changes; shortcuts cannot bypass payment validation; receipts print legibly on the pilot printer.

## 4. Damage, spoilage and wastage
Capture product, batch, quantity, cost, reason and approving staff. Deduct through StockService and report losses by reason, department and date, without counting adjustments twice.
- Acceptance: three damaged bottles reduce stock by three with the correct batch-cost loss and audit record; unauthorised approvals and cross-tenant reads fail.

## 5. Returns and exchanges
Link to the original receipt, enforce cumulative return limits and distinguish sellable/damaged goods. Return sellable stock to its original batch; keep damaged goods unavailable. Link exchanges to the replacement sale and payment difference.
- Acceptance: cannot return three units from a two-unit sale; damaged returns never increase sellable stock; exchanges preserve both transaction histories.

## 6. CSV/Excel opening-stock import
Column mapping, packaging/weight support, validation, preview and explicit commit. StockService records opening movements. Failed imports write nothing; retries must not duplicate stock.
- Acceptance: errors identify the row; valid preview shows prices and conversions; failed commit leaves no partial data; re-submission creates opening stock once.

## 7. Shelf-price labels and price history
Print product, unit/pack size, barcode and effective price. Audit old/new prices and actor; schedule future changes using tenant-aware jobs and identify labels needing reprinting.
- Acceptance: scheduled prices start at the effective time; historical sales stay unchanged; labels show the correct packaging and price.

## 8. Supplier returns and credit notes
Link returns to received goods/batches, quantity and reason. Deduct through StockService; track expected and issued credits and their application to supplier balances. Keep separate from customer refunds.
- Acceptance: five returned units remove five from stock; one credit affects the balance once; online return cannot exceed available stock.

## 9. Cash drops
Record cash removed from an open till, handing-over cashier, receiving manager and acknowledgement. Reduce expected drawer cash; do not classify as an expense or reduce business revenue.
- Acceptance: float 20,000 + cash sales 150,000 - drop 100,000 = expected drawer cash 70,000; closed tills reject drops; duplicate submissions do not remove cash twice.

## 10. English and Kiswahili
Persistent language preference, translated navigation/forms/checkout messages and selected-language receipts. Keep product names, API keys and error codes stable; missing translations fall back to English.
- Acceptance: switching language preserves the cart; login/stock/payment errors are translated; currency stays TSh.

## 11. Near-expiry markdowns
Approved, time-limited discounts for selected batches/quantities. Preserve regular prices, clearly identify discounted stock and audit approval. Existing expiry/quarantine restrictions still apply.
- Acceptance: only the selected eligible batch is discounted during its date range; expired or quarantined stock cannot be sold online.

## 12. Margin alerts
Warn authorised managers when effective selling prices/discounts fall below actual batch cost. Hide costs from staff lacking view_buying_price. Warnings must not silently change prices.
- Acceptance: price 800 against cost 1,000 generates a manager warning; cashier receives no cost disclosure; split-batch sales use their actual costs.

## Implementation dependencies
- All 12 are supermarket roadmap items, delivered incrementally; no feature has been implemented by this documentation change.
- Extend schema and the API contract before each affected implementation. Exact endpoints, payloads and permission matrix remain design work, not an invitation to invent interfaces while coding.
- Replace whole-unit-only assumptions before products/stock/POS: support exact weighted quantities and packaging conversions; define precision and rounding explicitly. Money remains integer TSh.
- Specify supported scale-label formats and pilot hardware, return payment allocation, supplier accounting and optional margin blocking/override policy before those tasks.
- New tenant routes, imports, jobs and reports require isolation tests. Quantity, stock, money and cash transfers require transactional, retry and concurrency coverage.
