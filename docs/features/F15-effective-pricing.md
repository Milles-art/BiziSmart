# Effective prices, promotions and selected-batch markdowns

F14 pricing rules were approved by the user. Only authenticated approved-device owners configure rules; existing manager checkout approvals do not grant access to costs or pricing configuration.

## Contracts

`GET /api/v1/pricing/rules` returns paginated owner rule records. `POST /pricing/rules/preview` resolves targets and produces a canonical snapshot/review hash; `POST /pricing/rules` repeats immutable details plus `review_hash`, creates the rule once, and returns `rule/replayed`. Both writes bind the operation UUID, tenant, actor and approved device. Cancellation has `/pricing/rules/{rule}/cancel/preview` and `/cancel`, with operation UUID/reason and the reviewed hash. Existing rules are cancelled and replaced; prices and targets cannot be silently edited. Fresh authority and tenant business locks serialize configuration with checkout.

Kinds: `schedule`, `fixed_price`, `percentage`, `buy_get`, `markdown`. Common input: operation UUID, name, priority 0–10000 (lower wins), reason, `starts_at` and optional `ends_at` as `YYYY-MM-DDTHH:mm` **Tanzania local time**, and at most 50 product/current-pack targets. Starts are inclusive and ends exclusive. Offers require an end; price schedules may continue until cancelled. Overlapping schedules for the same product/pack are rejected. Integer TSh `price`, integer discount `basis_points` (1–10000), or positive whole `buy_qty/free_qty` are mutually appropriate to the kind.

Markdown requires exactly one target with eligible `batch_id`, a fixed price or percentage, and exact positive `max_qty` in base units/kg bounded by the selected batch's eligible stock. Immutable packaging IDs bind a version. Archived versions do not silently acquire offers.

## Checkout and accounting

The current schedule supplies the authoritative base price. Each eligible item receives at most one automatic offer: the lowest valid resulting charge, with priority/public-ID ties. Buy-X-get-Y qualifies on groups of X+Y eligible whole units/packs and allocates free units to the lowest-priced eligible units. Loose weighed goods cannot participate in buy/get. Percentage charges round half-up against the authoritative line/eligible-part gross, not an already rounded unit discount. Manual line/cart discounts apply afterward under existing approval rules. No total becomes negative.

FEFO pricing allocations are snapshotted with public batch IDs, exact atoms/base quantities, gross/net charge, automatic discount and offer identity. Markdown caps apply only to that batch and do not change expiry/quarantine eligibility. The commit consumes the same locked batch allocations and appends finite markdown consumption inside the same sale transaction; failed or replayed sales cannot consume capacity twice. Historical receipts/returns retain their original priced line snapshots. Automatic offers are separately identified and do not require a new supervisor grant on every checkout.

Catalogue labels show the current scheduled base/pack price. Conditional cart promotions are quoted at checkout and cannot be inferred as unconditional shelf prices. `pricing:reconcile` carries each active tenant explicitly and records one-use activation/end lifecycle history. Checkout also reconciles under its existing business lock. History uses the actual effective window (never before creation), identifies its schedule source and does not mutate the stored catalogue baseline; scheduler repetition cannot duplicate records. Owner records show effective windows and cancelled rules; physical printer/scale compatibility and deployment workers remain separate verification gates.

## Interface and checks

The preserved owner screen provides product/pack search, bounded batch selection, Tanzania date fields, server review and explicit commit/cancellation. Uncertain bodies remain in memory Redux through navigation, clear at sign-out and retry the exact operation. Required checks cover owner/tenant/device isolation, canonical review changes and immutable journals; percentages/weighted packs/large denominators; stable best-offer ties; lowest-priced free units; finite batch eligibility/caps; cancellation/window boundaries; rollback/duplicate races; original-price refunds; and phone/keyboard interactions.
