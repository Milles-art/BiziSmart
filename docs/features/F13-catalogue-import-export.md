# Opening-stock import and catalogue export

User-approved contract: imports match existing registered active base/pack barcodes and reject unknown or duplicate barcode rows. No product creation occurs. Each row requires batch number, exact positive quantity and integer TSh cost per base pricing unit (per kg for weighed products); existing expiry requirements and rejection of expired deliveries apply. Maximum 200 data rows.

## API and authority

Approved-device authentication, `receive_stock` and `view_buying_price` are required for imports. `POST /api/v1/products/import-stock/preview` accepts `operation_id` UUID and `rows` of `barcode`, `batch_number`, string `qty`, integer `cost_price`, nullable YYYY-MM-DD `expiry_date`. Preview writes nothing and returns resolved product/pack public identifiers/names/version, exact base quantity, selling price, cost, unit and `review_hash`. Errors identify the first invalid row. Duplicate rows or unsupported conversions reject the whole preview.

`POST /api/v1/products/import-stock` repeats the exact input plus `review_hash`. Fresh actor/device authority and the tenant business are locked; catalogue details are re-reviewed. Changed catalogue details require another preview. A single outer transaction invokes `StockService.receive` for every row, writing receipts/movements through that service. A failure rolls back all rows. Deterministic per-row UUIDs and an append-only root journal bind tenant, actor, device and complete request fingerprint; exact retries return the original batch/movement IDs and do not duplicate stock. Existing expiry and overflow checks apply across the whole import.

`GET /api/v1/products/export` requires `manage_products`; it streams a UTF-8 CSV containing non-archived products and current active packs. Public product/pack IDs, barcode, exact contents, type/unit, current selling price and flags are exported. `cost_price` appears only with `view_buying_price`. Tenant is explicitly restored inside the stream. All responses are private/no-store. Spreadsheet formula-leading cells are escaped.

## File interface

Products opens Import & export. The browser reads CSV or the first worksheet in `.xlsx`, then the operator maps columns and reviews authoritative server conversions before explicit commit. Old binary `.xls`, formulas, macros, encryption, external sheets, ZIP64 and unsupported compression are rejected. File maximum 2 MB; XLSX expanded data maximum 10 MB, 200 archive entries, 100 columns and 1,000 characters per cell. Inflation is bounded while reading, not just by declared archive sizes. Native deflate support is required for compressed XLSX, with an explicit CSV fallback.

Barcodes must be saved as text; numeric Excel storage may already have removed leading zeroes. Dates must be text in YYYY-MM-DD format. No automatic Excel date or monetary rounding occurs. An uncertain commit retains the immutable request in memory Redux through navigation, clears on sign-out and offers retry. Definitive rollback validation errors allow another review; uncertain results never cause a fresh operation key.

## Checks

Cover mapped CSV/XLSX values and file limits; pack/weight conversion; expired, unknown, duplicate and cross-tenant barcode rejection; role/cost permissions; transaction rollback; actor/device-bound exact replay; review changes; safe tenant exports; phone import review and print paths.
