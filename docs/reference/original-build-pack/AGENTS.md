> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# AGENTS.md — BiziSmart

Read this file first in every session. Then read only the docs the current task needs (see "Where things are").

## What this project is
BiziSmart is a multi-tenant SaaS for Tanzanian SMEs. Verticals: Pharmacy (built first), then Loans/Agro, later Supermarket and Restaurant. One shared Core, one vertical at a time. Currency is Tanzanian shillings (TSh). Users are shop staff on cheap Android phones, often with unreliable internet.

## Stack (do not change without asking)
- Backend: PHP 8.3+, Laravel (current stable), **API only** (JSON). MySQL 8 (InnoDB). Redis (cache, queues). Laravel Sanctum Bearer tokens. spatie/laravel-permission (teams = business). Laravel Pennant (feature flags). Horizon (queues). spatie/laravel-activitylog.
- Super Admin: Filament at admin.bizismart.co.tz (the only server-rendered UI).
- Frontend: React 18 + Vite, MUI, Redux Toolkit + RTK Query, React Router. JavaScript (TypeScript later). PWA via Workbox + Dexie.js (offline comes in Phase 3).
- Tests: Pest (backend), Vitest + React Testing Library (frontend), Playwright (E2E).
- Files: Cloudflare R2 via Laravel S3 driver. Payments: AzamPay/Selcom. Messaging: Meta WhatsApp Cloud API, Beem SMS.

## Repo layout
```
/backend    Laravel app (API, Filament admin, jobs)
/frontend   React app (Vite)
/docs       Specs and rules (source of truth)
AGENTS.md   This file
```

## Hard rules (never break these)
1. **Tenant isolation.** Every table with `business_id` has a model using the `BelongsToBusiness` trait (global scope, fails closed, sets `business_id` on create). Never accept `business_id` from request input. Never use `withoutGlobalScopes()` outside the Filament/admin namespace or the webhook tenant lookup. Never run raw `DB::table()` / `DB::select()` on tenant tables without `business_id` in the query. Queue jobs must carry `businessId` and set the tenant before working. Cache keys and file paths include the business id.
2. **Business logic lives in Laravel services** (`app/Services/*`), not in controllers and never in React. React displays data and collects input. The API is the authority on permissions; the UI only hides things.
3. **Money is an integer number of TSh** (`unsignedBigInteger`). No floats, no decimals. Rounding rule is in `docs/02-business-rules.md`.
4. **Every change to stock quantity** goes through `StockService` and writes a `stock_movements` row, inside a DB transaction, with `lockForUpdate()` on the batch row.
5. **Online sales never oversell** (`INSUFFICIENT_STOCK`). **Offline-synced sales are never rejected** for stock/price/expiry reasons: commit and flag (`docs/02-business-rules.md` section 9).
6. **Payment callbacks are idempotent** (unique `checkout_request_id`). A duplicate returns 200 and changes nothing.
7. **Passwords/PINs are hashed.** Never log PINs, tokens, national IDs, or prescription data. Sensitive columns use encrypted casts.
8. **Follow the API contract** in `docs/04-api-contract.md` exactly (paths, field names, envelope, error codes). If you must change it, update the doc in the same change and say so.
9. **Do not add packages** that are not listed in the stack without asking first.
10. **Migrations only add**; never edit a migration that has been merged. Write a new one.

## Conventions
- Laravel: Form Requests for validation, API Resources for responses, one Service class per domain (`SaleService`, `StockService`, `TillService`, `DebtService`, `PrescriptionService`, `MpesaService`), Policies/permissions for authorization, `snake_case` columns, plural table names, `public_id` (prefixed ULID, e.g. `prod_01J...`) in all API payloads; internal numeric ids never leave the server.
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
cd frontend && npm ci && npm run dev
npm run test && npm run build
# e2e
npx playwright test
```

## How to work
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
| docs/02-business-rules.md | All money, stock, till, debt, pharmacy and offline rules with examples |
| docs/03-database-schema.md | Tables, columns, indexes |
| docs/04-api-contract.md | Endpoints, payloads, errors |
| docs/features/*.md | One spec per feature with acceptance criteria |
| docs/06-task-list.md | Ordered tasks and progress log |
| docs/07-decisions.md | Decisions and assumptions |
| docs/08-test-checklist.md | Required tests |
| docs/09-prompt-playbook.md | Prompt templates for the human |
