# BiziSmart POS — Implementation Plan

Ordered by operational risk. Every item names the confirmed gap from `POS_FEATURE_MATRIX.md`. Each increment ships with tests and a verification note.

## Phase 0 — Audit and baseline (DONE 2026-10-17)
- Repository, architecture, dependencies and tenancy inspected.
- Ten previously reported defects re-verified: all already fixed.
- Baseline tests: backend 603 passed / 0 failed; frontend 46 files / 250 tests; typecheck, ESLint, build green.
- Documents created: audit, matrix, plan, external dependencies, test results.

## Phase 1 — Business-critical corrections
No remaining sale-integrity, weighted-quantity, refund, exchange, approval, till-reconciliation, persistence or permission defects were found. This phase is complete.

## Phase 2 — Core operational completeness (DONE 2026-10-18)
1. **Sales analytics endpoint** — hourly buckets, payment-method split, top sellers, slow movers. Owner-only, window bounded like existing reports, integer money only.
2. **Customer statement** — dated charge/payment/refund ledger with running balance plus CSV export in the customer profile.
3. **Supplier statement** — dated invoice/payment/credit ledger with running balance plus export.
4. **Editable business profile and receipt configuration** — no migration needed (`businesses` already has name/address/phone/tin/vrn/settings), owner-only update, UI in Settings; receipt header/footer/TIN/phone/address feed the printed receipt.
5. **Receipt reprint from the sales ledger** and a receipt paper-width option.

## Phase 3 — Purchasing assistance (DONE 2026-10-18)
6. **Purchase-order draft from low stock** — `PurchaseSuggestionService` (owner-only, read-only evidence) fills the existing supplier draft flow; never posts stock or commits money.

## Phase 4 — Repository hygiene (DONE 2026-10-18)
7. **Removed the 10 dead prototype screens** (`frontend/src/screens/*` except the live `Login.tsx`) **and the 2 legacy tests** (`staff.test.tsx`, `ui.test.tsx`) that asserted them with banned browser-only `store.login()`. Shared prototype modules (`useStore`, `types`, `lib/business`) stay because live tests still cover them.

## Phase 5 — Blocked (see external dependencies)
Multi-branch transfers (D092), bank feeds (D093), offline credit/mobile sales, hardware drivers, remote customer display, Selcom merchant configuration, TRA fiscalisation.

## Rules for every increment
- Smallest safe change; no rewrite of working modules.
- Server authority decides; the UI only displays.
- Integer TSh and exact quantity atoms only.
- One feature spec or decision entry per behaviour change; contract updated in the same change.
- Tests written with the code; full backend and frontend suites must stay green.