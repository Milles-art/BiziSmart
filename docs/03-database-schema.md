# 03 — Database Schema (MySQL 8, InnoDB, utf8mb4)

> Supermarket-only revision (2026-10-08): this is the supermarket core baseline, not a complete supermarket contract. All 12 additions in features/F10-supermarket-expansion.md are approved. Before affected implementation, define exact packaging/weighted quantity representations and extensions for departments, wastage, returns/exchanges, imports, price history/labels, supplier credits, cash drops, localisation, batch markdowns and margin alerts. Existing endpoints/envelope remain the baseline; do not invent undocumented payloads.

Conventions: table names plural snake_case. `id` bigint unsigned PK (internal). `public_id` char(26) ULID, unique (API-facing, prefixed in responses). All tenant tables have `business_id` bigint unsigned NOT NULL, FK -> businesses.id, **indexed (usually as the first column of composite indexes)**. Money columns `bigint unsigned` (TSh). Timestamps `created_at`, `updated_at` (UTC). Soft deletes (`deleted_at`) only where noted.

Tenant models use the `BelongsToBusiness` trait. Tables marked **(global)** have no `business_id`.

## Implemented S7 cash schema (migration 000015)
This supersedes the draft sale columns for the initial cash delivery. `sales` stores business/branch/cashier/device/till ownership, unique business UUID operation_id and receipt_number, request fingerprint, immutable logical `lines` JSON, VAT rate and unsigned bounded integer subtotal/discount/total/VAT/tender/change. Every ownership FK is composite with business_id; (business_id,id) is unique. `businesses.sale_sequence` is incremented under the shop lock in the same transaction.

`sale_items` stores tenant sale/product/batch FKs, line_index, positive base qty_atoms and snapshotted unit_price. Monetary totals belong to logical lines, never rerounded batch splits. `sale_payments` is an append-only tenant sale FK, method and allocated amount. All three tenant models use BelongsToBusiness/public IDs and immutable records. `stock_movements.sale_id` is an optional composite FK; sale allocations append a movement per batch with fresh operation UUIDs and public sale/line references. Customer/debt/refund/mobile columns and additional journals belong to their later additive migrations.

## S9 customer/debt schema (migration 000016)
`customers` stores encrypted name/optional phone, tenant-unique keyed canonical phone lookup, bounded integer credit_limit and journal-derived outstanding_balance. Public IDs use cust_. Profile writes cannot change balances; no archive/notes feature is implemented by this migration.

`sales.customer_id` is nullable with composite tenant ownership. Immutable sale_payments supports cash and credit; `sale_debts` records original_amount/remaining_amount per sale, with composite business/customer/sale ownership. `customer_payments` records an immutable cash collection, actor/device/own till, tenant-unique operation UUID, fingerprint and reviewed public invoice allocations. `debt_allocations` journals each selected invoice settlement with composite customer/payment/debt foreign keys. Payments and allocation journals are append-only; DebtService owns locked balance changes. Mobile collections are linked by migration 000021; return journals are migrations 000017/000020.

## Platform / admin (global)
| Table | Columns |
|---|---|
| businesses | id, public_id, name, subdomain (unique), plan_id FK, owner_phone, tin, vrn, vat_rate tinyint default 18, address, phone, is_active, settings JSON (till_variance_limit default 5000, receipt_header, receipt_footer, receipt_method), timestamps |
| plans | id, code (free, basic, professional, enterprise), name, price_monthly, limits JSON (users, products, tills), features JSON |
| subscriptions | id, business_id, plan_id, starts_at, ends_at, status (active, expired, suspended), payment_ref, timestamps |
| subscription_payments | id, business_id, subscription_id, amount, method, reference, paid_at, recorded_by |
| features (Pennant) | Pennant's `features` table, scope = business |
| admin_users | Filament admin users (separate from tenant users), 2FA required |

