# 03 — Database Schema (MySQL 8, InnoDB, utf8mb4)

> Supermarket-only revision (2026-10-08): this is the supermarket core baseline, not a complete supermarket contract. All 12 additions in features/F10-supermarket-expansion.md are approved. Before affected implementation, define exact packaging/weighted quantity representations and extensions for departments, wastage, returns/exchanges, imports, price history/labels, supplier credits, cash drops, localisation, batch markdowns and margin alerts. Existing endpoints/envelope remain the baseline; do not invent undocumented payloads.

Conventions: table names plural snake_case. `id` bigint unsigned PK (internal). `public_id` char(26) ULID, unique (API-facing, prefixed in responses). All tenant tables have `business_id` bigint unsigned NOT NULL, FK -> businesses.id, **indexed (usually as the first column of composite indexes)**. Money columns `bigint unsigned` (TSh). Timestamps `created_at`, `updated_at` (UTC). Soft deletes (`deleted_at`) only where noted.

Tenant models use the `BelongsToBusiness` trait. Tables marked **(global)** have no `business_id`.

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
| mpesa_transactions | id, business_id, sale_id nullable, phone, amount, checkout_request_id (**unique, globally**), merchant_request_id, status (pending, confirmed, failed, timeout), raw_callback JSON, confirmed_at | global unique lookup used by the webhook to find the tenant |
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

S4a migration 000009 implements only `products` and `product_barcodes`: tenant-scoped models, product public IDs, counted/weight metadata, integer prices, signed reorder_level_atoms, activation/soft archive and current base barcode. Registry rows retain every reserved base barcode with unique (business_id, barcode), binary collation and a composite (business_id, product_id) product foreign key. S4b adds packaging associations and extends the registry additively (below). Departments, batches and movement tables remain pending. Product quantity type is immutable from creation to preserve its audit history. No stock quantity is stored or changed in this catalogue slice.

## S4b packaging schema (implemented)
Migration 000010 adds tenant-scoped product_packagings with prefixed public IDs, product/family/version, integer conversion_atoms, independent integer selling_price, binary-collated optional barcode, active/archive metadata and timestamps. Composite ownership references products(business_id,id); unique business/family/version keeps immutable versions distinct. The shared product_barcodes registry adds nullable packaging_id and a composite business/product/package FK, preventing attachment to another product or shop. Base codes retain null packaging_id. Archived versions and reservations stay stored. This migration adds no batch, balance or movement table.
