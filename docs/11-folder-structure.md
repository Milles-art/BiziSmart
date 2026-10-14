# 11 - Project Folder Structure

Status: S0 framework foundation and local S1 tenant core implemented. Laravel/React, tenant models/migrations, test runners and CI workflow configuration are present. Shop-feature folders remain placeholders; remote CI has not run. Empty leaf directories contain .gitkeep so Git can preserve the structure; remove them as real files are added.

```text
BiziSmart/
|-- AGENTS.md
|-- README.md
|-- .gitignore
|-- .github/
|   `-- workflows/                    PHP/frontend/browser checks
|-- backend/                         Laravel API and Filament Super Admin
|   |-- app/
|   |   |-- Models/
|   |   |   `-- Concerns/            Tenant model scope and shared traits
|   |   |-- Http/
|   |   |   |-- Controllers/Api/V1/   API controllers
|   |   |   |-- Middleware/          Tenant resolution, auth and request context
|   |   |   |-- Requests/            Validation
|   |   |   `-- Resources/           API response presentation
|   |   |-- Services/                Stock, sale, till, debt and payment rules
|   |   |-- Support/                 ExactMath, Quantity and Money arithmetic helpers
|   |   |-- Policies/                API authorisation
|   |   |-- Jobs/                    Tenant-aware queued work
|   |   |-- Tenancy/                 Scoped context, cache and private file helpers
|   |   |-- Exceptions/              Domain errors
|   |   `-- Filament/                Separate platform administration
|   |-- config/
|   |-- database/
|   |   |-- migrations/
|   |   |-- factories/
|   |   `-- seeders/
|   |-- routes/
|   `-- tests/
|       |-- Unit/
|       `-- Feature/                 API, tenant isolation and DB behaviour
|-- frontend/                        React + Vite + MUI
|   |-- public/                      Public static assets
|   `-- src/
|       |-- app/                     App setup, routing, Redux and RTK Query base API
|       |-- components/              Shared presentation components
|       |-- layouts/                 Shared application layouts
|       |-- theme/                   MUI theme and design tokens
|       |-- locales/
|       |   |-- en/
|       |   `-- sw/
|       |-- assets/
|       |-- test/                    Shared frontend test setup/helpers
|       `-- features/
|           |-- auth/
|           |-- dashboard/
|           |-- products/
|           |-- inventory/           Batches, receiving, counts and wastage
|           |-- pos/                 Cart, checkout and receipt view
|           |-- tills/               Sessions, paid-outs, cash drops
|           |-- customers/           Profiles, credit and debt
|           |-- suppliers/           Purchasing, supplier returns and credits
|           |-- returns/             Customer returns and exchanges
|           |-- promotions/          Offers and batch markdowns
|           |-- imports/
|           |-- reports/
|           |-- staff/
|           `-- settings/
|-- tests/
|   `-- e2e/                         Cross-application Playwright flows
|-- infrastructure/
|   |-- local/                       Windows MySQL install/start/stop and private setup helpers
|   |-- nginx/                       Reserved routing/TLS configuration
|   `-- supervisor/                  Reserved Horizon worker configuration
`-- docs/
    |-- 01-...12-*.md                Scope, contracts, roadmap and guides
    |-- features/                    Active supermarket requirements
    `-- reference/                   Historical, non-authoritative source files
