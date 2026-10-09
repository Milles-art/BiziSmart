# AGENTS.md — BiziSmart

Read this file first in every session. Then read only the docs the current task needs (see "Where things are").

## What this project is
BiziSmart is one supermarket and mini-mart management SaaS for Tanzanian SMEs. Multiple independent businesses use the same product with securely isolated data. There are no other business-type modules or future expansion tasks. Currency is Tanzanian shillings (TSh). Users are shop staff on cheap Android phones, often with unreliable internet.

The user approved all 12 additions in `docs/features/F10-supermarket-expansion.md`. Historical source files in `docs/reference/` are non-authoritative and must not drive implementation. S3 exact quantity/packaging contracts are defined in docs/features/F02-exact-quantities.md: whole counted items, integer grams with three-decimal kg entry, exact quantity strings and checked shared-balance conversions. Stock/POS must use those rules; money remains integer TSh with browser-safe bounds.

## Stack (do not change without asking)
- Backend: PHP 8.3+, Laravel (current stable), **API only** (JSON). MySQL 8 (InnoDB). Redis (cache, queues). Laravel Sanctum Bearer tokens. spatie/laravel-permission (teams = business). Laravel Pennant (feature flags). Horizon (queues). spatie/laravel-activitylog.
- Super Admin: Filament at admin.bizismart.co.tz (the only server-rendered UI).
- Frontend: React 18 + Vite, MUI, Redux Toolkit + RTK Query. The user supplied BiziSmart-fixed.zip as the active TypeScript frontend; preserve its Tailwind classes, emerald/slate design, components, charts and navigation. Existing archive dependencies are part of that approved baseline; new unlisted packages still require approval. PWA via Workbox + Dexie.js (production offline selling comes in Phase 3).
- Tests: Pest (backend), Vitest + React Testing Library (frontend), Playwright (E2E).
- Files: Cloudflare R2 via Laravel S3 driver. Payments: AzamPay/Selcom. Messaging: Meta WhatsApp Cloud API, Beem SMS.

## Repo layout
```
/backend    Laravel app (API, Filament admin, jobs)
/frontend   React app (Vite)
/docs       Specs and rules (source of truth)
/tests/e2e  Cross-application browser tests
/infrastructure  Deployment configuration (later)
/.github/workflows  CI configuration
AGENTS.md   This file
```

## Hard rules (never break these)
1. **Tenant isolation.** Every table with `business_id` has a model using the `BelongsToBusiness` trait (global scope, fails closed, sets `business_id` on create). Never accept `business_id` from request input. Never use `withoutGlobalScopes()` outside the Filament/admin namespace or the webhook tenant lookup. Never run raw `DB::table()` / `DB::select()` on tenant tables without `business_id` in the query. Queue jobs must carry `businessId` and set the tenant before working. Cache keys and file paths include the business id.
2. **Business logic lives in Laravel services** (`app/Services/*`), not in controllers and never in React. React displays data and collects input. The API is the authority on permissions; the UI only hides things.
3. **Money is an integer number of TSh** (`unsignedBigInteger`). No floats, no decimals. Rounding rule is in `docs/02-business-rules.md`.
4. **Every change to stock quantity** goes through `StockService` and writes a `stock_movements` row, inside a DB transaction, with `lockForUpdate()` on the batch row.
5. **Online sales never oversell** (`INSUFFICIENT_STOCK`). **Offline-synced sales are never rejected** for stock/price/expiry reasons: commit and flag (`docs/02-business-rules.md` section 9).
6. **Payment callbacks are idempotent** (unique `checkout_request_id`). A duplicate returns 200 and changes nothing.
7. **Passwords/PINs are hashed.** Never log PINs, tokens or sensitive customer/staff data. Sensitive columns use encrypted casts.
8. **Follow the API contract** in `docs/04-api-contract.md` exactly (paths, field names, envelope, error codes). If you must change it, update the doc in the same change and say so.
9. **Do not add packages** that are not listed in the stack without asking first.
10. **Migrations only add**; never edit a migration that has been merged. Write a new one.

## Conventions
- Laravel: Form Requests for validation, API Resources for responses, one Service class per domain (`SaleService`, `StockService`, `TillService`, `DebtService`, `MpesaService`), Policies/permissions for authorization, `snake_case` columns, plural table names, `public_id` (prefixed ULID, e.g. `prod_01J...`) in all API payloads; internal numeric ids never leave the server.
- Every API response uses the envelope in `docs/04-api-contract.md` (`success`, `data`/`error`, `request_id`).
- React: feature folders (`src/features/<name>`), RTK Query for all API calls, MUI components, no business rules, no direct `fetch` outside the RTK Query base API.
- Errors: throw domain exceptions (e.g. `InsufficientStock`) mapped to API error codes in one handler.
- Write tests with the code (see `docs/08-test-checklist.md`). Money, stock, tenancy and auth code without tests is not done.

