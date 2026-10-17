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

Current authentication D109: user explicitly chose email/password without an authenticator for shops and Super Admin. Read docs/features/F01-email-password.md before auth work. Enrolled email users use12+character/72-byte-max passwords, private activation/reset and email/password approvals; do not require or falsify old TOTP metadata. Existing unmigrated accounts retain backward compatibility until explicit safe activation. Device approvals, tenant separation, permission checks, bounded supervisor grants and session revocation remain mandatory.
1. **Tenant isolation.** Every table with `business_id` has a model using the `BelongsToBusiness` trait (global scope, fails closed, sets `business_id` on create). Never accept `business_id` from request input. Never use `withoutGlobalScopes()` outside the Filament/admin namespace or the webhook tenant lookup. Never run raw `DB::table()` / `DB::select()` on tenant tables without `business_id` in the query. Queue jobs must carry `businessId` and set the tenant before working. Cache keys and file paths include the business id.
2. **Business logic lives in Laravel services** (`app/Services/*`), not in controllers and never in React. React displays data and collects input. The API is the authority on permissions; the UI only hides things.
3. **Money is an integer number of TSh** (`unsignedBigInteger`). No floats, no decimals. Rounding rule is in `docs/02-business-rules.md`.
4. **Every change to stock quantity** goes through `StockService` and writes a `stock_movements` row, inside a DB transaction, with `lockForUpdate()` on the batch row.
5. **Online sales never oversell** (`INSUFFICIENT_STOCK`). **Offline-synced sales are never rejected** for stock/price/expiry reasons: commit and flag (`docs/02-business-rules.md` section 9).
6. **Payment callbacks are idempotent.** Persist globally unique Selcom order/transaction mappings and unique one-use sale allocation; tenant comes from that persisted mapping (F08). A duplicate returns 200 and changes nothing. The callback only records authenticated notification identity and schedules a trusted status query; never trust unsigned amounts.
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

S4a exposes real product catalogue CRUD/search/base-barcode APIs with ProductService, exact validation, restricted buying prices and preserved tenant barcode reservations. S5a connects frontend/src/features/live/Products.tsx to these APIs with real forms, search, pagination and confirmed archive. S4b adds PackagingService and connected Packagings.tsx: exact positive base_qty strings, independent pack prices, immutable versions, shared barcode ownership and selected_packaging lookup. S4c adds real department catalogue, product assignments/filters and connected management forms. Archive is blocked by any assigned product, including inactive/archived products. S4d adds real StockService receiving, batch reads and connected StockReceiving.tsx under Products → Stock. Receipts use tenant-scoped operation UUIDs, exact base/pack quantities, locked transactions and append-only movements; costs remain restricted. The user selected selling through the expiry date in Tanzania time and rejecting already-expired deliveries. S4e adds exact eligible catalogue stock, permission-filtered held totals, nearest expiry, low-stock/expiry filters and stock alert APIs through StockService. Reads do not mutate balances; online receiving checks aggregate overflow. S4f adds read-only movement history through StockService and StockHistory.tsx: exact historical batch changes/balances, original receipt snapshots, public attribution, batch filtering and pagination under receive_stock. S4g adds real correction preview/commit endpoints and StockCorrection.tsx: nonzero signed base-unit changes, encrypted reasons, expected-balance checks, checked aggregate bounds, owner-only stockkeeper grants consumed atomically, immutable operation fingerprints and safe retries. Every stockkeeper correction requires owner approval, and online corrections must leave non-negative balances (D068); offline sale acceptance remains unchanged. Owners correct directly under adjust_stock. Inactive/expired/held batches can be reconciled without changing eligibility flags; archived products remain unavailable. Allocations and checkout remain pending; S6 till lifecycle is implemented below. Never substitute sample balances or enable an unbuilt stock workflow.

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

S6 resource scope (D071): use TillAccessService with fresh locked users/tenant targets. Managers view tills and approve variances only in their assigned branch; cashiers operate their own assigned-branch session; owners view all tenant branches. Supervisor variance consumption requires branch_id from the authoritative snapshot and rechecks the manager assignment. Scope helpers/grants do not enable till lifecycle routes or prototype till screens. Plan till capacity is business-wide concurrently open sessions (D070).

