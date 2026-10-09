> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# F02 — Products and Batches
**Goal:** keep an accurate catalogue and batch-level stock with expiry dates.
**Reads:** rules §2, §3; schema products/product_batches/stock_movements; API Products section.

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
- Pharmacy: `expiry_date` required; reject past dates when receiving unless Owner confirms (`allow_expired_receive`).
- Duplicate barcode -> 409. Price change writes activity log (old/new).
- Stock total = sum of sellable batches; display also total including expired for the stockkeeper (marked).

## Acceptance criteria
- [ ] Create/update/archive product with validation errors in the envelope.
- [ ] Receiving a batch of 100 creates a batch and a movement (`qty_change=+100`, `qty_after=100`).
- [ ] Adjustment of -3 with reason writes a movement; without reason fails validation.
- [ ] `GET /products?expiring_in=30` returns only products with a batch expiring within 30 days and qty > 0.
- [ ] Cashier cannot see `buying_price` in any response.
- [ ] Product of business A is never returned to business B (leak test).
