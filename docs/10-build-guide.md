# 10 - Build Guide

## Purpose and sources of truth
Keep one BiziSmart supermarket/mini-mart product moving through small, verifiable tasks. Multiple businesses are isolated tenants of that same product. All 12 additions remain approved.

Use current user instructions and root AGENTS.md, then the active scope/catalogue, roadmap, feature specs, business rules and contracts. Treat docs/reference/ as historical only. Resolve a conflict in active specifications before implementing the affected behaviour; do not copy missing rules from historical sources.

## Skills and project guidance
- BiziSmart Build (`$bizismart-build`): locally installed workflow skill for grounding tasks in this repository. It routes to these documents rather than duplicating feature specifications.
- AGENTS.md: persistent repository rules, even when the custom skill is unavailable on another computer.
- frontend-design: use for new screens/substantial UI work; retain React, MUI and the agreed architecture.
- Relevant document skills: only for actual PDF/spreadsheet/document artefacts, not ordinary application development.

The custom skill is installed on this computer under `~/.codex/skills/bizismart-build`. Its absence on another machine does not block work: follow AGENTS.md and this guide. Do not automatically install additional skills, plugins or packages to begin coding.

## Before the first application task
1. Inspect PHP/Composer, Node/package manager, MySQL/Redis and Git availability; record missing prerequisites without exposing secrets. Choose a supported local development setup before installing services.
2. S0 establishes backend/frontend skeletons, environment examples, test runners, CI configuration, response envelope and request IDs. Confirm dependency versions against official documentation when implementing, while keeping the chosen stack.
3. S1 establishes tenant context and isolation tests before feature data is exposed.
4. Before S2, complete owner 2FA/forced-PIN-change flows and supermarket permissions.
5. Before S4, read the completed S3 contract in features/F02-exact-quantities.md: quantity strings, integer item/gram atoms, shared-balance packaging versions/barcodes and half-up per-line integer money. Helpers exist; product/stock tables and their concurrency/tenant tests do not.
6. Identify pilot hardware before claiming scanner, scale or thermal-printer compatibility. Tax/provider/regulatory assumptions are validation work before affected launch integrations, not verified facts.

Later design questions do not block unrelated foundation work. S0, the local S1 tenant core and S2 roles/auth/session/staff-management backend now exist. The user-selected BiziSmart-fixed.zip design now hosts real Laravel sign-in, owner setup, staff and device management through src/features/live. App does not route to prototype workflows. Trusted local initial onboarding is implemented; production onboarding/recovery, shop operations, Redis workers and remote CI remain pending. Stock/till/sales/payment tools are unavailable until their real services exist; never simulate API success. Approved roles are server supplied; integer TSh and exact quantity contracts remain required before live shop operations. See F00/F01 for implemented boundaries.

## Task working agreement
For each authorised task, prepare a short brief:
- Task ID and concrete user-visible outcome.
- Required spec/contract sections and prerequisite decisions.
- Acceptance examples, including relevant money, stock, permission and tenant cases.
- Files/subsystems expected to change.
- Checks to run and any environment limitations.

Then implement the smallest complete slice. Do not ask for a separate 'go' when the user already requested implementation. Questions should address genuinely missing business choices or explicit project approval rules. Design-only and review-only requests do not authorise coding.

## UI direction
Phone-first checkout with visible product search/scanning, an accessible cart, clear payment totals and plain-language errors. Retain keyboard/scanner workflows for desktop tills. Show menus/actions by permissions, with the API enforcing access. Use English/Kiswahili support when its task is built; preserve the cart on language changes. Establish reusable MUI theme/layout/form components before repeating screen patterns; detailed styling belongs to the UI task.

## Quality gates
- Tenancy: no cross-business reads/writes, token reuse or unscoped jobs/cache/storage.
- Money/stock: exact calculations, movements for every quantity change, locks, rollback, duplicate-safe retries and refund quantity limits.
- Payments/tills: confirmed M-Pesa allocation, reconciliation and no double counting of cash drops.
- UI: phone layout, keyboard/scanner use, validation, unauthorised access, error/retry state and receipt output where relevant.
- Required tests run and pass; explicitly report unavailable tests instead of marking the task complete.

Backend: Pest. Frontend: Vitest/React Testing Library. End-to-end: Playwright. Commands are in AGENTS.md; run applicable checks once code and dependencies exist. Automated CI configuration is not evidence that remote CI has run.

