
## Baseline and increment results (2026-10-17)

| Check | Command | Result |
|---|---|---|
| Backend full suite (baseline at `74e8c68`) | `php artisan test` | 603 passed / 0 failed (5935 assertions, 582s) |
| Frontend full suite (baseline) | `npm run test --workspace frontend` | 46 files / 250 tests passed |
| Typecheck / ESLint / build (baseline) | `npm run typecheck`, `npm run lint`, `npm run build` | clean |
| Increment 1 — sales analytics backend | `php artisan test tests/Feature/SalesAnalyticsTest.php` | 5 passed / 82 assertions (8.6s) |
| Increment 1 — sales analytics frontend | `npm run test --workspace frontend -- reports-analytics` | 3 passed |
| Backend full suite after increment 1 | `php artisan test` | 608 passed / 0 failed (6018 assertions, 666s) |
| Frontend full suite after increment 1 | `npm run test --workspace frontend` | 47 files / 253 tests passed |
| Typecheck / ESLint after increment 1 | `npm run typecheck`, `npm run lint` | clean |

### What the new analytics tests prove
- Payment split comes from saved `sale_payments` rows: cash 25,500 / mobile 0 / credit 0 for a single cash sale.
- A sale paid with both cash and credit is counted once per allocation (34,000 total, not double counted).
- A refunded line reverses its own quantity, revenue and cost inside the ranking, using the same cumulative proportion rule as the profit report, and lands in the payment-method refund column (cash 42,500 sales / 8,500 refunded / 34,000 net).
- Hourly buckets use Africa/Dar_es_Salaam event time (a 09:00 UTC fixture lands in hour 12).
- Slow movers list only stock that recorded no sale in the window, valued at exact batch cost.
- An empty window returns empty arrays and zero totals instead of fabricated values.
- The report is owner-only (cashier 403), tenant-scoped (a beta token cannot read alpha), and rejects inverted or malformed dates with 422.

### Not covered by automated tests here
- Physical hardware (printers, drawers, scales), Selcom settlement with real credentials, TRA fiscalisation, bank feeds, multi-branch transfers — all BLOCKED in `docs/POS_EXTERNAL_DEPENDENCIES.md`.
- Playwright e2e suite was not re-run in this pass; it requires the local hosts entry for `*.localhost`.