## Tenant core
| Table | Columns | Indexes / notes |
|---|---|---|
| branches | id, public_id, business_id, name, address, gps_lat, gps_lng, is_active | (business_id) |
| users | id, public_id, business_id, branch_id nullable, name, phone (encrypted text), phone_lookup char(64), pin_hash, role_hint, is_active, must_change_pin default true, locked_until, failed_pin_attempts, last_login_at, soft deletes | unique (business_id, phone_lookup); implemented Spatie teams use business_id directly |
| devices | id, public_id, business_id, user_id nullable, device_uid, name, status (pending, approved, blocked), approved_by, last_seen_at, last_ip | unique (business_id, device_uid) |
| products | id, public_id, business_id, name, barcode nullable, category, unit (e.g. bottle), buying_price, selling_price, reorder_level, department_id nullable, tracks_expiry bool, is_active, soft deletes | unique (business_id, barcode) where not null; index (business_id, name) |
| product_batches | id, public_id, business_id, product_id, batch_number, expiry_date nullable (required for products tracking expiry), qty_remaining **bigint signed** (can be negative via sync), cost_price, received_at, supplier_id nullable, is_quarantined bool | index (business_id, product_id, expiry_date); **no CHECK >= 0** |
| stock_movements | id, business_id, product_id, batch_id, type (see rules 2), qty_change signed, qty_after, reason nullable, user_id, device_id nullable, sale_id nullable, operation_id nullable, created_at | index (business_id, batch_id, created_at); append-only |
| customers | id, public_id, business_id, name, phone, credit_limit default 0, outstanding_balance default 0, notes, soft deletes | unique (business_id, phone) when not null |
| customer_payments | id, public_id, business_id, customer_id, amount, method (cash, mpesa), till_session_id nullable, reference, received_by, created_at | |
| sales | id, public_id, business_id, branch_id, till_session_id, cashier_id, customer_id nullable, receipt_number, subtotal, discount, total, vat_amount, change_given, status, source (online, offline_sync), operation_id nullable unique, receipt_url nullable, fiscal_receipt_no nullable, created_at | unique (business_id, receipt_number); index (business_id, created_at) |
| sale_items | id, public_id, business_id, sale_id, product_id, batch_id, qty, unit_price, cost_price (batch cost at time of sale, for profit), discount, line_total, refunded_qty default 0 | |
| sale_payments | id, business_id, sale_id, method (cash, mpesa, credit), amount, mpesa_transaction_id nullable | |
| held_sales | id, public_id, business_id, branch_id, user_id, cart JSON, expires_at | |
| till_sessions | id, public_id, business_id, branch_id, cashier_id, opened_at, closed_at nullable, opening_float, closing_cash nullable, expected_cash nullable, variance nullable, approved_by nullable, status (open, closed) | unique partial: one open per (business_id, branch_id, cashier_id) enforced in code + test |
| till_paid_outs | id, business_id, till_session_id, amount, reason, user_id | |
| mobile_payments | Tenant/actor/device/branch/till; immutable encrypted request/fingerprints, purpose, operation UUID, exact TZS amount, mutable reconciliation status/timestamps; globally unique provider evidence; unique sale/customer-payment consumption | Migrations 000018/000021; composite ownership FKs; models guard immutable identity and allocated/confirmed evidence. |
| suppliers | id, public_id, business_id, name, phone, notes | Supplier UI is in the supermarket roadmap |
| sync_operations | id, business_id, operation_id (**unique**), device_id, type, status, result JSON, created_at, processed_at | idempotency |
| sync_conflicts | id, business_id, operation_id, device_id, type (NEGATIVE_STOCK, PRICE_CHANGED, EXPIRED_BATCH, RECALLED_BATCH, QUARANTINED), product_id nullable, batch_id nullable, details JSON, status (open, resolved), resolved_by nullable, resolved_note nullable, timestamps | |
| activity_log | spatie activitylog table + `business_id` column | |

## Implemented S1 core
S1 creates plans, businesses, branches, users, devices and tenant-scoped personal_access_tokens. S2 adds roles, memberships, auth challenges, device binding and encrypted audits as described below. User names/phones, device names/IPs, branch addresses and business contact/tax fields use encrypted casts and text columns. `phone_lookup` is a keyed SHA-256 HMAC of the canonical phone string using APP_KEY; it enables indexed lookup/uniqueness without storing the phone in plaintext. S2 defines canonical normalization below. Rotating APP_KEY requires re-encryption and recomputing phone indexes, not simply generating another key.

Branches/users/devices have unique `(business_id, id)` indexes and composite foreign keys for user branch, device user and device approver relationships. Tokens have business_id plus Sanctum's tokenable/name/token/abilities/last_used_at/expires_at/timestamps; composite `(business_id, tokenable_id)` references users. This token table supports tenant users only; platform admin authentication remains separate. Only token hashes are stored.

