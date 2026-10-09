# 08 — Test Checklist (required)

Write these tests as you build the related feature. CI must be green before merging.

## S0 foundation verification (2026-10-08)
- [x] Pest: health envelope, request IDs, validation, authentication/permission/method errors, rate-limit headers, domain errors and hidden internal details (10 tests, 41 assertions).
- [x] Vitest: connected state, retry after failure and unknown-route navigation (3 tests).
- [x] Frontend production build succeeds.
- [x] Composer strict validation and installed platform requirements pass locally.
- [x] Playwright desktop/mobile connection, retry, navigation and viewport checks pass (6 tests, installed Chrome). Initial startup hit a port conflict and cold Vite navigation timeouts; dedicated ports and a 60-second test budget resolve local startup. Final run completed in 47.1 seconds.
- [ ] GitHub Actions backend PHP 8.3/8.5 with MySQL, frontend and browser jobs pass remotely. Workflow exists; no remote repository/run yet.

These foundation checks do not validate the supermarket feature scenarios below; those remain pending.

## Local database readiness (2026-10-09)
- [x] Official MySQL 8.4.11 archive matches its published checksum.
- [x] Development and test databases use InnoDB and UTC, with successful transaction rollback.
- [x] Neither development nor test account can switch to the other database.
- [x] Laravel connects to bizismart locally and bizismart_test in testing mode.
- [x] Private runtime files and environment credentials are ignored by Git.
- [x] Normal stop/start succeeds and waits for readiness; all 10 backend tests (41 assertions) pass with the configured testing environment.
- [ ] Tenant isolation scenarios below remain pending; database account separation is not tenant isolation.

## A. Tenant isolation (CI-blocking, build in S1)
- [x] S1 branches/users/devices/tokens prove real-MySQL isolation, OR-query safety, bulk/quiet write safeguards and cross-business foreign-key rejection.
- [ ] Two businesses A and B each with products; querying as A returns only A's rows, as B only B's; with no tenant set a query returns 0 rows.
- [x] Creating a model sets `business_id` from the current tenant; a client-supplied `business_id` is ignored, including force-filled values.
- [x] Every implemented model whose table has `business_id` uses `BelongsToBusiness` (test enumerates schema/models).
- [x] Application code contains no unauthorised global-scope escapes (source scan).
- [x] Test-only tenant probe routes reject foreign resource IDs, numeric/raw IDs, nested/reserved/foreign hosts and reused tokens; request context clears after failures.
- [ ] For every tenant API route, fetching another tenant's resource id returns 404 (never 403, never the data).
- [x] A test-issued Sanctum token for business A is rejected on business B's subdomain; deactivated/deleted users and inactive businesses also fail.
- [x] A job missing businessId fails; valid businessId runs scoped and restores context on errors. Sync transport tested; Redis worker verification is pending.
- [x] TenantCache/TenantFiles keys/paths include the business id, separate matching names and reject no-tenant access/traversal.
- [x] Demo seed runs twice without duplication and produces two disabled owners/pending devices/no tokens. Database refresh refuses a non-test database.

Latest full backend result: 119 tests / 779 assertions passed on MySQL 8.4.11/PHP 8.5, including S2 roles/auth/staff management. Product isolation and real shop API cases remain required when those features are built.

## B. Auth
- [x] All 14 permissions checked for each approved role through Laravel Gate; role_hint grants nothing and disabled staff have no authority.
- [x] Test-only authenticated product/report probes reject cashiers/managers as specified; cross-business tokens fail. These probes are not production endpoints.
- [x] Role/membership constraints, inverse-pivot writes, nested cache/team context, retained cached user shortcuts, role replacement and idempotent provisioning tested.
- [x] Login success, canonical phone variants, wrong PIN, locked after 5 failures, unlock after 15 min and rate-limit retry headers.
- [x] New device pending -> blocked until approved; blocked device cannot log in, and blocking revokes every device token/challenge.
- [x] Owner authenticator enrolment/verification, accepted-step replay rejection, expiry and five invalid-code challenge attempts.
- [x] Forced PIN order/change, owner-only reset, stale challenge/rotation rejection and legacy unbound token rejection.
- [x] Auth/device public IDs, foreign tenant resources and composite token/device foreign keys.
- [x] Audit properties encrypted; no PIN/token/phone/name/TOTP secrets logged. Model status/role changes and PIN reset recorded.
- [ ] Permission matrix: each role vs 5 representative endpoints.
- [x] Deactivated/deleted user's tokens and challenges are immediately revoked; published session routes reject them.
- [x] Owner staff CRUD: public IDs/pagination, hashed temporary PINs, canonical duplicates, invalid legacy roles/foreign branches, last active owner, active-staff creation/reactivation limits, revocation on profile/role changes, secret-free audits and non-owner/cross-tenant/numeric-ID rejection (7 staff tests).

