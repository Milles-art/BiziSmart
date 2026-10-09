> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 03 — Database Schema (MySQL 8, InnoDB, utf8mb4)

Conventions: table names plural snake_case. `id` bigint unsigned PK (internal). `public_id` char(26) ULID, unique (API-facing, prefixed in responses). All tenant tables have `business_id` bigint unsigned NOT NULL, FK -> businesses.id, **indexed (usually as the first column of composite indexes)**. Money columns `bigint unsigned` (TSh). Timestamps `created_at`, `updated_at` (UTC). Soft deletes (`deleted_at`) only where noted.

Tenant models use the `BelongsToBusiness` trait. Tables marked **(global)** have no `business_id`.

## Platform / admin (global)
| Table | Columns |
|---|---|
| businesses | id, public_id, name, vertical (enum pharmacy, supermarket, restaurant, loans, agro), subdomain (unique), plan_id FK, owner_phone, tin, vrn, vat_rate tinyint default 18, address, phone, is_active, settings JSON (till_variance_limit default 5000, receipt_header, receipt_footer, receipt_method), timestamps |
| plans | id, vertical, code (free, basic, professional, enterprise), name, price_monthly, limits JSON (users, products, tills), features JSON |
| subscriptions | id, business_id, plan_id, starts_at, ends_at, status (active, expired, suspended), payment_ref, timestamps |
| subscription_payments | id, business_id, subscription_id, amount, method, reference, paid_at, recorded_by |
| features (Pennant) | Pennant's `features` table, scope = business |
| admin_users | Filament admin users (separate from tenant users), 2FA required |

## Tenant core
| Table | Columns | Indexes / notes |
|---|---|---|
| branches | id, public_id, business_id, name, address, gps_lat, gps_lng, is_active | (business_id) |
| users | id, public_id, business_id, branch_id nullable, name, phone, pin_hash, role_hint, is_active, locked_until, failed_pin_attempts, last_login_at, soft deletes | unique (business_id, phone); roles via spatie tables with team_id = business_id |
| devices | id, public_id, business_id, user_id nullable, device_uid, name, status (pending, approved, blocked), approved_by, last_seen_at, last_ip | unique (business_id, device_uid) |
| products | id, public_id, business_id, name, barcode nullable, category, unit (e.g. tablet), buying_price, selling_price, reorder_level, requires_prescription bool, controlled_schedule tinyint null, tfda_reg nullable, is_active, soft deletes | unique (business_id, barcode) where not null; index (business_id, name) |
| product_batches | id, public_id, business_id, product_id, batch_number, expiry_date nullable (required for pharmacy), qty_remaining **bigint signed** (can be negative via sync), cost_price, received_at, supplier_id nullable, is_quarantined bool | index (business_id, product_id, expiry_date); **no CHECK >= 0** |
| stock_movements | id, business_id, product_id, batch_id, type (see rules 2), qty_change signed, qty_after, reason nullable, user_id, device_id nullable, sale_id nullable, operation_id nullable, created_at | index (business_id, batch_id, created_at); append-only |
| customers | id, public_id, business_id, name, phone, credit_limit default 0, outstanding_balance default 0, notes, soft deletes | unique (business_id, phone) when not null |
| customer_payments | id, public_id, business_id, customer_id, amount, method (cash, mpesa), till_session_id nullable, reference, received_by, created_at | |
| sales | id, public_id, business_id, branch_id, till_session_id, cashier_id, customer_id nullable, pharmacist_id nullable, prescription_id nullable, receipt_number, subtotal, discount, total, vat_amount, change_given, status, source (online, offline_sync), operation_id nullable unique, receipt_url nullable, fiscal_receipt_no nullable, created_at | unique (business_id, receipt_number); index (business_id, created_at) |
| sale_items | id, business_id, sale_id, product_id, batch_id, qty, unit_price, cost_price (batch cost at time of sale, for profit), discount, line_total, refunded_qty default 0 | |
| sale_payments | id, business_id, sale_id, method (cash, mpesa, credit), amount, mpesa_transaction_id nullable | |
| held_sales | id, public_id, business_id, branch_id, user_id, cart JSON, expires_at | |
| till_sessions | id, public_id, business_id, branch_id, cashier_id, opened_at, closed_at nullable, opening_float, closing_cash nullable, expected_cash nullable, variance nullable, approved_by nullable, status (open, closed) | unique partial: one open per (business_id, branch_id, cashier_id) enforced in code + test |
| till_paid_outs | id, business_id, till_session_id, amount, reason, user_id | |
| mpesa_transactions | id, business_id, sale_id nullable, phone, amount, checkout_request_id (**unique, globally**), merchant_request_id, status (pending, confirmed, failed, timeout), raw_callback JSON, confirmed_at | global unique lookup used by the webhook to find the tenant |
| suppliers | id, public_id, business_id, name, phone, notes | (later UI) |
| sync_operations | id, business_id, operation_id (**unique**), device_id, type, status, result JSON, created_at, processed_at | idempotency |
| sync_conflicts | id, business_id, operation_id, device_id, type (NEGATIVE_STOCK, PRICE_CHANGED, EXPIRED_BATCH, RECALLED_BATCH, QUARANTINED), product_id nullable, batch_id nullable, details JSON, status (open, resolved), resolved_by nullable, resolved_note nullable, timestamps | |
| activity_log | spatie activitylog table + `business_id` column | |

## Pharmacy
| Table | Columns |
|---|---|
| patients | id, public_id, business_id, name, phone nullable, dob nullable, allergies JSON nullable (later) |
| prescriptions | id, public_id, business_id, patient_id nullable, patient_name, prescriber_name, facility, prescription_number, prescribed_on, photo_path nullable (encrypted path), logged_by, created_at; unique (business_id, prescriber_name, prescription_number) |
| prescription_items | id, business_id, prescription_id, product_id, qty_prescribed |
| controlled_substances_log | id, business_id, product_id, batch_id, sale_id, prescription_id, qty, patient_name, prescriber_name, pharmacist_id, created_at — **append-only (no update/delete)** |
| pharmacist_duty_log | id, business_id, user_id, started_at, ended_at nullable (Phase 4) |

## Roles/permissions (spatie, teams = business)
Default roles seeded per business: owner, pharmacist, cashier, stockkeeper. Permissions: `sell`, `refund`, `void`, `give_discount`, `view_buying_price`, `view_reports`, `manage_products`, `adjust_stock`, `receive_stock`, `manage_customers`, `manage_users`, `manage_settings`, `dispense_rx`, `manage_till`, `approve_variance`, `view_controlled_register`.

## Migration order
1 plans, businesses -> 2 branches, users, devices, spatie tables, Pennant -> 3 products, product_batches, stock_movements -> 4 customers, till_sessions -> 5 sales, sale_items, sale_payments, held_sales, mpesa_transactions, customer_payments -> 6 sync_* -> 7 pharmacy tables.
