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
The F01 role matrix and owner 2FA challenge flow are implemented. Existing open choices remain in docs/07-decisions.md: verified onboarding/TOTP recovery, department/till resource scope, mixed-batch allocation, promotion stacking, return allocations, supplier accounting, supported hardware, margin policy, feature tiers and pilot release cut. Resolve them just before their dependent tasks; this guide does not silently decide them.

Supervisor grants are implemented as five-minute, single-use operation approvals bound to shop/requester/session/device/action/snapshot. Future domain services must validate and lock their resources, compute the authoritative review hash, and consume the grant within their DB transaction. No external provider call belongs inside the retried approval callback. Approval dialogs remain with the real till/discount/refund workflows.

S4a catalogue CRUD/search/base-barcode lookup is implemented independently of receiving. ProductService serialises active plan limits and barcode reservations under a shop lock; quantities/prices are exact and buying prices are permission-filtered. API stock fields explicitly remain unavailable until StockService exists. S5a now connects the preserved product UI to these routes with real search, pagination, forms and archive confirmation. Continue the remaining S4 tables/services after expiry/quarantine policies are selected, then add their receiving/scanning UI. Do not infer implemented packaging, batches or checkout from the catalogue routes/screens.

S4b packaging is available through PackagingService, nested catalogue routes and connected Packagings.tsx. Follow the exact F02 contract, immutable versions and shared barcode ownership; do not create separate pack stock balances. Departments and expiry-dependent StockService remain next.
