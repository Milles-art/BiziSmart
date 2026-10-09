# S3 — Exact quantities and packaging

Status: quantity precision and line rounding selected by the user on 2026-10-09. Arithmetic helpers are implemented and tested. This defines S4 product/stock schema and API extensions. S4a/S5a product catalogue and S4b packaging metadata/screens are implemented; stock tables and sales remain pending.

## Units and wire values

- Counted goods use one whole base item: bottle, piece, bag or another descriptive `unit` label. `quantity_type` is `counted`, stock precision is zero, and fractional counted items are rejected.
- Weighed goods use kilograms for entry/pricing and integer grams internally. `quantity_type` is `weight`, `unit` is `kg`, precision is three and `quantity_scale` is 1000. One stock atom is one gram. `0.750` kg becomes 750 atoms; `0.001` kg becomes one atom.
- Quantity fields in JSON are decimal **strings**, including counted goods: `"24"`, `"0.75"`, `"-0.003"`. Numeric JSON quantities, scientific notation, whitespace, plus signs, leading-zero whole parts, incomplete decimals and more than three decimal places are rejected. No silent rounding of entered stock quantities.
- Responses use canonical strings: trim fractional trailing zeros, preserve necessary leading zero, and return zero as `"0"`. A canonical value and its parsed atoms round-trip exactly. If internal atoms or packaging factors appear in a payload, they also use integer strings so browsers do not lose precision.
- Products supply their precision; a client cannot choose a different quantity type or scale for an operation. Product quantity type cannot change after stock/history exists. A product's `unit` label is distinct from its department/category.
- Server atom balances/deltas use signed 64-bit integers; negative balances remain available for the approved future offline accept-and-flag flow. Positive receipt/sale/package quantities and nonnegative reorder levels are validated by their domain requests. A parser's ability to represent a negative number does not authorise a negative online sale or stock deduction.

## Packs and cartons

All packaging sizes share the same product/batch stock atoms. No copied stock balance and no separate stock movement just for choosing a packaging size.

A packaging record has `pkg_` public ID, product, name, positive whole `conversion_atoms`, whole-TSh selling price and active/version metadata. For counted bottles, a carton of 24 has conversion 24; for a fixed 500 g pack of a weighed product, conversion is 500. Package quantities are whole package-count strings, never fractional cartons. The server multiplies package count by the stored factor with overflow checks. Manually weighed sales use the product's kg price and gram quantity; fixed packs use their independent package price.

Worked acceptance: receive `"10"` cartons at factor 24 -> 240 bottles; sell `"1"` carton and `"2"` single bottles -> 214 remain. Receive `"10"` fixed 500 g packs -> 5000 grams/5 kg; sell one pack -> 4500 grams/4.5 kg. Choosing an alternate packaging barcode changes the selected selling unit, not the product's underlying balance.

One tenant-scoped barcode registry owns both base-product and packaging codes, with unique `(business_id, barcode)`. Keeping two independent unique indexes on different tables is insufficient: a carton barcode must not collide with another product's base barcode. Registry rows bind product and optional packaging using composite shop/product relationships. Archived codes remain reserved unless a controlled conversion-version update rebinds the same product's code; do not silently reassign a historical code to a different product.

Conversion changes create a new packaging version/archive the old record once stock or transaction history references it. Transaction/movement snapshots retain the original packaging ID/version, factor, entered quantity, base atoms and effective price. Updating today's conversion or price cannot rewrite receipts, received costs, returns or ledger history. Packaging versions do not rewrite batch atom balances.

## Integer money and rounding

Amounts and prices are integer TSh. API money is bounded to 0 through 9,007,199,254,740,991, the largest integer a browser can represent exactly. Signed differences/variances use the corresponding negative bound. Use a 64-bit PHP runtime and checked integer arithmetic; storage can use BIGINT, but never accept values outside the application's exact wire range. Overflow is a validation failure, not a float, clamped value or truncated amount.

Counted line gross is whole quantity times unit price. Weighed gross is `round_half_up(grams * price_per_kg / 1000)`. A package line uses whole package count times package price; converted base atoms are for stock. Apply integer line discount **after** rounding gross; a discount cannot make the line negative. Sum already-rounded logical lines, then apply the sale-level integer discount and existing sale-total VAT rounding. No currency decimals.

- `"0.750"` kg at TSh 4,000/kg -> TSh 3,000; with TSh 100 line discount -> TSh 2,900.
- One gram at TSh 500/kg -> TSh 1 (half shilling rounds up); at TSh 499/kg -> TSh 0. Minimum selling-price policy is not introduced by arithmetic rounding.
- One 24-bottle carton may have its own TSh 25,000 price even when the single bottle costs TSh 1,200. Deduct 24 bottles and record TSh 25,000, not an invented TSh 28,800 carton price.