## C. Stock and FEFO
- [ ] Rules §3 example: batches A(10, 2026-12-31), B(20, 2027-03-31), C(50, expired) sell 15 -> A=0, B=15, items (A,10),(B,5).
- [ ] Expired batch never sold online; selling more than available -> `INSUFFICIENT_STOCK` with `available`.
- [ ] Every stock change writes a `stock_movements` row and `qty_after` equals the batch qty.
- [ ] Concurrency: two simultaneous sales of the last unit -> exactly one succeeds.
- [ ] Refund returns qty to the same batch; to quarantine if batch expired.

## D. Sales and money
- [ ] Total 11,800 at 18% -> VAT 1,800. Total 8,500 -> VAT 1,297.
- [ ] Cash 10,000 for 8,500 -> change 1,500. Split 5,000 + 3,500 -> change 0. Split 5,000 + 3,000 -> `PAYMENT_MISMATCH`.
- [ ] Discounts cannot make totals negative; price override without `give_discount` is ignored.
- [ ] Failure mid-sale (force an exception after the first item) leaves no sale, no stock change.
- [ ] Same `Idempotency-Key` twice -> one sale.

## E. Till and debt
- [ ] Till worked example: expected 165,000, variance -1,000, closes.
- [ ] Variance -15,000 needs approval; closed session rejects new sales.
- [ ] Credit limit 50,000 / balance 30,000: 20,000 ok, 25,000 rejected; overpayment rejected.
- [ ] Aging buckets for sales 10/45/75/120 days old.

## F. M-Pesa
- [ ] Success, failure, timeout, duplicate callback (no second record), unknown id (200 + nothing created).
- [ ] Callback sets tenant from the stored transaction, not the payload.
- [ ] A `checkout_request_id` cannot be reused by a second sale.

## G. Frontend
Current baseline preserves BiziSmart-fixed.zip design and now uses real Laravel auth/staff/device APIs. The prototype checks below are historical compatibility verification; App does not serve those workflows. Latest live verification is recorded at the end.
- [x] All 21 supplied checkout/refund/exchange/report/persistence tests pass; five new staff tests verify edit, confirmed activation, duplicate/invalid inputs, owner restrictions/last-owner protection and six-digit demo PIN creation/sign-in.
- [x] Imported TypeScript checking, ESLint and production build (including PWA shell generation) pass.
- [x] All eight current desktop/mobile Playwright checks pass: demo login/dashboard, staff edit/deactivation/persistence and simulated checkout/M-Pesa wording; real Laravel auth/logout verified separately. Both login/dashboard and staff layouts visually inspected.
- [ ] Cart maths (qty, discount, total, change) with Vitest.
- [x] Protected 401 clears token/cache and returns to sign-in; 30-minute inactivity clears the local session, with activity extending the deadline.
- [x] Sign-in, TOTP enrolment/verification, forced PIN confirmation, expired challenge and pending-device errors; authentication mutation data removed and only device UUID persisted (9 frontend tests total).
- [x] All 12 Playwright desktop/mobile checks pass against real Laravel/MySQL, including cashier sign-in/logout, pending-device denial, missing shop-address message and viewport checks. Synthetic staff exist only in the guarded test database; authentication traces are disabled. Both sign-in layouts visually inspected.
- [ ] Playwright: login -> open till -> sell (cash) -> close till; error message shown for insufficient stock.

## H. Offline (Phase 3)
- [ ] Two devices sell the last 2 units offline -> both commit, stock -2, `NEGATIVE_STOCK` conflict.
- [ ] Duplicate `operation_id` ignored; price change > 5% flagged; unapproved device quarantined.

