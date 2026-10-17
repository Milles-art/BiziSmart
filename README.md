# BiziSmart

Local project folder: `C:\Users\DIGITAL STAR\BiziSmart` (relocated at the user's request on 2026-10-09). Open this folder for further development.

One supermarket and mini-mart management application for Tanzanian businesses. BiziSmart-fixed.zip is the frontend design baseline; its emerald/slate colours, layout, navigation and reusable components are preserved. Laravel and MySQL authenticate the active interface and persist staff and device changes.

## Implemented

- Real phone/PIN sign-in, mandatory owner authenticator enrolment/verification, forced temporary-PIN change and server logout.
- Tenant isolation, approved owner/manager/cashier/stockkeeper permissions, encrypted staff information and hashed PINs.
- Owner staff list/create/edit/deactivate/reactivate/PIN reset, active-staff plan limits, canonical phone uniqueness, last-owner protection and session revocation after changes.
- Owner device list/approval/blocking. Blocking revokes staff sessions on that device.
- Supervisor approval endpoint and internal atomic consume service for refunds, voids, discounts and till variances. Five-minute single-use grants bind the exact operation to its shop/requester/session/device. Owner approval also requires fresh TOTP; PIN/profile/role/session changes revoke grants. Financial consumers and approval dialogs are connected for checkout discounts, receipt returns/voids and till variances.
- Initial owner setup through a private, one-use 90-minute invitation issued by a trusted local operator. This cannot recover or replace an existing operational owner.
- Exact quantity and money helpers: whole counted items, integer grams with up to three decimal places in kilograms, checked pack/carton conversions and whole-TSh line rounding. The S3 contract is in docs/features/F02-exact-quantities.md; real receiving and stock summaries are implemented below.
- Real product catalogue API and connected Products screen: create/edit/confirmed archive, server search/category/base and pack barcode lookup and pagination, active-product plan limits, exact reorder levels and permission-filtered buying prices. Barcode changes/archiving preserve reservations and audit history. Catalogue stock fields now report real eligible balances and nearest expiry; held totals and stock filters are restricted to stock staff. No product/cost data persists in browser storage.

There are no quick sample-account buttons, browser-only sign-in or payment simulations in the active application. Approved-device sign-in survives refresh in the same browser tab: sessionStorage keeps only the device-bound Bearer token, expiry, last activity and device UUID. Laravel revalidates the account/permissions through `/me` after reload; saved roles/identity are never trusted. The device UUID remains in localStorage; PINs, authenticator secrets and shop/PII data are not persisted. Logout, unauthorized-session responses, token expiry and 30 minutes of inactivity clear the remembered session; reload does not restart the idle window. Storage failures fall back to memory-only sign-in. Old prototype browser records are removed when the application starts. No simulated success is returned when Laravel fails.

Stock receiving/corrections, tills and cash checkout have real services. Point of Sale uses Laravel previews and atomic sale/stock/till commits, exact base/pack quantities and duplicate-safe retries; the inline receipt shows saved server amounts. Its current contract is F03-cash-checkout.md. Customers now have real encrypted profiles, credit limits, full-credit/cash+credit checkout, selected-invoice cash collections and debt aging through F05-customers-debt.md. Saved collections update invoice/customer balances and till cash atomically. Receipt-linked cash/credit returns and atomic exchanges are connected through POS → Refund, including debt-first settlement, supervisor approval, original-batch restoration and separate quarantine for unsellable goods. Exchange replacements use the current cart at current prices; only the cash difference changes the drawer. Selcom checkout, cash/mobile/credit splits, selected-invoice mobile collections, signed callback reconciliation and manually recorded proportional mobile refunds now have real connected endpoints. Server-held carts, approved discounts/voids, printable/PDF receipts, optional private product images, exact barcode scanning, stock imports, catalogue exports, shelf labels and price history are connected. Private R2 configuration and physical hardware verification remain external gates; owner reports, supplier accounting, expenses/photos, cash drops, pricing, attendance, notification preferences, loyalty/commissions and historical demand forecasts are connected. Atomic mobile-value exchanges require a separate settlement contract. Selcom was selected but no merchant credentials are configured. The owner dashboard uses real report totals, daily sales and recent receipts; financial queries remain restricted from other roles. Imported prototype screens/calculations remain historical compatibility code and tests; App does not route to them. Money is integer TSh and S3's exact quantity/packaging contract governs production. Approved offline cash selling uses signed 24-hour leases and an encrypted IndexedDB queue. Sync commits valid snapshots with stock/price/expiry flags; closed-till or invalid-lease arrivals remain quarantined for owner review. Posted conflicts are read-only and cannot post cash again. See docs/features/F14-operations-delivery-batch.md for scope and external launch gates.

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

Remote CI, Redis workers, production onboarding/recovery, hardware and real payment-provider integrations remain unverified or unfinished.

2026-10-15 dependency hardening: build/test tooling is pinned to Vite 6.4.4, Vitest 4.1.11 and vite-plugin-pwa 0.21.2, retaining React 18, Tailwind 3 and the existing browser build target. Root dev dependency jsdom 26.1.0 matches the frontend's existing version and makes the hoisted Vitest environment resolvable. A root override scoped to Tailwind 3.4.19 uses postcss-selector-parser 7.1.6. Its generated CSS is byte-identical to the prior parser output (42,429 bytes; SHA-256 `5a64b71364dd124c8e157276e587ca900d9f5bf42380f58f2b4a8525c7cd24ab`), with no PostCSS warnings. The dependency tree has no invalid peers; both Vite configurations load and the focused margin-warning test passes all three cases on Vitest 4. These targeted checks do not replace the complete frontend, build/PWA and browser compatibility checks after installation.

The locked npm audit still reports **five high-severity affected build packages**, all arising from the single [unpatched braces stack-exhaustion advisory](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm): braces, chokidar, micromatch, fast-glob and Tailwind. No released braces version fixes it; it is not suppressed or recorded as resolved. Build/watch inputs and glob patterns must come only from reviewed repository sources, never tenant input or untrusted uploads; do not expose development/test servers publicly. Review untrusted changes before CI jobs with secrets. `npm audit --omit=dev` reports zero production advisories; the full audit reports zero critical or moderate advisories after these changes. Recheck both audits before deployment and keep the remaining build-tool risk under review until an upstream fix or a separately verified compatible replacement is available. Locked Composer audit also reported zero advisories and no abandoned packages before this tooling update.

S3 verification on 2026-10-09: **23 new arithmetic unit tests / 60 assertions** pass, covering gram precision, canonical quantity strings, carton conversion, half-up line rounding and integer overflow. These helpers perform no database writes. The full application checks above were completed before S3; frontend/browser checks were not repeated for this backend-only arithmetic change. Local execution requires 64-bit PHP.

S4a verification on 2026-10-09: **163 backend tests / 1,055 assertions** pass on MySQL, including **21 catalogue cases**. After that run, a direct-browser authentication regression was added and all **12 foundation tests / 50 assertions** pass separately. Migration 000009 is applied locally; the five catalogue routes are registered, PHP syntax checks pass, and unsigned requests through the Vite proxy return no-store JSON 401. Frontend/build/browser checks were not repeated for this backend-only slice. Product UI, departments, packaging and StockService remain pending.

S5a verification on 2026-10-09: the connected Products screen passes **39 frontend tests** (26 retained prototype cases and 13 live cases), TypeScript, ESLint and production/PWA build. All **six desktop/mobile browser checks** pass, now including real product create/edit, kg quantities, inactive metadata, leading-zero barcode search with Enter and confirmed archive. Product screenshots were visually inspected on desktop and phone. Development previews are restored. Backend logic is unchanged in this slice; stock receiving, departments, packaging and checkout remain pending.

Reload-session verification on 2026-10-09: **49 frontend tests**, TypeScript, ESLint and production/PWA build pass. All **six desktop/mobile browser checks** pass, including owner/cashier reload, retained permission restrictions, minimal tab-session storage and logout followed by reload remaining signed out. Ten new unit cases cover expiry/idle deadlines, changed-device/malformed records, revocation and blocked storage. Sign in once after this update to create the remembered session; subsequent same-tab refreshes retain access within the existing expiry/idle limits. Normal development previews are restored.

Approved specifications are in docs/. Read AGENTS.md and docs/10-build-guide.md before implementation. Historical source documents under docs/reference/ are non-authoritative.

S4b adds real packs/cartons in Products → Packs: exact contents, independent prices, barcodes, active status and confirmed archive. Each effective edit retains the prior version. Pack barcode searches show the matched selling unit. Stock receiving, departments and checkout remain pending.

S4b verification on 2026-10-09: **168 backend tests / 1,150 assertions**, **51 frontend tests**, TypeScript, ESLint and production/PWA build pass. All **six desktop/mobile browser checks** pass including pack create/edit/versioned price, exact barcode selection and confirmed archive. Pack screenshots were inspected; migration 000010 is applied and development previews restored. The API contract is extended with nested packaging routes, pack metadata and selected_packaging; departments and stock/checkout remain pending.

S4c adds real department catalogue and product assignment/filtering. Products → Manage departments creates/renames/archives departments; product forms offer paginated department choices or Unassigned. Archiving is blocked by any assigned product, including inactive and archived products, as selected by the user. Clear department assignments before archiving a product if its department may later be archived; archived-product reassignment is not yet a public workflow. Departments remain separate from category text and physical branches. Department reports/counts and stock receiving remain pending; this slice changes catalogue metadata only.

S4c verification on 2026-10-09: focused MySQL catalogue suite **30 tests / 371 assertions**, all **55 frontend tests**, TypeScript, ESLint and production/PWA build pass. All **six desktop/mobile browser checks** pass, including department assignment/filtering and blocked/successful archive. Screenshots inspected; additive migration 000011 applied. Full backend suite was not repeated. StockService and receiving remain pending the expiry policy decisions.

S4d adds real batch receiving and reads under Products → Stock. Receive base items/kg or whole pack counts, enter base-unit TSh cost and expiry dates, and see confirmed batch balances. Server rejects already-expired deliveries and duplicate stock writes; receipt snapshots preserve package history. Network failures retain the same operation/input for retry, and changed packs can be refreshed. Aggregate catalogue stock totals, adjustments and checkout remain pending.

S4d verification on 2026-10-09: seven focused MySQL receipt tests / 105 assertions pass. The broader backend run passed 179 of 180 cases; its sole outdated catalogue assertion expected the newly implemented receipt route to be unavailable. After correction, that case passed separately (1 test / 10 assertions), verifying all 180 cases across the full run and follow-up. All 59 frontend tests, TypeScript, ESLint and production/PWA build pass. All six desktop/mobile browser checks pass, including ten 500 g packs received as exactly 5 kg with versioned receipt metadata. Both layouts inspected; additive migration 000012 applied.

S4e adds real eligible stock and nearest expiry to the Products catalogue. Owners/stockkeepers also see signed held quantities and a server-calculated Low-stock matches card, plus low-stock and expiry-window filters. Receiving refreshes catalogue queries. Read-only reorder/expiry alert APIs are available; aggregate overflow is checked before online receipt writes. Monetary valuation, adjustments, ledger browsing and checkout remain pending.

S4e verification on 2026-10-10: focused MySQL catalogue/receiving suite **45 tests / 631 assertions**, all **63 frontend tests**, TypeScript, ESLint and production/Workbox PWA build pass. All six browser checks passed; both owner flows were then verified after a search-width layout correction, with an isolated desktop rerun resolving a setup timeout. Final desktop/phone layouts inspected. Full backend suite was not repeated. No new migration or package.

S4f adds real read-only stock movement history under Products → Stock → Movement history. Batch links filter the ledger; exact historical changes/balances, Tanzania timestamps and immutable package receipts come from Laravel. Public actor/device IDs are returned without staff names or sensitive data. Adjustments remain the next slice under the approved mandatory owner approval for stockkeepers and non-negative online correction rules (D068).

S4f verification on 2026-10-10: focused MySQL receiving/history suite **15 tests / 313 assertions**, all **68 frontend tests**, TypeScript, ESLint and production/Workbox PWA build pass. All **six desktop/phone browser checks** pass; both history screenshots inspected. Full backend suite was not repeated. No new migration or package.

S4g completes real online batch corrections through Products → Stock → Correct stock. Review before/change/after quantities from Laravel, enter a mandatory reason and confirm. Every stockkeeper correction requires owner PIN/fresh authenticator approval; owners correct directly. Negative resulting stock, stale balances and duplicate operations are blocked or safely replayed. Reasons are encrypted and original ledger balances are retained. No cost/expiry/recall/quarantine setting changes through this form.

S4g verification: 36 existing MySQL regression cases / 409 assertions pass; nine new correction cases verified across initial run and corrected focused rerun (4 cases / 73 assertions). All 73 frontend tests, TypeScript, ESLint, final production/Workbox build and six desktop/phone browser checks pass; layouts inspected. Full backend suite was not repeated. Additive migration 000013 applied. Next: real till opening/closing before sales; scanner/hardware and payment integrations remain pending.

S6 real Tills & Cash: open an assigned-branch till with integer TSh float, record paid-outs, review counted cash and close with branch supervisor approval when the variance exceeds the configured limit. Session history and owner variance notices come from Laravel. Operation IDs and encrypted replay records prevent duplicate writes. Checkout, payments and acknowledged cash drops remain separate unfinished features.

Design reference: docs/design/BiziSmart-design-reference.zip is the user-supplied visual baseline. F00-design-parity.md records restored panels and intentionally unavailable tools; all operational access remains through Laravel.

Inventory now shows real low-stock and near-expiry panels, product-selected movement history and existing batch correction entry points. The dashboard uses real stock/till panels. Financial reports, stock takes, wastage, imports and unconnected payment tools remain unavailable.

S15 cash/credit returns/exchanges verified on 2026-10-10: all 60 selected backend cases pass across regression/focused reruns, including 11 return cases and real MySQL duplicate/competing returns. All 96 frontend tests, TypeScript, lint, production/PWA build and six desktop/phone browser checks pass; return/exchange screenshots inspected. Migration 000017 applied locally. In POS, use Refund to look up the original receipt; for an exchange, add replacement goods to the cart first. Cash/credit only; Selcom/mobile refunds remain unavailable. Normal development previews are restored, existing accounts preserved and no new browser persistence introduced. Full backend suite and remote CI were not repeated.

Final S15 live-preview check caught missing no-store headers on return exception envelopes (successful responses were already protected). Added return paths to ApiResponse's exception-safe headers; all 13 foundation cases / 68 assertions pass, including JSON/HTML auth/permission/missing errors. The normal preview now returns unsigned return lookup 401 with no-store/private, health 200 and frontend 200. Total selected backend coverage this delivery: 73 cases verified across the regression/focused runs; full backend suite/remote CI not repeated.

Selcom software lifecycle: POS checkout/splits, Customers selected-invoice collections, server recovery, signed callback receiver, scheduled status reconciliation, one-use atomic allocation, owner unresolved-payment review and manually recorded external refunds are connected. Keep SELCOM_* empty until private merchant provisioning; configuration is bound to one SELCOM_BUSINESS_PUBLIC_ID. Order/push acceptance is never paid; only matching authenticated status evidence authorizes allocation. No actual merchant request has been made. See docs/features/F08-mpesa.md for the launch gate and mobile-exchange boundary.

Run the local reconciliation scheduler alongside API/Vite:
```powershell
php backend/artisan schedule:work
```
Production must provision Laravel's scheduler every minute. Durable per-order leases limit provider status queries to three-minute intervals. The browser polls saved status and cannot repeat a wallet push. Live merchant/sandbox verification and public HTTPS callbacks remain pending because no merchant account/credentials are available.


### POS and product tools

Shared-hosting deployment (D096): see `docs/14-shared-hosting.md`. The opt-in profile uses database runtime stores and finite five-minute cron processes; it does not install Redis or daemons. Migration000041 adds missing framework infrastructure tables. Generate a source/build cPanel package with `infrastructure/cpanel/package.ps1` after the frontend build; it excludes actual .env files, vendor, tests and Git. Composer no-dev installation, private configuration, DNS/HTTPS, MariaDB compatibility and hosted acceptance are still required. No staging domain is selected, and a generated archive is not a live deployment. TRA sandbox discovery is documented in F22; credentials remain private and TRA stays disabled/last.
The preserved POS now includes server held carts, exact line/cart discount reviews with branch-scoped supervisor approval, F2 search/F4 review, registered-barcode keyboard/camera helpers, saved receipt printing/PDF, and full-receipt void selection through approved refunds. Product tools expose optional authenticated private images, price history, current shelf labels and previewed CSV/.xlsx opening-stock import/export. See docs/features/F03-pos-tools.md, F03-held-carts.md, F12-product-price-tools.md and F13-opening-stock-import.md.
R2 images require a private bucket and server-only R2 configuration (never paste credentials into chat). The required approved Laravel S3 adapter is installed. Selcom still requires merchant provisioning/verification. Run the scheduler for payment reconciliation and products:cleanup-images retries. Camera decoding is capability-dependent; unsupported browsers offer manual/keyboard entry. Actual scale-label decoding and pilot thermal-printer compatibility are separate verification gates. Never run two backend/E2E suites against the same test database simultaneously.

Coordinated POS/product-tools verification (2026-10-13): final browser run passes all 18 desktop/phone cases; frontend verifies 149 distinct cases, selected financial regressions pass 23 / 231 assertions and arithmetic passes 28 / 106. TypeScript, lint and production/PWA build pass. Full backend rerun and remote CI remain unverified. Normal development preview is restored.

The D089 remaining batch adds department/till and classified loss/count reports, configurable activity alerts, bank matching/supplier schedules/price comparisons, Filament administration and subscriptions, same-device customer display/configurable gram labels, QR cards/commission rankings and consented WhatsApp/SMS delivery. SMS delivery polling uses the authenticated Beem report API; unsupported Unicode stays blocked until verified. Publish admin assets with `php artisan filament:assets`, provision an administrator interactively and run the minute scheduler (including `messaging:reconcile-sms`). Exact current test evidence is in docs/08-test-checklist.md and docs/NEXT-CONVERSATION.md. See docs/LAUNCH-READINESS.md for production credentials, hardware and deployment work. Selcom/mobile-settled exchanges remain deferred for walk-in launch; TRA is the final gate.