```

## Placement rules
- Laravel services own persisted money, stock and payment calculations; controllers validate/authorise and invoke services.
- React features own their screens, local presentation components and RTK Query endpoints. Put tests beside features; src/test holds shared setup only.
- Backend tests stay in backend/tests; root tests/e2e coordinates both applications.
- Filament lives in backend/app/Filament, not a second React admin product.
- locales/en and locales/sw are reserved for English/Kiswahili. Do not add a translation package until its dependency choice is approved under project rules.
- Every supermarket uses this same structure; tenants are data boundaries, not copied code folders.
- Future offline sync belongs to the same frontend/backend; create its folders when Phase 3 is implemented.
- infrastructure/local contains the Windows development database helpers; nginx/supervisor remain deployment placeholders.

## Foundation entrypoints
Backend: artisan, bootstrap/app.php, public/index.php, routes/api.php, composer.json and composer.lock. Frontend: index.html, vite.config.js, src/main.jsx and src/app/App.jsx. Root package.json/package-lock.json own the npm workspace and Playwright dependencies; run npm ci from the repository root. The active CI file is .github/workflows/ci.yml.

Local MySQL, the S1 tenant core and S2 permissions/auth/staff management are implemented. StaffRoleService owns role provisioning/assignment; StaffAuthService owns login/challenges/PIN/token changes; StaffManagementService owns staff CRUD/activation guards; DeviceService owns approval/blocking. Models/Concerns/HasTenantRoles protects permission checks.

The user-selected ZIP supplies the preserved layout/primitives and TypeScript/Tailwind/MUI baseline. Active entrypoints are frontend/src/main.tsx and frontend/src/App.tsx; vite.config.ts and tests/ hold build/test configuration. src/features/live owns real RTK Query auth/session/staff/device adapters, owner-facing screens and session lifetime. src/screens/Login.tsx is the real login/setup/challenge form. src/store/redux.ts supplies the Redux session/API cache, with rememberedSession.ts restoring minimal tab-scoped credentials for Laravel revalidation after reload. Imported prototype screens/store/calculation helpers remain historical compatibility code, not App routes. Prior frontend is archived under ignored tmp/pre-fixed-frontend. tests/e2e verifies the connected interface against real MySQL test fixtures. OwnerSetupService and app/Console/Commands/OwnerInvite implement guarded local initial onboarding. Next: remaining S2 recovery contracts and S3-S7 real stock/till/checkout; establish Redis before dependent operations.
SupervisorApprovalService issues and atomically consumes bound operation grants; SupervisorApproval stores scoped hashed grants with composite session/user/device foreign keys. Staff/role/auth services revoke them on security changes. SupervisorCheckRequest validates the narrow real endpoint; SupervisorApprovalTest covers binding, single use, expiry, rollback, role/TOTP/lockout and revocation.

S3 exact quantity contracts are defined in docs/features/F02-exact-quantities.md. app/Support/ExactMath.php, Quantity.php and Money.php implement checked integer arithmetic, whole items/integer grams, pack conversions and whole-TSh line rounding. tests/Unit/ExactQuantityTest.php verifies them without database writes. S4a adds the catalogue tables below; packaging/batch/ledger tables and StockService remain pending. These helpers do not implement stock operations.

S4a adds Models/Product.php and ProductBarcode.php, Services/ProductService.php, Http/Requests/Products, ProductResource and Controllers/Api/V1/ProductController.php. Migration 000009 creates catalogue/base-barcode tables only. ProductCatalogueTest verifies permissions, exact values, shop isolation, reservations, rollback, limits and audits. Departments, packaging associations, batches/ledger and StockService remain pending; no product UI or stock balance is enabled by this backend slice.

S5a adds frontend/src/features/live/Products.tsx and catalogue adapters to live/api.ts; App routes the Products navigation to this connected screen. frontend/tests/products-live.test.tsx verifies live requests/error/cache/permission boundaries, and tests/e2e/live.spec.js includes desktop/phone catalogue operations against MySQL. Historical src/screens/Products.tsx remains unrouted compatibility code. Stock balances, receiving and packaging operations remain pending.

src/features/live/rememberedSession.ts owns minimal sessionStorage serialization/validation/restoration and Redux persistence middleware. useSessionLifetime.ts retains idle deadlines across reload and clears remembered credentials on lock. tests/remembered-session.test.tsx covers restore, activity, expiry, changed devices, malformed data, logout/revocation and blocked storage; live browser tests verify owner/cashier reload and logout against Laravel.


S4b adds backend ProductPackaging/PackagingService, SavePackagingRequest/PackagingResource/PackagingController, additive migration 000010 and live frontend Packagings.tsx. Products and RTK Query connect these real routes; product catalogue tests cover versions, gram factors, barcode ownership and tenant permissions.

S4c adds Department/DepartmentService, department request/resource/controller and additive migration 000011; frontend live Departments.tsx holds management and paginated pickers. Products/RTK Query provide real assignment/filtering. Backend/frontend catalogue suites and browser owner flows verify these connections.

S4d receiving uses backend/app/Services/StockService.php, tenant ProductBatch/StockMovement models and additive migration 000012. Place receipt/list requests under Http/Requests/Products, batch resources under Http/Resources and the thin controller under Api/V1. Frontend live/StockReceiving.tsx holds batch reads and receipt input only; live/api.ts owns its RTK Query endpoints. Stock calculations and eligibility stay in Laravel. Test receipt transactions/tenant ownership/idempotency in backend/tests/Feature/StockReceivingTest.php; guarded process workers use only the separate test database.

S4e keeps stock queries in backend/app/Services/StockService.php; StockAlertsController, StockAlertsRequest and ExpiryAlertResource expose read-only alerts. Products.tsx uses live/api.ts catalogue filters and authoritative stock strings. No additional migration or package is required.

S4f movement-history reads use backend/app/Services/StockService.php and the StockMovement controller/request/resource. frontend/src/features/live/StockHistory.tsx displays ledger entries within StockReceiving.tsx; live/api.ts owns the authenticated query and cache invalidation.

S4g: StockAdjustmentController/AdjustStockRequest call StockService previewAdjustment/adjust; additive migration 000013 adds encrypted reason, fingerprint and approver metadata. StockCorrection.tsx is nested in StockReceiving and uses live RTK Query preview/approval/commit adapters; no browser quantity arithmetic. StockAdjustmentTest covers exact stock, approval, tenant and process concurrency invariants.

S6 till lifecycle: backend/app/Services/TillService.php plus TillCashService/TillAccessService; backend/app/Models/TillSession.php, TillCashEntry.php, TillOperation.php; backend/app/Http/Requests/Tills and TillResource/TillController. frontend/src/features/live/Tills.tsx connects through api.ts. TillTest and tills-live.test.tsx verify real contracts; tests/e2e/live.spec.js includes cashier till opening/reload/paid-out/variance approval/closing history on desktop and phone.

F00 restores the supplied design through live/Dashboard.tsx and Inventory.tsx, shared StockAlertPanels and TillCards, compact SummaryCard/ScreenTabs, and existing domain dialogs. docs/design/BiziSmart-design-reference.zip keeps the intact visual reference; extracted preview files stay ignored under tmp/.

S15 implementation: backend/app/Services/{ReturnService,ReturnAccessService,RefundCalculator}.php; append-only Models/{SaleReturn,SaleReturnItem,SaleExchange}.php; Http/Requests/Sales/ReturnRequest.php and Controllers/Api/V1/ReturnController.php; additive migration 000017; frontend/src/features/live/{Returns.tsx,returnState.ts} with live API adapters and POS entry point. Contract: docs/features/F03-returns-exchanges.md. MySQL regressions: SaleReturnTest; arithmetic: RefundCalculatorTest; frontend: returns-live.test.tsx; connected desktop/phone: tests/e2e/live.spec.js.

S12a provider transport lives in backend/app/Payments (gateway contract, merchant binding and signature verification); it has no payment journal or callback endpoint yet. Keep future orchestration/reconciliation in Laravel domain services/jobs and expose only authorized real lifecycle routes. frontend/src/features/live/MobilePaymentStatus.tsx reads server capabilities in the existing POS dialog.
