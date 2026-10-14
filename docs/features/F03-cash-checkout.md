# S7 — Connected cash checkout

First delivery of the user's grouped sales/customer/returns/Selcom/product-tools request. F03-pos-sale.md remains the full sales scope; this file defines its cash-only prerequisite, not completion of all five areas.

## Contract
- User chose dated eligible batches first (expiry, id), then undated eligible batches (received_at, id). Positive, unexpired, unrecalled, unquarantined batches only; Tanzania's inclusive expiry date remains unchanged.
- POST /sales/preview resolves active products/current packaging and exact quantities, prices and VAT in Laravel. It returns canonical lines, total and a SHA-256 review hash. No stock or cash is changed.
- POST /sales uses a required UUID Idempotency-Key, explicit branch_id and till_id, canonical items, cash_tendered, and review_hash. The server recomputes the review under locks. Changed price/package/quantity returns SALE_CHANGED; insufficient stock rolls everything back. Cash tendered must cover the total. No client total or cash allocation is authoritative.
- Retry the same key/body after an uncertain response. Same actor/device/body returns the immutable original sale even after closing the till, changing a price or archiving a product. Changed inputs/actor/device return OPERATION_ID_REUSED.
- Sales, per-batch items, cash payments, stock movements, receipt sequence and till cash entry commit atomically. Product locks use numeric id order, batches use allocation order. All quantities are strings; all money is bounded integer TSh. Logical-line rounding happens before splitting a line across batches.
- GET /sales/{sale} initially permits the originating cashier/device only; wider sales-history permissions will be specified with reporting/refunds. No internal IDs or costs appear in the resource. Inline receipt view uses saved logical lines. PDF/R2 receipt jobs remain S8.
- Cash only in this delivery. Credit, Selcom, discounts, held carts, refunds and product extras stay unavailable until their respective contracts/services/tests exist. Nonzero discounts and non-cash payment requests fail closed.

## Files and checks
Add sale migration/models, SaleService, StockService allocation hook, sales Form Requests/controller/resource, live POS and RTK Query adapters, backend tenant/rollback/retry/quantity/race tests and frontend/browser checkout tests. Preserve the supplied POS catalogue/cart split, emerald controls, slate panels and mobile layout.

## Acceptance
- [x] Counted and gram-precision goods and independent pack prices use the same base stock balance.
- [x] Dated batches precede undated, expired/held batches are skipped, insufficient stock causes no partial sale/cash/movement.
- [x] TSh 8,500 total, TSh 10,000 tendered: TSh 1,500 change, TSh 1,297 inclusive VAT at 18%, till increases by TSh 8,500.
- [x] Price changes require a fresh review; replay returns the saved receipt without another deduction.
- [x] Two independent MySQL transactions competing for the last unit cannot both sell it.
- [x] No cross-tenant IDs, unauthorised till, blocked device, inactive product or fractional counted/package quantity succeeds. Final receipt/access/inactive/pack regression passes 1 case / 10 assertions in addition to the selected 75-case suite.
- [x] Phone and desktop checkout show authoritative confirmation/receipt and preserve uncertain retry inputs.

## Following deliveries
Customers/DebtService and cash+credit splits are implemented under F05 (S9). Following deliveries: supervisor-approved discounts/held carts; receipt printing/PDF; approved refund allocation and exchanges; Selcom provider adapter/callback/reconciliation; product images, scanning, import/export, shelf labels and price history. Selcom was selected by the user; credentials, merchant readiness and callback deployment remain external prerequisites. Return payment/rounding allocation and pilot camera/scanner/label formats still need contracts; no prototype rules are inherited.
