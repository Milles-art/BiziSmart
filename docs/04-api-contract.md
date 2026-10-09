# 04 — API Contract (v1)

> Supermarket-only revision (2026-10-08): this is the supermarket core baseline, not a complete supermarket contract. All 12 additions in features/F10-supermarket-expansion.md are approved. Before affected implementation, define exact packaging/weighted quantity representations and extensions for departments, wastage, returns/exchanges, imports, price history/labels, supplier credits, cash drops, localisation, batch markdowns and margin alerts. Existing endpoints/envelope remain the baseline; do not invent undocumented payloads.

Base URL: `https://{business}.bizismart.co.tz/api/v1` (tenant = subdomain; never send a business id). Webhooks only: `https://api.bizismart.co.tz/v1`.
Headers: `Authorization: Bearer <device_token>`, `Accept: application/json`, `Content-Type: application/json`, optional `Idempotency-Key` on POST /sales.
IDs are prefixed ULIDs (including sale-item IDs used for refunds): `biz_`, `br_`, `usr_`, `prod_`, `bat_`, `cust_`, `sale_`, `till_`, `dev_`, `pay_`, `si_`, `apr_`, `pkg_`. Money = integer TSh; quantities are exact decimal strings (whole items or kg with up to three decimal places). Dates = ISO 8601 UTC; `expiry_date` = `YYYY-MM-DD`.

## Envelope
```json
// success
{ "success": true, "data": { }, "meta": { "page": 1, "per_page": 50, "total": 284 }, "request_id": "req_abc" }
// error
{ "success": false, "error": { "code": "INSUFFICIENT_STOCK", "message": "Only 3 units of Bottled Water 500ml available", "field": "items.0.qty", "details": { "available": 3 } }, "request_id": "req_abc" }
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
| 403 | FEATURE_DISABLED | Feature off for this business |
| 403 | DEVICE_NOT_APPROVED | Device pending/blocked |
| 403 | PLAN_LIMIT_REACHED | Users/products limit hit |
| 403 | SUBSCRIPTION_INACTIVE | Subscription expired or suspended |
| 404 | NOT_FOUND | Missing or belongs to another tenant (never reveal which) |
| 405 | METHOD_NOT_ALLOWED | Unsupported HTTP method |
| 409 | DUPLICATE_BARCODE | Barcode exists |
| 409 | TILL_ALREADY_OPEN | Open session exists |
| 409 | TILL_REQUIRED | No open till |
| 422 | VALIDATION_ERROR | Invalid input |
| 422 | MPESA_TIMEOUT / MPESA_REJECTED | STK problems |
| 423 | ACCOUNT_LOCKED | Too many wrong PINs |
| 429 | RATE_LIMITED | Throttled |
| 500 | INTERNAL_ERROR | Unexpected |
| 503 | SERVICE_UNAVAILABLE | Temporary service outage |

## Foundation endpoint (S0)
**GET /health** (public, no tenant data) -> `{ "success": true, "data": { "status": "ok", "service": "BiziSmart API" }, "request_id": "req_<ULID>" }`.
This is application liveness only; it does not prove MySQL/Redis, tenant setup or checkout readiness. Browser requests use the same origin via /api/v1; the development Vite proxy forwards /api to local Laravel.

All foundation JSON responses include a server-generated `request_id` and matching `X-Request-Id` header. Client-provided request IDs are not trusted/reused. Framework validation errors retain `details.errors`; unexpected API failures return a generic message even with debug enabled. Test-only routes are registered in tests, never production routes.

## Auth
User-approved browser sessions survive same-tab reload using sessionStorage for `{token,expiresAt,lastActiveAt,deviceUid}` only. A matching device UUID, unexpired token and activity within 30 minutes are required to attempt restore. Restore never supplies user/role/permission data; GET `/me` revalidates the existing device-bound Bearer token before workspace display. Server auth paths and Sanctum contracts are unchanged; no cookie-auth endpoint or public refresh bypass is introduced. Logout, expiry/inactivity and protected UNAUTHORIZED responses remove remembered credentials; reload preserves the original expiry/idle deadlines. No PIN/challenge/TOTP/shop data is persisted. If browser storage is unavailable, access remains memory-only.

Initial owner setup: a local trusted operator runs `bizismart:owner-invite` for an active shop without an operational owner (including inactive/deleted accounts) or approved device. It creates a hashed, one-use, 90-minute invitation and saves the setup link in private local storage. There is no public invitation issuance endpoint. POST `/auth/owner/setup` accepts `{invitation_token,name,phone,pin,device_uid,device_name}` and consumes the invitation under a business lock. It provisions the first owner with a hashed chosen PIN, then returns the normal owner TOTP enrolment challenge; no authenticated token is issued before TOTP verification. Phone/PIN/device validation follows login/staff contracts. Invalid/expired/consumed invitations return 401 `INVALID_INVITATION`; an existing operational owner returns 409 `OWNER_ALREADY_CONFIGURED`. This setup cannot reset an existing owner or bypass TOTP. Setup responses/errors use no-store and never echo credentials. The invitation link uses a browser fragment, removed immediately after reading; tokens are never stored in browser persistence. Super Admin recovery/production onboarding remain separate.
S1 supplies tenant/token infrastructure; S2 now exposes the auth/session/device routes identified below. Staff CRUD, single-use supervisor approval and S4a product catalogue routes are implemented; stock routes remain pending. Tests register private permission probes temporarily. Sanctum cookie/CSRF routes are disabled; tenant APIs use Bearer tokens.

Tenant resolution accepts exactly one subdomain beneath `TENANT_DOMAIN`; unknown/inactive tenants, reserved labels and nested/foreign hosts return the generic 404 envelope. Tenant resolution runs before token authentication and resource binding. Business IDs in headers/query/body never select the tenant. A token for another business returns 401. Resource bindings require the correct prefixed ULID; internal numeric IDs and raw unprefixed ULIDs return 404. Request context and cached guards are cleared after each request, including failures. `/health` remains the only tenant-free API route.

Development uses `TENANT_DOMAIN=localhost`, with frontend shop URLs such as `http://demo-alpha.localhost:15173` proxied to API port 18080. Backend isolation tests configure `bizismart.test`; browser tests use `localhost` against the separate test database. Production uses `bizismart.co.tz`; deployment must explicitly set the domain. No business selector supplies the tenant.

