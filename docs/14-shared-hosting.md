# Shared-hosting deployment profile

D108 installed schedule: three preserved account cron entries — existing bounded business runtime, daily backup at20:17server-local and private monitor at4-59/5. Actual automatic encrypted/offsite backup and healthy monitor runs were observed. See `19-backup-recovery.md` for recovery proof, private status paths and crontab rollback. Do not install the historical example scheduler again. External messaging alerts and automatic backup deletion are not configured.

User-approved compatibility target: Namecheap Stellar18, PHP 8.3+ with intl, MariaDB 11.4, database cache/limiter/sessions/queues, no Redis, no persistent daemons and cron no more often than five minutes. This is an alternative deployment profile; dedicated-server defaults remain supported. It is not a claim of hosted acceptance.

## Install and boundaries

Current installation supersedes the historical provisioning paragraph below: bizismart.com, freshchoice.bizismart.com and admin.bizismart.com are already provisioned. Shop public root is `/home/afyabgzo/bizismart.com`; private Laravel is `/home/afyabgzo/bizismart/backend`. D105 is deployed; D106 verifies public desktop/phone sign-in. Do not reconfigure domain roots or install the historical D104 ZIP. The read-only live audit confirmed database cache/limiter/sessions/queue, proc_open, the existing bounded wrapper and matching runner source. Runtime scheduling status is recorded in NEXT-CONVERSATION; do not add a duplicate scheduler from the example below.

D100 confirms the user's domain as **bizismart.com**. Proposed explicit pilot hosts: `freshchoice.bizismart.com` (shop, matching the eventual business subdomain) and `admin.bizismart.com` (platform admin). They are not provisioned yet. Use `TENANT_DOMAIN=bizismart.com` and `ADMIN_DOMAIN=admin.bizismart.com` in the private production env only after host/account setup; set APP_URL consistently with the approved hosted topology. The apex is not a shop host under the existing tenant resolver. Leave local/test configuration and generic templates intact. Pilot certificates must cover the exact named hosts; a paid wildcard is not assumed. The user's cPanel General Information paste confirms account Shared IP **199.188.200.89**, user `afyabgzo`, home `/home/afyabgzo`. Domain addition is reported done; verify the precise document roots and existing DNS/email records before changes. Addon quota is 2/2: preserve existing sites. Read-only DNS at preparation showed apex162.255.119.245 and no admin A record, while server241.web-hosting.com resolves to199.188.200.87 (server hostname only, not the account IP); no remote configuration was changed. The expired primary-domain afyaguard.org certificate does not establish bizismart.com's TLS status.

Use backend/.env.shared.example as a blank production template. Preserve the existing app/encryption keys for upgrades. Never copy development databases, test users, .env files or secrets into a release archive. Follow doc13 for locked dependencies, additive migrations, Filament assets, required MFA, explicit plan features and encrypted recovery. Use DB_CONNECTION=mysql with the MariaDB server and InnoDB; never weaken database locks/constraints to pass compatibility checks.

Keep the entire Laravel application outside public_html. cPanel shop subdomain document roots serve only built React assets and the supplied API gateway/.htaccess. Admin/API subdomains point only at backend/public. The gateway resolves backend/public/index.php outside the web root; adapt its single installation path before deployment. Never upload the repository root to a public document root. Keep Authorization headers and tenant Host intact. Existing sites/databases are separate and must not be overwritten. Actual domain, DNS/wildcard HTTPS, PHP extensions, proc_open permission, MariaDB migrations and tenant/admin routing must be verified on staging first.

The package maps `private/bizismart` to `/home/ACCOUNT/bizismart` (not a folder named private inside public_html), and `public_html/bizismart` to the selected shop document root. If cPanel forbids document roots outside public_html, the provided `public_html/bizismart-admin` uses a private Laravel front-controller bridge for the admin/API hosts instead. After `php artisan filament:assets`, copy the resulting public css/js/fonts into this admin public directory again. Set the actual ADMIN_DOMAIN before access; Laravel still enforces its admin guard/MFA/domain. Verify every asset and unauthenticated API response is served correctly on staging. No hosted files are uploaded by the packaging script.

## One finite cron entry

### Installed account entry (D106)

Already installed on2026-10-16; do not add the generic example as a duplicate:

```cron
*/5 * * * * BIZISMART_BACKEND=/home/afyabgzo/bizismart/backend BIZISMART_PHP=/opt/alt/php85/usr/bin/php sh /home/afyabgzo/bizismart/shared-cron.sh >> /home/afyabgzo/bizismart/backend/storage/logs/shared-cron.log 2>&1
```

