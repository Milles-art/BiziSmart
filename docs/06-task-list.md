# 06 - Task List and Progress Log

Supermarket-only roadmap for the single BiziSmart product. Implement one reviewable task at a time. Check boxes only after acceptance criteria and tests pass. All added features remain unimplemented.

## Phase 1 - Core and first supermarket checkout
- [ ] S0 Setup: Laravel/React monorepo, CI, test frameworks, response envelope and request IDs.
  - [x] Laravel API and React/MUI workspace installed with committed-style dependency lockfiles and environment examples.
  - [x] API liveness, response/error envelopes and server-generated request IDs implemented.
  - [x] Pest: 10 tests / 41 assertions pass locally on PHP 8.5.
  - [x] Vitest/React Testing Library: 3 tests pass; production frontend build passes.
  - [x] GitHub Actions configured for backend PHP 8.3/8.5, MySQL, frontend and browser checks.
  - [x] Playwright: all 6 desktop/mobile browser checks pass locally using installed Chrome.
  - [ ] Remote CI passes after an authorised GitHub remote is configured; S0 remains open until this gate passes.
- [ ] S1 Tenancy and base migrations: plans, businesses, branches, users/devices, scope and CI-blocking isolation tests; demo supermarket tenants.
  - [x] Local MySQL 8.4 installed separately from XAMPP; development/test databases and accounts configured.
  - [ ] Redis runtime and queue/cache readiness established before Redis-dependent operations.
  - [x] Base migrations, tenant context/scopes, demo tenants and isolation tests implemented; 49 backend tests pass locally on MySQL 8.4.
  - [ ] Remote CI verifies the tenant suite on PHP 8.3/8.5; no remote is configured.
- [ ] S2 Specify supermarket roles, owner 2FA and missing auth contracts; implement auth/device approval.
  - [x] User approved manager matrix; tenant role tables, approved role provisioning/assignment, Laravel Gate/middleware and isolation tests implemented.
  - [x] Owner 2FA/enrolment, forced PIN change, phone normalization and device-bound token contracts completed.
  - [x] Backend login/lockout, owner TOTP, PIN change/reset, logout/rotation, device approval/blocking and session endpoints implemented; 85 tests / 579 assertions pass on MySQL.
  - [x] Previous React API sign-in/challenge/PIN/logout implementation verified (9 frontend/12 browser tests); archived when the user supplied the new frontend baseline.
  - [x] Owner staff list/create/update/deactivation API implemented with plan limits, last-owner protection and session revocation; full backend suite passes 92 tests / 638 assertions.
  - [x] Imported demo staff cards support owner profile edit and confirmed deactivation/reactivation, with validation and retained history.
  - [x] Real API sign-in/challenges, initial owner setup and staff/device management connected to the preserved design; prototype routes removed from App. Approved roles come from Laravel; money/quantity workflows remain disabled pending S3-S7.
  - [x] User-approved same-tab reload persistence: minimal approved-device credentials in sessionStorage, authoritative `/me` revalidation, preserved expiry/inactivity deadlines and storage clearing on logout/401/idle lock. No identity, PIN, TOTP or shop data persisted.
  - [x] Supervisor single-use approval contract and backend service implemented before S6/S7; grants bind shop/requester/session/device/action/snapshot, expire after five minutes and commit consumption with domain DB changes. UI/financial consumers belong to S6/S7 and returns.
  - [ ] Verified owner onboarding and TOTP recovery designed and implemented; demo accounts stay disabled.
- [x] S3 Specify exact quantity units, packaging conversions, weight precision/rounding and product/stock API/schema extensions (F10.1-2). User chose gram precision; exact arithmetic helpers and 23 focused tests pass. Product/stock implementation remains S4.
- [ ] S4 Products, departments, batches and StockService; receive/adjust stock, packaging barcodes, manual weighted quantities and movement ledger.
  - [x] S4a catalogue backend: product create/edit/archive, search/category/base-barcode lookup, exact prices/reorder levels, cost filtering, plan limits and historical barcode reservations. MySQL catalogue tests pass; full S4 remains open.
  - [x] S4b packaging catalogue: exact contents, independent prices, immutable versions, shared barcode registry and nested ownership; connected pack forms and barcode selection.
  - [ ] Departments, then StockService/batches/receiving/adjustment/ledger after expiry policies are selected.
- [ ] S5 Product UI, search/camera scanning, receive stock and weighted entry.
  - [x] S5a connected product catalogue: live RTK Query list/filter/pagination, create/edit/confirmed archive, exact kg reorder strings/integer TSh, error retention and cost/control visibility. 39 frontend tests and all six updated desktop/mobile checks pass; preserved layouts inspected.
  - [x] Connected pack/carton create/edit/archive forms and exact packaging barcode selection (S4b).
  - [ ] Receiving, batch/movement details and camera/hardware scanning after their services exist.
