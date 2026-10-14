# Product price history and shelf labels

The approved product extras expose recorded selling-price changes and browser/PDF shelf labels. They do not schedule future prices or claim pilot-printer compatibility.

## Authority and recording

Catalogue services append initial and changed base/pack selling prices inside their existing locked transaction. No record is added for unchanged prices. Each record stores its original product/pack description, packaging version, exact contents, old/new integer TSh and tenant actor. History is append-only, tenant scoped and does not change historical sales. Recording begins with this release; earlier activity is not invented or backfilled as historical prices. Existing products show current prices and their next actual price change.

## API

Both endpoints require approved-device authentication and `manage_products`, following existing catalogue authority. IDs are public identifiers; buying costs never appear.

- `GET /api/v1/products/{product}/price-history?page=1&per_page=50`: `data.prices`, newest first, with `meta.page/per_page/total`. Per-page maximum 100. Entries: `id`, `product_name`, nullable `packaging_id/name/version`, `unit`, exact `base_qty`, nullable `old_price`, `new_price`, `actor_id/name`, `created_at`.
- `GET /api/v1/products/{product}/shelf-labels`: `data.labels`, `generated_at`. Current base price plus current active packaging versions. Labels: `id`, `product_id`, `product_name`, nullable `packaging_name/version`, exact `base_qty`, `unit`, nullable `barcode`, integer `selling_price`.

Both use the standard success/error envelope, fail-closed tenant binding and `Cache-Control: no-store, private`.

## Interface

Products opens Prices & labels. History is paginated, displays Tanzania time and exports the displayed page as CSV with formula protection. Shelf labels display server prices, pack contents and Code 128 B for registered printable ASCII barcodes. Print uses the browser print/PDF dialog. Users refresh labels after changing prices; no persistent reprint acknowledgement or future-price scheduling is implied.

## Checks

Verify initial/changed/no-op prices, immutable pack versions, exact weighted contents, tenant/role isolation, pagination, append-only records, no cost disclosure, Code 128 checksum/quiet zones, safe CSV and phone/print layout. Scale-label parsing remains dependent on the supported-format/pilot decision in F10.
