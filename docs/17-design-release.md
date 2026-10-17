# Frontend design release on the existing cPanel account

D105 is an authorized frontend update for BiziSmart's current shop screens. Keep the existing backend, database, account configuration, .env, APP_KEY and provider settings. This is not a catalogue/opening-stock import or fiscal integration.

The shared public root is `/home/afyabgzo/bizismart.com` for both `bizismart.com` and `freshchoice.bizismart.com`. The private application is `/home/afyabgzo/bizismart/backend`; admin uses its existing public directory. Do not change any domain document root, the Family Choice storefront or AfyaGuard.

## Verify and package

Run the frontend tests with one worker, typecheck, lint and the full PWA build. Run the real desktop and phone browser checks sequentially against the guarded local test schema. Preserve financial, tenant, auth and pending-request assertions; browser provider fixtures do not verify production delivery/payment. Review fresh screenshots, including the desktop cart column and phone cart pointer/keyboard controls.

`infrastructure/cpanel/package-design.ps1` packages only allowed files from `frontend/dist`. It hashes every source and verifies every decompressed ZIP entry, then writes a JSON manifest and a SHA256 file list with explicit LF line endings for Unix sha256sum. Complete PWA generation before invoking it. The archive excludes backend/PHP, .env, mockup/test images, credentials, gateway.php and .htaccess.

## Connect and stage

This computer uses a dedicated key outside the repository and a separate OpenSSH configuration, `C:/Users/DIGITAL STAR/.ssh/bizismart_config`, alias `bizismart-deploy`. The server is `server241.web-hosting.com`, port21098, accountafyabgzo. Its host fingerprints were verified against loopback output from the user's authenticated cPanel Terminal. Strict host checking stays enabled. Never upload or print the private key or include it in an archive.

Use SFTP with that configuration to upload the ZIP, its SHA256 file list and `infrastructure/cpanel/deploy-design.sh` privately under `/home/afyabgzo/bizismart`. Verify the ZIP hash and script syntax there. Staging files privately is not a deployed update.

## Install and verify

The deployment helper takes the private absolute ZIP path, its verified SHA256 and a unique `design-YYYYMMDD-HHMMSS` release name. It validates exact realpaths and allowed archive entries, obtains a deployment lock, verifies staged files, checks live readiness, and creates a private tar rollback backup. It installs hashed assets first, retains older assets for open clients, and switches index.html with a same-filesystem rename. It verifies gateway/.htaccess preservation, every installed entry, HTTPS entry pages and health/readiness. Failure after installation begins restores the prior public files from the backup; unused new hashed assets may remain.

Run `infrastructure/cpanel/smoke-design.mjs` against the generated manifest and actual release name after installation. It independently checks public bytes for every packaged file on both HTTPS domains and verifies health/readiness. `smoke-hosted-design.sh` performs actual HTTPS GET/hash checks from the hosting connection; record the connection vantage explicitly. `check-hosted-design.mjs` checks fresh desktop/phone public sign-in screens without credentials. Its optional `--slow-link`120s first-load diagnostic is not a change to application/API or regression deadlines. A transport error or incomplete download is not passing browser acceptance even when HTTP200 was received. These checks do not impersonate the owner or record a sale. Owner acceptance should use their existing login and real configured stock. Finish any unconfirmed operation, then close/reopen all shop tabs to activate the PWA update; never clear encrypted offline records or browser data to force an update.

Record the actual artifact hash, release name, private backup location, test results and hosted checks in NEXT-CONVERSATION and08-test-checklist. A successful local build or private upload alone is not deployment success. The earlier owner-branch repair is a separate backend hotfix and must be tracked separately.

## Installed D105 release

D106 follow-up: fresh hosted desktop and Pixel7 sign-in checks now pass with normal30s timeout, no script errors or overflow. Actual screenshot review passed; earlier external download failures are historical. Authenticated operator/physical hardware acceptance remains separate. No public bundle or transport configuration was changed in this follow-up.

On2026-10-16 installed `design-20261016-060327`, ZIP678517bytes/16entries, SHA256 `9f1e4b0b09b5fc86b18b7baa40f9fa84a0deb0988727e768401f3726a01fa88b`. Private rollback archive: `/home/afyabgzo/bizismart/backups/design-20261016-060327-public.tar.gz` with its checksum. All installed files and32 public HTTPS responses verify from the hosting connection; health/readiness pass on both shop domains. Local229 frontend tests and50 desktop/phone browser checks pass. External desktop guest loading encountered an interrupted HTTP/2 JS download; phone/operator acceptance remains open. D105 supersedes older D104 public ZIPs. No PHP, database, secrets, account, gateway/.htaccess or unrelated site changes were installed.