S6 implements TillService, tenant-scoped TillSession/TillCashEntry/TillOperation, migration 000014 and connected Tills.tsx. All writes use immutable actor/device operation fingerprints; close previews bind expected cash/revision/limit and consume variance approval atomically. Cash totals derive from append-only entries, and closed sessions are immutable. Use recordCash only inside an authorised domain transaction; it validates exact integer amounts and own-till scope. Owner variance notices are persistent in the Tills & Cash overview. S7 cash checkout now uses this hook; S19 acknowledged drops remain pending.

Design reference: the user supplied project-bolt-sb1-wmxwj6um (3).zip on 2026-10-10 to correct visual omissions. Read docs/features/F00-design-parity.md when extending implemented screens. The intact reference is docs/design/BiziSmart-design-reference.zip; it guides visual composition only. Preserve the live Laravel adapters and permissions. Do not copy its prototype data, financial logic, authentication or simulated success.

F00 connects the Inventory screen to existing stock alerts, product-selected movement history and batch correction dialogs. Dashboard and Inventory share StockAlertPanels and RTK Query caches. Preserve permission-based query skipping, exact server quantity strings and pending-state errors; do not aggregate mixed units or fabricate inventory/wastage valuations. StockReceiving initialView selects the established batch/history view without changing receipt or correction authority.

S7a cash checkout is connected and specified in features/F03-cash-checkout.md. SaleService owns canonical price/VAT previews and locked atomic commits; StockService.allocateSale writes every deduction/movement and TillService.recordCash records net cash. Immutable logical lines are priced once before per-batch splits. Live POS/cart use RTK Query and memory-only Redux; same UUID key/body is frozen during uncertainty. Do not persist cart/receipt/shop state, infer receipt PDFs from an inline view, or enable refund/held/discount/mobile flows from the cash prerequisite. Credit and cash debt collections now follow the verified S9 contract below. D076 defines mixed allocation, D077 selects unconfigured Selcom and D078 records proportional debt-first refunds. D080 requires staff-selected invoice allocations for collections; D081 approves branch-based returns with supervisor approval and own-till cash refunds. These future consumers need their own contracts and tests; current receipt reads remain original cashier/device only.

S9 is connected and verified through features/F05-customers-debt.md. CustomerService stores encrypted tenant contacts and canonical keyed phone lookup; DebtService controls credit/invoice balances and append-only collection/allocation journals. Sales permit optional customer_id/credit_amount for full-credit or cash+credit, and cash tender covers only the cash allocation. Collection review/commit settles explicitly selected unpaid invoices under locks, checks exact sums/ownership/balances and credits the operator's own till atomically. All writes refresh authority and use actor/device-bound immutable UUID replays. Customers.tsx and the POS customer picker render Laravel data; uncertain collection keys/bodies stay in memory through navigation and clear on sign-out. Empty search and full local/canonical Tanzania phone search work. Do not enable mobile collections, loyalty, refunds or financial reports from S9 alone. No customer/cart/receipt data persists in browser storage.

S15 cash/credit returns and exchanges are specified in features/F03-returns-exchanges.md. ReturnService provides scoped exact receipt lookup, server review and approved atomic commits with immutable SaleReturn/SaleReturnItem/SaleExchange journals. ReturnAccessService enforces current receipt branch assignment and manager refund-grant scope; owners use their own active till for cross-branch receipts. RefundCalculator uses cumulative exact original-line entitlement deltas, including zero-money quantities. StockService restores original good batches or separate linked quarantine, and DebtService reduces only the original unpaid invoice before paid cash refunds. Exchanges fund a current-price SaleService replacement with the paid refund and additional cash, validating credit after reduction. Financial reads use current locks; grant peeking must not establish a stale repeatable-read transaction snapshot. Connected Returns.tsx/returnState retain only memory-based uncertain retries and clear at sign-out. Receipt returns now support proportional manually recorded mobile refunds under F08. A mobile payout is never performed by BiziSmart; retain external-reference attestation in the bound supervisor review. Atomic exchanges that require a mobile refund remain unavailable until their settlement contract is defined.

