# 08 — Test Checklist (required)

Write these tests as you build the related feature. CI must be green before merging.

## D109 email/password-only authentication (verification in progress)

- User approved no authenticator for owner/staff/admin,12+character passwords and support@bizismart.com sender. No new packages or live account assignment. Contract features/F01-email-password.md and rollout20.
- Guarded local migrations42/43 applied. Full frontend242/242 across45files passes215.66s (`tmp/d109-frontend-complete.log`), sequential typecheck/lint/build chain finishing. Focused staff/session12 and email/refund/offline17 pass. PDF test preloads actual jsPDF before fake timers, retaining actual byte assertions and original deadline.
- Full backend589pass/1independent child60s timeout,5781assertions/1299.10s. Focused duplicate-sale race plus full email auth18/18,135assertions/40.89s pass afterward. Initial earlier DB startup attempt lost a child process and is not passing evidence. Foreground MySQL and sequential DB suites are required. Do not present initial full run as green; browser acceptance and final isolated checks still pending.
- Email tests cover case-insensitive tenant identity, active/lockout rules, device approval, no-MFA owner till/approval access, old PIN refusal,12character/72-byte validation, private existing-user activation binding/replay/expiry, deleted-email reservation, real-mail readiness, SMTP failure, reset binding/expiry/replay, session/lease/grant/link revocation. Admin tests cover historical MFA removal, inactive denial, profile/current-password checks and separate reset broker.
- Browser fixtures/specs now use synthetic email/password under dedicated *_test only, preserve financial assertions, and exercise private-link routing with an already signed-in session. Current E2E run is not yet claimed passing.
- Read-only host mail probe found sendmail executable, no support mailbox at expected path. No provider send/inbox delivery or authentication deployment is claimed yet. Public-source CI remains unapproved and the D108 source snapshot is stale.

## D108 real backup/recovery and final launch checks (2026-10-16)

- Final source-complete snapshot `backup-20261016-131257-6dfcdfea.cms`:95tables/243rows,2555197bytes, SHA256 `f5c593f88ef6d38f4815f6d5e3f110f012c3b520474f9a197cbc08b8dab8889d`; all five Laravel startup archive members verified before signed encryption, R2 read-back hash passes (`tmp/d108-final-backup-result.json`). Full DB recovery below applies to the earlier239-row snapshot. No second latest-snapshot restore is claimed; four additional framework runtime/cache rows are not business edits.

- User authorized remaining launch checks with real test information. Products/costs/opening-stock configuration stays deferred to owner training; TRA paused. No live restore, account reset, sales/stock writes or messages to unspecified recipients.
- Actual production/S3/R2 profile verified, debug disabled, decryption private key absent on server (`tmp/d108-production-verification.json`). New private deployed helper SHA256 hashes match reviewed local source. Existing application/public bundle unchanged.
- Existing real product photo copied to a fresh tenant verification key, uploaded, read/hash-checked, deleted and confirmed absent (`tmp/d108-r2-roundtrip.json`). Original product/object unchanged; private cleanup ledger complete. This is real storage I/O, not a signed-in browser product-edit flow.
- Crypto7checks pass on both host OpenSSL1.1.1k and local OpenSSL3.5.7 (`tmp/d108-{host-crypto,crypto}-tests.log`): binary/Unicode, wrong recipient key, wrong trusted signer, altered/truncated input, overwrite refusal and failed-output cleanup. CMS/GCM host probe failed; actual deployed format is signed CMS over RSA-OAEP/AES256CBC with pinned signature verification before decryption. No fallback to unauthenticated decryption.
- Actual95-table/239-row consistent dump, original application environment/key and code/build packed, encrypted/signed and hashed after R2 upload. Initial archive2551469bytes, SHA256 `ccfb7446369260813ad157e3a95effc5e5cd32d462e1d38ee0e6b6f10aa72cfe` (`tmp/d108-backup-result.json`). Downloaded encrypted hash matches, keys outside Git; runbook19 records custody.
- Full isolated local recovery passes95tables/239rows, exact table/count match,219foreign-key checks, existing owner ciphertext decrypts with recovered APP_KEY, own disposable DB and plaintext staging removed (`tmp/d108-live-recovery-verified.json`). Earlier guard failure/native3105 attempts do not pass. The local option file is copied into protected staging; no ACL guard bypass. MariaDB stored-generated till values are checked against original status/cashier and converted only to DEFAULT in separate MySQL recovery SQL. Original signed SQL/money/quantity/string literals retained. Compatibility8checks pass (`tmp/d108-normalize-tests.log`). Windows native helper21assertions pass including numeric diagnostic/no sensitive SQL output (`tmp/d108-helper-tests-final.log`). No native MariaDB restore claimed; unused prepared plaintext and this task's incomplete portable-runtime download removed.
- Actual cron backup at12:32:16UTC produced `backup-20261016-123215-d6a513b6.cms`, same size/tables/rows, SHA256 `c5436a0fb95954d74da764e110e786cb1b503bac7da31224aeeb066b1ef6f865`, R2 read-back hash verified. Cron-only log351bytes proves automatic execution. Monitor automatic12:34:31UTC healthy/all flags, own log602bytes (`tmp/d108-final-host-status.json`). Daily backup final schedule20:17server-local time, monitor4-59/5, original runtime preserved: three total entries, no minute scheduler/daemon/global TZ changes (`tmp/d108-final-operational-cron.json`). Private rollback crontab in19. Current Tanzania conversion03:17 can shift with server DST. Status/logs are private; external notifications and deletion/retention policy not configured.
- Full backend **549/549,5511assertions,534.62s**, guarded MySQL *_test with reuse enabled (`tmp/d108-backend-final.log`), after recovery finished; no concurrent *_test suite. Full frontend **229/229 in43files,219.32s**, one worker, final typecheck/lint and production/PWA build pass14entries/2214.85KiB (`tmp/d108-{frontend,typecheck,lint,build}-final.log`). Pre-existing chunk warning remains non-failing. Prior50 browser cases remain the D105 result on unchanged application source; not presented as a new D108 browser run.
- npm production audit0vulnerabilities; Composer locked audit0advisories/abandoned (`tmp/d108-{npm,composer}-audit.json`). Git whitespace check passes. No dependency update/new package.
- Remaining external gates: remote CI awaits explicit public-source publication choice (existing repository verified public; reviewed source-only snapshot prepared, no push/PR); Meta/Beem credentials absent (account-readiness question pending), actual chosen-recipient delivery not performed; only virtual Windows printers detected/no scanner/Xprinter attached; physical acceptance pending. Safeguarded independent decryption-key copy and chosen retention policy remain operator responsibilities. Nothing pending is simulated as success.

## D107 Windows preparation and private image read (2026-10-16)

- Focused `product-images-scanner.test.tsx` and `receipt-print.test.tsx`:13/13 pass in66.41s, one worker (`tmp/d107-device-tests.log`). Uses existing tests/current app source. Keyboard burst/leading zero capture, editable-field/locked-POS protection, private image retry/config state, camera teardown, saved exact money/escaping, PDF pagination and isolated print preview pass. Software tests do not certify physical USB/LAN drivers or actual print quality.
- Actual existing Fresh Choice image read through configured R2 storage succeeds; stream byte count, decoded dimensions and MIME match saved metadata (`tmp/d107-storage-read.json`). Product query used TenantContext/current scoped model; path prefix checked before storage access. Probe bounded to40s and at most image byte limit+1. No exported object/path/credentials, fresh upload/delete or product/stock/account writes. Fresh upload and authenticated browser image flow remain open.
- Unsigned actual HTTPS image route returns401 and private/no-store (`tmp/d107-private-image-http.json`), no transport error, no credentials submitted. This verifies that route's authentication gate, not a live cross-tenant security test.
- Subsequent automatic runtime at11:10:15UTC:7completed/0failed. No duplicate cron, app/bundle change, full-suite rerun or new deployment in D107. User/supplier Xprinter driver/USB/LAN facts are recorded in18; exact hardware compatibility remains unverified.

## D106 public browser and bounded runtime follow-up (2026-10-16)

- Fresh hosted guest Chrome checks pass desktop and Pixel7 with normal30000ms first-load timeout: Sign In/phone/PIN/button visible, no script errors or horizontal overflow; initial loads7075ms and20882ms. Log `tmp/d106-hosted-browser.log`; actual `tmp/d105-hosted-login-{desktop,mobile}.png` screenshots inspected. No login/sale/provider call performed by this diagnostic. Earlier interrupted-download failures are not current passing evidence; no transport configuration was changed.
- Hosted shared cache/limiter/session/queue and proc_open/timeout verified read-only; wrapper and three runner source hashes match local tested implementation. Initial crontab had zero active entries, no prior runtime cache status. WhatsApp token/phone ID and Beem key/secret/sender absent; no secret values printed.
- Manual bounded run passes7units/0failed-or-timeout (`tmp/d106-runtime-manual.log`). New cron installer syntax/hash checks and live profile/recent-run/messaging preflight pass; installed one five-minute tasks entry and verified exact resulting crontab (`tmp/d106-install-cron.log`). Prior crontab retained privately at `/home/afyabgzo/bizismart/backups/cron-before-20261016-110353-wXzZ4W`. Automatic execution confirmed at11:05:35UTC, after installation:7completed/0failed in the cache status plus successful cron-created log (`tmp/d106-cron-shop-observation.json`, `tmp/d106-cron-log-observation.txt`). Earlier11:02 manual result is separate. This establishes one automatic pass, not delivery, ongoing uptime, backlog or capacity acceptance.
- Fresh Choice counts were queried through TenantContext and scoped models only:1active product,1image metadata,0stock batches,1open till. No catalogue/stock/money/account writes. Image metadata is not current R2 read/upload evidence; an open till does not identify the operator or certify their workflow.
- Windows scanner/printer setup guide18 prepared from actual keyboard-capture/receipt code. Exact models, drivers and physical tests remain open. No app source/bundle change; prior229/50 evidence is retained rather than rerun without source changes.

