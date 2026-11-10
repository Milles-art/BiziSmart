# Project bug scan — 2026-10-19

Review of the current working tree, including existing uncommitted changes. This is a scan, not a claim that every possible execution path is bug-free. Application code, configuration and operational records were not changed as part of this review.

## Findings

Follow-up implementation: all four findings below now have local fixes and regression coverage. Focused verification passed: 25 backend tests / 386 assertions (including real MySQL sale races and tenant/receipt isolation) and 20 frontend tests. Logs: `tmp/bugfix-backend.log`, `tmp/bugfix-frontend-focused-final.log`. Full frontend verification and production packaging are underway. Deployment is pending replacement SSH-key authorization; these are not yet live fixes. The findings below preserve the original review evidence.

### 1. P2: Archiving a returned product breaks sales analytics

Location: `backend/app/Services/SalesAnalyticsService.php`, the returned-line product lookup in `build()`.

The normal route-binding lookup excludes soft-deleted products. Its fallback includes them, but compares `products.public_id` against the complete `prod_...` API identifier. `HasPublicId` stores the bare ULID and adds/removes the prefix at the API boundary. Consequently the fallback cannot match an archived product and `firstOrFail()` aborts the entire report with 404.

Reproduction: sell a product, commit an approved return, archive the product, then request sales analytics for the return's period. Historical return rows must remain reportable after catalogue archival. Use `resolveRouteBindingQuery(Product::withTrashed(), $line['product_id'])` for the historical lookup, preserving the tenant scope, and cover this sequence with a regression test.

### 2. P2: Owner sales-ledger reprints call an API the owner cannot use

Locations: `frontend/src/features/live/Reports.tsx`, `ReprintReceipt`; `backend/app/Services/SaleService.php`, `read()`.

The owner-only sales ledger renders Reprint for all sales. The component calls `GET /sales/{sale}`, whose service requires both the original cashier and original device. An owner viewing a cashier's sale therefore receives 403, and the component offers Try again against the same forbidden endpoint. The same failure occurs for the owner's own receipt from another device.

Reproduction: commit a sale as a cashier, sign in as owner, open Reports → Sales ledger and select Reprint. Resolve the receipt-access contract explicitly: either expose an appropriately owner-authorized receipt read with tests or limit the action to accessible receipts. Do not remove the existing permission check merely to make the button succeed.

### 3. P2: Customer statement display and CSV silently omit entries after the first 50

Locations: `frontend/src/features/live/Customers.tsx`, `CustomerStatementDialog`; `frontend/src/features/live/api.ts`, `customerStatement`; `backend/app/Services/CustomerStatementService.php`, pagination and totals.

The query accepts only the customer ID and requests the default first page. The service defaults to 50 entries, while its totals and closing balance cover all matching movements. The dialog has no pagination controls, and Export statement (CSV) serializes only that first page instead of calling the existing `/statement/export` endpoint. The downloaded ledger can therefore disagree with the full-ledger totals printed beneath it.

Reproduction: create more than 50 credit/collection/return movements for one customer, open the statement and export CSV. Add server-backed pagination to the display and use the bounded full-export endpoint for the CSV; test a multi-page ledger, totals and the last exported entry.

### 4. P2: Staff Reset PIN remains visible for email/password accounts

Locations: `frontend/src/features/live/Staff.tsx`, staff-card actions and `ResetPinDialog`; `backend/app/Services/StaffAuthService.php`, `resetPin()`; `backend/app/Http/Requests/Auth/ResetPinRequest.php`.

Every active staff card exposes Reset PIN, including `email_login_enabled` accounts. The backend correctly rejects these accounts with 409 `EMAIL_PASSWORD_REQUIRED`. The dialog also advertises and accepts 4–10 digits, while the request accepts only 4–6 digits, so 7–10 digit PINs fail with 422 even for legacy accounts.

Reproduction: open Reset PIN for an email-enrolled staff member; any valid request is rejected. For a legacy account, enter matching seven-digit PINs; the client submits them and server validation rejects them. Hide legacy PIN controls for email accounts, direct them to password reset, and align the remaining legacy validation with the server. Preserve the backend prohibition on PIN resets for email-enrolled accounts.

## Verification

