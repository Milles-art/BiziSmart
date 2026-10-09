# F02 — Products and Batches
**Goal:** supermarket catalogue, departments, packaging and exact batch-level quantities, with expiry dates where applicable.
**Reads:** rules §2, §3; schema products/product_batches/stock_movements; API Products section.

## S4a catalogue slice
The catalogue backend implements product create/update/archive, authenticated list/detail/search/category/base-barcode lookup, integer TSh prices and exact reorder levels. Owner and stockkeeper can write; cashier and manager can read active products without buying prices. Catalogue managers can also inspect disabled products. Archived products are excluded from all public routes; their records and barcode reservations remain.

Quantity type defaults to counted and is immutable from creation, including its audit history; weight products require `unit=kg`. Reorder levels are nonnegative whole-item or three-decimal kg strings. Product creation/reactivation counts active, non-archived products against the plan limit (null/missing means unlimited). The shop lock serialises limit checks and barcode reservations; failed saves roll back products, reservations and audit records together. Changed base barcodes remain reserved to their original product and can be selected again by that product; old codes do not resolve as its current barcode.

Until StockService and batches exist, responses explicitly return `stock=null`, `nearest_expiry=null`, `stock_available=false`. Low-stock/expiry filters and non-null department assignments are rejected rather than simulated. Batch/adjustment endpoints and departments remain future S4 work. S4b extends the shared registry and implements packaging metadata under the S3 composite ownership contract.

## S5a connected catalogue UI
The preserved Products screen now uses live RTK Query catalogue endpoints for pagination, name/category/current base or pack barcode search, create/edit and confirmed archive. Owner/stockkeeper navigation remains permission-based; buying-price columns are hidden without view_buying_price. MUI dialogs preserve existing emerald/slate classes and manage keyboard focus. Forms send integer TSh prices and unchanged exact reorder-level strings; the server validates and returns canonical values. Weight products use kg and quantity type cannot change on edit. Activation/expiry flags are editable metadata, not stock operations.

Search applies submitted server filters, including keyboard Enter from the barcode input. Changes invalidate the live catalogue cache; no products or costs persist in browser storage. Failure/validation messages retain form values and never report successful saves. Archiving requires confirmation and preserves backend history/barcode reservations. Stock values remain unavailable; promotions, margin calculations, camera scanning, scale decoding, packaging, receiving and movement/detail screens are not enabled by this slice.

## Flow
- Create product (name, unit, prices, flags). Barcode optional but unique per business. Plan limit check (`PLAN_LIMIT_REACHED`).
- Receive stock: add a batch (batch number, expiry date, qty, cost). Writes a `receive` movement.
- Adjust stock: reason mandatory; writes `adjustment` movement. Never edit `qty_remaining` directly.
- List with search, category, low-stock (stock <= reorder_level), expiring within N days.
- Archive (soft delete) instead of delete; archived products are not sellable and keep history.
- Barcode scan on the frontend fills the search box and calls `GET /products?barcode=`.

## Frontend screens
Product list (search, filters), product form, product detail with batches and movements, receive-stock dialog, low-stock and expiring tabs. Buying price hidden without `view_buying_price`.

## Edge cases
- Products with expiry tracking require expiry_date. Receiving already expired goods must not make them sellable; specify the quarantine workflow before implementation.
- Duplicate barcode -> 409. Price change writes activity log (old/new).
- Stock total = sum of sellable batches; display also total including expired for the stockkeeper (marked).

## Acceptance criteria
- [x] Create/update/archive product with validation errors in the envelope (S4a catalogue).
- [ ] Receiving a batch of 100 creates a batch and a movement (`qty_change=+100`, `qty_after=100`).
- [ ] Adjustment of -3 with reason writes a movement; without reason fails validation.
- [ ] `GET /products?expiring_in=30` returns only products with a batch expiring within 30 days and qty > 0.
- [x] Cashier cannot see `buying_price` in catalogue responses; batch-cost responses remain to be built/tested.
- [x] Product of business A is never returned to business B (catalogue leak test).

## Supermarket dependencies
F10.1-2 extend products with pack/carton barcodes, conversions and weights. S3 quantity formats are now defined in F02-exact-quantities.md: whole items or integer grams, exact decimal-string quantities, shared packaging stock and half-up integer TSh line totals. Use that contract before the S4 migrations/services. Keep department/category distinction, authorised cost visibility and opening imports consistent with F10.

S4b packaging catalogue is implemented with nested tenant/product ownership, exact counted/gram factors, independent prices, immutable effective edits and reserved historical barcodes. The connected Products pack forms create/edit/archive real records and barcode search shows the selected pack. This satisfies packaging metadata acceptance; stock and POS use of these factors remains future work.