Source-matched bounded wrapper and live shared/database profile were verified. Manual pass7/0 preceded installation; first actual cron pass at11:05:35UTC independently produced7completed/0failed in cache and the private log. No credentials/provider sends were activated. Installer refuses configured messaging credentials, duplicate/potential schedulers, unavailable prerequisites, missing recent successful manual run, changed crontab or exhausted five-entry limit. Exact installation is idempotent. Installer `infrastructure/cpanel/install-shared-cron.sh` is account/path-specific, not a generic deployment script. The prior crontab backup is private; inspect and merge any later unrelated changes before restoring it, rather than blindly replacing the account crontab. Retain monitoring: one pass is not an uptime/capacity guarantee.

### Template for a separate future installation

Use the account's PHP8.3+ CLI path and GNU timeout (confirm them, not just the web PHP version). Upload shared-cron.sh privately, then set these nonsecret paths in its cron invocation:

```cron
*/5 * * * * BIZISMART_BACKEND=/home/ACCOUNT/bizismart/backend BIZISMART_PHP=/path/to/php sh /home/ACCOUNT/bizismart/shared-cron.sh >> /home/ACCOUNT/bizismart/backend/storage/logs/shared-cron.log 2>&1
```

Do not install schedule:work, queue:listen, a persistent queue worker, one-minute cron, or normal schedule:run in this profile. Normal schedule registration is disabled when BIZISMART_HOSTING_PROFILE=shared. The runner uses a database cache lock, a durable task/tenant cursor, a 45-second work budget and attached child processes with at most25 seconds per unit. Child output is discarded to avoid leaking tenant/provider details. Failed/timed-out units advance the cursor and are revisited next cycle; errors return nonzero. Terminating an uncertain message send never permits automatic resend: existing durable dispatch claims become uncertain. Existing service transactions roll back if the child is killed.

Tasks cover scheduled price history, today/yesterday activity alerts, consented owner notices, up to ten message dispatch attempts per tenant/pass within a20-second soft budget, one accepted-SMS status lookup, and private product/expense cleanup. Each row is attempted at most once per unit; accepted/uncertain rows are excluded. Unconfigured providers remain blocked. The shell wrapper terminates the process group after50 seconds (two-second termination grace), including startup/DB waits. A pass may span several cron ticks under load; five minutes is a minimum scheduling interval, not a delivery SLA. There is no throughput guarantee. Monitor private infra:shared-last-run cache status and logs/backlog; backlog growth or repeated timeouts requires capacity changes before adding shops. No fabricated success, automatic uncertain resend or request-time network calls in checkout.

There are currently no concrete Laravel queue jobs dispatched by the application; messaging uses its own durable outbox. The same private shell helper has an optional queue mode: invoke `sh /home/ACCOUNT/bizismart/shared-cron.sh queue` with the same path variables in a second five-minute cron entry. It processes at most one job, exits on an empty queue, has a25-second outer limit and never detaches; retry_after90 exceeds the process bound. One attempt is allowed before failure review, rather than blind repetition. Offset it by two minutes from the tasks cron if supported (`2-57/5 * * * *`). Do not add more than the plan's five simultaneous cron jobs. New queued financial/provider operations still require explicit interruption/idempotency tests; selecting database queue alone does not prove those contracts. Do not claim worker health from /ready.

Scheduled selling prices/promotions are evaluated against current timestamps during catalogue/review/commit. Delayed lifecycle history does not delay the effective checkout price. Reports/alerts, daily summaries, renewal/debt reminders, delivery reports and cleanup may lag. Large tenant work units that repeatedly exceed the bound are not supported without further chunking/capacity; monitor this explicitly.

## Historical D104 product-card frontend update — superseded by deployed D105

Do not execute this older extraction over D105; current release is in17-design-release.md. The following records the historical public frontend-only update after D101. Artifact `tmp/BiziSmart-product-cards-update-20261016.zip` contains16 static build entries,670071bytes; SHA256 `79F02007079854B6E308F35D6B9335B8EFE7A4389643339CE36BCBCB11CA0BC1`. Decompressed hashes matched that build. The ZIP contains no gateway, .htaccess, .well-known, backend, env, credentials, migration or illustrative product photo/data.

Upload the ZIP privately under `/home/afyabgzo/bizismart`, then:

```sh
(
set -e
cd /home/afyabgzo/bizismart
sha256sum -c <<'CHECKSUMS'
79F02007079854B6E308F35D6B9335B8EFE7A4389643339CE36BCBCB11CA0BC1 BiziSmart-product-cards-update-20261016.zip
CHECKSUMS
test -f /home/afyabgzo/bizismart.com/gateway.php
unzip -o BiziSmart-product-cards-update-20261016.zip -d /home/afyabgzo/bizismart.com
)
```