- PHP syntax: all 413 PHP files under application, routes, migrations and infrastructure passed `php -l`.
- API wiring: all 184 registered API routes reference existing public controller methods. Evidence: `tmp/scan-route-actions.log`. This checks action wiring, not endpoint authorization or response correctness.
- Backend unit suite: 148 tests / 926 assertions passed, 49.30 seconds. Evidence: `tmp/scan-backend-unit.log`. This does not establish database transaction or tenant-isolation acceptance.
- TypeScript: exit 0 on the follow-up run (`tmp/scan-typecheck-verified.log`). Initial ESLint completed without reported errors (`tmp/scan-lint.log`).
- Production build/PWA: passed; Vite warned about a bundle over 500 kB. Evidence: `tmp/scan-build.log`.
- Production npm audit: zero reported vulnerabilities. Evidence: `tmp/scan-npm-audit.json`.
- Composer locked audit: unavailable because Packagist's advisory request timed out; no clean audit claim. Evidence: `tmp/scan-composer-audit.json`.
- Initial frontend run: 177 passed, 1 failed, plus 9 worker-start errors. The assertion failure involved the offline unknown-lease message. Worker timeouts prevent treating this run as reliable full regression evidence. Evidence: `tmp/scan-frontend.log`.
- Initial backend run: 174 passed, 449 failed. Many failures report tables already existing or missing while migrations run against the shared `bizismart_test` schema. These are not 449 confirmed application bugs. Evidence: `tmp/scan-backend.log`.
- Follow-up verification: a single-fork frontend run stalled without a result and was stopped. A single-thread run was also stopped without a completed test summary. Neither is a passing run. Logs: `tmp/scan-frontend-sequential.log`, `tmp/scan-frontend-threads.log`.
- A separately provisioned `bizismart_scan_test` schema avoided the shared database. The focused archived-return and owner-reprint reproduction attempt remained in schema setup and was stopped before assertions ran. Its diagnostic test is retained outside the normal suite at `tmp/ScanSalesReproTest.php`, with output in `tmp/scan-reproductions.log`. Findings above are established from the current code paths, not claimed as completed runtime reproductions. The disposable scan schema is retained; no operational database was restored or intentionally reset.

Browser E2E, hosted services, physical hardware, live mail and provider settlement are not verified by this scan. TRA/provider work remains paused.

Release preparation update: production build including PWA completed successfully (`tmp/bugfix-build.log`), with the existing >500kB chunk warning. Final TypeScript check exited0 (`tmp/bugfix-typecheck-final.log`). Frontend candidate ZIP `tmp/BiziSmart-scan-fixes-20261019.zip` has16 verified entries,694172bytes, SHA256 `63b6e58a6488c2fbebe276823fa79e8fe10ee591972eb36754a9c55478b5c8a0`; it is not deployed. Full frontend suite remains running in `tmp/bugfix-frontend-all.log`. Do not upload this candidate before comparing live backend compatibility: unrelated pre-existing working-tree changes are present. New SSH identity is configured only in the dedicated local deployment config; pinned host checks preserved. User must import and authorize the replacement public key before reconnecting.

Final frontend evidence (2026-10-19): full run finished with252 passing tests in48 files plus one worker-start timeout for offline-pos (`tmp/bugfix-frontend-all.log`); exit1 means the monolithic run is not green. Isolated offline-pos rerun passed4/4 in4.48s (`tmp/bugfix-offline-pos-retry.log`). Thus all256 assertions-as-tests across49 files passed across the two runs, with the environment startup limitation explicitly retained. Focused backend25/25 remains green. Build/PWA and final typecheck pass. No new hosted/browser acceptance is claimed. Live deployment remains blocked on the user's replacement public-key authorization and subsequent live compatibility inspection.

## 2026-10-20 manual cPanel handoff (user requested ZIP instead of deployment)

Replacement SSH authorization now works. Current live source was compared with local source after normalizing line endings: 44 existing PHP files differ and32 new application/migration files are required; generated bootstrap caches were excluded. No existing migration requires replacement. Composer lock matches the host. Hosted preflight found one active Fresh Choice owner,2 active staff,247 products,0 sales,1 open till, no email credential schema, and log mail transport. Fresh encrypted/signed backup with verified offsite bytes: backup-20261020-063049-6767f81d.cms, SHA25680fa8eeb61bbbfbe2ae7731998b4c5444a9c3acd6509dae115aa792684638d44. Private source snapshots and staging uploads were made; application files, schema, accounts and public assets were NOT replaced.

Fresh full backend verification passed625 tests/6296 assertions in713.49s (`tmp/deploy-backend-full-20261020.log`) against isolated bizismart_scan_test. The user then chose to upload the update themselves. Combined archive `outputs/deployment/BiziSmart-cPanel-update-20261020.zip`:814738bytes,92 application files plus2 private handoff/manifest files, SHA25684951508adfdebb4a26f9cf55a25bfc8d234d801d987e1b0d9b7556d3055a304. Every archived application file was checked against its verified source hash; archive CRC and secret/data/path exclusions passed. Prefixes are bizismart/backend and bizismart.com; extraction target is /home/afyabgzo. It excludes .env, credentials, vendor, storage/data and existing migrations. Instructions include maintenance, backups, additive migrations42/43, cache refresh, owner-chosen email activation and unconfigured reset-mail limitation. If cPanel has no Terminal, upload first and ask for the already-authorized SSH completion before extracting. No private activation token has been issued. No new hosted/browser E2E acceptance is claimed.