- [ ] S6 Till open/close and supervisor approvals BEFORE sales; multi-till limits and manager overview.
- [ ] S7 Cash sale API/POS, FEFO where applicable, exact prices/quantities, concurrency, rollback and idempotency.
- [ ] S8 Fast checkout hardware/shortcuts and printable/PDF receipts (F10.3).
- [ ] S9 Customers, credit limits, debt and split payments.
- [ ] S10 Basic sales/profit/inventory/debt reports, with department and till summaries.
- [ ] S11 Super Admin onboarding, supermarket plans, subscriptions and feature flags.

Phase 1 gate: checkout, stock and till work end to end in a test supermarket.

## Phase 2 - Supermarket operations and pilot
- [ ] S12 M-Pesa fake gateway, then chosen provider sandbox and reconciliation.
- [ ] S13 Department counts, loss-prevention controls and suspicious-activity alerts.
- [ ] S14 Damage/spoilage/wastage records and reports (F10.4).
- [ ] S15 Receipt-linked returns/exchanges; specify payment and stock disposition contracts (F10.5).
- [ ] S16 CSV/Excel opening-stock import, preview and duplicate-safe commit (F10.6).
- [ ] S17 Shelf labels and audited/scheduled price changes (F10.7).
- [ ] S18 Supplier receiving, returns/credit notes; specify supplier accounting contracts (F10.8).
- [ ] S19 Cash drops, manager acknowledgement and drawer reconciliation (F10.9).
- [ ] S20 English/Kiswahili interface, messages and receipts (F10.10).
- [ ] S21 Approved near-expiry batch markdowns (F10.11).
- [ ] S22 Authorised margin alerts without cashier cost leaks (F10.12).
- [ ] S23 Promotions, supported weighing-scale/label formats and customer-facing display.
- [ ] S24 Hardening, backups, low-end-device checks and pilot deployment.

Phase 2 gate: 3-5 paying supermarkets use it daily. The staged order is a working dependency sequence; feature tier assignments and pilot release cuts require separate decisions.

## Later
- [ ] Phase 3: offline selling, Workbox/Dexie, accept-and-flag sync and device tests.
- [ ] Multi-branch, loyalty, forecasting, WhatsApp automation and TRA behind feature flags.
- [ ] Continue improving this same supermarket product; no additional business-type modules.

## Progress log
- 2026-10-08: Original build pack created; no application implementation.
- 2026-10-08: User selected supermarket for the product; this was subsequently narrowed to supermarket-only scope.
- 2026-10-08: Imported the supplied pack into the workspace; added all 12 supermarket additions in F10, updated scope/roadmap/decisions and documented required contract/schema revisions. No application code or completed feature claims.

- 2026-10-08: User confirmed one supermarket-only product. Updated all active specifications, removed other business types from API/schema/roadmap, preserved historical sources and added the complete supermarket catalogue.

## Additional catalogue work (same product; detailed specs pending)
- [ ] Imported frontend: update vulnerable build/test dependencies with design/PWA compatibility checks before deployment.
- [ ] Expenses with photos, supplier balances/payment scheduling and last-three-price comparison.
- [ ] Cash flow/profit-loss/tax reports, bank reconciliation and report exports.
- [ ] Staff attendance and notification preferences/delivery workflows.
- [ ] Later: loyalty/QR cards, commissions/leaderboards, forecasting and enterprise API access.

## Build guidance update
- 2026-10-08: Added docs/10-build-guide.md, installed the local bizismart-build skill, linked repository instructions and corrected the task starter. Workflow preparation only; S0 remains incomplete.

## Folder structure update
- 2026-10-08: Created backend/frontend, E2E, infrastructure and CI directory scaffolds with Git placeholders and root ignore rules; documented placement in 11. Frameworks/tests/CI are not installed or running; S0 remains incomplete.

