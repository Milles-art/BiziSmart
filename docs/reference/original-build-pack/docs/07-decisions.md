> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 07 — Decisions and Assumptions

Add a line whenever a choice is made or an assumption is used. Mark assumptions to confirm.

| ID | Decision | Why | Status |
|---|---|---|---|
| D001 | React frontend + Laravel JSON API | Owner chose React; Laravel keeps rules in one tested place | Decided |
| D002 | MySQL 8, not PostgreSQL | Familiar; no RLS so isolation is app-level + tests | Decided (upgrade path: Postgres + RLS) |
| D003 | Tenant isolation via `BelongsToBusiness` global scope, fail-closed, CI leak tests | MySQL has no RLS | Decided |
| D004 | Online-first launch; offline selling in Phase 3 | Faster to first paying clients | Decided |
| D005 | Money = integer TSh | Avoid float errors | Decided |
| D006 | `stock_movements` ledger for every stock change | Auditability, debugging stock errors | Decided |
| D007 | Prices are VAT-inclusive; VAT computed on total | Typical retail pricing | **Assumption: confirm with accountant; confirm 18% rate** |
| D008 | Sanctum Bearer device tokens (30-day expiry, rotation) | SPA + offline use | Decided |
| D009 | One vertical at a time, exit gate before next | Solo builder focus | Decided |
| D010 | Batch is sellable through its expiry date (inclusive) | Common practice in pharmacies | **Assumption: confirm with a pharmacist** |
| D011 | Offline sales accepted and flagged, never rejected | Goods already left the shop | Decided |
| D012 | Build with Codex/ChatGPT guided by AGENTS.md + docs | Owner's workflow | Decided |
| D013 | PIN lockout: 5 attempts, 15 minutes | Reasonable default | Assumption |
| D014 | Till variance limit TSh 5,000 (per-business setting) | From original design | Decided |
| D015 | Filament only for Super Admin; tenant UI is React | Avoid building a second React app | Decided |
| D016 | TRA E-Fiscal behind a feature flag, later | Approval timeline unknown | Open: ask TRA early |
| D017 | WhatsApp customer receipts opt-in; owner alerts first | Meta charges per message | Decided; check pricing |