## D105 all-screen design deployed (2026-10-16; public guest acceptance completed by D106)

- Final frontend229/229 in43files passes with one worker (185.21s, `tmp/d105-frontend-final.log`); typecheck and ESLint pass. Includes a phone cart quantity-preservation check, keyboard tab navigation, staff permissions/form access, exact frozen banking retries, and failed-read recovery/lazy comparison loading. First review caught a dialog accessible-name issue and a worker-start timeout; these are not cited as passing evidence. No timeout or financial assertion was reduced.
- Complete current-source production build passes, including PWA14entries/2214.85KiB (`tmp/d105-build-final.log`); existing chunk warning remains non-failing. Local Blade view compilation passes (`tmp/d105-view-cache.log`). No new backend suite is claimed for this frontend-only change.
- Public-only ZIP `tmp/BiziSmart-all-pages-design-20261016.zip`:678517bytes,16entries, SHA256 `9f1e4b0b09b5fc86b18b7baa40f9fa84a0deb0988727e768401f3726a01fa88b`. Every decompressed entry matches its build source. Entry HTML uses index-B7dB370n.js/index-CCUOS2V2.css. Archive selection excludes PHP/env/credentials/test photos. Installed release `design-20261016-060327` under the shared public root;16 staged and16 installed hashes pass, gateway.php/.htaccess unchanged. Private rollback `/home/afyabgzo/bizismart/backups/design-20261016-060327-public.tar.gz` plus SHA256 retained (`tmp/d105-deploy.log`). First attempt stopped before public writes because Windows CRLF polluted checksum filenames; package helper now emits LF checksums, verified ZIP unchanged (`tmp/d105-deploy-first.log`).
- The initial browser run was stopped after layout and helper fixes because its already-loaded test helpers were stale. Findings included unnecessary banking read concurrency, focus-sentinel desktop layout, phone cart backdrop stacking, separated supplier record selectors, report combobox selection and waiting for completed MFA/till readiness. Assertions now include actual phone pointer-close, Escape/focus, desktop cart placement and exact received/unpaid amounts. Fresh complete50/50 Chrome/shared-runtime regression passes in13.4m (`tmp/d105-e2e-clean.log`), including both device sizes and platform MFA/onboarding. Final desktop/phone screenshots inspected, including the cart beside the catalogue, untinted phone cart above its backdrop, compact date fields, inventory/tills and record/settings screens. Prior D104 evidence is not substituted for this batch.
- SSH/SFTP key authentication and host identity are verified. Deployment helper syntax and uploaded bytes match local SHA256 `137b6ec506bd89fcec8bc5042e176894dd04b7d99f9e29fbef8dd0e8ab075b50`. Post-installation real HTTPS GETs from the hosting connection verify32 decoded public responses against build hashes (all16 files on each shop domain), plus health/readiness on both domains (database/migrations/cache true): `tmp/d105-hosted-https-server.log`. No backend/database/account/provider changes in this release.
- Fresh external guest-browser acceptance is unresolved. This desktop's downloads stall/terminate; the final120s diagnostic recorded main-JS HTTP200 followed by `net::ERR_HTTP2_PROTOCOL_ERROR`, with no rendered Sign In heading (`tmp/d105-hosted-browser-slow-link.log`). HTTP200 alone is not a completed download. No successful hosted browser screenshots or authenticated acceptance claimed. The optional slow-link timeout affects only the read-only diagnostic, not the50-case suite or application/API deadlines. Hosted gzip was verified active without configuration changes. User phone connection check requested; do not clear browser/offline data to force a PWA update.
- A separate HTTP/1.1 probe also received200/gzip but timed out after45s at153472 of181532 encoded bytes. The issue is not proven specific to HTTP/2, and its root cause remains unconfirmed. Response headers retained in `tmp/d105-http1-headers.txt`; partial transport output is not accepted as a valid asset. No transport/server configuration change made.

## D104 square product cards (2026-10-16)

- Focused product-cards, products-live, product-images-api and product-images-scanner:45/45 pass (60.96s). Covers exact server quantities, retained table workflows, owner/seller cost/held-stock restrictions, menu archive confirmation/cancel, view/filter preservation, missing-vs-zero stock, private image API/retries and scanner behavior. Typecheck and ESLint pass.
- First full frontend run222pass/3fail: existing PDF import and offline capture timed out at5000ms while browser/fixture work was running; the following offline recovery assertion also failed. Assertions remain unchanged. Final full run with `--maxWorkers=1`:225/225 in41files,164.54s (`tmp/product-cards-full-tests-final.log`). Final typecheck and ESLint pass.
- Real browser verification checks square dimensions/no internal overflow, four desktop columns, phone width, keyboard menu Escape/focus, details, StockService batch reads, list/grid filters and edit. Existing real catalogue/stock/import/label journey is retained via explicit List view. First selected browser run:8pass/2fail in6.1m; all8 desktop/phone real owner setup, departments/packs, receiving/corrections and price-history/labels/atomic-import checkpoints passed. The2 new card cases counted the table header row; selector now scopes to tbody without changing the expected one server-filtered product. A visual phone review also exposed squeezed category/department controls; corrected to a readable half-width row and added minimum-width assertions. Final card-only run2/2 passed in2.6m (`tmp/product-cards-e2e-final.log`), shared database session/cache/queue profile, installed Chrome. All10 selected distinct cases pass across those runs; not one uninterrupted10/10 run.
- Final desktop1536 and Pixel7 screenshots `tmp/product-cards-{desktop,mobile}.png` inspected against the saved approved reference. Fixture products have no uploaded photos, so screenshot uses honest placeholders. Live R2 upload/read remains unverified. No hosted products/stock/prices, credentials, backend rules or migrations changed for this task. No new backend suite is claimed for this frontend-only change; D103's preceding tenant/security evidence remains separate.
- Final production build succeeds:3406modules; PWA14precache entries/2180.74KiB. Existing >500kB chunk warning is non-failing. Public-only16-file update ZIP670071bytes, SHA256 `79F02007079854B6E308F35D6B9335B8EFE7A4389643339CE36BCBCB11CA0BC1`; every decompressed entry matches its compiled source hash. Selection excludes backend/env/PHP/secrets/mockup/test images and preserves hosted gateway/htaccess. Manifest in `tmp/product-cards-update-manifest.json`; git diff --check passes. Hosted deployment remains pending.

## D103 initial owner branch repair (2026-10-16)

- Focused OwnerSetupTest, RepairOwnerBranchTest, StaffManagementTest, TillTest and PlatformMessagingTest:46 passed/293 assertions,82.67s. Includes actual setup→TOTP→current till→open with a configured plan, tenant branch isolation, no guess with zero/multiple branches, existing scaffold handling, atomic cash journal and MySQL capacity/race checks.
- Final RepairOwnerBranchTest+TenancyTest:44 passed/646 assertions,21.63s. Includes later no-op preservation of an existing assignment/session, dry-run no mutation, fresh assignment/session revocation/PIN+TOTP preservation, audit once, repeat no-op, ambiguous owners/branches, inactive/missing shop, foreign branch isolation and open-till refusal. These runs overlap five repair tests; do not sum them as distinct tests.
- PHP syntax checks pass for both packaged files; git diff --check has no whitespace errors (existing CRLF normalization warnings). Two-entry private ZIP decompressed bytes verified against source; no migrations/dependencies/frontend changes.
- Platform desktop/mobile browser onboarding2/2 passed in3.6m, installed Chrome and shared-hosting database session/cache/queue profile. Real admin plan/client creation→new owner setup→MFA now asserts Main and enabled Open till, with no missing-branch warning. Log: `tmp/owner-branch-e2e.log`. Hosted repair and actual owner re-login/opening float still pending. No full backend/frontend rerun claimed for this narrow backend correction.

## S0 foundation verification (2026-10-08)
- [x] Pest: health envelope, request IDs, validation, authentication/permission/method errors, rate-limit headers, domain errors and hidden internal details (10 tests, 41 assertions).
- [x] Vitest: connected state, retry after failure and unknown-route navigation (3 tests).
- [x] Frontend production build succeeds.
- [x] Composer strict validation and installed platform requirements pass locally.
- [x] Playwright desktop/mobile connection, retry, navigation and viewport checks pass (6 tests, installed Chrome). Initial startup hit a port conflict and cold Vite navigation timeouts; dedicated ports and a 60-second test budget resolve local startup. Final run completed in 47.1 seconds.
- [ ] GitHub Actions backend PHP 8.3/8.5 with MySQL, frontend and browser jobs pass remotely. Workflow exists; no remote repository/run yet.

These foundation checks do not validate the supermarket feature scenarios below; those remain pending.

## Local database readiness (2026-10-09)
- [x] Official MySQL 8.4.11 archive matches its published checksum.
- [x] Development and test databases use InnoDB and UTC, with successful transaction rollback.
- [x] Neither development nor test account can switch to the other database.
- [x] Laravel connects to bizismart locally and bizismart_test in testing mode.
- [x] Private runtime files and environment credentials are ignored by Git.
- [x] Normal stop/start succeeds and waits for readiness; all 10 backend tests (41 assertions) pass with the configured testing environment.
- [ ] Tenant isolation scenarios below remain pending; database account separation is not tenant isolation.