## S0 implementation
- 2026-10-08: Installed Laravel 13, React 18/Vite/MUI/RTK Query, Pest, Vitest and Playwright. Added API foundation and a connected setup screen, environment examples and GitHub Actions. Backend tests (10/41), frontend tests (3) and production build pass locally. MySQL/Redis services and remote CI remain unavailable; no tenant/auth/stock features are implemented. BiziSmart uses ports 18080 (API) and 15173 (frontend) to avoid an existing project's port 8000.
- 2026-10-08: All 6 Playwright desktop/mobile tests pass against the real frontend/API with installed Chrome. Inspected desktop and phone screenshots; preview servers run locally. S0 remains open only for remote CI verification; S1 requires MySQL service setup before tenant migration/isolation checks.
- 2026-10-09: User approved the proposed separate MySQL setup. Installed official MySQL 8.4.11 locally on loopback port 3307, preserving XAMPP/MariaDB. Created separate password-protected development/test databases and accounts, configured private Laravel environment files, and verified both Laravel connections plus InnoDB rollback and database account boundaries. Added repeatable installation/start/stop/status helpers and guide 12. Kept S1 tenant implementation, Redis and remote CI pending.
- 2026-10-09: Implemented S1 core tables/models, request-scoped tenant resolution before auth/binding, fail-closed scopes and write guards, tenant-bound Sanctum tokens, composite foreign keys, encrypted contact fields/phone lookup, ID-only tenant jobs and isolated cache/files. Applied all three migrations to development and seeded two disabled demo shops idempotently. Full backend run: 49 tests / 214 assertions passed; after disabling automatic private-storage routes, foundation regression: 11 tests / 46 assertions passed. Public API remains health only, and the live frontend proxy returns its success envelope. Redis transport and remote CI remain pending; roles/permissions, login, device approval, products and other shop features are not implemented.
- 2026-10-09: User approved the manager permission matrix. Implemented Spatie 6.25.0 teams tied to TenantContext, scoped roles/memberships and guarded pivots, composite membership constraints, tenant permission caches, approved role provisioning/assignment with row locks and permission middleware. Migration applied locally; verified each demo shop has four roles, one disabled owner and zero tokens. Final backend suite passes 61 tests / 350 assertions; Composer strict validation/platform checks pass and the live frontend health proxy succeeds. No login, 2FA, device-management or shop-data routes are exposed by this slice; S2 remains open for those flows and frontend sign-in.
- 2026-10-09: User approved pragmarx/google2fa (9.1.0). Implemented canonical phone/PIN login, committed lockout counters, owner TOTP enrolment/verification with replay protection, hashed expiring challenges, forced PIN change/reset, device-bound tokens/rotation/logout, owner device approval/blocking and session endpoints. Added agreed Spatie activitylog with tenant isolation and encrypted allowlisted properties; applied auth/audit migrations and preserved disabled demo accounts. React sign-in handles challenges, PIN confirmation, in-memory sessions, protected 401, mutation cleanup and 30-minute inactivity. Final checks: 85 backend tests / 579 assertions, 9 frontend tests, production build, Composer strict/platform checks and all 12 real-Laravel/MySQL desktop/mobile browser tests pass. Inspected both sign-in layouts. Browser fixtures are confined to the test database; CI now includes browser MySQL but remote runs remain unverified. S2 stays open for owner staff/device-management screens, supervisor approval and verified onboarding/recovery; Redis and supermarket operations remain pending.
- 2026-10-09: User selected BiziSmart-fixed.zip as the new starting point and required design preservation plus clearly simulated payments. Read the supplied README, safely staged/imported its frontend into the monorepo, retained Laravel/private database configuration/specs and backed up the previous frontend locally. Preserved emerald/slate layout/navigation/primitives/charts. Added demo owner staff edit/deactivate/reactivate controls, validation/last-owner protection and persistence checks; added owner-only Laravel staff APIs with plan limits, canonical phone uniqueness, hashed temporary PINs, tenant branch validation, role replacement, session revocation and secret-free audit events. Added persistent demo notices, checkout/receipt simulation labels and accessible input/navigation names. Checks: all 21 supplied frontend tests and five new staff tests pass; typecheck/lint/production-PWA build pass; full backend 92 tests / 638 assertions pass; all eight updated desktop/mobile browser checks pass and layouts visually inspected. Backend auth is checked separately from the browser demo. Updated CI typecheck/lint and active docs; recorded imported dependency advisories for compatibility fixes. Real frontend/API integration, production role/currency/quantity alignment, M-Pesa/provider confirmation, Redis, remaining approved features and remote CI remain pending.

- 2026-10-09: User requested real endpoints and removal of demo access. Active interface now uses Laravel login/TOTP/PIN, owner staff CRUD/reset and device controls. Trusted local one-use owner invitation added for Fresh Choice Supermarket; no operational-owner recovery bypass. Current checks: 98 backend tests/678 assertions, 30 frontend tests and six real desktop/mobile browser checks pass. Stock/sales/payment screens disabled until real workflows exist.

