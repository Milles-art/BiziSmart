# BiziSmart frontend corrections

This project preserves the supplied screen layout, colours, navigation, product catalogue and reusable components. Corrections focus on the defects identified in the review. It remains a local frontend demo; this revision does not claim that the entire approved supermarket scope is production-ready.

## Run and verify

Use Node.js 20.19+ (or a current Node.js 22 release).

```sh
npm ci
npm run dev
npm test
npm run typecheck
npm run lint
npm run build
npm run preview
```

The existing quick demo login buttons remain available. Browser data is specific to the origin on which the app runs.

## Corrected behavior

- Weighted sales retain kilograms as the quantity, multiply the unit price and cost by that quantity, and deduct the same kilograms from stock. Repeated weighed additions merge into one line. Quantities retain three decimal places and monetary values two.
- Checkout validates available stock, an open till, finite payment values and exact split totals. Cash reporting records the retained sale amount rather than the customer's tendered cash. An already-consumed cart cannot be submitted again through a stale handler.
- Refunds allow item quantities and sellable/damaged conditions. Only selected sellable quantities return to inventory. Returned quantities and accumulated refund amounts are recorded on the original receipt. Amount-only price corrections never restore stock. Remaining quantities, tax and refund limits are validated again during approval.
- All cashier refunds and transaction voids require supervisor approval. Discounts above 10% remain unapplied until approval. Discount approvals are bound to the exact cart; changes require a fresh request. Logging out preserves the pending cart for supervisor review.
- Exchanges are drafts until completion. Completion records the original return and a linked replacement receipt, deducts replacement stock and records the cash difference. Positive differences require cash received; negative differences require enough cash in the active till. Damaged originals do not increase sellable stock. Cashier requests retain a replacement snapshot and execute once when approved.
- Till expected cash equals opening float plus net cash sales, minus every physical cash drop and paid-out. Manager acknowledgement does not subtract a drop again. Refund/void cash reversals are included in net cash sales.
- PDF report export generates PDF documents, including pagination. CSV export escapes commas, quotes and spreadsheet formula prefixes. Both exports follow the selected report period.
- Today, calendar-week, calendar-month and last-seven-day reporting use the current Africa/Nairobi date. Gross profit uses revenue excluding VAT. Old seeded demo transactions retain their historical dates and are not presented as today's sales.
- Products, transactions, inventory, tills, approvals, held sales, customers, suppliers, staff records and carts persist in versioned browser storage. Refresh requires signing in again but preserves business records. Storage failures display a message. Invalid saved top-level data is rejected rather than crashing the UI.

## Frontend stack

React 18 and Vite remain. Business state now lives in Redux Toolkit behind the existing screen-facing store interface. RTK Query supplies the local product query used in reports and invalidates it when products change. MUI provides the theme and shared section-card container without CSSBaseline or a style reset. Recharts renders the existing green weekly sales chart. The Vite PWA plugin generates a Workbox service worker that precaches the application shell and excludes API navigation; financial requests are not cached or replayed. Updates use prompt registration to avoid forcing a cashier session to reload.

## Remaining integration boundaries

M-Pesa is explicitly labelled as a simulation. No real STK push, provider confirmation or money reversal occurs. Backend APIs, authentication, tenant isolation, multi-device synchronisation, physical scale/scanner integration, receipt delivery and the remaining approved features still need implementation and verification.

Exchanges in this correction cover all remaining items on the selected original receipt and ordinary replacement products. Settlement is cash; credit sales must first use a credit refund. Supervisor-approved cash operations settle against the supervisor's active open till. Refunds update the original sale's report totals; a dated accounting event ledger is still needed for cross-period financial reporting. Damaged returned quantities are recorded in the movement reason without increasing sellable stock.

Browser persistence is local demo persistence, not a shared durable database or secure credential store. The seeded phone/PIN accounts remain demo accounts.

## Active workspace update — 2026-10-09

`BiziSmart-fixed.zip` is now the frontend starting point at `frontend/`. Its emerald/slate design, screen layout, navigation and reusable components are retained. The existing Laravel application, MySQL setup and approved supermarket specifications remain at `backend/`, `infrastructure/` and `docs/`. Prior frontend sources are backed up locally under ignored `tmp/pre-fixed-frontend/`; they are not the active interface.

Run workspace commands from the repository root using Node 24.x and npm 11+: `npm ci`, `npm test`, `npm run typecheck`, `npm run lint`, `npm run build`. Start `npm run dev` and `npm run dev:api` in separate terminals. Open `http://127.0.0.1:15173/` or the existing demo-alpha.localhost address. MySQL starts with `./infrastructure/local/mysql.ps1 Start`. Run backend tests with `cd backend` then `php artisan test`; they require the dedicated `_test` MySQL database. Stop preview servers before `npm run test:e2e`; browser fixtures also use only that test database. Do not run backend database resets and browser tests together.

Quick demo login: Owner phone **0711001100**, PIN **1234**; Cashier phone **0711004400**, PIN **4567**. These are synthetic browser-only credentials, not Laravel accounts. Refresh signs out while preserving local demo records. Do not enter real credentials or business data into this prototype.

Owner demo staff cards now support profile editing and confirmed deactivation/reactivation. Validation rejects duplicate canonical phones and invalid new PINs, preserves the last active owner and restricts these changes to owners. Sales history is retained. These controls remain browser-local and are not presented as server staff administration.

The Laravel backend separately exposes owner-only staff list/create/update/deactivate APIs with tenant isolation, canonical phone uniqueness, hashed temporary PINs, forced PIN change, active-staff plan limits, last-owner protection, session revocation and encrypted audit events. The demo screens are not yet wired to these APIs. Backend device approval/blocking and owner TOTP authentication remain implemented independently.

All demo payment methods, checkout and receipts now explicitly say they are simulated. No real STK push, payment confirmation, reversal or cash sale reaches Laravel. The imported prototype retains Kenyan sample labels, decimal arithmetic and legacy demo roles; the approved production contracts remain Tanzania/TSh integer money and the F01 owner/manager/cashier/stockkeeper matrix. Align these adapters before connecting real shop operations; do not treat the demo store as the backend's authority. Its PWA shell does not complete the approved Phase 3 offline-selling feature.

Dependency review found advisories in the imported Vite/Vitest/Tailwind dependency tree. Major upgrades are a separate compatibility task because Tailwind/PWA changes can alter the supplied design. This local demo is not a production-ready release.

Current verification: 92 backend tests / 638 assertions pass on real MySQL. All 21 supplied frontend tests pass, plus five staff-management tests (the additional creation case was run in the focused staff suite). Type checking, ESLint and the production/PWA build pass. All eight updated desktop/mobile Playwright checks pass: preserved demo login/dashboard, staff edit/deactivation/persistence, simulated checkout/M-Pesa wording, and independent real Laravel authentication/logout. Login/dashboard/staff screenshots were visually inspected. Remote CI and real payment/hardware integrations remain unverified.

## Verification

The supplied test suite checks the affected store workflows and rendered POS controls. Type checking, lint and the production build are also checked. Full browser screenshot comparison and real hardware/payment checks were unavailable in the execution environment; colours, existing Tailwind classes and page structure were preserved in source.
