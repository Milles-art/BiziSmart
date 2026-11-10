# BiziSmart POS — System Audit

Date: 2026-10-17 · Commit audited: `74e8c68` · Auditor pass over the whole repository (backend services, routes, migrations, frontend live screens, tests).

## 1. Architecture as built

| Layer | Implementation (verified) |
|---|---|
| Frontend | React 18.3.1 + Vite 6.4.4, Redux Toolkit 2.13 + RTK Query, MUI 9.4, Recharts 3.10, vite-plugin-pwa 0.21 (Workbox 7.4), Dexie 4.4 (encrypted offline vault), jsPDF 4.2 + html2canvas |
| Backend | PHP 8.3+/Laravel API-only, Sanctum Bearer tokens, MySQL 8 InnoDB, spatie/laravel-permission, Filament (platform admin), Laravel Pennant feature flags |
| Tenancy | `BelongsToBusiness` global scope (fails closed), host-subdomain tenant resolution, `ResolveTenant` middleware |
| Money / quantity | Integer TSh (`ReportMath`), exact quantity atoms (`Quantity`, 1000 atoms/kg), S3 contract for packs/cartons |
| Tests | 603 backend (Pest), 46 files / 250 frontend (Vitest + RTL), Playwright e2e |

## 2. Verification of the ten previously reported defects

Every item was located in the current code and checked against tests. **None is present any more.**

| # | Reported defect | Status now | Evidence |
|---|---|---|---|
| 1 | Weighted sale deducts 1 unit | **ALREADY FIXED** | `SaleService.php:125,237-248` converts weight lines to gram atoms; `Quantity.php:11-45`; tests `CashSaleTest.php:64-74` (0.002 kg → 2 atoms) |
| 2 | Exchange clears cart without replacement sale | **ALREADY FIXED** | `ReturnService.php:134-146` → `SaleService::completeReplacement` writes Sale/SaleItem/StockMovement + `SaleExchange`; `SaleReturnTest.php:104-113` |
| 3 | Partial refund restores all items | **ALREADY FIXED** | `ReturnService.php:195-244` allocates only submitted atoms per line; `RefundCalculator.php:36-46`; `SaleReturnTest.php:121-129,151` |
| 4 | Discount applied before approval | **ALREADY FIXED** | Every discount requires approval: `SaleService.php:158-170,321-326`, `SupervisorApprovalService.php:93-124`; `SaleDiscountTest.php`, `pos-discounts-live.test.tsx` |
| 5 | Till close ignores cash drops / paid-outs | **ALREADY FIXED** | `TillService.php:289-324` sums drop/paid-out/refund entries and blocks closure on pending drops; `AccountingTest.php:76-91` |
| 6 | PDF export produces CSV | **ALREADY FIXED** | `receiptOutput.ts:25-39` uses jsPDF `save('*.pdf')`; CSV is only on buttons explicitly labelled "(CSV)"; `receipt-print.test.tsx:41-45` |
| 7 | 7-day chart hardcoded date | **ALREADY FIXED** | `Dashboard.tsx:14-18` derives the window from `Date.now()` in EAT; `dashboard-live.test.tsx:78-92` |
| 8 | Data only in frontend state | **ALREADY FIXED** | Business data via RTK Query (`api.ts`); only approved items persist (device UUID, tab-scoped token, encrypted Dexie vault) |
| 9 | Agreed libraries missing | **SATISFIED** | All installed at the versions listed in §1 |
| 10 | UI-only management screens | **FIXED for live app; residue remains** | All live screens call real endpoints; 10 unrouted prototype files remain in `frontend/src/screens/` and 2 legacy tests still assert them |

## 3. Module status

| Module | Status | Real gap |
|---|---|---|
| 1 Dashboard | PARTIAL | No hourly sales, top sellers, slow movers, payment-method breakdown |
| 2 Sales / Checkout | PARTIAL | Receipt reprint path, paper-width option, printer selection, cash-drawer trigger (hardware) |
| 3 Products | PARTIAL | Bundles and bulk-quantity offers, product variants, supplier link |
| 4 Inventory | PARTIAL (transfers BLOCKED D092) | Weighted-average costing not implemented (per-batch cost + FEFO is the documented policy) |
| 5 Suppliers & Purchasing | PARTIAL | Supplier statement, PO generation from low stock, generated PO numbering |
| 6 Tills & Cash | PARTIAL | Denomination count shipped this session; drawer trigger/printer selection are hardware |
| 7 Customers | PARTIAL | Customer statement (dated ledger + running balance) and statement export |
| 8 Staff & Permissions | PARTIAL | No custom-role/permission editor (fixed role matrix by design) |
| 9 Reports & Analytics | PARTIAL | Payment-method split, top/slow movers, hourly, full-period export |
| 10 Banking & Payments | PARTIAL / BLOCKED | Manual statement + exact matching live; bank feeds BLOCKED D093; Selcom code-complete but unconfigured |
| 11 Offline Sync | PARTIAL / BLOCKED | Signed encrypted cash queue live; offline credit and mobile sales BLOCKED (no signed limit/settlement contract) |
| 12 Settings & Hardware | PARTIAL | Business profile and receipt configuration are read-only; scale label decode only |

## 4. Confirmed missing work (this audit's implementation scope)

1. Sales analytics: hourly buckets, payment-method split, top sellers, slow movers.
2. Customer statement with running balance + export.
3. Supplier statement + export.
4. Editable business profile and receipt configuration.
5. Receipt reprint from the sales ledger; receipt paper width option.
6. Purchase-order draft generated from low-stock items.
7. Removal of the 10 dead prototype screens and their 2 legacy tests.

## 5. Defects found in this audit (to fix)

- `frontend/tests/staff.test.tsx` and `frontend/tests/ui.test.tsx` assert against dead prototype screens, inflating apparent coverage.
- `frontend/src/screens/*` hardcode KSh values, a fake tax number and UI-only toggles (misleading if ever routed).
- `Reports` and `Expenses` had no client-side authority skip for granted non-owners (fixed this session).
- `Dashboard.tsx` and `Suppliers.tsx` rendered a replacement character instead of an em dash for masked till cash (fixed this session).