- 2026-10-09: Implemented real supervisor approval issuance/atomic consumption for the approved refund/void/discount/variance actions. Added composite tenant/session foreign keys, hashed secrets, owner TOTP, shared PIN lockout, strict binding and security-change revocation. Full MySQL backend suite: 119 tests / 779 assertions pass, including 21 approval cases. Applied additive development migration 000008. Production recovery and real financial workflows remain pending.

- 2026-10-09: Real supervisor issuance verified in all six desktop/mobile browser checks. Added typed RTK Query adapter and credential/session error distinction; all 34 frontend tests pass. Financial approval dialogs/consumers remain with their domain features; next actionable dependency is S3 quantity/packaging design before stock implementation.

- 2026-10-09: User selected three-decimal kg (gram) precision and half-up whole-TSh line totals. Completed F02-exact-quantities contract, quantity-string API examples, signed atom schema plan, versioned packages/shared barcode registry and safe integer bounds. Added Quantity/ExactMath/Money helpers; 23 focused cases/60 assertions pass without DB writes. No stock, sale, invoice or hardware endpoint enabled. Next: S4 product catalogue/StockService, after expiry/quarantine/mixed-batch receiving policies are settled.
- 2026-10-09: Implemented S4a real product catalogue endpoints, migration 000009, tenant models/shared base-barcode reservations, exact validation/resources, active plan limits, permission-filtered costs and encrypted before/after price audits. Full MySQL suite passes 163 tests / 1,055 assertions, including 21 catalogue cases and S3 arithmetic. Added a browser-header authentication regression after a live proxy check; all 12 foundation tests / 50 assertions then pass. PHP syntax checks pass. Applied the additive development migration; proxy returns a no-store JSON 401 without authentication. Product UI, departments, packaging associations and all stock operations remain pending; expiry receiving policy question is open. Frontend/build/E2E checks were not repeated for this backend-only slice.
- 2026-10-09: Completed S5a catalogue UI through real Laravel adapters while preserving emerald/slate primitives and navigation. Added server filters/pagination, whole-TSh and exact quantity forms, focus-managed MUI dialogs, activation/expiry metadata, confirmed archive, error retention and permission-based cost/write controls. All 39 frontend cases (26 prototype compatibility plus 13 live cases), typecheck, lint and production/PWA build pass. All six desktop/mobile browser checks pass, including real product create/edit/inactive metadata/barcode Enter search/archive; screenshots inspected. No backend logic/migration changed this slice; prior backend unit evidence is retained and connected MySQL browser checks ran. Development previews restored after the guarded test run. Next: remaining S4 packaging/departments/StockService and receiving policy, followed by receiving UI; no stock, camera, scale, till, sale or payment operation enabled.
- 2026-10-09: User requested sign-in to survive reload. Added minimal tab-scoped remembered-session middleware/restoration, authoritative account revalidation and idle activity persistence without resetting deadlines. Logout, 401, expiry and 30-minute idle lock remove stored credentials; malformed/device-mismatched records are rejected and blocked storage falls back to memory-only. All 49 frontend tests (including 10 new session cases), typecheck, lint and production/PWA build pass. All six desktop/mobile browser checks pass, now covering owner/cashier reload, retained role restrictions, logout and subsequent reload, with no stored identity/permissions/shop data. Backend contracts/logic unchanged; no cookie auth or new dependency introduced. Development previews restored; same-tab refresh is supported, browser-restart remembering is outside this change.
- 2026-10-09: Completed S4b real pack/carton catalogue and connected Products pack forms. Added exact contents/independent integer prices, immutable effective edits, shared barcode ownership/history, composite packaging FKs, stale-version errors, server barcode selection and confirmed archives. Full MySQL suite passes 168 tests / 1,150 assertions; all 51 frontend tests, TypeScript, ESLint and production/PWA build pass. All six desktop/mobile browser checks pass, including the packaging workflow; screenshots inspected. Cold-start E2E assertion waits now match the existing 15-second API deadline; production auth behavior is unchanged. Applied migration 000010 and restored normal development previews. Departments and expiry-dependent StockService/receiving remain next; stock, sales and payments stay unavailable.
- 2026-10-09: User requested relocation to C:\Users\DIGITAL STAR. Moved the complete project to C:\Users\DIGITAL STAR\BiziSmart, including Git metadata, dependencies, private configuration and local MySQL data. Updated MySQL absolute runtime paths and restored the development previews from the new directory. Future work should open the relocated project folder in Codex.
- 2026-10-09: User approved main with feature/ branch naming and requested the first local baseline commit. Record the naming convention in AGENTS.md and decisions. Include implemented code, tests and documentation; exclude private environments, local database/runtime, dependencies and generated outputs. Prior S4b validation remains the current code evidence; this task changes Git guidance only.