## Progress and recovery
User-approved reload persistence stores only the device-bound token, expiry, last activity and device UUID in tab-scoped sessionStorage. Restore must fetch `/me` before workspace display and preserve the original inactivity/expiry deadlines. Logout/401/idle expiry must clear remembered credentials; never persist user identity, permissions, PIN/challenge/TOTP secrets or shop data. This supersedes the old reload-sign-out behavior without changing backend auth contracts.

Update contracts alongside changes, decisions/open issues in 07, acceptance checks in 08 and completed tasks/progress in 06. End each task with its result, test evidence and remaining dependency. Preserve unrelated changes; never recommend blindly restoring uncommitted work. Commit only within authorised scope, and review the actual staged files. Deployments and external communications require applicable user authorisation.

## Open design queue
The F01 role matrix and owner 2FA challenge flow are implemented. Existing open choices remain in docs/07-decisions.md: verified onboarding/TOTP recovery, department resource scope, mixed-batch allocation, promotion stacking, return allocations, supplier accounting, supported hardware, margin policy, feature tiers and pilot release cut. Resolve them just before their dependent tasks; this guide does not silently decide them.

Supervisor grants are implemented as five-minute, single-use operation approvals bound to shop/requester/session/device/action/snapshot. Future domain services must validate and lock their resources, compute the authoritative review hash, and consume the grant within their DB transaction. No external provider call belongs inside the retried approval callback. Approval dialogs remain with the real till/discount/refund workflows.

S4a catalogue CRUD/search/base-barcode lookup is implemented independently of receiving. ProductService serialises active plan limits and barcode reservations under a shop lock; quantities/prices are exact and buying prices are permission-filtered. API stock fields explicitly remain unavailable until StockService exists. S5a now connects the preserved product UI to these routes with real search, pagination, forms and archive confirmation. Continue the remaining S4 tables/services after expiry/quarantine policies are selected, then add their receiving/scanning UI. Do not infer implemented packaging, batches or checkout from the catalogue routes/screens.

S4b packaging is available through PackagingService, nested catalogue routes and connected Packagings.tsx. Follow the exact F02 contract, immutable versions and shared barcode ownership; do not create separate pack stock balances. Departments and expiry-dependent StockService remain next.

S4c department catalogue is specified in features/F02-departments.md. Use the existing manage_products permission for writes; keep categories/branches separate, public IDs and tenant composite keys. The user-approved archive guard includes every assigned product, including archived records. Department stock takes/reporting and manager scope require separate contracts before implementation.

S4d is implemented under Products → Stock → Receive stock. Read features/F02-stock-receiving.md and the finalized S4d API contract before extending it. Batch balances/eligibility are authoritative Laravel data; catalogue aggregate totals/filters remain pending. User selected inclusive Tanzania expiry dates and rejection of already-expired deliveries. Preserve receipt operation UUIDs and identical inputs on uncertain retries; do not show success before server confirmation. Pack replacements require refreshing choices and re-entering quantity. Adjustments, thresholds/approval previews, mixed-batch sale allocation and supplier accounting remain separate decisions/slices.

S4e implements exact eligible/held catalogue summaries, low-stock and expiry filters, and read-only reorder/expiry alert APIs. Follow features/F02-stock-summaries.md. StockService owns eligibility and checked aggregate bounds; React renders returned strings/counts and invalidates catalogue queries after receipts. Adjustments, allocation and monetary valuation remain pending.

S4f implements read-only movement queries in StockService, exposed by StockMovementController/ListMovementsRequest/StockMovementResource. StockReceiving hosts the preserved Batches and Movement history tabs; StockHistory uses RTK Query and exact server strings. Receipt writes invalidate history. Use features/F02-stock-history.md; adjustment approval contracts and writes remain separate.

D068 resolves correction policy: every stockkeeper correction needs an owner approval, and online corrections must leave non-negative batch balances. Keep offline sale accept-and-flag unchanged. Specify bound single-use approvals and idempotent adjustment operations in the next slice before coding; do not treat any adjust_stock permission holder as an eligible owner approver.

S4g correction contract: read features/F02-stock-adjustments.md before extending StockService. Preview is authoritative, commit verifies expected balance and stores an immutable operation fingerprint. Stockkeeper approvals use the existing bound single-use service with adjust_stock and owner-only eligibility; consume inside the stock transaction. Reasons use encrypted casts and are omitted from audit properties. Preserve uncertain-operation retries and keep credentials/grants transient. S4g backend/frontend/browser verification is complete; S6 real till lifecycle is implemented, while camera/hardware work remains in S5/S8.