**POST /auth/login** (public, throttled 5/min per canonical phone+IP+business and 60/min per IP+business; challenge submissions 10/min per IP+business)
```json
// request
{ "phone": "0712345678", "pin": "1234", "device_uid": "c0a8-...", "device_name": "Amina's Tecno" }
// 200
{ "success": true, "data": { "token": "1|k3Jx...", "token_type": "Bearer", "expires_at": "2026-11-07T10:00:00Z",
  "user": { "id": "usr_01J...", "name": "Amina Hassan", "role": "cashier", "branch_id": "br_01J...", "permissions": ["sell","manage_till"] } } }
// 403 DEVICE_NOT_APPROVED for an unknown device, except the first owner device after all challenge steps
```
**POST /auth/logout** -> revokes current token. **POST /auth/token/rotate** -> new token, old revoked.
**GET /me** -> user, role, permissions, business `{ name, vat_rate, settings }`. **GET /me/features** -> `{ "features": { "fefo": true, "departments": true, "promotions": false } }`.
**POST /auth/pin/reset** (owner) `{ "user_id": "usr_...", "new_pin": "4321" }`. **POST /auth/supervisor-check** is implemented for authenticated staff on an approved device; see the operation approval contract below.
**Devices (owner):** GET /devices, POST /devices/{id}/approve, POST /devices/{id}/block.
**Users (owner, implemented):** GET `/users?page=1&per_page=50` (maximum 100) -> `{users:[...]}` plus envelope `meta`. POST `/users` accepts `{name,phone,pin,role,branch_id?}` and returns 201 with a managed staff object. `role` is owner/manager/cashier/stockkeeper; PIN is 4–6 digits and is temporary (`must_change_pin=true`). Optional branch ID is an active `br_...` from this shop, or null. PUT `/users/{id}` accepts any of `{name,phone,role,branch_id,is_active}`; PIN changes use the existing reset endpoint, and a submitted PIN here is rejected. POST `/users/{id}/deactivate` returns `{completed:true}` without deleting staff history.

