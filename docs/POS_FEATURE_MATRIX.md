# BiziSmart POS — Feature Completion Matrix

Legend: **COMPLETE** implemented + tested · **PARTIAL** exists, gap named · **MISSING** not implemented · **BLOCKED** needs an external credential, device, provider or owner decision.

## Dashboard
| Feature | State | Note |
|---|---|---|
| Today's sales, gross profit, cash+mobile, low-stock count | COMPLETE | `Dashboard.tsx` |
| Weekly trend chart (dynamic window) | COMPLETE | `ReportService::summary.daily` |
| Alerts: low stock, near expiry, till variance | COMPLETE | shared `StockAlertPanels` |
| Hourly sales | COMPLETE | GET /reports/sales-analytics.hourly (EAT buckets) |
| Top-selling products | COMPLETE | analytics `top_sellers`: exact qty, revenue, cost, profit |
| Slow-moving products | COMPLETE | analytics `slow_movers`: unsold stock at exact batch cost |
| Sales by payment method | COMPLETE | analytics payment-method split incl. refunds |
| Average transaction value, transaction count | PARTIAL | `sale_count` exists; average not surfaced |

## Sales / Checkout
| Feature | State | Note |
|---|---|---|
| USB scanner burst + Enter terminator, camera scan, manual barcode | COMPLETE | `useBarcodeKeyboard.ts`, `CameraScanner.tsx` |
| Exact weighted quantity, packs/cartons, unit switching | COMPLETE | S3 contracts, gram atoms |
| Hold/resume, held drafts with 24h expiry | COMPLETE | `HeldCarts.tsx` |
| Discounts requiring supervisor approval | COMPLETE | no threshold-free bypass |
| Cash / mobile / credit split, change calculation | COMPLETE | server-priced preview |
| Returns, exchanges, refunds with approval | COMPLETE | `ReturnService` |
| Receipt PDF + thermal print | COMPLETE | jsPDF + 72 mm CSS |
| Receipt reprint from ledger | COMPLETE | `ReprintReceipt` in `Reports.tsx` via GET /sales/{sale} |
| Paper width option (80 mm) | COMPLETE | `settings.business.paper_width` (58/80/A4) feeds receipt output |
| Printer selection / cash drawer trigger | BLOCKED | hardware |

## Products
| Feature | State | Note |
|---|---|---|
| CRUD, archive, images, departments, packagings | COMPLETE | |
| Import (stock receipt) / export | COMPLETE | server-owned preview + commit |
| Price history, shelf labels | COMPLETE | |
| Buy X Get Y promotions | COMPLETE | `PricingService` |
| Bundles, bulk-quantity offers | MISSING | only schedule/fixed/percentage/buy_get/markdown |
| Variants, supplier link, min/max stock | MISSING | |

## Inventory
| Feature | State | Note |
|---|---|---|
| Receiving, batches, FEFO allocation, expiry alerts | COMPLETE | |
| Corrections, loss, counts, owner approval | COMPLETE | |
| Movement ledger, history | COMPLETE | |
| Weighted-average costing | MISSING | per-batch cost is the documented policy |
| Multi-branch transfers | BLOCKED | D092 |

## Suppliers & Purchasing
| Feature | State | Note |
|---|---|---|
| Directory, POs, receiving, payables, returns/credits | COMPLETE | preview→commit pipeline |
| Bank reconciliation of supplier invoices | COMPLETE | |
| Reorder recommendation quantities | COMPLETE | `DemandForecastService` |
| PO draft generated from low stock | COMPLETE | `PurchaseSuggestionService` + `PurchaseSuggestions.tsx` fills supplier draft |
| Supplier statement | COMPLETE | GET /suppliers/{supplier}/statement (+ .csv), owner-only |
| Generated PO numbering | MISSING | ULID only |

## Tills & Cash
| Feature | State | Note |
|---|---|---|
| Open / paid-out / blind close, variance approval | COMPLETE | D114 |
| Expected cash incl. drops and paid-outs | COMPLETE | `AccountingTest` |
| Denomination count grid | COMPLETE | this session |
| Cash drops with acknowledgement/reversal | COMPLETE | |
| Drawer trigger / printer selection | BLOCKED | hardware |

## Customers
| Feature | State | Note |
|---|---|---|
| Directory, credit limits, aging, per-invoice collections | COMPLETE | |
| Loyalty, loyalty card, messaging consent | COMPLETE | |
| Loyalty double-award prevention | COMPLETE | `IncentiveService` idempotency |
| Customer statement | COMPLETE | GET /customers/{customer}/statement (+ export), `manage_customers` |
| Walk-in anonymous checkout | COMPLETE | no account required |

## Staff & Permissions
| Feature | State | Note |
|---|---|---|
| Staff CRUD, roles, branch, deactivate/reactivate | COMPLETE | |
| Email enrolment links, PIN reset, device approval | COMPLETE | |
| Attendance, commissions, leaderboard | COMPLETE | |
| Fixed role/permission matrix | COMPLETE (by design) | 4 approved roles |
| Custom role editor | MISSING | would need an owner decision |

## Reports & Analytics
| Feature | State | Note |
|---|---|---|
| Summary, sales ledger, stock valuation | COMPLETE | |
| Departments, till reports, losses, alerts, expenses | COMPLETE | |
| CSV export with formula-injection escaping | COMPLETE | |
| Payment-method split, top/slow movers, hourly | COMPLETE | GET /reports/sales-analytics | |
| Full-period export (not only current page) | PARTIAL | |
| VAT filing / TRA | BLOCKED | D099, disabled |

## Banking & Payments
| Feature | State | Note |
|---|---|---|
| Payment plans, manual statement lines, exact matching | COMPLETE | |
| No fabricated external transfers | COMPLETE | every write reports `external_transfer_executed:false` |
| Automated bank feeds | BLOCKED | D093 |
| Selcom mobile money | BLOCKED (unconfigured) | code complete, credentials needed |

## Offline Sync
| Feature | State | Note |
|---|---|---|
| Lease, encrypted Dexie vault, durable queue, idempotent sync | COMPLETE | |
| Conflict quarantine + owner reconciliation | COMPLETE | |
| Offline cash sales | COMPLETE | never rejected |
| Offline credit / mobile sales | BLOCKED | no signed limit or settlement contract |

## Settings & Hardware
| Feature | State | Note |
|---|---|---|
| Device approval/block, language, scale profile, incentives, pricing | COMPLETE | |
| Business profile editing, receipt configuration | COMPLETE | owner-only PUT /settings/business; header/footer/TIN/paper width feed receipts |
| Customer display | PARTIAL | same-device popout; remote pairing BLOCKED |
| TRA fiscalisation | BLOCKED | D099 / F22 discovery only |

## Cross-cutting
| Feature | State | Note |
|---|---|---|
| Tenant isolation, permission enforcement on the server | COMPLETE | scope fails closed |
| Money as integer TSh, exact quantities | COMPLETE | |
| Idempotent writes, immutable operation UUIDs | COMPLETE | |
| Document numbering for sales receipts | COMPLETE | business-wide sequence |
| Document numbering for refunds/exchanges/transfers | MISSING | |
| Backup / restore, shared-hosting cron | COMPLETE | D096 |
| Enterprise read-only API | COMPLETE | F21/D091 |