## A. Tenant isolation (CI-blocking, build in S1)
- [x] S1 branches/users/devices/tokens prove real-MySQL isolation, OR-query safety, bulk/quiet write safeguards and cross-business foreign-key rejection.
- [ ] Two businesses A and B each with products; querying as A returns only A's rows, as B only B's; with no tenant set a query returns 0 rows.
- [x] Creating a model sets `business_id` from the current tenant; a client-supplied `business_id` is ignored, including force-filled values.
- [x] Every implemented model whose table has `business_id` uses `BelongsToBusiness` (test enumerates schema/models).
- [x] Application code contains no unauthorised global-scope escapes (source scan).
- [x] Test-only tenant probe routes reject foreign resource IDs, numeric/raw IDs, nested/reserved/foreign hosts and reused tokens; request context clears after failures.
- [ ] For every tenant API route, fetching another tenant's resource id returns 404 (never 403, never the data).
- [x] A test-issued Sanctum token for business A is rejected on business B's subdomain; deactivated/deleted users and inactive businesses also fail.
- [x] A job missing businessId fails; valid businessId runs scoped and restores context on errors. Sync transport tested; Redis worker verification is pending.
- [x] TenantCache/TenantFiles keys/paths include the business id, separate matching names and reject no-tenant access/traversal.
- [x] Demo seed runs twice without duplication and produces two disabled owners/pending devices/no tokens. Database refresh refuses a non-test database.

Latest full backend result: 119 tests / 779 assertions passed on MySQL 8.4.11/PHP 8.5, including S2 roles/auth/staff management. Product isolation and real shop API cases remain required when those features are built.

## B. Auth
- [x] All 14 permissions checked for each approved role through Laravel Gate; role_hint grants nothing and disabled staff have no authority.
- [x] Test-only authenticated product/report probes reject cashiers/managers as specified; cross-business tokens fail. These probes are not production endpoints.
- [x] Role/membership constraints, inverse-pivot writes, nested cache/team context, retained cached user shortcuts, role replacement and idempotent provisioning tested.
- [x] Login success, canonical phone variants, wrong PIN, locked after 5 failures, unlock after 15 min and rate-limit retry headers.
- [x] New device pending -> blocked until approved; blocked device cannot log in, and blocking revokes every device token/challenge.
- [x] Owner authenticator enrolment/verification, accepted-step replay rejection, expiry and five invalid-code challenge attempts.
- [x] Forced PIN order/change, owner-only reset, stale challenge/rotation rejection and legacy unbound token rejection.
- [x] Auth/device public IDs, foreign tenant resources and composite token/device foreign keys.
- [x] Audit properties encrypted; no PIN/token/phone/name/TOTP secrets logged. Model status/role changes and PIN reset recorded.
- [ ] Permission matrix: each role vs 5 representative endpoints.
- [x] Deactivated/deleted user's tokens and challenges are immediately revoked; published session routes reject them.
- [x] Owner staff CRUD: public IDs/pagination, hashed temporary PINs, canonical duplicates, invalid legacy roles/foreign branches, last active owner, active-staff creation/reactivation limits, revocation on profile/role changes, secret-free audits and non-owner/cross-tenant/numeric-ID rejection (7 staff tests).

## C. Stock and FEFO
- [ ] Rules §3 example: batches A(10, 2026-12-31), B(20, 2027-03-31), C(50, expired) sell 15 -> A=0, B=15, items (A,10),(B,5).
- [ ] Expired batch never sold online; selling more than available -> `INSUFFICIENT_STOCK` with `available`.
- [ ] Every stock change writes a `stock_movements` row and `qty_after` equals the batch qty.
- [ ] Concurrency: two simultaneous sales of the last unit -> exactly one succeeds.
- [ ] Refund returns qty to the same batch; to quarantine if batch expired.

## D. Sales and money
- [ ] Total 11,800 at 18% -> VAT 1,800. Total 8,500 -> VAT 1,297.
- [ ] Cash 10,000 for 8,500 -> change 1,500. Split 5,000 + 3,500 -> change 0. Split 5,000 + 3,000 -> `PAYMENT_MISMATCH`.
- [ ] Discounts cannot make totals negative; price override without `give_discount` is ignored.
- [ ] Failure mid-sale (force an exception after the first item) leaves no sale, no stock change.
- [ ] Same `Idempotency-Key` twice -> one sale.

## E. Till and debt
- [ ] Till worked example: expected 165,000, variance -1,000, closes.
- [ ] Variance -15,000 needs approval; closed session rejects new sales.
- [ ] Credit limit 50,000 / balance 30,000: 20,000 ok, 25,000 rejected; overpayment rejected.
- [ ] Aging buckets for sales 10/45/75/120 days old.

## F. M-Pesa
- [ ] Success, failure, timeout, duplicate callback (no second record), unknown id (200 + nothing created).
- [ ] Callback sets tenant from the stored transaction, not the payload.
- [ ] A `checkout_request_id` cannot be reused by a second sale.

## G. Frontend
Current baseline preserves BiziSmart-fixed.zip design and now uses real Laravel auth/staff/device APIs. The prototype checks below are historical compatibility verification; App does not serve those workflows. Latest live verification is recorded at the end.
- [x] All 21 supplied checkout/refund/exchange/report/persistence tests pass; five new staff tests verify edit, confirmed activation, duplicate/invalid inputs, owner restrictions/last-owner protection and six-digit demo PIN creation/sign-in.
- [x] Imported TypeScript checking, ESLint and production build (including PWA shell generation) pass.
- [x] All eight current desktop/mobile Playwright checks pass: demo login/dashboard, staff edit/deactivation/persistence and simulated checkout/M-Pesa wording; real Laravel auth/logout verified separately. Both login/dashboard and staff layouts visually inspected.
- [ ] Cart maths (qty, discount, total, change) with Vitest.
- [x] Protected 401 clears token/cache and returns to sign-in; 30-minute inactivity clears the local session, with activity extending the deadline.
- [x] Sign-in, TOTP enrolment/verification, forced PIN confirmation, expired challenge and pending-device errors; authentication mutation data removed and only device UUID persisted (9 frontend tests total).
- [x] All 12 Playwright desktop/mobile checks pass against real Laravel/MySQL, including cashier sign-in/logout, pending-device denial, missing shop-address message and viewport checks. Synthetic staff exist only in the guarded test database; authentication traces are disabled. Both sign-in layouts visually inspected.
- [ ] Playwright: login -> open till -> sell (cash) -> close till; error message shown for insufficient stock.

## H. Offline (Phase 3)
- [ ] Two devices sell the last 2 units offline -> both commit, stock -2, `NEGATIVE_STOCK` conflict.
- [ ] Duplicate `operation_id` ignored; price change > 5% flagged; unapproved device quarantined.

## I. Supermarket additions (F10)
- [ ] All 12 F10 acceptance scenarios pass when their features are implemented.
- [ ] Exact packaging/weight stock deductions, fractional pricing and documented rounding boundaries.
- [ ] Returns cannot exceed original quantities, damaged returns stay unavailable and concurrent returns cannot double-refund.
- [ ] Import rollback/retry, scheduled-price boundaries and historical price preservation.
- [ ] Supplier credit retries and cash-drop retries do not duplicate accounting; closed tills reject cash drops.
- [ ] Cash-drop reconciliation distinguishes drawer cash from business revenue.
- [ ] Language switching preserves checkout; scanner/keyboard/thermal receipt tested on pilot devices.
- [ ] Markdown eligibility/expiry and cost-hidden margin warnings across multiple batches.
- [ ] Tenant isolation and permissions on every added endpoint, report, job and import.

## J. Single-product consistency
- [ ] No business-type selector or other business-type API/schema/module is generated from historical references.
- [ ] Department access and manager permission baseline are tested once finalised; original roles cannot leak costs/reports to cashiers.
- [ ] Non-expiring, expiring, quarantined and mixed-batch allocation policy is covered after specification.

## Active real interface verification — 2026-10-09
- [x] Backend: 98 tests / 678 assertions pass on MySQL, including tenant-scoped/expired/replaced owner invites, encrypted account/PIN hashing, mandatory TOTP and no operational-owner reset.
- [x] Frontend: all 30 tests pass; four new real API/session cases cover Bearer headers, protected 401 cache/credential clearing, failed authenticator requests, idle lock and sleeping-browser expiry. Other 26 cases verify retained prototype compatibility only.
- [x] Six current desktop/mobile browser checks pass against guarded MySQL test fixtures: owner setup/TOTP, real staff creation/edit/activation, last-owner rejection, device approval/block, cashier permission menus, logout token revocation and stored fake-session rejection.
- [x] TypeScript, ESLint and production/PWA build pass. Active login/staff screenshots visually inspected. No API caching, prototype login or simulated checkout in active App.
- [ ] Real stock/till/sales/payment and hardware checks after those workflows are implemented; no claim of financial completeness.
## Supervisor approval verification — 2026-10-09
- [x] Full backend suite passes: 119 tests / 779 assertions on MySQL; 21 approval cases cover all four permissions, owner TOTP/replay, shared PIN lockout and unknown/disabled credential consistency.
- [x] Exact operation/snapshot/requester/session/device/shop binding, expiry/replacement/single use, recursive-use rejection and atomic domain rollback tested.
- [x] PIN/profile/role/deactivation/logout/rotation/device-block revocation tested. Scoped model coverage and composite tenant foreign keys remain in the full suite.
- [x] Audit stores public approval/operation references without PIN/token/profile values. PHP syntax checks pass for the new service/model/request/migration.
- [ ] Financial-domain consumers and real approval dialogs once their features are implemented; no sale/refund is enabled by the issuance endpoint alone.
- [x] Six desktop/mobile browser checks pass with real supervisor issuance added to API authentication coverage; a grant cannot authenticate as a staff session. Existing real owner/staff/device flows pass.
- [x] Frontend suite passes all 34 cases, including eight real session/API cases. New adapter cases reject failed supervisor PIN/TOTP while keeping the cashier session, clear revoked sessions, and remove grants/credentials from mutation caches without browser persistence.