## I. Supermarket additions (F10)
- [ ] All 12 F10 acceptance scenarios pass when their features are implemented.
- [ ] Exact packaging/weight stock deductions, fractional pricing and documented rounding boundaries.
- [ ] Returns cannot exceed original quantities, damaged returns stay unavailable and concurrent returns cannot double-refund.
- [ ] Import rollback/retry, scheduled-price boundaries and historical price preservation.
- [ ] Supplier credit retries and cash-drop retries do not duplicate accounting; closed tills reject cash drops.
- [ ] Cash-drop reconciliation distinguishes drawer cash from business revenue.
- [ ] Language switching preserves checkout; scanner/keyboard/thermal receipt tested on pilot devices.
- [ ] Markdown eligibility/expiry and cost-hidden margin warnings across multiple batches.
- [ ] Tenant isolation and permissions on every added endpoint, report, job and import.

## J. Single-product consistency
- [ ] No business-type selector or other business-type API/schema/module is generated from historical references.
- [ ] Department access and manager permission baseline are tested once finalised; original roles cannot leak costs/reports to cashiers.
- [ ] Non-expiring, expiring, quarantined and mixed-batch allocation policy is covered after specification.

## Active real interface verification — 2026-10-09
- [x] Backend: 98 tests / 678 assertions pass on MySQL, including tenant-scoped/expired/replaced owner invites, encrypted account/PIN hashing, mandatory TOTP and no operational-owner reset.
- [x] Frontend: all 30 tests pass; four new real API/session cases cover Bearer headers, protected 401 cache/credential clearing, failed authenticator requests, idle lock and sleeping-browser expiry. Other 26 cases verify retained prototype compatibility only.
- [x] Six current desktop/mobile browser checks pass against guarded MySQL test fixtures: owner setup/TOTP, real staff creation/edit/activation, last-owner rejection, device approval/block, cashier permission menus, logout token revocation and stored fake-session rejection.
- [x] TypeScript, ESLint and production/PWA build pass. Active login/staff screenshots visually inspected. No API caching, prototype login or simulated checkout in active App.
- [ ] Real stock/till/sales/payment and hardware checks after those workflows are implemented; no claim of financial completeness.
## Supervisor approval verification — 2026-10-09
- [x] Full backend suite passes: 119 tests / 779 assertions on MySQL; 21 approval cases cover all four permissions, owner TOTP/replay, shared PIN lockout and unknown/disabled credential consistency.
- [x] Exact operation/snapshot/requester/session/device/shop binding, expiry/replacement/single use, recursive-use rejection and atomic domain rollback tested.
- [x] PIN/profile/role/deactivation/logout/rotation/device-block revocation tested. Scoped model coverage and composite tenant foreign keys remain in the full suite.
- [x] Audit stores public approval/operation references without PIN/token/profile values. PHP syntax checks pass for the new service/model/request/migration.
- [ ] Financial-domain consumers and real approval dialogs once their features are implemented; no sale/refund is enabled by the issuance endpoint alone.
- [x] Six desktop/mobile browser checks pass with real supervisor issuance added to API authentication coverage; a grant cannot authenticate as a staff session. Existing real owner/staff/device flows pass.
- [x] Frontend suite passes all 34 cases, including eight real session/API cases. New adapter cases reject failed supervisor PIN/TOTP while keeping the cashier session, clear revoked sessions, and remove grants/credentials from mutation caches without browser persistence.

## S3 exact quantity foundation — 2026-10-09
- [x] User selected gram precision: up to three decimal places in kg, whole-item counts, half-up integer TSh line totals.
- [x] 23 focused arithmetic tests / 60 assertions cover carton conversions, kilogram/gram canonicalisation, signed int64 boundaries, invalid floats/exponents/precision, discounts, batch-split-independent charges, browser money bounds and independently generated BigInt cases.
- [ ] S4 product/pack barcode tenant uniqueness, permissions, immutable conversion history and StockService movement/transaction/concurrency checks after implementing those tables/services. Helper tests do not prove persisted stock behavior.

