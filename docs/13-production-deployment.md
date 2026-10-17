# 13 — Production deployment runbook (walk-in launch)

D108 current installation: the shared-hosting sites and D105 design are already deployed. Daily encrypted/signed R2 backups and the private five-minute runtime monitor are installed and their automatic execution was observed. A real95-table recovery passed locally; see `19-backup-recovery.md` for evidence, key custody and recovery boundaries. Do not rerun generic provisioning, regenerate APP_KEY or add a duplicate scheduler. Remote CI, actual provider delivery and physical hardware acceptance remain open; the examples below are deployment templates, not current-host status.

Locally reviewable steps. Nothing here provisions hosting by itself; remote CI, DNS/HTTPS, credentials and hardware pilot remain external gates (see `LAUNCH-READINESS.md`). Configuration files are examples — review hostnames, paths and secrets before use.

For the user-approved Namecheap shared-hosting alternative (database runtime stores, bounded five-minute cron, MariaDB compatibility gates), use14-shared-hosting.md instead of installing the Redis/supervisor/minute-scheduler examples below. Migration000041 adds framework runtime tables for either profile. Keep the profiles distinct.

## 1. Provision

- Hosting with PHP 8.3+ (extensions including intl), MySQL 8/InnoDB (UTC, `utf8mb4`), Redis, HTTPS certificates and tenant/admin DNS (`*.bizismart.co.tz`, `admin.bizismart.co.tz`).
- Example web/worker configs: `infrastructure/nginx/bizismart.conf`, `infrastructure/supervisor/bizismart.conf`.

## 2. Install

- `composer install --no-dev --optimize-autoloader` (backend), `npm ci` + frontend build (`frontend/dist` is served for shop subdomains).
- Copy `backend/.env.example` to `.env` and set private values. Production rules:
  - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.bizismart.co.tz`, `TENANT_DOMAIN=bizismart.co.tz`, `ADMIN_DOMAIN=admin.bizismart.co.tz`. The template's local defaults are unsuitable for deployment; leaving `APP_ENV=local` also enables local-only invitation tooling.
  - `CACHE_STORE=redis`, `CACHE_LIMITER=redis` (the same shared Redis across all web/worker hosts), `SESSION_DRIVER=redis` (or shared database; never `array`), `QUEUE_CONNECTION=redis` (or database; never `sync` for dispatch-dependent messaging/reconciliation). A single-host file session is possible only with deliberate single-host deployment; it does not provide shared sessions across nodes.
  - `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `ADMIN_ALLOWED_IPS` allowlist.
  - Redis credentials, R2 credentials, Meta/Beem credentials stay empty until verified (see `LAUNCH-READINESS.md`).
- Generate the application key once during initial provisioning; preserve it and all other encryption keys across releases and back it up separately under controlled access. Never run `key:generate` over an existing operational key: encrypted data requires the original key.
- `php artisan migrate --force` (additive only; never reset operational data), `php artisan filament:assets`, `php artisan config:cache` after setting the correct environment. Give the service account write access only to required runtime directories (`storage`, `bootstrap/cache`), not application source or frontend assets.
- Tenant nginx serves `/api/` directly through the Laravel front controller with the original tenant Host; `/` uses the React fallback. Review PHP-FPM socket/paths, wildcard certificate coverage and DNS, then run `nginx -t` before reload. Confirm tenant `/api/v1/health` is JSON, never the SPA HTML. The example is source-reviewed, not deployed/validated on this host.

## 3. Platform administration

- `php artisan bizismart:admin-create` (interactive; enroll the required authenticator). Assign plan features explicitly; baseline prices/limits can be seeded from the panel; features start disabled.
- Onboard owners only through private expiring one-use setup links. Recovery stays disabled until identity rules are supplied.

## 4. Scheduler and workers

- Run exactly one of: system cron `* * * * * php artisan schedule:run` or the `bizismart-schedule` supervisor program. Scheduled commands: `payments:reconcile`, `pricing:reconcile`, `messaging:dispatch`, `messaging:reconcile-sms`, `bizismart:loss-alerts`, image/photo cleanup.
- Run `bizismart-queue` workers where required; verify restart procedures and monitor `schedule.log`/`queue.log`, failed jobs and queue depth.
- Create `/var/log/bizismart` with service-account write permissions before supervisor starts. The worker timeout is 60s; keep the selected queue's `retry_after` greater than that (90s baseline). `stopwaitsecs=3600` allows graceful shutdown; size it above the longest permitted job/scheduled command. After deploying code, issue `php artisan queue:restart` and `php artisan schedule:interrupt`, and restart the supervised `schedule:work` process to load new code. Verify new processes and failed-job/duplicate-delivery handling; a healthy web probe proves neither workers nor scheduler execution.

## 5. Backups

