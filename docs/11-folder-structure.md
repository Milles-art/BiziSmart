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