Plan features remain empty metadata until tier assignments are finalised. S2 role/permission tables are now implemented; Pennant/subscription/admin tables belong to their dependent tasks. `role_hint` is informational and grants no permissions.

## Supermarket extension requirements (not final migration designs)
Define tenant-scoped departments, packaging/barcodes/conversions, supported weight metadata, promotion rules, customer display sessions, stock-take sessions/items, wastage, returns/exchanges, import runs, price history/schedules, shelf labels, purchase orders/receipts, supplier returns/credits, cash drops, language preferences and batch markdowns before their implementations. Margin reporting uses preserved sale/batch costs and restricted permissions.

Broader product scope also requires expenses/attachments, supplier balances/payments, bank reconciliation, attendance, report exports and notification preferences; later scope includes loyalty, commissions, forecasting and multi-branch transfers. See the complete catalogue. These are requirements, not tables to generate blindly.

Quantity columns here are the counted-unit baseline only. Weighted quantities and packaging factors require a consistent exact representation across batches, movements, sale items, refunds and imports before S4. Money is integer TSh; signed variance/loss-of-stock values must use signed types where appropriate.

## S2 auth and audit schema
Auth migration adds encrypted users.totp_secret, nullable totp_confirmed_at and totp_last_step for replay prevention. personal_access_tokens.device_id has a composite business/device foreign key; legacy unbound tokens cannot access the published staff-session routes. auth_challenges has business_id, user_id, device_id, a unique SHA-256 token_hash, expiry, failed-code attempts, two_factor_verified and encrypted nullable setup_secret, with composite tenant user/device foreign keys. Successful completion deletes the challenge; reset/block invalidate relevant challenges/tokens.

Staff phone storage and lookup normalize local/international Tanzania formats to 255XXXXXXXXX; migration backfills existing encrypted values and indexes. Migration context can inspect inactive shops without persisting any activation.

activity_log is tenant scoped and uses the agreed Spatie activity logger. Properties are encrypted text; staff/device/role model logs allow only status/role/lockout fields and never PIN/hash/phone/name/TOTP secrets. PIN reset and permission provisioning have explicit events. Request metadata includes request ID, encrypted IP and a public device ID where available; subject/actor associations validate their tenant. Scheduled retention needs a tenant-aware worker. Model-created callbacks can refresh their own just-inserted row during the save lifecycle; the tenant write guard permits this only within that active creation call, then restores its persisted-original check.

## Roles/permissions (spatie, teams = business)
Default roles seeded per business: owner, manager, cashier, stockkeeper. Permissions: `sell`, `refund`, `void`, `give_discount`, `view_buying_price`, `view_reports`, `manage_products`, `adjust_stock`, `receive_stock`, `manage_customers`, `manage_users`, `manage_settings`, `manage_till`, `approve_variance`.

Implemented permission tables: `permissions` is a global name/guard vocabulary; `roles` has mandatory business_id, unique `(business_id, name, guard_name)` and `(business_id, id)`. `role_has_permissions` joins each shop's role to global permissions. `model_has_roles` / `model_has_permissions` have business_id/model_id/model_type and role_id/permission_id, with composite `(business_id, model_id)` referencing users and `(business_id, role_id)` referencing roles. Only tenant User memberships are supported; platform admin remains separate. Each table containing business_id has a scoped model. Role-permission pivots validate the related role's shop on writes and read through scoped roles. Bootstrap role provisioning uses the approved F01 matrix, with no default usable credentials.

## Migration order
1 plans, businesses -> 2 branches, users, devices, spatie tables, Pennant -> 3 products, product_batches, stock_movements -> 4 customers, till_sessions -> 5 sales, sale_items, sale_payments, held_sales, mpesa_transactions, customer_payments -> 6 sync_* -> 7 supermarket extensions in dependency order.

## Initial owner invitations (implemented)
owner_invitations has business_id (scoped model and restrictive business foreign key), token_hash (unique SHA-256 digest), expires_at, consumed_at and timestamps. A trusted local operator issues a 64-hex secret for 90 minutes; replacing the invitation removes prior unconsumed invites in this tenant. Registration locks the business and invitation, rejects configured owners and approved hardware, and consumes the invite once. The chosen owner name/phone remain encrypted, PIN is hashed and business owner_phone is updated canonically. No initial setup API issues invitations or recovers operational owners. The delivery URL is kept only in ignored private storage keyed by business public ID; the browser removes its fragment after reading it.