## S3 exact quantity foundation — 2026-10-09
- [x] User selected gram precision: up to three decimal places in kg, whole-item counts, half-up integer TSh line totals.
- [x] 23 focused arithmetic tests / 60 assertions cover carton conversions, kilogram/gram canonicalisation, signed int64 boundaries, invalid floats/exponents/precision, discounts, batch-split-independent charges, browser money bounds and independently generated BigInt cases.
- [ ] S4 product/pack barcode tenant uniqueness, permissions, immutable conversion history and StockService movement/transaction/concurrency checks after implementing those tables/services. Helper tests do not prove persisted stock behavior.

## S4a catalogue verification — 2026-10-09
- [x] Full MySQL backend suite: 163 tests / 1,055 assertions pass, including 21 catalogue cases and S3 arithmetic.
- [x] Product create/edit/archive, scoped search/category/barcode lookup, pagination, active limits/disabled reactivation, historical reservations and duplicate rollback verified.
- [x] Owner/stockkeeper writes and cost visibility; cashier/manager active reads without costs; foreign/numeric IDs and wrong-shop tokens rejected. Composite barcode ownership foreign keys and fail-closed models tested.
- [x] Integer/browser-safe prices, gram precision and strict whitespace/format validation, type immutability, readonly fields and encrypted price audits verified. Stock remains explicitly unavailable.
- [x] After the full run, browser-header authentication regression checked separately: all 12 foundation tests / 50 assertions pass. Live API through Vite responds with no-store JSON 401 when unsigned; migration 000009 applied and PHP syntax checks pass.
- [ ] Product UI, department/packaging associations and StockService/batch receiving/adjustment/ledger/concurrency verification. No stock operation is enabled by these catalogue tests.

Frontend, production build and desktop/mobile E2E evidence above is from the prior connected-auth slice; no frontend files changed for S4a and these checks were not repeated.

## S5a catalogue UI verification — 2026-10-09
- [x] All 39 frontend tests pass: 26 historical prototype compatibility cases and 13 real API/session/catalogue cases. Five new catalogue cases cover authenticated adapters, exact string quantities/integer prices, authoritative canonical responses/cache invalidation, failed edits without success, archive cancel/failure/retry, server pagination/barcode/category filters and cost/write visibility.
- [x] TypeScript, ESLint and production/PWA build pass. No new dependency or browser business-data persistence was added.
- [x] All six desktop/mobile Playwright checks pass against guarded MySQL fixtures. Owner flow now creates a kg product, checks canonical 0.75 kg and leading-zero barcode, edits price/activation, submits barcode search with Enter and waits for confirmed archive before navigating. Existing auth/staff/device/session checks still pass.
- [x] Product screenshots on desktop and phone visually inspected: preserved emerald/slate components, contained horizontal table scrolling and responsive forms/search layout. Development API/Vite previews restored.
- [ ] Batch/receiving/packaging/camera/hardware and full stock/POS scenarios after corresponding backend services are built. Catalogue tests do not validate stock movements or sales.

## User-approved reload sessions — 2026-10-09
- [x] All 49 frontend tests pass. Ten remembered-session cases verify fresh-store restore without identity/permissions/shop data, preserved idle deadlines, real activity without expiry extension, expired/idle/future/malformed/changed-device rejection, logout/401 clearing and blocked-storage fallback.
- [x] TypeScript, ESLint and production/PWA build pass; backend auth contracts and dependencies are unchanged.
- [x] All six desktop/mobile browser checks pass against guarded MySQL fixtures, including owner and cashier reload without new login, cashier permission restrictions, minimal sessionStorage fields, logout and a subsequent reload remaining signed out. Existing catalogue/staff/device/auth flows still pass.
- [x] Current session rules supersede the prior memory-only/reload-logout behavior. Only same-tab refresh is remembered; no PIN/challenge/TOTP/user identity or shop data is stored. Development previews restored after tests.

## S4b pack/carton catalogue — 2026-10-09
- [x] Full MySQL backend suite: 168 tests / 1,150 assertions. Four new packaging cases cover exact item/gram contents, canonical string factors, immutable edits/no-op/stale versions, reserved cross-base/family barcodes and rollback, nested tenant/product ownership, stockkeeper writes and manager restrictions.
- [x] All 51 frontend tests pass, including pack request serialization/server canonical responses and stale edit/archive failure retention. TypeScript, ESLint and production/PWA build pass.
- [x] Desktop/phone pack screenshots visually inspected; preserved emerald/slate layout, responsive MUI forms and contained product table.
- [ ] Packaging factors in receiving, sale stock deduction and financial history await StockService/POS; catalogue tests do not validate those operations.
- [x] All six desktop/mobile browser checks pass against guarded MySQL fixtures, including real gram-pack create/edit/version, leading-zero pack barcode lookup and confirmed archive, alongside owner/cashier reload, staff and approved devices. An interrupted run was repeated; initial cold-start assertion waits were corrected to the existing API deadline before the successful run.
- [x] Additive development migration 000010 applied. API/Vite previews restored using the normal development environment; no operational owner reset or simulated stock/payment introduced.

## S4c department catalogue — 2026-10-09
- [x] Focused MySQL ProductCatalogueTest suite: 30 tests / 371 assertions pass, including five department cases. Full backend suite was not repeated; the prior S4b full-suite evidence remains historical.
- [x] Department create/rename/soft archive, case-insensitive tenant name uniqueness, literal search, pagination and encrypted audit metadata verified. Archive fails for assigned active, inactive and archived products; clearing a live product permits archive and current-name reuse.
- [x] Public product assignment/filtering, omitted assignment preservation, null clearing and foreign/archived department rejection without partial price updates verified. Seller metadata hides counts; manager/cashier writes and foreign-shop IDs are rejected. MySQL composite department ownership rejects cross-shop product links; unauthorized responses remain no-store.
- [x] All 55 frontend tests pass. Four department cases cover authenticated create/rename, failed archive/duplicate retention, paginated choices preserving selection and server filtering/null assignment clearing. TypeScript, ESLint and production/PWA build pass.
- [x] All six Playwright desktop/mobile checks pass against guarded MySQL fixtures, including real department create/rename, product assignment/filter, blocked archive and successful archive after clearing. Authentication, session reload, packaging, staff and devices remain covered. Initial test wait/locator failures were corrected before successful reruns.
- [x] Desktop and phone department screenshots visually inspected; emerald/slate layout and MUI dialogs preserved. Additive development migration 000011 applied.
- [ ] Department reports/stock counts, manager resource scopes, archived-product reassignment/restore and StockService receiving/movement/concurrency scenarios remain outside this catalogue slice.

## S4d receiving and ledger — 2026-10-09
- [x] Seven focused MySQL tests / 105 assertions pass: counted/gram/pack receipts, canonical retries, changed-operation conflicts, retained immutable conversion snapshots, strict validation, inactive products, midnight expiry, expired-delivery rejection, cost/role/tenant isolation and foreign movement-user keys.
- [x] Ledger-failure injection rolls back the batch and quantity; independent artisan processes concurrently submitting the same receipt produce one batch/movement and identical public IDs. Movement model updates/deletes reject append-only history changes.
- [x] Broader backend regression: 179/180 cases initially passed (1,358 assertions). The sole failure was an old catalogue test expecting receipt routes to be unavailable. Corrected it to require validation rejection and zero batch/movement writes; its focused rerun passed (1 case / 10 assertions). All 180 cases are verified across those runs; do not describe the initial full invocation as all green.
- [x] All 59 frontend tests pass, including four receipt cases: exact authenticated serialization/server quantities, frozen same-operation retries after transport failure, expired-delivery error retention and refreshed pack-version selection requiring quantity re-entry. Read-only roles hide stock controls.
- [x] TypeScript, ESLint and production/PWA build pass. No new package or browser stock-data persistence added.
- [x] All six real-Laravel/MySQL desktop/mobile browser checks pass. Owner flow receives ten current 500 g bags as 5 kg, verifies version 2 and per-kg costs, and retains auth/reload/department/packaging/staff/device coverage. Receipt screenshots on both layouts visually inspected.
- [x] Additive development migration 000012 applied without resetting operational accounts.
- [ ] Catalogue aggregate balances/values, low-stock/expiry filters, adjustments/supervisor review, ledger browsing, supplier invoices and sale allocation remain future slices. Receipt tests do not claim completed checkout or production offline selling.

## S4e stock summaries and alerts — 2026-10-10
- [x] Focused MySQL catalogue/receiving suite: 45 tests / 631 assertions pass after correcting an outdated null-stock assertion and casting validated expiry query strings before date arithmetic. Full backend suite was not repeated for this slice.
- [x] Exact zero/count/gram/shared-carton and int64-max summaries, restricted held quantities, signed read-boundary formatting/overflow failures, inclusive Tanzania midnight expiry, quarantine/recall exclusion, no-batch/equality filters, combined filters and pagination verified. Reads leave movement counts unchanged; overflowing receipts roll back without new batches/movements.
- [x] Active/archive alert boundaries, cost filtering, seller denial, tenant isolation, no-store errors and rejection of forged summary authority verified. Independent-process duplicate-receipt regression passes.
- [x] All 63 frontend cases pass (single-worker run), including exact stock strings, filter serialization/clearing, seller visibility, receipt-driven catalogue refresh and error retention without stale balances. TypeScript, ESLint and production/Workbox PWA build pass.
- [x] All six desktop/mobile browser checks pass. After a desktop search-width correction, both owner flows pass again: phone in the paired rerun, desktop in an isolated rerun following an owner-setup timeout during concurrent build work. Final filter/stock-row screenshots inspected on both layouts. Test deadlines/auth behavior were not weakened.
- [ ] Monetary valuation, adjustment approvals, ledger browsing and sale allocation remain future work. Signed negative persisted batches belong to future offline acceptance; this slice verifies signed summary formatting without inventing adjustment writes.