## S4a catalogue verification — 2026-10-09
- [x] Full MySQL backend suite: 163 tests / 1,055 assertions pass, including 21 catalogue cases and S3 arithmetic.
- [x] Product create/edit/archive, scoped search/category/barcode lookup, pagination, active limits/disabled reactivation, historical reservations and duplicate rollback verified.
- [x] Owner/stockkeeper writes and cost visibility; cashier/manager active reads without costs; foreign/numeric IDs and wrong-shop tokens rejected. Composite barcode ownership foreign keys and fail-closed models tested.
- [x] Integer/browser-safe prices, gram precision and strict whitespace/format validation, type immutability, readonly fields and encrypted price audits verified. Stock remains explicitly unavailable.
- [x] After the full run, browser-header authentication regression checked separately: all 12 foundation tests / 50 assertions pass. Live API through Vite responds with no-store JSON 401 when unsigned; migration 000009 applied and PHP syntax checks pass.
- [ ] Product UI, department/packaging associations and StockService/batch receiving/adjustment/ledger/concurrency verification. No stock operation is enabled by these catalogue tests.

Frontend, production build and desktop/mobile E2E evidence above is from the prior connected-auth slice; no frontend files changed for S4a and these checks were not repeated.

## S5a catalogue UI verification — 2026-10-09
- [x] All 39 frontend tests pass: 26 historical prototype compatibility cases and 13 real API/session/catalogue cases. Five new catalogue cases cover authenticated adapters, exact string quantities/integer prices, authoritative canonical responses/cache invalidation, failed edits without success, archive cancel/failure/retry, server pagination/barcode/category filters and cost/write visibility.
- [x] TypeScript, ESLint and production/PWA build pass. No new dependency or browser business-data persistence was added.
- [x] All six desktop/mobile Playwright checks pass against guarded MySQL fixtures. Owner flow now creates a kg product, checks canonical 0.75 kg and leading-zero barcode, edits price/activation, submits barcode search with Enter and waits for confirmed archive before navigating. Existing auth/staff/device/session checks still pass.
- [x] Product screenshots on desktop and phone visually inspected: preserved emerald/slate components, contained horizontal table scrolling and responsive forms/search layout. Development API/Vite previews restored.
- [ ] Batch/receiving/packaging/camera/hardware and full stock/POS scenarios after corresponding backend services are built. Catalogue tests do not validate stock movements or sales.

## User-approved reload sessions — 2026-10-09
- [x] All 49 frontend tests pass. Ten remembered-session cases verify fresh-store restore without identity/permissions/shop data, preserved idle deadlines, real activity without expiry extension, expired/idle/future/malformed/changed-device rejection, logout/401 clearing and blocked-storage fallback.
- [x] TypeScript, ESLint and production/PWA build pass; backend auth contracts and dependencies are unchanged.
- [x] All six desktop/mobile browser checks pass against guarded MySQL fixtures, including owner and cashier reload without new login, cashier permission restrictions, minimal sessionStorage fields, logout and a subsequent reload remaining signed out. Existing catalogue/staff/device/auth flows still pass.
- [x] Current session rules supersede the prior memory-only/reload-logout behavior. Only same-tab refresh is remembered; no PIN/challenge/TOTP/user identity or shop data is stored. Development previews restored after tests.

## S4b pack/carton catalogue — 2026-10-09
- [x] Full MySQL backend suite: 168 tests / 1,150 assertions. Four new packaging cases cover exact item/gram contents, canonical string factors, immutable edits/no-op/stale versions, reserved cross-base/family barcodes and rollback, nested tenant/product ownership, stockkeeper writes and manager restrictions.
- [x] All 51 frontend tests pass, including pack request serialization/server canonical responses and stale edit/archive failure retention. TypeScript, ESLint and production/PWA build pass.
- [x] Desktop/phone pack screenshots visually inspected; preserved emerald/slate layout, responsive MUI forms and contained product table.
- [ ] Packaging factors in receiving, sale stock deduction and financial history await StockService/POS; catalogue tests do not validate those operations.
- [x] All six desktop/mobile browser checks pass against guarded MySQL fixtures, including real gram-pack create/edit/version, leading-zero pack barcode lookup and confirmed archive, alongside owner/cashier reload, staff and approved devices. An interrupted run was repeated; initial cold-start assertion waits were corrected to the existing API deadline before the successful run.
- [x] Additive development migration 000010 applied. API/Vite previews restored using the normal development environment; no operational owner reset or simulated stock/payment introduced.