Managed staff objects contain `{id,name,phone,role,branch_id,permissions,is_active,must_change_pin,two_factor_enabled}` with public IDs only. Phone is decrypted only for this owner-only response; PIN/hash/TOTP secrets/internal IDs are never returned. Pagination includes active and inactive non-deleted staff. Responses/errors use no-store. Canonical duplicate phones return 422 VALIDATION_ERROR (field phone), including disabled/deleted reservations; phones in other shops do not conflict. An unavailable/foreign branch gives the same 422 branch_id error. Creation/reactivation counts active staff, including owners, against the plan users limit; null/missing means unlimited. A last active owner cannot be deactivated or demoted (`409 LAST_OWNER_REQUIRED`). Changing profile/branch/role/activation revokes that staff member's tokens/challenges, including self edits; require fresh login. Role assignment replaces old grants. Audit records contain changed field names rather than sensitive profile values.

S2 challenge flow: successful PIN authentication requiring another step returns 202 with `{challenge_token, next_step, expires_at}`. `next_step` is `enroll_totp`, `verify_totp` or `change_pin`. Only enrolment includes `{setup_secret, otpauth_uri}`. Challenge expires in five minutes and has no API permissions. POST `/auth/totp/verify` accepts `{challenge_token, code}` (six digits); POST `/auth/pin/change` accepts `{challenge_token, new_pin}` (4-6 digits, different from old PIN). Each returns the next 202 step or normal 200 token/user response. Errors: `INVALID_CHALLENGE` (401), `INVALID_TOTP` (401), `PIN_CHANGE_REQUIRED` (403), `DEVICE_NOT_APPROVED` (403), `INVALID_PIN` (401), `ACCOUNT_LOCKED` (423), `STAFF_ROLE_REQUIRED` (403). Reset, logout, block and user deactivation return `{completed: true}`. Devices GET returns `{devices:[{id,name,status,user_id,approved_by,last_seen_at}]}` with public IDs only.

S2 backend implementation includes login/challenges, PIN change/reset, logout/rotation, /me, /me/features, owner staff management and device approval/blocking. Supervisor-check is implemented as a narrowly scoped operation approval; it never issues a staff session. These routes require a valid tenant host; protected routes additionally require a bound approved-device token, an active assigned user and no outstanding PIN/owner-TOTP requirement. Role tests still register private product/report probes, which are not production routes. Auth/session/staff/device responses (including errors) use Cache-Control: no-store. Business settings returned by /me are restricted to till_variance_limit; features returns only explicitly stored boolean plan flags (currently empty), without inventing feature tiers. The preserved frontend now calls these real auth/session/staff/device APIs through RTK Query. App does not expose imported synthetic login or prototype shop operations. Stock/till/sales/payment tools remain unavailable until real endpoints exist.

## Products
S4a implements GET/POST `/products` and GET/PUT/DELETE `/products/{id}` with authenticated approved-device sessions and no-store responses. List returns `{products:[...]}` plus envelope pagination metadata. Search, category and current base or active-pack barcode filters are available. Managers/cashiers see active products; users with `manage_products` also see disabled products. Archived/foreign/numeric-ID resources return generic 404.

S5a connects the preserved Products interface to these real routes through live RTK Query. It submits exact quantity strings and integer prices, displays authoritative responses, supports confirmed archive and preserves form values on failure. S4b adds the packaging routes and connected pack forms described below. Stock and payment endpoints remain unavailable.

Create requires name, unit, buying_price and selling_price. Optional quantity_type defaults to `counted`; `weight` requires kg. Prices must be JSON integers within the S3 browser-safe bounds; numeric strings/floats are rejected. Reorder level is an exact quantity string, defaults to `"0"`, and is nonnegative. PUT accepts partial fields, but quantity_type is immutable from creation. Barcode is an optional 1–100-character printable ASCII string without spaces (leading zeros preserved), or null; category is optional text or null. Tracks_expiry defaults false and is_active defaults true. Active product creation/reactivation is checked against plan limits under a shop lock. DELETE returns `{completed:true}` and preserves history and reserved codes.