## S4f read-only movement history — 2026-10-10
- [x] Focused MySQL receiving/history suite: 15 tests / 313 assertions pass. Covers stable tied timestamp ordering, exact counted/gram changes and historical balances, immutable package versions, duplicate receipt/read-only counts, tenant/product/batch isolation, roles, inactive/archive behavior, public attribution and hidden costs/PII. Initial timestamp assertion was corrected to match database second precision before the successful rerun. Full backend suite was not repeated.
- [x] All 68 frontend tests, TypeScript, ESLint and production/Workbox PWA build pass. Includes batch/all-history switching, exact strings, pagination, retained-filter retry, unavailable-product errors and receipt-driven invalidation.
- [x] All six desktop/phone browser checks pass, including real receiving-to-history, batch/all switching and returning to batches. Both history screenshots visually inspected; original emerald/slate layout retained.
- [ ] Stock adjustment writes and owner approvals remain the next slice; this history endpoint cannot change balances. D068 records the user-approved rules.

## S4g online stock corrections — 2026-10-10
- [x] Existing receiving/history and supervisor approval regression suites pass: 36 tests / 409 assertions.
- [x] All 73 frontend tests, TypeScript, ESLint and final production/Workbox PWA build pass. Covers authoritative preview, exact commit strings, blocked edit/cancel on uncertain outcome, identical retry, mandatory owner approval, cleared secret arguments, stale-balance review and unavailable targets. Initial new UI assertions used unavailable matchers; corrected them to native DOM property checks before the green rerun.
- [x] Nine new MySQL correction cases verified across runs: initial 8/9 passed (118 assertions), including duplicate/competing independent processes. Corrected rollback injection to an unconditional ledger-created exception; focused rerun of owner replay/collision, gram validation, atomic rollback and aggregate overflow passes 4 cases / 73 assertions. Do not describe the initial invocation as all green; full backend suite was not repeated.
- [x] All six desktop/phone browser checks pass, including owner correction, immutable history, stockkeeper disabled confirmation before owner PIN/TOTP approval, committed result and refreshed balances. Initial second-session setup failed on Node DNS for temporary .localhost hosts; corrected to browser-origin setup before the successful full rerun. Correction review, history and approved-state screenshots inspected; emerald/slate MUI design preserved.

## S6 cash arithmetic preparation — 2026-10-10
- [x] TillCashTest: 14 tests / 65 assertions pass. Covers expected 165,000 / variance -1,000, approval at -15,000, cash drop example 70,000, separate debt/refund/paid-out/drop contributions, exact positive/negative threshold equality, invalid cash types and browser-safe signed overflow.
- [ ] Real lifecycle, concurrent plan limits, manager scope, tenant isolation, approval atomicity and browser forms await S6 implementation. Arithmetic tests do not verify financial endpoints or notifications.

## D071 till access scope verification — 2026-10-10
- [x] SupervisorApprovalTest plus TillCashTest: 38 tests / 185 assertions pass on guarded MySQL. Existing session binding, owner TOTP, expiry, revocation, single-use and rollback cases remain green.
- [x] Final strengthened scope run: four tests / 35 assertions pass. Manager own-branch overview/approval, owner cross-branch approval, cashier own-session management, stockkeeper denial, missing manager assignment, cross-tenant target rejection and branch reassignment at consumption verified.
- [x] Matching approval digests do not permit missing, malformed, foreign or differently assigned branch targets; rejected grants remain unconsumed.
- [x] PHP syntax and diff whitespace checks pass. No frontend code, dependency or database schema changed in this permission slice.
- [ ] Till lifecycle routes, list filtering, plan limit concurrency, connected forms and browser till tests remain S6 work. The full backend suite was not repeated.

## S6 real till lifecycle — 2026-10-10
- [x] TillTest: nine cases / 86 assertions pass on guarded MySQL, covering own-till scope, role/branch/tenant isolation, exact cash validation, worked expected/variance example, supervisor requirement/consumption, stale reviews, immutable close, replay across a later shift, encrypted reasons, rollback and overflow.
- [x] Independent MySQL process checks serialize business-wide plan capacity and identical opening retries; one open session and one operation survive.
- [x] Existing supervisor/arithmetic regression run: 39 tests / 196 assertions pass. Final exact-type tightening rerun: three cases / 32 assertions pass, including internal decimal rejection. Full backend suite was not repeated.
- [x] Frontend suite: 76 tests pass. New till cases verify authenticated writes, unchanged uncertain retries, authoritative closing review, blocked confirmation before approval, grant cache cleanup and failed paid-out retention without false success.
- [x] TypeScript, ESLint and production/PWA build pass (7 precache assets, 510.23 KiB). No new dependency.
- [x] Additive development migration 000014 applied; existing accounts and sample catalogue/stock preserved.
- [x] All six desktop/phone browser checks pass, including real opening, paid-out, reload restoration, supervisor approval and immutable closing history. Corrected the initial mobile reload navigation timing race and made guarded fixtures clean up interrupted test tills through TillService before the successful full rerun. Desktop and phone approval/closed-state screenshots inspected; design preserved. Development previews restored.
- [ ] Sales/refund/debt/drop integration checks belong to S7/S9/S19; cash ledger hook alone does not enable those workflows or real payments.

## F00 supplied frontend design reconciliation — 2026-10-10
- [x] Final frontend suite: all 81 tests pass, including five dashboard/inventory cases covering exact server strings and counts, real filter navigation, seven-day expiry requests, error/retry without false all-clear, role-based query skipping and product movement adapter selection. Initial Inventory test looked for a hidden background tab while its MUI modal was open; moved the assertion before opening and reran the full suite successfully.
- [x] TypeScript, ESLint, diff whitespace and production/Workbox build pass; 7 precache assets / 534.47 KiB. No dependency added.
- [x] Full desktop/phone E2E suite passes all six checks for the restored panels/controls and existing real workflows. Initial desktop screenshot timeout during concurrent reference rendering was followed by a successful isolated full rerun, then another full green run after table changes; deadlines and business assertions unchanged.
- [x] After connecting Inventory/shared stock panels, both owner desktop/phone E2E flows pass again: Inventory overview, selecting real movements, batch correction entry, and existing catalogue/stock/staff/device workflows verified.
- [x] Compared source and rendered reference compositions; inspected final desktop/phone dashboard, products, tills, inventory and settings screenshots. Screenshot checks wait for data, reset main scroll and disable capture-time animations. Original saved ZIP unchanged; only the ignored reference preview fixed its legacy Dashboard access bug for visual inspection.
- [x] Development preview restored. Backend implementation/contracts unchanged; existing tenant-scoped APIs exercised by browser tests. Full backend suite and remote CI were not repeated. Financial reports, uploads, imports, stock takes, wastage and payment workflows remain separate tasks.

## S7a connected cash checkout — 2026-10-10
- [x] Selected MySQL regression: CashSaleTest, StockReceivingTest, StockAdjustmentTest, TillTest and ProductCatalogueTest pass 75 cases / 1,013 assertions. New cash cases cover canonical money/VAT, mixed allocation/eligibility, once-per-logical-line weight rounding, independent pack prices, stale reviews, insufficient stock rollback, injected cash-journal failure, immutable retries and independent-process duplicate/last-unit races. Initial failure-injection listener did not fire; replaced with a required TillService mock exception and verified rollback without weakening the assertion.
- [x] All 86 frontend tests pass, including five live POS cases: saved authoritative receipt, unchanged uncertain retries across screen navigation, insufficient-stock cart retention, closed-till request prevention and no late-response restoration after sign-out. TypeScript, ESLint and production/Workbox build pass; 7 precache assets / 550.91 KiB.
- [x] Final full browser suite passes all six desktop/phone checks, including rejected oversell, saved cash receipt/VAT/change, net till updates and supervisor close/history. Initial phone navigation raced the closing MUI receipt dialog's accessibility hiding; navigation now waits for the background banner before probing the mobile menu. Deadlines/business assertions unchanged. Desktop/phone payment/receipt/POS screenshots inspected.
- [x] Additive migration 000015 applied locally; development API/frontend restored (health 200, frontend 200, unsigned sale preview 401 with no-store/private). Existing operational accounts and sample inventory unchanged; all test sales use guarded *_test fixtures.
- [x] Final additional saved-receipt tenant/access and inactive/fractional-pack regression passes 1 case / 10 assertions. All ten new cash cases have passed across the selected suite and this focused follow-up; backend implementation was unchanged after the selected 75-case green run.
- [ ] Full backend suite and remote CI not repeated. Credit/debt, refunds, holds/discounts, PDF/printing, provider integration and product extras remain separate delivery gates; the cash prerequisite does not complete them.

## S9 connected customers and debt — 2026-10-10
- [x] All 91 frontend tests pass, including saved collection confirmation, unchanged retry after navigation, profile error retention, sign-out/late-response protection and server-authoritative cash+credit submission. TypeScript, ESLint and production/Workbox build pass (7 assets / 568.38 KiB).
- [x] Existing CashSaleTest passes all ten cases in the selected regression run, including duplicate and last-stock independent-process races.
- [x] Customer/debt development migration 000016 applied, preserving operational accounts/stock. Composite allocation FK names shortened to fit MySQL's identifier limit.
- [x] Final CustomerDebtTest run passes all eight cases / 107 assertions: encrypted/canonical contacts, tenant/role rejection, credit-limit boundary, full-credit and split journals, exact selected allocations, overpayment/customer ownership, stale reviews, injected rollback, aging and independent-process credit/collection races. Final full browser suite passes all six desktop/phone checks. Initial test failure injection reused a previously constructed controller; flushController now rebuilds it with the required TillService failure mock. Process helper factories moved to top-level functions because child PHP processes cannot load Pest-generated test classes. No business assertion or deadline weakened.
- [x] Final browser rerun passes all six desktop/phone checks: customer creation/list, POS selection, cash+credit and full-credit sales, chosen newer-invoice collection leaving the older invoice unchanged, and expected closing cash/variance including the collection. Initial browser run found empty search converted to null and rejected; CustomerRequest now accepts nullable empty search. The successful rerun exercises this API behaviour in both layouts without changing assertions or deadlines. Desktop/phone customer and collection-review screenshots inspected. Development API/Vite restored; full backend suite and remote CI not repeated.
- [x] Restored normal local API/frontend: both health and page return 200; unsigned customer reads return 401 with no-store/private cache headers.