## Supervisor approvals (S2 implementation)
Additive migration 000008 creates supervisor_approvals with public_id (apr_), business_id, requester_id, approver_id, requester_token_id, device_id, permission, operation_id (UUID), payload_hash (SHA-256), token_hash (unique SHA-256), expires_at, consumed_at and timestamps. Composite business/resource foreign keys enforce shop isolation for both users, the requester session and device. Session deletion cascades approval deletion without blocking logout; audit events remain separate. The migration adds a composite unique business/id token index for this relationship. No raw PIN, authenticator code, approval secret or operation contents are stored in this table. The scoped model hides internal IDs and digests from JSON. User profile/PIN/role/activation changes revoke linked grants. Domain services consume approvals with their own authoritative snapshot and commit all DB changes under the same transaction.

## S3 exact quantity extension (specified, tables not yet implemented)
Use features/F02-exact-quantities.md for the S4 schema: counted/weight products, signed BIGINT atom balances/movements, integer-gram precision for weights, product packaging versions and one shared tenant barcode registry. Existing product/batch/sale tables above are draft baseline columns and are superseded by the atom/snapshot plan for affected quantities. Money remains integer TSh and API amounts must fit the browser-safe integer range. Never add these stock tables without tenant composite keys, StockService and isolation/concurrency tests in S4.

S4a migration 000009 implements only `products` and `product_barcodes`: tenant-scoped models, product public IDs, counted/weight metadata, integer prices, signed reorder_level_atoms, activation/soft archive and current base barcode. Registry rows retain every reserved base barcode with unique (business_id, barcode), binary collation and a composite (business_id, product_id) product foreign key. S4b adds packaging associations and extends the registry additively (below). S4c departments are defined below. Batches and movement tables remain pending. Product quantity type is immutable from creation to preserve its audit history. No stock quantity is stored or changed in this catalogue slice.

## S4b packaging schema (implemented)
Migration 000010 adds tenant-scoped product_packagings with prefixed public IDs, product/family/version, integer conversion_atoms, independent integer selling_price, binary-collated optional barcode, active/archive metadata and timestamps. Composite ownership references products(business_id,id); unique business/family/version keeps immutable versions distinct. The shared product_barcodes registry adds nullable packaging_id and a composite business/product/package FK, preventing attachment to another product or shop. Base codes retain null packaging_id. Archived versions and reservations stay stored. This migration adds no batch, balance or movement table.

## S4c department schema
Migration 000011 creates scoped departments (public ID, business FK, name, nullable current_name, timestamps/soft archive); unique business/current_name enforces current-name uniqueness and NULL on archive permits reuse without deleting history. Composite business/id unique supports product.business_id/department_id FK. Product department_id is nullable and hidden from raw model serialization; resources return a dep_ public ID/name. Department's assignedProducts relation retains archived products for the approved archive guard/count. No stock data changes.

## S4d receiving schema
New additive migration creates tenant-scoped product_batches and stock_movements. Batches have public_id, composite business/product ownership, signed qty_remaining_atoms, integer base-unit cost_price, expiry date, received_at, quarantine/recall flags, tenant-unique operation UUID, SHA-256 canonical request fingerprint and original receipt metadata JSON. Movements have public_id, composite business/product/batch/user/device ownership, signed delta/after atoms, type, operation UUID and receipt snapshot. Receive movements are append-only and tenant-unique per operation; every receipt creates one batch and exactly one movement atomically. Receipt snapshots contain public catalogue IDs/exact quantity strings, never credentials or staff PII. Future balance changes must still use StockService and write new movements; no online sale/adjustment endpoint is enabled here.

### S4g correction ledger metadata (additive migration 000013)
stock_movements adds nullable request_fingerprint CHAR(64), reason TEXT with encrypted model cast, and approved_by unsignedBIGINT with composite (business_id,approved_by) FK to users. No quantity column/type changes. Adjustment receipt is stored as an empty JSON array and exposed as null; original receive snapshots are unchanged. Unique tenant operation IDs cover receipts and corrections together. Fingerprints include canonical reviewed balance/target/delta/reason; attribution remains public-only in resources.