S4a responses add quantity_type, readonly quantity_precision/quantity_scale and is_active. Until the stock slice is implemented, `stock` and `nearest_expiry` are null and `stock_available` is false. This deliberately supersedes the numeric stock example below for the currently available catalogue. `low_stock`, `expiring_in` and non-null department_id are rejected with 422 VALIDATION_ERROR; department_id in responses is null. Client business_id, stock, internal atom fields, precision/scale and packaging payloads are rejected. Buying price is omitted on all reads without view_buying_price. Changed/archived barcodes remain reserved; collisions return 409 DUPLICATE_BARCODE, even against inactive/history records, while the same code in another shop is allowed. A product may reuse its own historical base code. Batch/stock routes remain unimplemented; S4b packaging operations are defined below.

**GET /products** `?search=&category=&low_stock=1&expiring_in=30&page=`
```json
{ "id": "prod_01J...", "name": "Bottled Water 500ml", "barcode": "6123456789012", "category": "Beverages", "unit": "bottle",
  "selling_price": 1200, "buying_price": 800, "reorder_level": "50", "tracks_expiry": true,
  "stock": "80", "nearest_expiry": "2027-03-31" }
```
`buying_price` is omitted unless the user has `view_buying_price`.
**GET /products?barcode=6123456789012** returns the same list shape (0 or 1 item).
**POST /products** (`manage_products`) body: name*, barcode, category, unit*, buying_price*, selling_price*, reorder_level, tracks_expiry, department_id -> 201 product. 409 `DUPLICATE_BARCODE`; `PLAN_LIMIT_REACHED`.
**GET /products/{id}**, **PUT /products/{id}**, **DELETE /products/{id}** (archive, soft delete).
**GET /products/{id}/batches** -> `[ { "id": "bat_...", "batch_number": "B-2209", "expiry_date": "2027-03-31", "qty_remaining": "20", "cost_price": 800 } ]` (expiry/non-expiring allocation order under business rules 3).
**POST /products/{id}/batches** (`receive_stock`) `{ "batch_number": "B-2209", "expiry_date": "2027-03-31", "qty": "100", "cost_price": 800 }` -> 201 batch + `receive` movement. `expiry_date` required when the product tracks expiry.
**POST /stock/adjust** (`adjust_stock`) `{ "batch_id": "bat_...", "qty_change": "-3", "reason": "damaged" }`.
**GET /stock/reorder-alerts**, **GET /stock/expiry-alerts?days=30**.

## Sales
**POST /sales** (`sell`; needs open till; send `Idempotency-Key`)
```json
// request
{ "branch_id": "br_...", "customer_id": "cust_...",
  "items": [ { "product_id": "prod_...", "qty": "2", "unit_price": 4250, "discount": 0 } ],
  "discount": 0,
  "payments": [ { "method": "cash", "amount": 5000 }, { "method": "mpesa", "amount": 3500, "mpesa_checkout_request_id": "ws_CO_..." } ],
  "cash_tendered": 5000 }
// 201
{ "success": true, "data": { "id": "sale_01J...", "receipt_number": "RCP-2026-000981", "subtotal": 8500, "discount": 0, "total": 8500,
  "vat_amount": 1297, "change_given": 0, "status": "completed",
  "items": [ { "id": "si_01J...", "product_id": "prod_...", "batch_id": "bat_...", "qty": "2", "unit_price": 4250, "line_total": 8500 } ],
  "payments": [ { "method": "cash", "amount": 5000 }, { "method": "mpesa", "amount": 3500 } ], "receipt_url": null, "created_at": "2026-10-08T10:34:00Z" } }
```
Server chooses eligible batches under the expiry/non-expiring allocation rules (rules 3); `items[].batch_id` not accepted unless setting enabled. Server **recomputes** totals and ignores any client `total`. If `unit_price` differs from current price and user lacks `give_discount`, price is overridden by the server.
**GET /sales** `?from=&to=&cashier_id=&customer_id=`; **GET /sales/{id}**.
**POST /sales/{id}/refund** `{ "items": [ { "sale_item_id": "si_01J...", "qty": "1" } ], "reason": "wrong item", "approval_token": "..." }` (`SUPERVISOR_REQUIRED` if missing).
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