## S15 return/exchange verification
- Exact cumulative half-up original-line refunds, full/partial quantities and zero-money gram return stock movements.
- Original invoice debt-first reduction including prior cash collections; no excess quantity/payment refunds.
- Good original-batch restoration and separate linked damaged/expired/recalled quarantine; exact movement balances.
- Current tenant/session/requester branch and manager approval branch checks; owner cross-branch return uses own till.
- Immutable actor/device/body UUID replay after consumed grant/closed till; changed input fails; genuine MySQL duplicate and competing returns.
- Atomic replacement stock/prices/credit/cash; insufficient replacement stock rolls back return, original debt, balances, cash, grant and exchange link.
- Frontend requires authoritative review/grant, preserves exact uncertain operation through navigation, clears on sign-out and ignores late results; no new persistence.
- Real desktop/phone POS exchange and quarantined cash refund, drawer totals and overflow/screenshots.

### S12a Selcom transport

Verify exact ordered HS256 signing, integer TZS outbound amounts/base64 webhook URLs, accepted-vs-paid separation, canonical phone and immutable IDs, status order/amount/reference checks, unknown/malformed response handling, single uncertain request attempt and safe errors. Test merchant binding and missing/unsafe credentials with zero network calls; prohibit outbound requests inside DB transactions. Verify callback auth/digest/freshness/required fields/tampering and exclusion of unsigned amount/phone. Check seller-only no-store availability API and POS availability on desktop/phone while cash/credit/returns/exchanges stay usable. HTTP fakes are test-only. Callback persistence/reconciliation/confirmed-payment allocation and merchant sandbox remain separate unchecked gates.

### S12 persisted payment lifecycle and mobile refunds

- Verify immutable tenant/actor/device UUID dispatch, one unresolved operation, preflight stock and exact split amounts before any provider request. Lost responses must reuse the original request without another create/push.
- Signed callback semantic duplicates append once; notifications cannot confirm payment. Reconciliation queries outside database transactions, uses durable leases, rejects mismatched order/money/evidence and keeps confirmed results sticky.
- Confirmed checkout is consumed once with stock, invoice and till journals in one transaction. Verify independent-process initiation/allocation races and rollback leaving the payment confirmed and unused.
- Selected-invoice mobile collection leaves unselected invoices unchanged, records one allocation and never adds drawer cash. Receipt refunds distinguish cash collections from mobile collections.
- External refund records require actual completed-transfer attestation, a unique reference and fresh bound owner/supervisor approval. Never execute or claim a provider payout. Debt is reduced first; cumulative cash/mobile refunds cannot exceed remaining paid pools.
- Recover unresolved server records after navigation/reload; freeze uncertain bodies only in memory, clear on sign-out and ignore late results. Check checkout, selected-invoice debt and owner refund dialogs.
- Desktop/phone provider UI fixtures are browser-test-only. Real Laravel/MySQL persistence is verified separately; neither test establishes live merchant approval or settlement.
- Keep external merchant sandbox/production scheduler verification open. Atomic exchanges requiring a mobile refund remain unavailable pending a settlement contract.

Verification evidence (2026-10-12): payment/gateway/arithmetic/API coverage verifies all 96 selected cases across the 95-pass run and corrected focused rerun; includes independent-process duplicate dispatch/allocation and injected rollback. Existing cash/debt/supervisor/return regression verifies all 51 selected cases across the 50-pass run and a two-case focused rerun (41 assertions), which also covers owner-list exclusion of allocated debt payments. The historical cash-only mobile-preview rejection assertion was replaced with a valid mobile preview plus rejection of a commit lacking verified payment. All 109 frontend cases passed earlier in this delivery; final TypeScript, ESLint and production/Workbox build pass (7 precache entries / 604.25 KiB). Full backend suite and remote CI were not repeated. Browser verification is recorded separately after completion.

Browser evidence (2026-10-12): all eight desktop/phone checks verified across the final broad run's six existing real Laravel workflows and the corrected two-case Selcom UI rerun (33.9s). The initial cold desktop owner flow exceeded its unchanged 120-second total deadline, then passed in 50.5s; phone passed too. Fixed browser-test Node DNS routing by using loopback with the original tenant Host, and changed the closure assertion to the actual exact zero-variance notice. No application assertion or deadline was weakened. Inspected desktop/phone Selcom screenshots and verified no horizontal overflow; completion is explicit after verified status and the test-only provider response never changes real till cash. These browser fixtures do not establish live provider acceptance.

### Coordinated POS and product tools (D086)

Verify every line/cart discount requires a fresh bound supervisor grant, including owner sales; reject extraneous grants before payment dispatch. Cart shares and net refund entitlements must remain exact. Confirm discounted mobile initiation inspects approval without consuming it, and unchanged confirmed payments can renew approval before atomic allocation.

Verify single-use same-branch held-cart handoff, fresh business authority, 24-hour expiry, no stock reservation, and identical-operation recovery after claim succeeds but catalogue/customer restoration returns an error. Late restoration after logout must not clear a new session's pending operation.

Verify repeated identical scans and rapid different scans each reach an exact registered-barcode lookup without dropped items; ignore editable fields and stop camera tracks on close. Check immutable receipt HTML escaping, real PDF output, approved receipt voids and regular-price exchange disclosure.

Verify private image revision/ownership/MIME/size bounds and durable orphan cleanup; missing R2 configuration must remain explicit. Check genuine price history, current shelf-label snapshots, safe CSV exports, bounded CSV/.xlsx parsing and atomic existing-product stock imports with stale-preview, replay and injected later-row rollback checks.

Backend evidence (2026-10-13): all 63 selected financial/stock/hold/price cases are verified across the 62-pass broad run and corrected focused rerun (2 tests / 13 assertions). The broad run includes independent-process duplicate discounted sales and competing held-cart claims. Image, API foundation and supervisor cases also passed in the earlier selected run. Test databases are isolated from operational stock; suites sharing the same test database must run sequentially. Final browser/build/frontend evidence is recorded after those gates complete; a browser-server startup timeout is not acceptance evidence.

Final code-review regressions (2026-10-13): 23 selected mobile-preflight, discount, held-cart and cash-sale cases pass with 231 assertions; 28 exact-arithmetic cases pass with 106 assertions. Fixed large-basket discount allocation, empty optional held-draft reasons, post-claim hydration recovery, late-session completion and repeated/overlapping scan loss. All 149 frontend cases are verified across the broad 148-pass run and corrected five-case held-cart rerun; the final four scanner cases also pass. TypeScript, ESLint (no warnings) and the final production/Workbox build pass (11 precache entries / 1415.35 KiB). The redundant run containing older cash race cases was deliberately stopped only after its guarded test schema rebuild finished; it is not counted as a completed suite. Earlier genuine race evidence remains applicable. Full backend suite and remote CI were not repeated.

Final browser evidence (2026-10-13): all 18 desktop/phone cases pass in one normal-config run (7.3 minutes), including authenticated reload, stock approvals, actual PDF header, approved discounted checkout/void reversal, price history, shelf labels, atomic stock import and CSV export. Preserved all original assertions; split the oversized owner journey into five serial checkpoints sharing one ephemeral context, retaining each 120-second deadline and 15-second assertion limit. Dedicated POS/payment shops prevent open-till cascades; test-only transactional row cleanup preserves migration schema under the strict _test database guard. Desktop/phone receipt and catalogue layouts inspected. This establishes local software acceptance, not external Selcom/R2 or physical hardware certification.

2026-10-14 operations delivery completed under D087/D088: connected reports/dashboard, supplier POs/payables/payments/returns/issued credits, expense/photos/reversals, cash drops, scheduled pricing/promotions/markdowns, attendance/preferences, English/Kiswahili screens/receipts, encrypted signed offline cash and owner reconciliation/conflict history, configured loyalty/commissions and historical demand forecasts. Existing design retained. Migrations 000027–000033 are additive and applied without resetting operational data.

Final evidence: all 188 frontend tests pass in one bounded run; TypeScript, ESLint, production build and Workbox generation pass (13 entries / 2049.35 KiB). All 14 final desktop/phone operations/native-encryption browser cases pass (3.5 minutes), following the earlier complete 32-case acceptance run. Phone report/dashboard/offline layouts inspected. Backend evidence: all 128 unit cases / 846 assertions; current financial regression 29 / 375; existing checkout regression 48 / 629; supplier/photo, staff/pricing and offline API checks; owner conflict authorization/isolation 1 / 21; original event-date offline report 1 / 14; independent MySQL points/markdown races 1 / 24. Counts from overlapping focused suites are not summed. No full backend-suite or remote CI claim. Diff whitespace check passes.

Remaining external gates: Selcom merchant credentials/public HTTPS/sandbox verification; private R2 configuration/verification; production scheduling and backups; physical scanner/printer/scale pilot. External bank/mobile expense/supplier/commission/refund entries record completed transfers and never execute a transfer. WhatsApp/SMS delivery remains unconfigured. Department/till reports, bank reconciliation, platform onboarding/subscriptions, customer display and other unticked roadmap features remain separate work. No commit, push, deployment or live provider payment performed in this delivery.