S12 uses app/Payments transport/configuration/signature primitives plus MobilePaymentService's tenant/actor/device-bound encrypted dispatch journal. F08 specifies create/push/status/callback, durable three-minute reconciliation leases, unique provider evidence and one-use sale or selected-invoice debt allocation. Provider calls must stay outside retried DB transactions; no redirects, push retries, tenant credential fallback or raw response/secret output. Payment capabilities enable only a correctly bound configured shop. POS/Customers recover server records and retain uncertain UUID/body only in memory. ManualMobileRefundService resolves confirmed unallocated money with owner approval and a completed external refund record. ReturnService separates cash/mobile refund pools, including invoice collections. Neither mobile collections nor mobile refunds credit drawer cash. Production scheduler and external merchant verification remain prerequisites for real collection; HTTP/provider fakes are tests only.


The coordinated POS/product-tools extension follows features/F03-pos-tools.md and user decision D086. Discounts require approval for every operator and branch-scoped managers; cumulative proportional cart shares preserve exact net return values. Mobile initiation validates but does not consume discount approval; unchanged confirmed checkout can renew approval at commit. Held carts use encrypted server drafts, 24-hour expiry, no reservation and single-use same-branch handoff. Browser print/PDF uses immutable receipts; completed voids remain approved refunds. Images use private authenticated R2 objects, optimistic revisions and durable cleanup; never expose file paths. Price history and labels are genuine server snapshots. CSV/.xlsx opening-stock imports match existing barcodes and commit all receipts atomically through StockService; browser parsers never own stock calculations. Never run concurrent suites against the same *_test database. Preserve human/Cursor changes and verify their current implementation without assuming authorship.


## Current operations implementation (2026-10-14)
D087/D088 supersede earlier pending notes for the approved coordinated operations batch. Connected modules include reports/dashboard, supplier accounting, expenses/photos, cash drops, pricing, staff operations, bilingual receipts/screens, configured incentives and encrypted offline cash sync/reconciliation. Owner-only committed-conflict history is read-only; never repost its cash. Preserve outstanding external Selcom/R2, production scheduler and physical hardware gates. See F14 delivery/localisation/offline specs and F15 pricing; consult the final evidence in docs/08-test-checklist.md before extending this work.

Latest launch scope (2026-10-14): walk-in customers; Selcom deferred and not a launch prerequisite. Read docs/NEXT-CONVERSATION.md when resuming in a new conversation. Keep provider code unconfigured and never simulate successful payments.

D096 (2026-10-15) authorizes the shared-hosting deployment profile in docs/14-shared-hosting.md as an alternative to Redis/persistent workers: database cache/limiter/sessions/queue, additive runtime migration000041, bounded attached processes and five-minute cron. Preserve exact financial/stock and uncertain-send safeguards. Do not weaken controls to fit the host. MariaDB runtime, actual cron/proc_open/timeout/HTTPS and capacity need hosted acceptance. No staging domain is selected. Use F00 for the approved ZIP composition corrections and F22 for read-only TotalVFD discovery; TRA stays last.

D099 current priority: the user reports an owner-provided one-year grace period and pauses TRA/provider/Postman work to focus on software. Record that report without verifying exemption or inventing dates; keep TRA disabled and preserve tax/pricing/audit rules. Use docs/16-shop-setup.md for the approved 247 Fresh Choice products and saved prices. Source availability is not opening stock; buying costs, quantity type/unit and expiry choices need actual shop confirmation. Barcodes are optional for manual products and must be real if scanning is required. Do not bypass ProductService or confuse F13 stock import with catalogue creation. No fiscal-provider research or stopped Postman download should resume without a new request.

D089 remaining batch now has local implementations under F17 (display/scales/QR/leaderboards), F18 (banking/supplier planning), F19 (department/till reports, classified loss/counts and owner thresholds), F20 (Filament/Pennant/subscriptions/messaging) and F03-completed-void-audit. Check current verification in NEXT-CONVERSATION and 08-test-checklist before asserting completion. Retain exact registered barcode priority over scale prefixes. Owner loss/count authority reuses correction rules. Completed voids are approved returns with an explicit immutable audit classification. Platform administration uses a separate required-MFA guard and one-use owner-chosen PIN/TOTP setup, never owner recovery. SMS polling verifies exact authenticated Beem report identities; never resend an uncertain send or guess Unicode encoding. Read LAUNCH-READINESS for external gates. TRA stays disabled and last; multi-branch transfers remain blocked by the unresolved stock/approval contract (D092). The read-only enterprise API is implemented under F21/D091; use dedicated expiring owner keys, preserve both API and underlying module entitlements, and check final verification in NEXT-CONVERSATION and 08-test-checklist.