## Reports (basic)
**GET /reports/sales?from=&to=&group=day** -> totals, cash/mpesa/credit split, top products. **GET /reports/inventory-value**. **GET /reports/debts**. Require `view_reports`.

## Sync (Phase 3)
**POST /sync/push** `{ "device_id", "operations": [ { "operation_id": "uuid", "type": "SALE", "created_at": "...", "payload": { } } ] }` -> per-operation `status`: `committed | committed_with_conflict | duplicate | quarantined | rejected`. **GET /sync/pull?since=**. **GET /sync/conflicts**, **PUT /sync/conflicts/{id}/resolve**.

## Pending supermarket interface design
The product has no business-type selector; only supermarket workflows are in scope. Before implementation, extend the contract for departments, packaging/weights, hardware label decoding, promotions/display, department counts, wastage, return dispositions/exchanges, imports, price history/schedules/labels, supplier orders/receipts/credits, cash drops, language preferences, batch markdowns and margin warnings. Also cover the catalogue's expenses, attendance, reconciliation and export workflows. Define permissions, validation and public identifiers alongside each interface; no undocumented endpoints may be invented during coding.

Owner 2FA enrolment/verification and forced PIN flows are defined and implemented above. Verified onboarding and lost-authenticator recovery remain pending; no PIN-only TOTP bypass exists. S3 now specifies quantity fields as decimal strings for both counted and weighed goods; see features/F02-exact-quantities.md and the extension below. Product/stock/sales examples above use the S3 string-quantity shape; these endpoints are still unimplemented.

## Single-use supervisor approval (S2 implementation)
POST `/auth/supervisor-check` requires the requester's full device-bound staff token and accepts `{phone,pin,permission,operation_id,payload_hash,code?}`. `phone` identifies the supervisor using canonical Tanzania format; PIN is 4–6 digits. `permission` is limited to `refund`, `void`, `give_discount` or `approve_variance`. `operation_id` is a UUID identifying one domain operation. `payload_hash` is a lowercase SHA-256 digest of the exact reviewed operation snapshot, including targets, quantities, integer TSh amounts, reason and relevant settings/version. A supervisor must be an active owner/manager with that permission, a completed PIN change and no account lock. Owners additionally require confirmed TOTP and a fresh six-digit `code`; approval never enrols or recovers an owner.

Response 200: `{approved_by:"usr_...",approval_token:"<64 hex secret>",expires_at:"<UTC ISO8601>"}`. No login token or broad permission grant is returned. Approval lasts five minutes, is hashed in the database and is bound to the current business, requester, Sanctum session, device, permission, operation UUID and payload digest. A replacement for the same session/operation/permission invalidates older unused grants. Response/errors use no-store. Never persist approval secrets in browser storage or expose them in logs.

Requester needs `sell` for refund/void/discount approvals or `manage_till` for a variance approval; this endpoint does not give them that permission permanently. Unknown/disabled/wrong supervisor credentials return the same 401 `INVALID_PIN`. Failed PIN attempts share login's five-failure/15-minute persisted lockout. Issuance uses the existing canonical phone+IP+business 5/min and IP+business 60/min limiter. Unsupported actions, malformed UUIDs/digests or code return 422 `VALIDATION_ERROR`. Ineligible supervisors return 403 `APPROVER_NOT_AUTHORIZED`; an owner without a supplied code returns 403 `TOTP_REQUIRED`; invalid/replayed codes return 401 `INVALID_TOTP`.

Consumption is an internal `SupervisorApprovalService::execute` call, not a public consume endpoint. The implementing refund/void/discount/till service must resolve and lock its tenant resources within its outer DB transaction, validate its full operation, derive the authoritative snapshot, and provide that snapshot to the approval service. It must never echo `payload_hash` from the client as proof or bypass stock/till/domain permissions. Snapshot hashing recursively sorts object keys, preserves array order, uses compact UTF-8 JSON with unescaped Unicode/slashes, and accepts exact integer/string/bool/null scalars, never floats. The server-reviewed hash should be supplied by the domain preview when that workflow is built.

