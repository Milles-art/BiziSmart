# 01 - Product Overview

## Goal
One affordable mobile-first supermarket and mini-mart management SaaS for Tanzanian SMEs. Multiple supermarket businesses use this single product with isolated staff, stock, sales and subscriptions. Target: 3-5 paying supermarkets using it daily; subsequent work improves this same product.

## Users
Owner (all operations and finances), manager (authorised department/till oversight and approvals), cashier (checkout, customer payments, own till), stockkeeper (products, receiving and counts), and separate Filament Super Admin. The supermarket permission matrix is approved in F01 and implemented; department/till resource scope belongs to the respective features.

## Supermarket features
The complete product inventory, including broader reporting and later features, is in `05-supermarket-feature-catalog.md`.
Shared core: phone/PIN login, device approval, owner 2FA, products/batches, barcode scanning, cash/M-Pesa/split/credit sales, held sales, refunds, till reconciliation, customer debts, receipts, reports, audit history and Super Admin.
Original supermarket features: departments and department reports; multiple tills and manager overview; weighing-scale integration; supervisor loss-prevention controls; promotions; customer-facing display; suspicious-activity alerts; stock takes by department.

All 12 approved additions are in `features/F10-supermarket-expansion.md`:
1. Carton, pack and single-unit stock.
2. Manual weighted sales and scale-label barcodes.
3. Fast checkout hardware and shortcuts.
4. Damage, spoilage and wastage.
5. Receipt-linked returns and exchanges.
6. CSV/Excel opening-stock import.
7. Shelf-price labels and price history/scheduling.
8. Supplier returns and credit notes.
9. Cash drops.
10. English and Kiswahili.
11. Near-expiry batch markdowns.
12. Margin alerts.

## Delivery boundaries
Online-first, incremental delivery. Offline selling remains Phase 3. Multi-branch, loyalty, forecasting, broad WhatsApp automation and TRA E-Fiscal remain later. Approval adds all 12 to scope; exact release sequencing and tier assignments remain undecided.

## Supermarket plans - original PDF baseline
- Free: TSh 0/month; 1 till, 2 users, 200 products, basic POS.
- Basic: TSh 70,000/month; 2 tills, 5 users, departments, full inventory.
- Professional: TSh 150,000/month; 5 tills, 15 users, promotions, loss prevention, analytics.
- Enterprise: TSh 280,000/month; unlimited tills/users, multi-branch, weighing scale, API access.

Enforce limits/features in the API. Do not silently assign the 12 additions to subscription tiers.

## Quality bar
Normal cash checkout under 30 seconds; exact packaging and weight quantities; accountable stock and cash; no cross-tenant data access.
