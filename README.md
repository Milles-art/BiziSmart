# BiziSmart

Local project folder: `C:\Users\DIGITAL STAR\BiziSmart` (relocated at the user's request on 2026-10-09). Open this folder for further development.

One supermarket and mini-mart management application for Tanzanian businesses. BiziSmart-fixed.zip is the frontend design baseline; its emerald/slate colours, layout, navigation and reusable components are preserved. Laravel and MySQL authenticate the active interface and persist staff and device changes.

## Implemented

- Real phone/PIN sign-in, mandatory owner authenticator enrolment/verification, forced temporary-PIN change and server logout.
- Tenant isolation, approved owner/manager/cashier/stockkeeper permissions, encrypted staff information and hashed PINs.
- Owner staff list/create/edit/deactivate/reactivate/PIN reset, active-staff plan limits, canonical phone uniqueness, last-owner protection and session revocation after changes.
- Owner device list/approval/blocking. Blocking revokes staff sessions on that device.
- Supervisor approval endpoint and internal atomic consume service for refunds, voids, discounts and till variances. Five-minute single-use grants bind the exact operation to its shop/requester/session/device. Owner approval also requires fresh TOTP; PIN/profile/role/session changes revoke grants. Actual financial workflows and their approval dialogs remain pending.
- Initial owner setup through a private, one-use 90-minute invitation issued by a trusted local operator. This cannot recover or replace an existing operational owner.
- Exact quantity and money helpers: whole counted items, integer grams with up to three decimal places in kilograms, checked pack/carton conversions and whole-TSh line rounding. The S3 contract is in docs/features/F02-exact-quantities.md; stock operations remain pending.
- Real product catalogue API and connected Products screen: create/edit/confirmed archive, server search/category/base and pack barcode lookup and pagination, active-product plan limits, exact reorder levels and permission-filtered buying prices. Barcode changes/archiving preserve reservations and audit history. Stock operations remain pending; catalogue stock fields explicitly report unavailable values. No product/cost data persists in browser storage.

There are no quick sample-account buttons, browser-only sign-in or payment simulations in the active application. Approved-device sign-in survives refresh in the same browser tab: sessionStorage keeps only the device-bound Bearer token, expiry, last activity and device UUID. Laravel revalidates the account/permissions through `/me` after reload; saved roles/identity are never trusted. The device UUID remains in localStorage; PINs, authenticator secrets and shop/PII data are not persisted. Logout, unauthorized-session responses, token expiry and 30 minutes of inactivity clear the remembered session; reload does not restart the idle window. Storage failures fall back to memory-only sign-in. Old prototype browser records are removed when the application starts. No simulated success is returned when Laravel fails.

Stock, tills, sales, financial reports and M-Pesa are unavailable until their real services and integrations are built. Dashboard figures show unavailable values. Imported prototype screens/calculations remain as historical compatibility code and tests; App does not route to them. Their Kenyan sample currency, decimal calculations and legacy permissions are not approved production rules: money is integer TSh, and the completed S3 quantity/packaging contract governs the upcoming stock implementation. Offline selling remains Phase 3.

## Run locally

Use PHP 8.3+, MySQL 8, Node 24.x and npm 11+. The Windows development database is a separate MySQL instance on port 3307; XAMPP MariaDB is unchanged. See docs/12-local-database.md. Keep private environment files private.

```powershell
./infrastructure/local/mysql.ps1 Start
npm ci
cd backend
composer install
php artisan migrate
cd ..
npm run dev:api
```

In another terminal run `npm run dev`. The API is on port 18080 and Vite on 15173. Open the shop's subdomain, such as http://demo-alpha.localhost:15173/; the bare localhost/IP address does not select a tenant. The existing demo-alpha shop is named **Fresh Choice Supermarket**. Its initial owner enters their actual phone, name and private PIN in the setup form and then adds a time-based authenticator account.

A trusted local operator can issue the initial setup link without choosing the owner's PIN:

```powershell
cd backend
php artisan bizismart:owner-invite demo-alpha --name="Fresh Choice Supermarket"
```

The link is saved under ignored `backend/storage/app/private/owner-setup-biz_<public-id>.json`; it is not printed to console or stored in plaintext in the database. Open it privately. Issuing another invite invalidates the prior unconsumed invite. This command is limited to local/testing; production platform onboarding and verified authenticator recovery remain pending. Previously seeded accounts have no usable credentials and do not authenticate as shop staff.

## Verify

```powershell
npm test
npm run typecheck
npm run lint
npm run build
npm run test:backend
$env:PLAYWRIGHT_CHANNEL='chrome'
npm run test:e2e
```

Stop local API/frontend servers before Playwright, which starts its own test servers. Backend and browser tests require the separate `_test` MySQL database; never run database resets alongside browser tests. Synthetic browser-test credentials exist only in that test database. Owner test traces are disabled to keep setup secrets private.

2026-10-09 verification: **119 backend tests / 779 assertions**, **34 frontend tests** (26 retained prototype cases plus eight live session/API cases), **six desktop/mobile browser checks** pass. Browser checks cover real owner setup/TOTP, staff changes, last-owner rejection, device approval/blocking, cashier permissions, logout revocation, supervisor approval issuance restricted to operation grants, and rejection of stored fake sessions. TypeScript, ESLint and production/PWA build pass; login/staff screenshots were visually inspected. The PWA caches only the application shell, never API responses or payment requests.

Remote CI, Redis workers, production onboarding/recovery, hardware and real payment-provider integrations remain unverified or unfinished. The imported dependency tree has recorded advisories; compatibility upgrades/hardening are required before deployment.

S3 verification on 2026-10-09: **23 new arithmetic unit tests / 60 assertions** pass, covering gram precision, canonical quantity strings, carton conversion, half-up line rounding and integer overflow. These helpers perform no database writes. The full application checks above were completed before S3; frontend/browser checks were not repeated for this backend-only arithmetic change. Local execution requires 64-bit PHP.

S4a verification on 2026-10-09: **163 backend tests / 1,055 assertions** pass on MySQL, including **21 catalogue cases**. After that run, a direct-browser authentication regression was added and all **12 foundation tests / 50 assertions** pass separately. Migration 000009 is applied locally; the five catalogue routes are registered, PHP syntax checks pass, and unsigned requests through the Vite proxy return no-store JSON 401. Frontend/build/browser checks were not repeated for this backend-only slice. Product UI, departments, packaging and StockService remain pending.

S5a verification on 2026-10-09: the connected Products screen passes **39 frontend tests** (26 retained prototype cases and 13 live cases), TypeScript, ESLint and production/PWA build. All **six desktop/mobile browser checks** pass, now including real product create/edit, kg quantities, inactive metadata, leading-zero barcode search with Enter and confirmed archive. Product screenshots were visually inspected on desktop and phone. Development previews are restored. Backend logic is unchanged in this slice; stock receiving, departments, packaging and checkout remain pending.

Reload-session verification on 2026-10-09: **49 frontend tests**, TypeScript, ESLint and production/PWA build pass. All **six desktop/mobile browser checks** pass, including owner/cashier reload, retained permission restrictions, minimal tab-session storage and logout followed by reload remaining signed out. Ten new unit cases cover expiry/idle deadlines, changed-device/malformed records, revocation and blocked storage. Sign in once after this update to create the remembered session; subsequent same-tab refreshes retain access within the existing expiry/idle limits. Normal development previews are restored.

Approved specifications are in docs/. Read AGENTS.md and docs/10-build-guide.md before implementation. Historical source documents under docs/reference/ are non-authoritative.

S4b adds real packs/cartons in Products → Packs: exact contents, independent prices, barcodes, active status and confirmed archive. Each effective edit retains the prior version. Pack barcode searches show the matched selling unit. Stock receiving, departments and checkout remain pending.

S4b verification on 2026-10-09: **168 backend tests / 1,150 assertions**, **51 frontend tests**, TypeScript, ESLint and production/PWA build pass. All **six desktop/mobile browser checks** pass including pack create/edit/versioned price, exact barcode selection and confirmed archive. Pack screenshots were inspected; migration 000010 is applied and development previews restored. The API contract is extended with nested packaging routes, pack metadata and selected_packaging; departments and stock/checkout remain pending.