Consumption revalidates active roles/permission, completed PIN change, account lock, current unrevoked/unexpired requester session and approved device. It checks the exact binding under row locks, marks the approval consumed before executing the domain DB callback, and audits issuance/consumption without PIN/token/profile data. The approval and domain DB changes commit or roll back together; recursive/duplicate use fails. No external provider calls may run inside the retried DB callback. Expired, consumed, replaced, foreign, changed-operation/snapshot/session or ineligible approvals return 403 `INVALID_APPROVAL` (session revocation returns normal 401). Profile/role/PIN/activation changes revoke associated grants; requester logout/rotation/device blocking deletes them through the session relationship.

Stock/till/sale/refund domain endpoints and approval dialogs remain future tasks; this foundation does not process a sale or refund, and does not enable currently unavailable screens. Production owner recovery and manager department/till resource scope remain separate decisions.
Frontend RTK Query exports useSupervisorCheckMutation for future domain dialogs. They must remove the mutation result after each attempt and retain the returned grant only in transient operation state. The adapter preserves the requester's session for supervisor INVALID_PIN/INVALID_TOTP failures; UNAUTHORIZED or other unexpected protected 401s still clear credentials/cache. This distinction does not turn failed approvals into successful actions.

## S3 quantity contract (defined before product/stock/sales implementation)
Use exact quantity strings: counted `qty:"24"`, weighed `qty:"0.75"` kg (maximum three decimal places), signed adjustment `qty_change:"-0.003"`. No numeric quantities, exponent notation or silently rounded weights. Product metadata supplies quantity_type (counted/weight), unit, readonly precision (0/3) and scale (1/1000); the client cannot override them. Responses use canonical quantity strings; internal atoms/factors, if returned, use integer strings. Money is integer TSh bounded to the browser's exact integer range (0..9007199254740991; signed differences within the matching negative bound).

Future receive/sale items may supply packaging_id (`pkg_...`); qty then means whole package count. The server resolves the persisted package/version, converts to the same product/batch atoms and uses its authoritative independent pack price. Without packaging_id, qty means whole base items or kg. Return entered qty and converted base_qty canonically; never trust client conversion/stock/price fields. A shared barcode registry must enforce base/pack code uniqueness per shop. Exact schema, immutability, examples and per-logical-line rounding are in features/F02-exact-quantities.md. Products/Sales examples have been aligned to quantity strings; those endpoints must still be implemented and tested. Implemented auth/staff/device/approval payloads are unaffected.

## S4b packaging catalogue (implemented)
GET/POST `/products/{product}/packagings`; PUT/DELETE `/products/{product}/packagings/{packaging}`. Reads require an authenticated approved-device session. Writes require manage_products. Managers/cashiers read active current packs on active products; product managers also read inactive current packs. List has envelope pagination metadata (50 rows/page).

Create: `{name, base_qty, selling_price, barcode?, is_active?}`. Updates accept any of these fields. base_qty is an exact positive string in the product base entry unit, e.g. "24" bottles or "0.500" kg. selling_price is an actual JSON integer TSh within browser-safe bounds; it is independent of base product price. Barcode validation matches products. Server-owned business/product/family/version/atom/stock fields are prohibited.

Response: `{id:pkg_..., product_id:prod_..., name, base_qty, conversion_atoms:"500", selling_price:2100, barcode:null, version:1, is_active:true, archived:false}`. Canonical quantities and integer-string factors prevent browser precision loss. Effective edits create a new pkg_ ID/version and archive the prior row; no-op edits retain their ID. Stale edits/archives return 409 PACKAGING_VERSION_CHANGED. DELETE archives, without freeing the barcode. Shared registry collisions return 409 DUPLICATE_BARCODE, including base codes and other families on the same product. Only later versions of the same family may reclaim its previous code. Foreign or wrongly nested IDs return generic 404.

Product responses include active current `packagings` and nullable `selected_packaging`. Exact GET /products?barcode= resolves the current base code or active current pack code. An archived/inactive pack code remains reserved but does not resolve. A matched pack returns its own factor/price in selected_packaging; it does not create another stock balance. These operations do not receive stock, process sales or simulate payments.