## S6 till schema
Additive migration 000014 creates till_sessions (till_ public IDs, composite branch/cashier/approver tenant FKs, integer float/count, signed expected/variance, revision, close limit snapshot, persistent owner-review flag, UTC timestamps). Generated nullable open_cashier_id enforces one open session per business/branch/cashier in MySQL. Shop lock serializes business-wide plan capacity. till_cash_entries (tce_) stores append-only positive integer amounts by cash domain type, encrypted nullable reason, unique tenant operation UUID and fingerprint, with composite till/user ownership. till_operations stores immutable actor/device-bound fingerprints and encrypted replay payloads; unique tenant operation UUID prevents double opening/paid-out/close. No sales/payment records are created by this migration.

## S15 additive return journals (migration 000017)

`product_batches.source_batch_id` nullable composite tenant/product FK to original batch for returned quarantine stock. `sale_items` adds `(business_id,sale_id,id)` ownership unique index.

` sale_returns `: tenant/public ID; original sale; actor/device/approver/own till composite tenant FKs; tenant-unique operation UUID and request fingerprint; encrypted reason; bounded unsigned refund_total/debt_reduction/cash_refund/cash_collected/cash_paid; immutable JSON original-value logical lines; created_at. Unique tenant/id and tenant/sale/id support child ownership. No updates/deletes.

` sale_return_items `: tenant/public ID; sale/return/source sale_item composite ownership FKs; product/target batch composite FK; positive exact signed qty_atoms; created_at. Each source allocation's cumulative return cannot exceed its sale quantity under the business lock. Append-only stock restoration allocations identify original versus quarantine destination.

` sale_exchanges `: tenant/public ID; unique return and unique replacement sale composite tenant FKs; created_at. Append-only link inserted in the same outer return transaction. Original sale snapshots remain immutable. Return status comes from allocations, invoice reductions are journaled by return debt_reduction and applied only in DebtService.

## S12 payment extensions — migrations 000018–000021
mobile_payment_notifications is append-only tenant/payment/notification identity, deduplicated independently of signature timestamp. sale_payments.mobile_payment_id and customer_payments.mobile_payment_id uniquely reference verified payments. mobile_payments.sale_id/customer_payment_id are one-use allocation links; application purpose guards disallow cross-consumption. customer_payments receives a composite business/id unique key before the payment link; migration 000021 can resume its partial additive step safely.

manual_mobile_refunds is an append-only tenant/owner/device/approver/payment record with unique tenant operation and external-reference hash, immutable fingerprint, amount, encrypted reference/reason and one full resolution per unallocated payment. sale_returns adds unsigned mobile_refund and encrypted mobile_refund_reference plus tenant-unique reference hash. Both refund services lock the business and check references across both journals. No provider payout or drawer entry is generated by a mobile refund record.


## POS/product-tools migrations 000022–000026
000022 adds tenant held_carts and immutable held_cart_operations with branch/user/device composite ownership, encrypted drafts/results, 24-hour expiry and unique tenant UUID journals. 000023 adds nullable tenant-owned discount_approved_by and encrypted discount_reason to immutable sales; exact gross/line/cart/net price snapshots remain in lines JSON.
000024 adds append-only product_price_histories with product, actor and optional packaging ownership (business_id/product_id/packaging_id references the existing packaging triple unique key). Initial/old/new integer prices and exact package conversion/version snapshots are recorded in the catalogue transaction.
000025 adds optional private image path/MIME/bytes/dimensions to products and tenant product_image_cleanups. Random tenant objects are referenced only after successful upload; optimistic revision prevents stale replacement; durable cleanup retries do not remove currently referenced objects, including archived-product images.
000026 adds immutable catalogue_import_operations with tenant UUID, actor/device, request fingerprint and saved result. Per-row deterministic receipt UUIDs and outer StockService transaction prevent partial/duplicate opening stock. Operational data was not reset.


## Coordinated operations journals (migrations 000027–000033)

Supplier tables store directory records, owner-issued purchase orders, accepted delivery invoices, invoice allocations, payment records, stock returns, issued credit notes and immutable actor/device operation fingerprints. A purchase order has no payable or stock effect. Invoice receipt snapshots retain exact base quantities and original costs. Credit allocations distinguish issued credits from expected return credits. Tenant composite foreign keys bind every linked resource.

