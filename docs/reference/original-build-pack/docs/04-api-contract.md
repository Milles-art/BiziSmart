> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 04 — API Contract (v1)

Base URL: `https://{business}.bizismart.co.tz/api/v1` (tenant = subdomain; never send a business id). Webhooks only: `https://api.bizismart.co.tz/v1`.
Headers: `Authorization: Bearer <device_token>`, `Accept: application/json`, `Content-Type: application/json`, optional `Idempotency-Key` on POST /sales.
IDs are prefixed ULIDs: `usr_`, `prod_`, `bat_`, `cust_`, `sale_`, `till_`, `rx_`, `dev_`, `pay_`. Money = integer TSh. Dates = ISO 8601 UTC; `expiry_date` = `YYYY-MM-DD`.

## Envelope
```json
// success
{ "success": true, "data": { }, "meta": { "page": 1, "per_page": 50, "total": 284 }, "request_id": "req_abc" }
// error
{ "success": false, "error": { "code": "INSUFFICIENT_STOCK", "message": "Only 3 units of Paracetamol 500mg available", "field": "items.0.qty", "details": { "available": 3 } }, "request_id": "req_abc" }
```
Lists: `?page=1&per_page=50&search=...` (max per_page 100). Validation errors: `422 VALIDATION_ERROR` with `details.errors` = `{ field: [messages] }`.

## Error codes
| HTTP | Code | When |
|---|---|---|
| 400 | INSUFFICIENT_STOCK | Online sale qty > sellable stock |
| 400 | EXPIRED_STOCK | Requested batch expired |
| 400 | PAYMENT_MISMATCH | Payments do not cover total |
| 400 | CREDIT_LIMIT_EXCEEDED | Credit would exceed limit |
| 400 | OVERPAYMENT | Debt payment > balance |
| 401 | UNAUTHORIZED | Missing/invalid/expired token |
| 401 | INVALID_PIN | Wrong phone/PIN (generic message) |
| 403 | PERMISSION_DENIED | Role lacks permission |
| 403 | SUPERVISOR_REQUIRED | Needs supervisor PIN |
| 403 | PRESCRIPTION_REQUIRED | Rx/controlled item without prescription or pharmacist override |
| 403 | FEATURE_DISABLED | Feature off for this business |
| 403 | DEVICE_NOT_APPROVED | Device pending/blocked |
| 403 | PLAN_LIMIT_REACHED | Users/products limit hit |
| 403 | SUBSCRIPTION_INACTIVE | Subscription expired or suspended |
| 404 | NOT_FOUND | Missing or belongs to another tenant (never reveal which) |
| 409 | DUPLICATE_BARCODE | Barcode exists |
| 409 | TILL_ALREADY_OPEN | Open session exists |
| 409 | TILL_REQUIRED | No open till |
| 422 | VALIDATION_ERROR | Invalid input |
| 422 | MPESA_TIMEOUT / MPESA_REJECTED | STK problems |
| 423 | ACCOUNT_LOCKED | Too many wrong PINs |
| 429 | RATE_LIMITED | Throttled |
| 500 | INTERNAL_ERROR | Unexpected |

## Auth
**POST /auth/login** (public, throttled 5/min per phone+IP)
```json
// request
{ "phone": "0712345678", "pin": "1234", "device_uid": "c0a8-...", "device_name": "Amina's Tecno" }
// 200
{ "success": true, "data": { "token": "1|k3Jx...", "token_type": "Bearer", "expires_at": "2026-11-07T10:00:00Z",
  "user": { "id": "usr_01J...", "name": "Amina Hassan", "role": "cashier", "branch_id": "br_01J...", "permissions": ["sell","manage_till"] } } }
// 403 DEVICE_NOT_APPROVED when device is new and user is not Owner (device created as pending, owner notified)
```
**POST /auth/logout** -> revokes current token. **POST /auth/token/rotate** -> new token, old revoked.
**GET /me** -> user, role, permissions, business `{ name, vertical, vat_rate, settings }`. **GET /me/features** -> `{ "features": { "fefo": true, "prescriptions": true, "controlled_register": false } }`.
**POST /auth/pin/reset** (owner) `{ "user_id": "usr_...", "new_pin": "4321" }`. **POST /auth/supervisor-check** `{ "pin": "...", "permission": "refund" }` -> `{ "approved_by": "usr_..." , "approval_token": "..." }` (short-lived, single use).
**Devices (owner):** GET /devices, POST /devices/{id}/approve, POST /devices/{id}/block.
**Users (owner):** GET/POST /users, PUT /users/{id}, POST /users/{id}/deactivate.

## Products
**GET /products** `?search=&category=&low_stock=1&expiring_in=30&page=`
```json
{ "id": "prod_01J...", "name": "Paracetamol 500mg", "barcode": "6123456789012", "category": "Analgesics", "unit": "tablet",
  "selling_price": 1200, "buying_price": 800, "reorder_level": 50, "requires_prescription": false, "controlled_schedule": null,
  "tfda_reg": "TFDA-2021-0045", "stock": 80, "nearest_expiry": "2027-03-31" }
```
`buying_price` is omitted unless the user has `view_buying_price`.
**GET /products?barcode=6123456789012** returns the same list shape (0 or 1 item).
**POST /products** (`manage_products`) body: name*, barcode, category, unit*, buying_price*, selling_price*, reorder_level, requires_prescription, controlled_schedule (1-3), tfda_reg -> 201 product. 409 `DUPLICATE_BARCODE`; `PLAN_LIMIT_REACHED`.
**GET /products/{id}**, **PUT /products/{id}**, **DELETE /products/{id}** (archive, soft delete).
**GET /products/{id}/batches** -> `[ { "id": "bat_...", "batch_number": "B-2209", "expiry_date": "2027-03-31", "qty_remaining": 20, "cost_price": 800 } ]` (FEFO order).
**POST /products/{id}/batches** (`receive_stock`) `{ "batch_number": "B-2209", "expiry_date": "2027-03-31", "qty": 100, "cost_price": 800 }` -> 201 batch + `receive` movement. `expiry_date` required for pharmacy.
**POST /stock/adjust** (`adjust_stock`) `{ "batch_id": "bat_...", "qty_change": -3, "reason": "damaged" }`.
**GET /stock/reorder-alerts**, **GET /stock/expiry-alerts?days=30**.