## D089 batch verification — 2026-10-14 (feature/departments, local)
- [x] Frontend: 213 Vitest cases pass (38 files, 149.65s); TypeScript, ESLint and production/Workbox build pass (13 entries / 2116.46 KiB).
- [x] Backend focused (BIZISMART_REUSE_TEST_SCHEMA=1, sequential, guarded *_test): LossReporting 9/101, Banking 4/41, Incentives 14/233, PlatformMessaging 15/72, SmsDeliveryReport 6/34, SmsDeliveryReportTransport 4/17, GsmSmsEncoding 2/13, ScaleLabel 2/6.
- [x] Full backend suite: 494 passed / 4954 assertions in 429.64s. Cold first-run migration ~546s; reuse runs seconds. No failures.
- [x] Playwright: 42/42 desktop/mobile checks pass in 11.2m (installed Chrome, workers 1, per-case 120s deadline, 15s assertions). 11 spec files incl. banking, loss-ui, messaging, platform, remaining-ui (display/scales/QR/leaderboard), operations, offline-storage, pos-tools, selcom-ui, live, api-auth.
- [ ] Remote CI, production provisioning, live Meta/Beem/Selcom verification, R2 verification, hardware pilot and push remain open. No live provider payment performed.

## S22 margin alerts — 2026-10-14 (feature/departments, local)
- [x] MarginAlertTest: 6 cases / 76 assertions pass (owner exact costs + unblocked commit, manager redaction with no cost keys, cashier key absence + successful sale, split-batch actual costs, weighted grams + at-cost silence, discounted nets + read-only previews + tenant scoping).
- [x] Regression: CashSale/SaleDiscount/SaleReturn/PosConcurrency 34 cases / 414 assertions pass with the extended review_hash.
- [x] Full backend suite: 500 passed / 5031 assertions (494 prior + 6 margin cases). No failures.

## POS margin display + F21 enterprise API — 2026-10-15 (feature/departments, local)
- [x] Frontend margin-warnings.test.tsx: 3 cases pass (owner cost detail, manager redaction, cashier/empty silence); pos-live.test.tsx 6 cases pass unchanged.
- [x] EnterpriseApiTest: 4 cases / 39 assertions pass (issue/list/revoke with one-time plaintext, owner-only management, staff-session hiding, owner-level reads, *-token rejection, tenant isolation, api_access gating).
- [x] These initial slice results are historical; independent full and focused acceptance after review is recorded below.

## Independent non-TRA review — 2026-10-15 (local, uncommitted)

- [x] Full backend regression: **525 passed / 5317 assertions**, 1023.70s, guarded MySQL test schema, reuse enabled and exclusive DB window. Includes all 15 enterprise cases, all 12 read aliases, current owner/token/device authority, module flags, tenant isolation and the HasPublicId guard regression. Corrected an initially invalid Pest dataset declaration and fixture-only cash/expiry assumptions before this green run.
- [x] Windows backup helper regression: **16 fake-native-CLI assertions**, no DB. Validates native failures, no partial publication/pruning on failure, exact Unicode bytes, exact target/identifier/retention and credential/staging permission guards. Fixed a Windows test-harness DACL privilege issue; production private-directory creation was not bypassed.
- [x] Revised real backup drill: reviewed Windows production helpers round-tripped all **3 Unicode/binary rows exactly**, then removed only their own random local database and private staging files. Original fixed-name ASCII drill evidence is superseded. Encryption/offsite restore and Unix runtime remain unverified.
- [x] Pre-hardening frontend **217/217** (39 files), TypeScript, ESLint and build/PWA **13 entries / 2117.64 KiB** pass. This is not substituted for post-upgrade compatibility checks.
- [x] Compatible dependency updates: Vite6.4.4/Vitest4.1.11/PWA0.21.2, scoped parser override, byte-identical raw CSS, clean peers and successful clean `npm ci` with unchanged lockfile hash. Focused Vitest4 margin component **3/3** passes. Audit: production npm and locked Composer zero findings; five high build-package findings remain from one unpatched braces advisory (D095/README). Not marked fixed.
- [x] Final response-header regressions after the full backend run: **40 passed / 387 assertions**, 37.08s (enterprise/readiness/API foundation). Includes final readiness405 case and early enterprise feature/tenant/method failures. This is focused verification of the final small header change, not a second full backend run.
- [x] Post-upgrade frontend: **217/217** across 39 files on Vitest4 (76.32s), TypeScript, ESLint and production/PWA build **13 entries / 2129.73 KiB** all pass after successful clean `npm ci`. Non-fatal worker-shutdown diagnostic appeared; test process exited0 with all cases green, no timeout/config changes. Existing chunk warning remains a warning.
- [x] **All 46 distinct desktop/phone browser checks verified across runs**, not one uninterrupted 46-pass run. Updated broad run: **44 passed / 2 failed (12.4m)**. Focused margin rerun: **4/4 passed**, including phone cashier after waiting for till-dialog closure and verifying POS navigation. Final platform rerun: **2/2 passed (1.1m)** after waiting for actual wire:model binding and excluding the event-driven OTP widget from that text-field-only condition. Genuine MFA, owner setup, cost/privacy assertions and original deadlines remain. No application logic/auth bypass or simulated provider success was introduced. Initial pre-fix 41/46 and intermediate OTP-helper failures are not counted as passing. Margin desktop/phone screenshots captured without entry animations and inspected.
- [x] Final screenshot review found custom Super Admin utility styles absent from vendor Filament CSS. Scoped presentation-only styles restore existing amber/dark theme, input boundaries, responsive forms and cards without changing bindings/actions. Blade compilation/PHP syntax passed; PlatformMessaging **15/72** passed (33.46s), then desktop/phone platform onboarding **2/2** passed again (1.1m) after the final view edit. Both final screenshots inspected. Log: tmp/opencode-verify-e2e-platform-styled.log. No broad-suite rerun is claimed for this presentation-only change.
- [ ] Remote CI (including new Unix helper/runtime dependency audit checks), deployed nginx/supervisor/Redis, encrypted offsite recovery, providers/R2 and physical device pilot remain external. Development migration status was read-only verified through 000040; no operational reset.

2026-10-14 operations delivery completed under D087/D088: connected reports/dashboard, supplier POs/payables/payments/returns/issued credits, expense/photos/reversals, cash drops, scheduled pricing/promotions/markdowns, attendance/preferences, English/Kiswahili screens/receipts, encrypted signed offline cash and owner reconciliation/conflict history, configured loyalty/commissions and historical demand forecasts. Existing design retained. Migrations 000027–000033 are additive and applied without resetting operational data.

Final evidence: all 188 frontend tests pass in one bounded run; TypeScript, ESLint, production build and Workbox generation pass (13 entries / 2049.35 KiB). All 14 final desktop/phone operations/native-encryption browser cases pass (3.5 minutes), following the earlier complete 32-case acceptance run. Phone report/dashboard/offline layouts inspected. Backend evidence: all 128 unit cases / 846 assertions; current financial regression 29 / 375; existing checkout regression 48 / 629; supplier/photo, staff/pricing and offline API checks; owner conflict authorization/isolation 1 / 21; original event-date offline report 1 / 14; independent MySQL points/markdown races 1 / 24. Counts from overlapping focused suites are not summed. No full backend-suite or remote CI claim. Diff whitespace check passes.

Remaining external gates: Selcom merchant credentials/public HTTPS/sandbox verification; private R2 configuration/verification; production scheduling and backups; physical scanner/printer/scale pilot. External bank/mobile expense/supplier/commission/refund entries record completed transfers and never execute a transfer. WhatsApp/SMS delivery remains unconfigured. Department/till reports, bank reconciliation, platform onboarding/subscriptions, customer display and other unticked roadmap features remain separate work. No commit, push, deployment or live provider payment performed in this delivery.

## D096 shared hosting and supplied-design follow-up — 2026-10-15

- [x] Approved archive source verified against the inspected extraction. Restored compact header tools, dashboard transaction width, server-backed POS category tabs and shared report-card composition. Real desktop/phone dashboard, report, margin and platform screenshots inspected. No prototype money/authentication, business rules or new dependency copied.
- [x] Additive migration000041 creates framework cache/locks/sessions/jobs/batches/failed-jobs tables. Initial database-store tests caught the missing tables; migration applied to guarded test schema and later to development additively, with no operational reset.
- [x] Full backend attempt: **535 passed /1 failed, 5429 assertions, 605.36s**. Failure was the existing process-based loss fixture using the real child clock while parent time was frozen, expiring its test token. Fixed the test clock, preserving application token checks. Final hosting/loss focused rerun: **22/211**,12.95s, including the corrected genuine duplicate-loss race, bounded outbox selection and independent-connection cache/lock ownership.
- [x] Database-session browser tests exposed a real Filament middleware bug (D097): Livewire repeated cookie decryption/session start. Fixed registration, retained persistent authentication/password/IP/activity checks and MFA. After this final backend change, hosting/platform/readiness **39/289**,17.66s pass. These are focused checks following the broad run, not a claim of one entirely green full backend rerun.
- [x] Final frontend **218/218**,39files,200.33s. Typecheck, ESLint and production/PWA build pass (**13 entries/2131.47KiB**). Existing chunk-size warning remains. Earlier217 broad and20 focused checks are superseded by218 final tests.
- [x] **All46 distinct shared-profile desktop/phone browser cases verified across runs**, not one uninterrupted all-green46. Broad run **43pass/3fail,13.4m**; intermediate focused run reproduced the failures. After the session fix and phone navigation readiness correction, final focused **5pass/1fail,4.4m** verifies both platform MFA/onboarding journeys and both owner margin journeys. That run's cashier login hit a connection timeout while frontend tests ran; the identical isolated mobile cashier case then passed **1/1,1.4m** with original deadlines and assertions. Shared mode uses real database cache/limiter/sessions/queue configuration against guarded MySQL8.4. Provider/browser fakes remain explicitly test-only. No MariaDB or live provider acceptance claim.
- [x] Windows fake-native backup regressions **19 assertions** pass, including MySQL versus MariaDB dump options and refusal of unknown flavors. Revised real MySQL production-helper drill again round-tripped all **3 Unicode/binary rows**, cleaned up only its random DB/files. POSIX scripts and PowerShell packaging/helper syntax pass; CI YAML/profile matrix parses. Unix runtime, actual MariaDB dump/restore and encrypted offsite recovery remain open.
- [x] cPanel source/build release generated and inspected for required public gateways, private backend/examples and absence of real env files, vendor/tests/Git. It requires Composer no-dev install and private configuration; it is not an installed/deployed application. Regenerate package after source/runbook changes. Latest checksum is recorded in NEXT-CONVERSATION.md.
- [x] TotalVFD October13 email and public developer guide located read-only. Sandbox details were not copied into source/docs; no login, API sale, email reply or fiscal success claimed. F22 lists unresolved fiscal contracts; TRA remains last/disabled.
- [ ] Hosted gates: chosen staging address (user has none selected), valid HTTPS, supported PHP/intl/Composer/proc_open/GNU timeout, MariaDB11.4 schema/locking/workflows, Apache routing/authorization/assets, actual cron/backlog/capacity, private providers/R2, hardware pilot, encrypted offsite recovery and remote CI. MariaDB11.4 local download attempts failed; CI now includes MariaDB and standard/shared browser profiles but has not run remotely.