Expenses, expense reversals and expense payment/refund journals retain separate occurrence, reversal and payment dates. Descriptions, reasons and external references are encrypted. `financial_operations` stores encrypted results and an immutable tenant/actor/device UUID fingerprint. `cash_drops` freezes handover fields and retains acknowledgement/reversal projections alongside append-only till entries. `sale_items.cost_price` snapshots original allocation costs; legacy null values use their immutable original batches.

Pricing rules and targets retain schedule/offer windows, priorities and selected products, packaging or batches; operation and consumption journals bind previews and exact markdown capacity. Schedule lifecycle history records activation/end only once. Attendance retains original server stamps and versioned corrections; notification preferences/notices are tenant and staff scoped.

Offline leases retain encrypted signing secrets and catalogue snapshots, approved device/user/original till, exact 24-hour validity and revocation. Offline operations retain encrypted immutable bodies, captured times, conflicts and owner reconciliation results. Sales retain `occurred_at` and `is_offline`; original closed till records are unchanged during reconciliation.

Incentive configurations and sale accounts retain historical earning terms. Entries store cumulative paid net value and exact point/commission deltas; redemption/restoration and external commission payout journals are append-only. Expense attachments retain encrypted tenant-private paths, content metadata, actor/device UUID fingerprints and durable pending/linked/removed/deleting/deleted cleanup state. Public APIs expose prefixed public IDs and validated content only.

## Coordinated remaining-software journals (F17–F20, migrations 000034–000039)

This section describes current additive code and supersedes earlier draft/pending descriptions for these tables. Executed verification and deployment status remain in the batch checklist/handoff; schema presence is not evidence of a successful migration or production launch.

### F19 classified stock loss, physical counts and alerts

Migration `000034_create_loss_alerts` adds tenant-scoped `loss_alerts`: public ULID, staff `user_id`, `kind`, Tanzania `event_date`, JSON `evidence`, nullable `acknowledged_at`/`acknowledged_by` and timestamps. Unique `(business_id,user_id,kind,event_date)` deduplicates each configured threshold crossing; composite tenant/user foreign keys bind the staff and acknowledging owner. Evidence retains the original observed value and threshold; acknowledgement does not rewrite it.

No second stock ledger is introduced. Existing append-only `stock_movements` record `stock_loss` and `stock_count`, retaining exact atom changes/balances and immutable receipt classification snapshots. Loss snapshots include damage/spoilage/wastage category, product/base unit, original cost, exact integer loss value and department attribution. Count snapshots retain selected department and exact physical count, including zero-variance accepted counts. Existing expected-balance checks, actor/device operation fingerprints, owner grants for stockkeepers and `StockService` transactions remain authoritative. Owner-configured nullable daily refund-count/refund-value/void-count thresholds live in business settings; they start disabled.

Department/till reports introduce no projection tables. They read existing immutable sale/return, allocation-cost, till-entry and cash-drop journals. Department financial classification uses current product assignments, explicitly labelled as such; loss/count department snapshots retain event attribution. Stock valuation is business-wide because batches have no branch ownership.

### F18 statement evidence and supplier plans

Migration `000035_create_bank_reconciliation` adds tenant-scoped `bank_statement_lines` (`bank_`): actor, encrypted account label/reference, `booked_on`, debit/credit direction, bounded unsigned integer amount, account/reference `identity_hash`, nullable `matched_type`/`matched_id` and created timestamp. Unique tenant identity prevents duplicate evidence; unique `(business_id,matched_type,matched_id)` prevents matching one completed payment twice. Statement source fields are immutable. Match targets are validated/locked tenant-owned supplier or expense payment rows in the service; this polymorphic match has no cross-table foreign key.

The same migration adds `supplier_payment_schedules` (`sched_`): actor, selected `invoice_id`, `due_on`, integer amount, scheduled/cancelled status and timestamps. Actor/invoice links use composite tenant foreign keys. Plans do not reserve money or alter invoice balances. Current invoice remaining amount is displayed separately; cancellation retains the plan. Both banking and scheduling reuse encrypted actor/device-bound `supplier_operations` UUID results/fingerprints. Last-three purchase-price comparisons read existing immutable invoice-line snapshots and add no price table.

### F17 configured scales and QR cards

The two additive `000036` migrations add `scale_label_profiles` (`scale_`) and `loyalty_cards` (`card_`). Each retains tenant, public ULID, originating user/device, tenant-unique operation UUID, fingerprint and timestamps. Profile rows retain nullable JSON field positions for EAN-13 integer-gram labels; null disables labels, and prior configuration versions remain stored. No default physical hardware format is inferred.