Compute the charge once for the logical selling line, **before FEFO splits stock across batches**. Never re-round every batch allocation: a 2 g line at TSh 250/kg is TSh 1; rounding two 1 g allocations separately would incorrectly charge zero. Allocation records must sum to the logical line's amount with deterministic remainder assignment when those sale tables are built. Costs come from the actual allocated batches; their accounting/return allocation design remains with the respective domain feature. Only merge cart entries with identical product, selling-unit version and effective pricing/discount context.

## S4 schema plan

Products/base barcodes were implemented in S4a; packaging and its registry ownership are implemented in S4b. Batches and stock movements remain planned. Use additive migrations.

- `products`: existing descriptive/permission fields plus `quantity_type`, `unit` and `reorder_level_atoms`. Base buying/selling prices are TSh per counted item or per kg. Do not store fractional stock or money columns.
- `product_packagings`: public ID, business/product IDs, name, conversion_atoms, selling_price, version/active/archive metadata. Factors/prices are snapshotted historically; weight-pack factors are grams.
- `product_barcodes`: public ID, business/product IDs, packaging_id nullable and unique barcode registry. Composite foreign keys must prevent linking a package to a different product or shop.
- `product_batches`: `qty_remaining_atoms` signed BIGINT; no nonnegative database CHECK because Phase 3 may commit negative offline balances. Cost price remains integer TSh per product base pricing unit. Expiry/quarantine eligibility is defined before StockService allocation.
- `stock_movements`: signed `qty_change_atoms` and `qty_after_atoms`, packaging/conversion snapshots where applicable, actor/device, reason and operation UUID. Append-only; every actual quantity change goes through StockService, transactions and row locks.
- Future sale/return items retain entered selling quantity, package/version, conversion_atoms and converted base quantity plus integer price/discount/line allocations. Never reinterpret historical quantities using a changed product setting.

Receiving `cost_price` means integer TSh per base item/kg, even when receiving a packaging count. Do not silently divide an invoice/carton total into a rounded per-item cost; allocating non-divisible aggregate invoice costs is a separate supplier-accounting design before that workflow is connected.

## API extensions for S4–S7

S4b packaging catalogue uses GET/POST `/products/{product}/packagings` and PUT/DELETE `/products/{product}/packagings/{packaging}`. Writes require `manage_products`. Input is name, positive exact-string `base_qty` in the product's base unit, integer TSh `selling_price`, optional barcode and active status. Responses use `pkg_` IDs, canonical `base_qty`, integer-string `conversion_atoms`, version and archive metadata. Fixed packs have independent selling prices and share the product's future stock balance.

Every effective edit creates a new immutable version/public ID and archives its predecessor. Editing an archived version returns `409 PACKAGING_VERSION_CHANGED`; no-op edits return the existing version. Archived barcodes stay reserved; only another version in the same packaging family may reclaim them. Base codes and other families cannot claim them, including on the same product. DELETE archives the current version. Reads return current versions, including inactive versions only for product managers. Product responses include active current packaging metadata; exact packaging barcode searches return its product and `selected_packaging`. These routes change catalogue metadata only, without receiving stock or processing payments.

Existing paths remain. Quantity fields change from draft JSON numbers to exact strings; this is safe before product/stock/sales routes exist and must not alter implemented auth APIs.

Product responses include `quantity_type`, descriptive `unit`, readonly `quantity_precision` (0/3), readonly `quantity_scale` (1/1000), canonical `stock`/`reorder_level`, packaging metadata and permission-filtered costs. Product creation validates `reorder_level` as a quantity string and derives atoms from the persisted quantity type.

Receipts and future sale items accept `qty` as a string plus optional `packaging_id`. No package selected means quantity in the product's base entry unit (whole items/kg). Package selected means whole package count. The server resolves package ownership/active version, derives base atoms and authoritative price, and returns both entered quantity and canonical converted `base_qty`. Client factor/scale/base quantity/price fields cannot select authority. Stock adjustments use signed `qty_change` in the base entry unit and retain the mandatory reason/approval policy.

Base/pack barcode lookup returns the exact selling-unit selection. Manual weight entry works without connected hardware. Scale-label barcode formats remain unconfigured until pilot hardware/protocol is selected; do not decode arbitrary labels as weight/price or claim hardware compatibility.

## Implemented helpers and evidence

`App\Support\Quantity` parses/formats whole items or kg strings without floats and converts whole package counts to atoms. `ExactMath` checks signed addition, nonnegative multiplication and half-up rational products, including cases where the intermediate numerator would overflow but the final result fits. `Money` validates browser-exact integer TSh and computes logical line totals/discounts. They contain no database writes and do not replace StockService/domain permissions.

The focused arithmetic suite covers the F10 carton/weight examples, kg canonicalisation, signed int64 boundaries, unsupported numeric/precision formats, half-up discounts, FEFO rounding independence, browser-safe money limits and independently generated BigInt overflow examples. Product isolation, stock ledger/concurrency/rollback, package barcode uniqueness and immutable snapshots must be tested when S4/S7 build the actual tables and services.