Logs: ignored tmp/shared-backend-full.log, shared-backend-focused-final.log, shared-platform-backend-final.log, shared-frontend-final.log, shared-e2e.log, shared-e2e-final.log, shared-cashier-final.log, shared-backup-helpers.log and shared-backup-drill.log. No push, PR, hosting upload, DNS change, live merchant operation or production data reset.

- [x] After D097, the standard-profile desktop/phone platform journeys also passed **2/2 (1.1m)**; default file-backed development sessions were not regressed. Log:tmp/shared-standard-platform.log.

## D098 owner-operated sandbox preparation — 2026-10-15

Executed: node --check infrastructure/sandbox/build-totalvfd-kit.mjs; node --check infrastructure/sandbox/totalvfd-kit.test.mjs; node --test infrastructure/sandbox/totalvfd-kit.test.mjs. All 42 tests pass (364.7ms final run); ignored log tmp/totalvfd-kit-offline.log. Checks execute the generated Postman scripts offline and verify import files exactly match the reviewed generator, blank credentials, all 20 fixtures, consumed case arms, missing/unsafe setup, fixed sandbox endpoint/method, scope/run/body binding, original duplicate payload/receipt identity, contract gates, pending retention, 201/409 envelopes, response mismatches and validation vs authentication failures. Synthetic responses are confined to offline tests and are not provider evidence. git diff --check passes; pre-existing line-ending normalization notices only.

No actual Postman import/send or provider login/API call occurred. No backend/frontend production source, dependencies, schema or active shop data changed for D098, so previous broad application suites were not repeated. The Postman client skip/redirect behavior must be verified by the operator before secrets; actual provider coverage, NIL/identity/tax/weight fixtures, final receipt verification and production fiscal integration remain open. Commercial fiscal readiness gate is recorded in LAUNCH-READINESS and D098.

## D099 non-TRA software verification — 2026-10-15

Executed the full backend suite sequentially against the guarded MySQL *_test schema, with BIZISMART_REUSE_TEST_SCHEMA=1 and PHP_INI_SCAN_DIR=backend/.local/php: php artisan test --compact. Result: **539 passed, 5460 assertions, 602.68 seconds**. Log: tmp/non-tra-backend-final.log. Local MySQL was stopped on resumption and restarted using the existing local helper; operational records were not reset. The full suite is now green after the prior D097 focused fixes, rather than only the earlier 535-pass/1-failure broad run. No overlapping database/E2E/drill suite was launched.

node --check infrastructure/local/prepare-shop-catalogue.mjs passes. Ran the helper on the actual approved source hash and checked every output product against source: all 247 IDs, names, selling units and integer approved prices match; all unknown registration fields and opening stock remain null; no row is marked ready for product creation. No formal test suite was added for this reversible offline preparation tool. No application endpoints, production backend/frontend financial logic, dependencies, database schema or existing shop data changed in D099; frontend/browser builds were not repeated. git diff --check passes. Actual shop registration, opening stock, selected hardware and hosting acceptance remain separate gates. The owner's claimed grace period is recorded as reported, not verified by these tests.

## D101/D102 design reconciliation — 2026-10-16

Reference SHA256: 2AAB937F275CBE236925CB27199AE5D84F2E5DD451BD787336E557EAF19E9620. Reviewed the top-level source in the supplied second ZIP; nested projects, prompts and demo financial/auth/provider logic were not imported. All-screen navigation/snapshots cover 13 shop destinations and the pricing, attendance, reward, scale and personal contexts on desktop and Pixel 7. Separate platform/owner setup, real checkout/stock/import/receipt/QR/display/privacy and role checks remain in the functional suites.

- Backend focused: `BIZISMART_REUSE_TEST_SCHEMA=1 php artisan test --filter=PlatformMessagingTest` — **16 passed / 74 assertions, 9.23s**. Includes the new UTC22:30/Tanzania-next-day payment-date acceptance and local-future-date rejection, alongside existing platform MFA, revisions, frozen operations and messaging tests. `php artisan view:cache` succeeds; all three private-patch PHP/Blade files pass `php -l`. No new full backend run is claimed; the D099 539-test run is historical.
- Browser broad run: installed Chrome, one worker, shared hosting profile, guarded MySQL test schema, all specs except deferred Selcom — **35 passed / 5 failed / 6 did not run, 21.8m**. Failures were: a desktop banking supplier read connection error; both operations journeys still using the removed promotions button selector; the mobile screen sweep reusing a consumed TOTP time step from the banking actor; and a phone navigation helper skipping its menu while a closing dialog still hid the header.
- Fresh final rerun: banking desktop, both all-screen sweeps, both complete serial operations journeys and both scale/QR/display/leaderboard journeys — **17/17 passed, 6.2m**. The banking workflow passed unchanged. Updated selectors follow the real tab roles; screen-sweep fixtures now have separate actors; phone helpers await the visible menu after modal exit. No forced clicks, relaxed financial/privacy assertions, MFA replay bypass or enlarged request/assertion deadlines. Test-server cold boot alone uses a bounded 180-second startup window and a testing-only PHP execution ceiling of120 seconds. Production runtime settings are unchanged.
- **All 46 distinct non-Selcom desktop/phone browser cases are verified across the broad run and final rerun. This is not one uninterrupted 46-pass run.** The final17 overlap six already successful cases; do not add the pass counts as distinct cases. Both platform MFA/client onboarding journeys passed in the broad run. An earlier desktop platform navigation timeout in the preflight was superseded by these passes.
- Snapshots under ignored `tmp/design-*-desktop.png`, `tmp/design-*-mobile.png` and the existing platform/receipt/label/display captures were inspected. No header More tools menu, viewport horizontal overflow or generic read error was present in the final all-screen captures. Pending offline preparation blocks navigation; the full operations journeys also prove encrypted local cash persistence, signed sync and exactly one committed sale. Screenshots are test data and are not evidence of a hosted deployment or provider delivery.

Logs: `tmp/design-final-e2e.log`, `tmp/design-final-e2e-rerun.log`. Final frontend/build/package evidence follows below after completion. No hosted records, production credentials, provider calls, domain configuration, migration, app key, owner account, commit or push was changed by this design task.

Final frontend pipeline (sequential after browser completion): Vitest `--maxWorkers=1 --no-file-parallelism --pool=threads` — **40 files / 220 tests passed, 210.80s**; `tsc --noEmit -p tsconfig.app.json` and ESLint clean; Vite production/PWA build succeeds with **14 precache entries / 2149.38 KiB**. The pre-existing chunk-size warning remains. Logs: `tmp/design-final-vitest.log`, `tmp/design-final-typecheck.log`, `tmp/design-final-lint.log`, `tmp/design-final-build.log`. `git -c core.safecrlf=false diff --check` passes.

Final archives were inspected entry-by-entry; every decompressed entry hash matches the selected built/source file, paths use forward slashes, and public content excludes PHP, env, vendor, tests, maps and source ZIPs. Public frontend: **16 entries / 662139 bytes**, `tmp/BiziSmart-design-update-20261016.zip`, SHA256 **3D373C272321644C0BA679A64D4BAAFE26F9F7060999F1193FA3CA41C788570E**. Private admin patch: **3 entries / 7641 bytes**, `tmp/BiziSmart-admin-design-update-20261016.zip`, SHA256 **52EA7694F5CAC7BC7FE042F5D877727F038F086D9E20A89169D2EFE5DFEC89F0**. Entry hashes are in ignored `tmp/design-update-manifest.json`. The earlier same-name frontend candidate is superseded. Upload/backup/refresh checks are in14-shared-hosting.md. These archives are locally prepared, not uploaded; existing hosted accounts, data, gateway, keys and provider configuration are unchanged.

### D101 hosted extraction and public acceptance — 2026-10-16

User reports an existing backup and supplies terminal evidence: both final archive checksums match, frontend files extracted directly to /home/afyabgzo/bizismart.com, all three private files extracted to /home/afyabgzo/bizismart/backend, and php artisan view:cache succeeded. Read-only HTTPS checks from this workspace confirm Fresh Choice / returns200 and references assets/index-DzhaB1EJ.js with title BiziSmart | Supermarket Management; that JS and assets/index-CmFZoD8A.css return200 with correct content types. /api/v1/ready returns200 with ready/database/migrations/cache true. No authenticated hosted workflow, private server file inspection, image-storage/provider, scheduler, backup-restore or hardware acceptance is inferred. The user must refresh the open app without clearing site data and review the new shop/admin interface. No additional upload, credential use, migration, key change or operational write was performed by the agent.
