# 05 - Complete Supermarket Feature Catalogue

Updated 2026-10-08. One product: BiziSmart supermarket and mini-mart management for Tanzania. Multiple tenant businesses share the software with isolated data. All entries below are planned scope, not implemented functionality. The roadmap stages delivery; detailed contracts are required before coding unspecified workflows.

## Checkout and payments
- Product search and phone-camera barcode scanning.
- USB/Bluetooth keyboard-style scanners, checkout shortcuts and supported thermal printers (F10.3).
- Cash, M-Pesa, split payments and customer credit.
- Hold/resume sales; full/partial refunds with supervisor approvals.
- Receipt-linked returns/exchanges and sellable/damaged disposition (F10.5).
- Customer-facing cart/total display.
- Browser print, thermal, PDF and opt-in WhatsApp receipts.
- M-Pesa confirmation, duplicate-safe callbacks and unmatched-payment reconciliation.

## Products, prices and promotions
- Catalogue, categories, departments and barcodes.
- Carton, pack and single-unit conversions, barcode and price per packaging size (F10.1).
- Manual weights, supported scale integration and weight/price label decoding (F10.2).
- Shelf-price labels, price history and scheduled price changes (F10.7).
- Buy X Get Y, percentage, fixed-price, bulk and time/weekend promotions.
- Approved near-expiry batch markdowns (F10.11).
- Restricted below-cost margin alerts (F10.12).

## Inventory
- Batch tracking, expiry dates where applicable and eligible batch allocation.
- Low-stock/reorder and expiry alerts.
- Reasoned/approved stock adjustments and complete movement history.
- Department stock takes and cycle counts.
- Damage, spoilage and wastage records/reports (F10.4).
- Sellable versus damaged returned-stock handling.
- Barcode-label generation.
- Validated CSV/Excel product/opening-stock import with preview and duplicate-safe commit (F10.6).

## Suppliers and purchasing
- Supplier records, purchase orders and goods-received notes.
- Supplier balances and payment scheduling.
- Last-three-price comparison.
- Supplier returns and credit-note tracking/application (F10.8).

## Tills and loss prevention
- Independent cashier tills, opening float and closing cash count.
- Cash paid-outs and acknowledged cash drops (F10.9).
- Expected/count reconciliation, variance alerts and supervisor approval.
- Manager till status/sales/transaction/void overview.
- Approval and audit for refunds, voids and large discounts.
- Suspicious cashier-activity alerts with specified thresholds.

## Customers, staff and access
- Customer profiles, purchase history, credit limits, debt collection and aging.
- Phone/PIN login, device approval/blocking, owner 2FA and auto-logout.
- Owner, manager, cashier and stockkeeper roles with finalised permissions.
- Employee sales tracking and attendance.
- English/Kiswahili interface, messages and receipts (F10.10).

## Reports and administration
- Daily/weekly/monthly sales, payment splits and top products.
- Gross profit/margins, department stock/profit and inventory valuation.
- Expiry, wastage, debt, till and cash-drop reports.
- Profit/loss, cash flow, expenses with photos and bank reconciliation.
- Tax reporting and CSV/PDF/Excel report exports, staged by the roadmap.
- Auditable actors, devices, IPs and relevant before/after state.
- Separate business subdomains, strict tenant isolation and tenant-aware jobs/storage/cache.
- Super Admin onboarding, plans, subscriptions, payments, feature flags and limits.

## Later improvements to the same product
- Offline selling, local receipts, automatic/manual sync and accept-and-flag conflict review (Phase 3).
- Multi-branch management, inter-branch transfers and consolidated reports.
- Loyalty points/QR cards; commissions/employee leaderboards.
- Demand forecasting, stockout predictions and reorder suggestions.
- Automated owner/customer WhatsApp/SMS alerts, summaries, debt reminders and renewal reminders, with consent/preferences.
- TRA E-Fiscal behind a feature flag, subject to verified requirements.
- Enterprise API access.

## Interpretation
All 12 F10 additions remain approved. No new business type is planned. Exact pilot release cut and plan-tier assignments for added capabilities remain open. Historical material under reference/ is superseded and must not introduce additional scope. Quantity precision, promotion precedence, supported hardware, accounting allocations and new API/schema contracts remain explicit design dependencies.