- **These helpers create protected plaintext staging SQL only. They do not encrypt files or provide offsite recovery.** Authenticated encrypted storage, independent offsite copies, key custody/recovery, backup monitoring and a verified encrypted restore remain required external launch gates. No selected encryption format or production recovery service is claimed working. Never expose staging files through nginx, repository commits or public object storage.
- Use `sh infrastructure/prod/backup.sh` on a verified Linux host (other Unix hosts require their own compatibility checks), or PowerShell **7.4+** `infrastructure/prod/backup.ps1` on Windows. Required environment: `MYSQL_DEFAULTS_FILE` (absolute path to a private MySQL `[client]` connection file), `MYSQL_DATABASE`, `BIZISMART_BACKUP_CONFIRM_DB` (exact database name, required for **every** backup and restore), `BACKUP_DIR`. Optional `MYSQLDUMP_BIN`/`MYSQL_BIN` select installed MySQL 8 client executables; `RETENTION_DAYS` is an integer from 1 to 36500 (default 30). Do not pass passwords in environment/argv; host, port, user, password and optional verified TLS settings belong in the private connection file. MySQL options in that file are trusted operator configuration.
- The MySQL connection file and plaintext dump must be owner-only (`600` or `400` POSIX); backup directory must be owner-only (`700`). The POSIX helper refuses symlinks, foreign owners and unsafe modes. Windows helpers check ACLs and refuse access beyond the current account, SYSTEM and Administrators; a new staging directory receives a protected current-user ACL. Restrict any pre-existing staging directory/connection-file ACL before use. Do not share a writable staging path with other accounts. Use dedicated least-privilege DB accounts with only the grants required to dump/restore the intended schema (routines/events require their corresponding privileges); verify remote DB TLS separately.
- Dumps go to a private `.sql.part` file via native `--result-file` (no text decoding), then publish as completed `.sql` only after a zero exit and nonempty output. Failure removes the part and preserves older backups. Retention runs only after successful publication and matches this helper's completed filename format for the exact database; unrelated/manual SQL files and symlinks are excluded. Use a separate lifecycle policy for encrypted offsite objects. A successful dump process is not proof of recoverability.
- Restore with `restore.sh`/`restore.ps1` and `BACKUP_FILE` naming a private, nonempty, **trusted** plaintext SQL dump. Provision an existing target database and appropriate grants first; the restore helper never creates or drops a database. SQL itself is executable and may contain destructive statements: verify its origin and intended schema. Stop operational writes/queue/scheduler in a maintenance window, take a fresh protected backup, restore with exact confirmation, and independently verify schema, rows, app key, receipt/till totals and application workflows before reopening. Helpers check native exit codes and preserve UTF-8/binary bytes; they do not claim business verification.
- **Previous evidence superseded:** the earlier fixed-name, three-ASCII-row local drill did not exercise production helper scripts or Unicode correctness. It is not evidence for this revised tooling. The revised `infrastructure/local/backup-drill.php` refuses any host/port except `127.0.0.1:3307`, creates a random disposable DB exclusively, invokes the real production helper for the OS, compares all three Unicode/binary rows exactly, and removes only its own DB/staging files. It requires the existing private `.local/mysqladmin.cnf`, installed MySQL clients and PS7.4 on Windows. Do not run concurrently with test suites. The independent Windows run passed all three Unicode/binary rows and cleaned up its own random DB/files (08-test-checklist.md); Unix runtime remains unverified. This local pass does not establish staging/offsite recovery.
- No-DB regression checks: `pwsh -NoProfile -File infrastructure/prod/test-helpers.ps1` (Windows fake native CLI compiled with the existing Windows .NET Framework compiler), or `sh infrastructure/prod/test-helpers.sh` on real Unix. No compiler/tool is installed by these tests. They cover native dump/restore failures, partial cleanup/publication, exact Unicode bytes, retention boundaries, confirmation/identifier/permission guards. Readiness tests mock all dependencies and do not migrate/reset a database. POSIX helper runtime permission checks require a real Unix filesystem; Git Bash on Windows exits 77 rather than claiming permission proof.

## 6. Smoke test and pilot

- `BASE_URL=https://api.bizismart.co.tz sh infrastructure/prod/smoke.sh` — public `/health` + `/ready` only. `/ready` reports `{ready, checks:{database, migrations, cache}}` with 503 while degraded and no connection details. It requires a nonempty readable migration source, no pending migrations, and fresh unique write/read probes against shared configured application-cache and rate-limiter stores; array/file/null/failover stores cannot claim readiness. It does not prove queue processing, scheduler execution, provider delivery, R2, backup recovery or business checkout correctness. Test a tenant origin too so the same-origin API path is verified.
- Run independent remote CI and the production smoke/pilot checks: owner onboarding, cash sale end-to-end, R2 photo round-trip, Meta/Beem verification, scanner/printer/scale/display pilot, low-end Android offline/reload behaviour.

## 7. Monitoring guidance

- Watch: HTTP 5xx rate, `/ready` status, queue depth/failed jobs, scheduler heartbeat (`schedule:run` last exit), disk (backups, logs, R2), MySQL slow queries, Redis memory.
- Rotate Laravel logs (`storage/logs`) and supervisor logs; alert on `REPORT_TOO_LARGE`, repeated `SALE_CHANGED`/`STOCK_CHANGED` bursts and loss-alert creation spikes.