D071 till scope is implemented in TillAccessService and variance grant issuance/consumption. Use the service with freshly locked users and tenant branches in TillService; do not infer that the permission foundation enables a till screen or creates sessions. Managers oversee their assigned branch, cashiers operate their own assigned-branch session and owners view all tenant branches.

S6 connects Tills & Cash to real current/overview/open/paid-out/close-preview/close APIs. Read F04 and the finalized Till API before adding S7: retain actor/device operation fingerprints, shop-level capacity locks, immutable closed snapshots and revision/limit preconditions. TillService::recordCash is an internal transactional hook, with exact type validation and own-till scope; SaleService must use it for net cash receipts and keep stock, cash and sale commits atomic. Refund/debt/drop callers remain with their own features. Never enable POS or M-Pesa through a till-only change.

For frontend additions, compare composition with docs/design/BiziSmart-design-reference.zip and features/F00-design-parity.md. Retain its compact cards, grids, toolbar/tab structure and emerald/slate styling. Extend the live adapter; do not route prototype screens or copy sample business logic.

Grouped sales work (2026-10-10): user requested POS, customers/debt, returns/refunds, real mobile payments and product extras together. Deliver connected, verified domain slices within that scope, beginning with F03-cash-checkout.md. D076 resolves mixed dated/undated allocation; D077 selects Selcom without merchant credentials; D078 resolves proportional/debt-first refund allocation. Do not mark the whole batch complete when cash checkout passes. Customer/credit/payment/refund consumers, receipt delivery and product images/scanning/import/labels/history remain their own acceptance gates.

D080 requires staff-selected unpaid-sale allocations for collections. D081 approves branch-based returns with supervisor approval and own-till cash refunds. Preserve these decisions when specifying DebtService and return review/commit; do not inherit prototype allocations or grant scope. Cash checkout now has passing backend/frontend/desktop/phone verification; the grouped scope remains incomplete.

S9 is connected and verified through features/F05-customers-debt.md. CustomerService stores encrypted tenant contacts and canonical keyed phone lookup; DebtService controls credit/invoice balances and append-only collection/allocation journals. Sales permit optional customer_id/credit_amount for full-credit or cash+credit, and cash tender covers only the cash allocation. Collection review/commit settles explicitly selected unpaid invoices under locks, checks exact sums/ownership/balances and credits the operator's own till atomically. All writes refresh authority and use actor/device-bound immutable UUID replays. Customers.tsx and the POS customer picker render Laravel data; uncertain collection keys/bodies stay in memory through navigation and clear on sign-out. Empty search and full local/canonical Tanzania phone search work. Do not enable mobile collections, loyalty, refunds or financial reports from S9 alone. No customer/cart/receipt data persists in browser storage.

### S15 cash/credit returns and exchange boundary
Use features/F03-returns-exchanges.md and D083 for connected receipt lookup/preview/commit. ReturnService binds branch, exact cumulative original-value refunds, debt-first settlement, batch eligibility and own till revision to supervisor grants. StockService restores good quantities or linked quarantine; DebtService alone reduces balances. SaleExchange links an atomic normal replacement sale funded by the paid refund plus additional tender. Original sales remain immutable. Return operation bodies/grants are memory-only Redux state and clear at sign-out. Retain the POS Refund button, established MUI dialogs and emerald/slate design. Unsupported payment methods fail explicitly. S12 extends this service with proportional external mobile-refund recording under F08; merchant provisioning remains separate.

S12 implements F08's persisted checkout and selected-invoice collection lifecycle, signed callback/query reconciliation, one-use financial allocation and owner external-refund recording. Keep network calls outside retried DB transactions and never repeat an uncertain push. Server recovery owns unfinished orders; memory-only pending bodies clear at logout. Mobile amounts never enter drawer cash. Configure the local/production scheduler and privately provision/verify a Selcom merchant before collecting real money. Receipt mobile refunds are manually recorded with bound approval and external reference. Mobile-value atomic exchanges remain a separate settlement contract.

Local HTTP runtime: `npm run dev:api` starts PHP's loopback-only server through `backend/dev-server.php`, which reuses Laravel's official router. CLI opcode caching is enabled for this process, with zero-second timestamp revalidation so code edits remain visible. This avoids repeated framework compilation and Windows request execution-limit failures; it does not cache permissions or shop data, alter request deadlines or change global PHP configuration. Restart the server after private environment configuration changes. Playwright uses this same command with its testing environment and dedicated database fixture guard.
