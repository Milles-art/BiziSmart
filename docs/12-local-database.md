# 12 - Local MySQL on Windows

The BiziSmart development database uses the official MySQL 8.4.11 ZIP runtime under ignored `.local/`. XAMPP at `C:/xampp` runs MariaDB 10.4 on its own port; BiziSmart MySQL uses `127.0.0.1:3307`. No Windows service or system PATH change is needed.

Verified on 2026-10-09: both Laravel environments connect, normal stop/start succeeds, and UTC/InnoDB rollback/account boundaries pass. Core, roles/auth and encrypted audit migrations are applied; the backend suite passes 92 tests / 638 assertions on MySQL including staff management. Redis remains pending.

## First installation

Requires the project's PHP/Composer setup, `pdo_mysql`, Windows `curl.exe`/`tar.exe` and an existing `backend/.env` with its application key. From the repository root:

```powershell
./infrastructure/local/install-mysql.ps1
./infrastructure/local/mysql.ps1 Status
```

The installer downloads the [official MySQL package](https://dev.mysql.com/downloads/mysql/8.4.html), verifies its published checksum, extracts it and initialises a fresh directory. The first start assigns generated passwords through an init file; after configuration that file is deleted and the server restarts normally. It refuses to initialise an existing database directory. Do not delete `.local/mysql-data` to troubleshoot a startup problem: it contains the databases.

## Everyday use

```powershell
./infrastructure/local/mysql.ps1 Start
./infrastructure/local/mysql.ps1 Status
./infrastructure/local/mysql.ps1 Stop
```

Start launches the process in the background and waits for readiness. It needs starting again after a computer restart. Status verifies the version, UTC, InnoDB transaction rollback, and separate account permissions. Logs are private local files at `.local/mysql-error.log`. No Redis service is installed by these scripts; retain array cache/session and sync queues until Redis setup is completed.

## Database accounts and environments

- `bizismart`: development database and its own account, configured in ignored `backend/.env`.
- `bizismart_test`: separate test database/account, configured in ignored `backend/.env.testing`.
- Root administration credentials are stored in ignored `.local/mysql-credentials.json` and `.local/mysqladmin.cnf`; Laravel uses its own accounts.

Generated passwords are never printed or checked into Git. The two application accounts have privileges only on their respective database. Root is used only for setup/shutdown. Do not copy local credentials into documentation, CI or production. GitHub Actions supplies its own test credentials and port 3306.

Before running database-dependent tests, use `.env.testing` and verify `DB_DATABASE=bizismart_test`; no test should reset the development database. The existing API health route remains liveness only and does not check database readiness.

All destructive test paths now also verify the live connection using `SELECT DATABASE()`: its name must equal the configured MySQL `_test` database while the application is in `testing`. The shared assertion runs before default tenant fresh migrations, provider-test migrations/fixture cleanup, opt-in schema-reuse cleanup and browser fixture migrations/deletes. Changing configuration around an already-open connection therefore cannot redirect a test reset to a different database. Existing environment/name guards remain, and foreign-key checks are restored after fixture cleanup.

Current local batch verification can set process-only `BIZISMART_REUSE_TEST_SCHEMA=1` after a fresh schema has been verified. The tenant test trait reuses a schema only when every current migration is present, then clears committed race fixtures under a double guard: testing environment/configured MySQL `_test` name and the actual connected database name must match. Foreign keys are restored before assertions. Missing migrations still invoke Laravel's normal fresh rebuild. Default commands/CI retain ordinary fresh-schema verification; no production database or runtime safety setting is changed. Run only one suite at a time against the same test database.

Filament requires PHP `intl`. The local development API launcher enables the already installed extension for its own process when necessary; it does not edit global PHP configuration. For CLI verification on this Windows machine use the project-local extra ini directory (`$env:PHP_INI_SCAN_DIR=(Resolve-Path backend/.local/php).Path`) or `php -d extension=intl`. Production/CI must install/enable `intl` normally. New admin web sessions require a persistent file/database/Redis driver, separate host-only encrypted cookies and HTTPS in production; array sessions remain appropriate for tests.

Installation establishes empty databases. Apply the implemented core, role, auth and audit schema/demo data with `php backend/artisan migrate --seed` from the repository root. The current computer already has these migrations and two disabled demo shops, each with its approved role matrix. Sign-in is implemented, but demo owner accounts remain unusable until verified onboarding is built. Later supermarket features require additional migrations.

The development environment uses `TENANT_DOMAIN=localhost`. The user-selected frontend now displays its browser-local demo at `http://127.0.0.1:15173` and the existing demo-alpha.localhost address; its quick logins do not authenticate with Laravel. Backend host isolation remains enforced independently. Browser tests start a testing-mode API and seed synthetic cashiers into `bizismart_test` only, with an environment/database-name guard. Never run backend database resets concurrently with browser tests. The local limiter uses file storage across requests; Redis remains required for shared production cache/rate limits and workers.