No Artisan/composer/migration/APP_KEY command is needed. Verify public entry references `/assets/index-UucziySZ.js` and `/assets/index-Bkr97MDt.css`, then owner sign-in, Products default grid, existing photo read or neutral missing-photo placeholder, List view and filters, edit, card actions and importer on phone/desktop. Use the existing PWA update prompt only after pending operations are safe; never clear session/IndexedDB/offline records to force an update. Private R2 verification remains separate. The owner-branch repair ZIP/command is also separate and is not bundled here. Roll back from the saved frontend files if acceptance fails; do not change other domain roots. No hosted deployment of this artifact is claimed.

## Required acceptance

Backups on a MariaDB client must set MYSQL_DUMP_FLAVOR=mariadb and MYSQLDUMP_BIN to the actual mariadb-dump/mysqldump executable. This omits the MySQL-only GTID option; all private path, exact-target, byte-preserving and native failure guards remain. MySQL defaults stay unchanged. The cPanel release does not bundle a database or verified encrypted backup service. Select and test protected dump/restore plus encrypted offsite recovery on the real account.

Local evidence belongs in08-test-checklist.md. Hosted gates remain: valid HTTPS, gateway authorization/tenant isolation, PHP/Composer compatibility, proc_open/child termination, exact MariaDB schema/locking/race tests, cron execution, private storage/provider verification and encrypted backup/restore. No migration reset or test suite may target an operational shop database. TRA stays last and disabled. Shared hosting limitations do not authorize changing money/stock rules or weakening security.

### Confirmed account directories (D100 follow-up)

The user's Domains listing confirms /home/afyabgzo/bizismart.com as the public document root; its File Manager listing contained .well-known and cgi-bin only. Preserve those folders. The user reports creating /home/afyabgzo/bizismart privately. The separately prepared private-only archive tmp/BiziSmart-private-backend.zip extracts directly inside that private directory, yielding backend/ and shared-cron.sh. It excludes Composer vendor and operational secrets; uploading is not installation or acceptance. The generic public gateway's two-parent path is incompatible with this shallower document root: adapt and verify the public package before uploading public files. Existing sites remain separate.

### Frontend-only design updates (D101)

For the existing Fresh Choice installation, public files belong directly in `/home/afyabgzo/bizismart.com`. The cPanel document-root input is home-relative (`bizismart.com`); do not enter the full home path into that field. Keep the private Laravel directory `/home/afyabgzo/bizismart/backend` and other hosted sites separate.

The D101 update artifact is `tmp/BiziSmart-design-update-20261016.zip`, containing only the built frontend entry, hashed assets, manifest and service worker. It contains no backend, PHP, env, dependency vendor or operational data; existing gateway.php/.htaccess stay in place. During a quiet session with no pending checkout/write, keep a rollback copy of the previous public files privately, then upload/extract the update directly into the confirmed public root (no extra enclosing directory). Old hashed assets may remain for rollback; do not recursively delete the public directory or its .well-known/cgi-bin folders. Refresh the application after extraction and verify the new HTML asset references, authenticated navigation and readiness. Do not clear browser site data/IndexedDB to update the design: encrypted offline sales and device identity must be retained. No database migration, app-key change or owner reset is needed. Artifact hash and final verification belong in NEXT-CONVERSATION/08-test-checklist; preparing it locally does not upload it.

The separate `tmp/BiziSmart-admin-design-update-20261016.zip` is a **private backend patch**. It contains only `app/Providers/Filament/AdminPanelProvider.php`, `app/Filament/Services/PlatformService.php` and `resources/views/filament/pages/platform-management.blade.php`. Keep private rollback copies of those files, upload outside the public document root, and extract directly into `/home/afyabgzo/bizismart/backend`. It adds emerald admin styling/filtered client counts and the D102 Tanzania-calendar validation correction. It includes the already approved admin middleware configuration from the current source; compare against any newer hosted customization before replacement. Do not extract this archive into bizismart.com, expose source, replace .env or regenerate keys. Run `php artisan view:cache` from the private backend after extraction. No Composer update, migration or owner/account reset is required by this patch.

Accept the update only after checking shop PIN/TOTP access, sidebar/context tabs, product search/checkout/receipt and phone navigation, plus admin MFA/clients/plans/features. Confirm HTTPS `/api/v1/ready` separately. Local checks do not prove hosted scheduler, capacity, backups, providers or hardware. Keep archives/rollback copies private and remove any uploaded ZIP from a public document root after extraction.
