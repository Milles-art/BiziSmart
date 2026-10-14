# S4d — Stock receiving and ledger preparation

Status: receiving backend and connected batch forms implemented and verified. Read F02-exact-quantities.md for the already approved arithmetic and packaging contract. This receipt slice exposes real batch balances; catalogue aggregate responses remain stock=null / stock_available=false until a separately tested summary is connected.

## Implemented slice

Implement real batch receiving, a receive movement for every receipt and permission-filtered batch reads. Connect receiving forms only after these backend acceptance criteria pass. Stock adjustments, sale allocation, supplier accounting, wastage and offline sync remain separate slices; their open policies must not be inferred from this work.

Existing API paths: GET/POST /products/{product}/batches. POST uses receive_stock (owner/stockkeeper). Batch cost_price is omitted without view_buying_price. Require approved-device sessions and the same fail-closed tenant resolution as the catalogue. Product/batch/package/actor/device ownership uses composite business relationships and public identifiers; client business_id/internal IDs never select tenancy.

## Already agreed requirements

- Receive positive exact-string qty in whole base items or kg with at most three decimals. Optional packaging_id changes qty to a whole package-count string. Resolve the product's current, active package version and convert using its stored factor with checked arithmetic; packages share the same base stock balance.
- cost_price is a nonnegative browser-safe integer TSh per base item/kg, including when receiving cartons. Do not silently derive a base cost from a supplier invoice total or update the product's buying/selling price from this input.
- expiry_date uses YYYY-MM-DD and is required for tracks_expiry products. Already-expired goods must never become sellable. Server received_at is authoritative; callers cannot backdate stock receipt time or override atom balances/eligibility.
- Every balance change goes through StockService, inside a DB transaction with lockForUpdate on the batch row. New batches start at zero, then receive the checked positive delta and exactly one receive movement. Batch creation, conversion snapshots, balance update, movement and audit commit or roll back together.
- product_batches and stock_movements use signed BIGINT atoms, exact response strings, tenant model scopes, public IDs and composite ownership. Do not add a nonnegative database CHECK that prevents the future approved offline accept-and-flag policy.
- Receive movement history retains actor/device, server timestamp, entered quantity, converted base quantity and package ID/version/factor where selected. Effective package edits must never reinterpret old movements.
- Recheck active actor/permission and product/package state under locks. Use the established actor → business → product → package/batch locking order so catalogue edits and receipt validation serialize consistently.

## Approved expiry policy

1. User selected selling through the expiry date. Evaluate the date using Africa/Dar_es_Salaam; timestamps remain UTC on the wire. A batch dated today is eligible until the local date changes.
2. User selected rejecting already-expired deliveries (400 EXPIRED_STOCK); create no batch, balance or ledger row. This slice does not add a quarantine receipt override.

Mixed dated/undated sale allocation is still open, but receiving does not allocate sales. Resolve that policy before S7; no provisional FEFO allocator is included here. Likewise, the owner-defined adjustment threshold and approval review contract must be resolved before exposing /stock/adjust.

## Contract details to finalize with implementation

The finalized receipt contract is in docs/04-api-contract.md. Require operation_id UUID, batch_number (nonempty, max 100), positive exact qty, base-unit cost_price, optional expiry_date and packaging_id. Each receipt creates its own batch; batch_number is a label and is not an idempotency key. A tenant-scoped operation ID plus canonical input fingerprint prevents duplicate stock. Identical replay returns 200 with replayed=true, even after package edits or passage of the expiry date; new receipt returns 201/replayed=false. Different input for an existing operation returns 409 OPERATION_ID_REUSED. Replays still require current authorization and an accessible product.

S4d exposes batch balances and Laravel eligibility. S4e (F02-stock-summaries.md) adds exact catalogue summaries and low-stock/expiry filters, excluding expired/quarantined/recalled stock from eligible balances. Online receiving now checks both held and eligible aggregate bounds before writes. Monetary valuation remains unavailable. Preserve restricted costs in every response and error. Checkout remains unavailable.

## Implementation files and checks

Expected backend changes: a new additive migration; ProductBatch/StockMovement tenant models; StockService; receive/list Form Requests, controller and API Resources; route and no-store response coverage; focused MySQL receiving tests. Update product stock resources only when authoritative summaries are included and tested. Follow with live RTK Query receiving forms and desktop/mobile browser fixtures, retaining the existing design.

Required acceptance checks:

- Receive 100 counted items → batch balance 100 and one receive movement +100/100.
- Receive 10 cartons at factor 24 → balance 240 base items; receive 10 fixed 500 g packs → 5 kg. Later package edits leave receipt snapshots unchanged.
- Reject zero/negative/fractional counted quantities, numeric JSON quantities, fourth-decimal kg, overflow and foreign/archived package IDs without partial writes.
- Assert selected expiry-day boundary and expired-delivery behavior, including Tanzania midnight and quarantined/expired stock exclusion if applicable.
- Owner/stockkeeper receipt access, manager/cashier denial, cost redaction and wrong-tenant IDs/tokens/foreign keys.
- Transaction rollback on ledger failure; duplicate request retries and genuinely concurrent receipt/duplicate operations using independent MySQL connections; balance equals committed ledger changes.
- Real frontend failures retain entered values and do not show success; exact input strings survive serialization; confirmed successful receipt shows server data. Desktop/phone workflows and keyboard controls pass.

Do not tick S4d until the chosen policy, API/schema changes, code and applicable tests are complete.
