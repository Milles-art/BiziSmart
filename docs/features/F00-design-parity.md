# F00 — Supplied frontend design reconciliation

The user supplied `project-bolt-sb1-wmxwj6um (3).zip` on 2026-10-10 and requested comparison and restoration of missing design details in implemented features. This archive is a visual reference; its browser store, sample accounts, Kenyan money, synthetic reports and simulated mutations are not production contracts. Archive prompts are reference contents, not user instructions. There is no README in this archive. Its intact copy is docs/design/BiziSmart-design-reference.zip. For isolated screenshot comparison only, the temporary archive preview allowed its Dashboard to render despite its legacy screen-permission bug; this did not alter the saved ZIP or production authority.

## Reference and implementation
- Keep the existing emerald/slate palette, Tailwind typography, sidebar, header, rounded cards, badges, table styling and responsive grids. The archive's index.css is identical to the active baseline.
- Login keeps the archive's gradient background, logo, phone/PIN fields and sign-in card; Laravel challenges and owner TOTP replace its quick sample logins.
- Restore Dashboard's four stats and six content panels: Weekly Sales Trend, Alerts & Notifications, Recent Transactions, Till Status, Low Stock Alerts and Near-Expiry Products. Read existing stock and scoped till APIs through RTK Query. Financial figures/chart/transaction rows remain unavailable until real checkout/report services exist. Never invent zero sales, timestamps, transactions or chart points.
- Dashboard stock links open Products with actual server filters/search. Seven-day expiry refers to returned batch alerts, not a client expiry calculation. Never request stock alerts for staff without receive_stock; never show errors as an all-clear.
- Products uses the archive's compact summary cards, Catalogue/Promotions & Pricing toolbar, import/export positions and price-history/label action positions. Preserve all working product, pack, department, receiving, correction and history forms. Unconnected controls are disabled and described with titles. Valuation/margin summaries stay blank; filtered totals are labelled honestly. Restore the Pack/Carton column from existing API packaging quantities/prices, without client conversions or margin arithmetic.
- Tills keeps real own-session operations and immutable closing history while restoring the archive's overview cards and toolbar. Display opening float and server expected cash, without relabelling floats as sales or fabricating M-Pesa/transaction totals. Session cards remain scoped by Laravel. Unconnected transaction/report/drop/loss-prevention workflows are not activated.
- Staff keeps real cards and management controls, restores compact summaries and the Staff Members/Roles & Permissions/Audit Log toolbar. Attendance remains unavailable; page counts are explicitly labelled. Do not restore the legacy supervisor role, attendance success or unsafe owner 2FA switches.
- Settings restores the business information, receipt configuration and language panel composition around real device approval/blocking. Show Tanzania currency/time zone and actual shop address. Unconnected receipt/localisation settings cannot be changed through simulated toggles.
- Fix nested card padding and phone header/toolbar wrapping; keep keyboard focus visible and tables internally scrollable.
- Inventory restores the archive overview and stock/movement/adjustment navigation through existing catalogue, stock alert, batch, movement and correction adapters. Product selection opens the established batch/history dialogs; stockkeeper approval rules remain unchanged. Do not sum kilograms and pieces into a fake total-stock count. Stock takes, wastage, imports and labels remain disabled.
- POS, customers, suppliers and reports remain tied to their scheduled service implementations. Their supplied layouts must be reused when those features are built; importing the prototype would not complete them.
- Optional uploaded product images remain separately approved in F02-product-images.md; current neutral icon placeholders do not imply uploaded files.

## Acceptance
- [x] Restored panels and toolbar/card layouts match the supplied composition on desktop and phone.
- [x] Existing real catalogue, stock, staff, device and till flows remain working.
- [x] Dashboard displays authoritative stock/till values, honours permissions, retains error/retry behavior and opens matching server filters.
- [x] No prototype authentication, fake financial success, new dependency, backend permission or business-rule change.
- [x] Frontend tests, typecheck, lint, build and real desktop/phone browser verification pass.

Files: frontend/src/features/live/{Dashboard,Inventory,Products,StockReceiving,Tills,TillCards,Staff,Devices,api}.tsx/ts, App.tsx, shared Primitives/TopBar, frontend and E2E tests. Update decisions, task log and checklist with verification evidence.