Card rows additionally retain customer, encrypted random token, token hash and active/revoked state. Unique `(business_id,token_hash)` supports tenant-scoped lookup without plaintext searches; `(business_id,customer_id,active)` supports replacement revocation. These two migrations use restrictive resource foreign keys; their scoped models and locked services also validate tenant ownership. Card issuance changes no credit, points, stock or financial journal. The customer display is an ephemeral same-computer browser popout with no database/storage projection; commission ranking reads existing earning/reversal journals without adding a leaderboard table.

### F20 platform and subscription journals

Migration `000037_create_platform_administration` adds global `admin_users`, separate from tenant staff: unique email, hashed password, encrypted authenticator secret, active flag, remember-token and timestamps. `admin_totp_steps` stores only secret HMAC and last accepted timestep for transactional replay prevention. These global platform tables have no tenant model scope because they do not contain `business_id`.

Tenant-scoped `subscriptions` (`sub_`) retain one row per business, plan, UTC start/end instants, status, revision and timestamps. The chosen Tanzania end date becomes an exclusive midnight boundary. `subscription_payments` retain subscription, bounded integer amount, cash/bank/mobile method, encrypted completed-payment reference, tenant-unique reference hash, paid timestamp and platform administrator attribution. Composite business/subscription ownership is enforced; payment records are immutable and never execute transfers.

Global append-only `platform_operations` retain unique UUID, administrator, action, fingerprint, encrypted input/result and created timestamp. Migration `000039_add_platform_operation_evidence` additively supplies nullable encrypted-input storage when upgrading a schema that already lacked it; rollback retains evidence. Global Pennant `features` rows have unique name/scope, stored value and timestamps; evaluation uses explicit business scopes. Plan `features`/`limits` and business-settings overrides remain the configured sources. Existing unmanaged local businesses retain current behavior until explicitly managed; no paid tier is inferred. TRA remains false.

### F20 consent and durable delivery

Migration `000038_create_message_delivery` adds tenant-scoped `customer_message_consents`: public ULID, customer, channel/purpose booleans, revision, recording staff, encrypted evidence and timestamps; one row per tenant/customer with composite tenant ownership.

`message_deliveries` (`msg_`) retain hashed source event key, channel/purpose, nullable customer/owner recipient links, encrypted phone/message snapshots, status, globally unique nullable provider ID, failure code, dispatch/acceptance/delivery timestamps and normal timestamps. Unique `(business_id,event_key,channel)` deduplicates source messages independently of UI operation UUIDs. Composite tenant recipient links and immutable source snapshots preserve attribution. Only delivery state/evidence fields can change. Acceptance and confirmed delivery are distinct states.

`messaging_operations` retain tenant-unique UUID, actor/device composite ownership, fingerprint, encrypted replay result and created timestamp. Append-only `message_provider_events` retain tenant, provider ID, authenticated delivered/failed status and receipt timestamp, unique per tenant/provider/status. They persist signed early WhatsApp evidence before the send response has recorded its provider mapping, then apply it under a delivery row lock. Read callbacks normalize to delivered; later failures cannot regress delivered state. Conflicting provider IDs become uncertain instead of creating duplicate accepted mappings. No raw provider payload or secret is journaled.

SMS reconciliation needs no additional table: accepted SMS retain the unique `beem:` provider mapping and encrypted original recipient; allowed mutable `updated_at` provides a durable five-minute GET claim. Matched authenticated reports set delivered state/local verification time or failed evidence under a fresh row lock. Pending/error/mismatch keeps accepted state, and terminal/cancelled rows cannot be overwritten or resent. The GSM guard blocks unverified Unicode before transport without altering immutable message text.

### Explicit completed-void evidence without a migration

Optional return `operation_kind: void` is bound into the existing approval snapshot/fingerprint after Laravel verifies every remaining original quantity and disallows exchanges. Ordinary omitted-kind snapshots remain unchanged. The normal immutable `sale_returns`/`sale_return_items` and `sale_returned` audit remain the refund journals; a committed void additionally writes exactly one tenant-scoped `sale_voided` activity on that return, with public sale/return/branch/approval attribution. Operation replay writes neither stock/cash nor another audit. `void_count` reads this explicit evidence; ordinary full refunds are not inferred to be voids. See F03-completed-void-audit.