## Commands
```
# backend
cd backend && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan test        # Pest
php artisan horizon                            # queue worker (local)
# frontend
npm ci && npm run dev                 # repository root; frontend workspace
npm test && npm run build             # repository root
npm run dev:api                       # second terminal, repository root
# e2e
npm run test:e2e
# local database (Windows; install instructions in docs/12-local-database.md)
./infrastructure/local/mysql.ps1 Start
./infrastructure/local/mysql.ps1 Status
```

## Build guidance
The active frontend preserves BiziSmart-fixed.zip's design and uses Laravel through src/features/live for real shop access and catalogue operations. App never routes to imported prototype workflows or synthetic accounts. User-approved reload persistence stores only the approved-device Bearer token, expiry, last activity and device UUID in tab-scoped sessionStorage; device UUID alone remains in localStorage. Never persist PINs, challenges, authenticator secrets, permissions, user identity or shop data. On reload, revalidate with `/me` before showing the workspace. Clear the remembered token on logout, unauthorized responses, expiry or 30-minute inactivity; reload must not reset the idle window. Stock/till/sales/report/payment tools remain unavailable until their real services exist. Never restore browser-only authentication or return simulated success when a real API fails. Retained prototype calculations/tests use historical KSh/decimal/legacy-role data and must not drive production rules. Initial owner invitations are one-use, tenant scoped and limited to trusted local issuance; they must never reset operational owners or bypass TOTP recovery. Preserve the supplied interface while adding real workflows.

For BiziSmart development, use the available `bizismart-build` skill and `docs/10-build-guide.md`. If the local skill is unavailable, this file and that guide provide the same repository workflow. Historical instructions under docs/reference/ remain non-authoritative.

S4a exposes real product catalogue CRUD/search/base-barcode APIs with ProductService, exact validation, restricted buying prices and preserved tenant barcode reservations. S5a connects frontend/src/features/live/Products.tsx to these APIs with real forms, search, pagination and confirmed archive. S4b adds PackagingService and connected Packagings.tsx: exact positive base_qty strings, independent pack prices, immutable versions, shared barcode ownership and selected_packaging lookup. Departments and StockService are still pending. Catalogue responses explicitly use stock=null and stock_available=false; never substitute sample balances or enable stock workflows before their services and tests exist.

## How to work
- Git branches: use `main` for the main branch and the user-approved `feature/` prefix for feature work (for example `feature/departments` and `feature/stock-receiving`). Do not use `codex/` for this repository.
- One feature spec at a time from `docs/features/`. Before coding, restate the plan in a few lines and list the files you will create or change.
- Make the smallest change that satisfies the spec. Do not refactor unrelated code.
- After coding: run the tests, report results, and list anything you were unsure about or assumed.
- If a rule is missing or ambiguous, **ask** instead of inventing. Put assumptions in `docs/07-decisions.md`.
- Update `docs/06-task-list.md` (tick boxes, add a progress-log line) at the end of each task.

## Definition of done
Spec acceptance criteria pass; tests written and green (including the tenant leak tests); no new lint errors; docs updated if behavior or contract changed; progress log updated.

## Where things are
| File | Use it for |
|---|---|
| docs/01-product-overview.md | Scope, users, MVP boundaries |
| docs/02-business-rules.md | All money, stock, till, debt, supermarket operations and offline rules with examples |
| docs/03-database-schema.md | Tables, columns, indexes |
| docs/04-api-contract.md | Endpoints, payloads, errors |
| docs/05-supermarket-feature-catalog.md | Complete approved product scope |
| docs/features/*.md | One spec per feature with acceptance criteria |
| docs/06-task-list.md | Ordered tasks and progress log |
| docs/07-decisions.md | Decisions and assumptions |
| docs/08-test-checklist.md | Required tests |
| docs/09-prompt-playbook.md | Prompt templates for the human |
| docs/10-build-guide.md | Task preparation, skill routing and quality gates |
| docs/11-folder-structure.md | Directory layout and placement rules |
| docs/12-local-database.md | Local MySQL setup, private credentials and separate test database |