## Sales
**POST /sales** (`sell`; needs open till; send `Idempotency-Key`)
```json
// request
{ "branch_id": "br_...", "customer_id": "cust_...", "prescription_id": null,
  "items": [ { "product_id": "prod_...", "qty": 2, "unit_price": 1200, "discount": 0 } ],
  "discount": 0,
  "payments": [ { "method": "cash", "amount": 5000 }, { "method": "mpesa", "amount": 3500, "mpesa_checkout_request_id": "ws_CO_..." } ],
  "cash_tendered": 5000,
  "pharmacist_pin_approval_token": null }
// 201
{ "success": true, "data": { "id": "sale_01J...", "receipt_number": "RCP-2026-000981", "subtotal": 8500, "discount": 0, "total": 8500,
  "vat_amount": 1297, "change_given": 0, "status": "completed",
  "items": [ { "product_id": "prod_...", "batch_id": "bat_...", "qty": 2, "unit_price": 1200, "line_total": 2400 } ],
  "payments": [ { "method": "cash", "amount": 5000 }, { "method": "mpesa", "amount": 3500 } ], "receipt_url": null, "created_at": "2026-10-08T10:34:00Z" } }
```
Server chooses batches by FEFO (rules 3); `items[].batch_id` not accepted unless setting enabled. Server **recomputes** totals and ignores any client `total`. If `unit_price` differs from current price and user lacks `give_discount`, price is overridden by the server.
**GET /sales** `?from=&to=&cashier_id=&customer_id=`; **GET /sales/{id}**.
**POST /sales/{id}/refund** `{ "items": [ { "sale_item_id": 12, "qty": 1 } ], "reason": "wrong item", "approval_token": "..." }` (`SUPERVISOR_REQUIRED` if missing).
**POST /sales/hold** `{ "cart": { } }` -> hold id; **GET /sales/hold**; **DELETE /sales/hold/{id}**.
**GET /sales/{id}/receipt** -> `{ "receipt_url": "<signed R2 url>" }` (generated by job; 202 while pending).

## Till
**POST /till-sessions/open** `{ "branch_id": "br_...", "opening_float": 20000 }` -> 201 (`TILL_ALREADY_OPEN`).
**GET /till-sessions/current**. **POST /till-sessions/current/paid-out** `{ "amount": 2000, "reason": "transport" }`.
**POST /till-sessions/current/close** `{ "counted_cash": 164000, "approval_token": null }` -> `{ "expected_cash": 165000, "variance": -1000, "status": "closed" }`; if `abs(variance) > limit` and no approval -> 403 `SUPERVISOR_REQUIRED` (session stays open).

## Customers and debt
**GET /customers** `?search=`; **POST /customers** `{ "name", "phone", "credit_limit" }`; **GET/PUT /customers/{id}**; **GET /customers/{id}/debt** -> `{ "outstanding_balance": 30000, "aging": { "0_30": 20000, "31_60": 10000, "61_90": 0, "90_plus": 0 }, "open_sales": [ ] }`; **POST /customers/{id}/payments** `{ "amount": 10000, "method": "cash" }`.

## M-Pesa
**POST /mpesa/stk-push** `{ "phone": "255712345678", "amount": 3500 }` -> `{ "checkout_request_id": "ws_CO_...", "status": "pending" }`.
**GET /mpesa/status/{checkout_request_id}** -> `{ "status": "pending|confirmed|failed|timeout" }` (client polls every 3s up to 60s).
**POST https://api.bizismart.co.tz/v1/mpesa/callback** (webhook, no auth, verify provider signature/secret): finds `mpesa_transactions` by `checkout_request_id` (global unique) -> sets tenant -> updates status. Duplicate -> 200, no change. Always return 200 quickly.

## Pharmacy
**POST /pharmacy/prescriptions** `{ "patient_name", "prescriber_name", "facility", "prescription_number", "prescribed_on", "items": [ { "product_id", "qty_prescribed" } ], "photo": "<multipart optional>" }` -> 201 `rx_...`. **GET /pharmacy/prescriptions**. **GET /pharmacy/controlled-substances** `?from=&to=` (printable list).

## Reports (basic)
**GET /reports/sales?from=&to=&group=day** -> totals, cash/mpesa/credit split, top products. **GET /reports/inventory-value**. **GET /reports/debts**. Require `view_reports`.

## Sync (Phase 3)
**POST /sync/push** `{ "device_id", "operations": [ { "operation_id": "uuid", "type": "SALE", "created_at": "...", "payload": { } } ] }` -> per-operation `status`: `committed | committed_with_conflict | duplicate | quarantined | rejected`. **GET /sync/pull?since=**. **GET /sync/conflicts**, **PUT /sync/conflicts/{id}/resolve**.
