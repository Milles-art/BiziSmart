# Walk-in launch readiness

This is the operational release checklist for D089. The requested software batch is implemented locally under F17–F20 and F03-completed-void-audit. Executed acceptance evidence is recorded in `08-test-checklist.md`; pending checks must not be treated as passing.

## Production setup

- Provision hosting, MySQL 8/InnoDB, PHP with required extensions (including intl), private secrets, HTTPS and tenant/admin DNS. Install the locked dependencies, publish Filament assets and apply additive migrations through 000039. Do not reset operational data.
- Provision a platform administrator interactively with `php artisan bizismart:admin-create`; enroll the required authenticator. Assign plan features explicitly and select real subscription dates. Baseline prices/limits can be seeded from the panel; features start disabled.
- Onboard verified owners through private, expiring, one-use setup links. Owners choose a PIN and enroll TOTP. Lost-authenticator recovery is deliberately disabled until verified identity rules are supplied.
- Configure shared production cache/rate limiting, persistent secure admin sessions, queue workers where required and Laravel's minute scheduler. Verify dispatch/cleanup/loss-alert logs, time zone, monitoring and worker restart procedures.
- Set up encrypted backups and perform an actual restore drill. Configure and verify private R2 access before using product/expense photos. Test protected object reads and removal/cleanup against the real bucket.
- Run independent remote CI and the production smoke/pilot checks. Local tests do not certify hosting or real devices.

## WhatsApp and SMS

Server secrets are listed, empty, in `backend/.env.example`. Bind credentials to the intended `MESSAGING_BUSINESS_PUBLIC_ID`; other shops remain unconfigured.

Meta needs a phone-number ID, token, app secret, verification token, chosen supported Graph version and approved utility template/language with one BODY text parameter. Configure the public HTTPS webhook and verify signatures and real delivery reports. Beem needs key/secret, approved sender and account credits. Authenticated SMS delivery-report polling is implemented through the official API, with five-minute checks and exact recipient/request identity. Run `messaging:reconcile-sms` through the scheduler and verify actual provider reports. GSM text is supported; other Unicode is blocked with `SMS_ENCODING_UNVERIFIED` until the provider encoding/multipart contract is verified. Current software distinguishes queued, blocked, accepted, delivered, failed and uncertain; acceptance is never delivery. Uncertain sends are not resent automatically.

Record real customer channel/purpose consent. Enable desired summaries, debt reminders and renewal notices explicitly, and run the scheduler. Verify real opted-in recipients and provider charges in the pilot; no live provider delivery has been performed by local tests.

## Shop devices

Select the actual scale and label layout, then configure and test its EAN-13 product/gram slices and checksum. Exact registered catalogue/pack barcodes take priority. Manual exact weight entry remains available. Vendor serial/USB protocols and remote tablet pairing need the selected hardware contract; the current customer display is a same-device pop-out.

Pilot the scanner, camera, thermal printer, QR cards, customer display and low-end Android offline/reload behaviour. Browser printing and generated QR/scale tests are software evidence, not physical compatibility certification.

## Scope and honest report limits

Bank statement matching uses explicit exact completed-payment evidence; it does not download bank feeds or execute transfers. Supplier schedules are advisory. Historical price comparison uses received base-unit costs. Classified stock losses carry immutable cost/department snapshots; batch stock is business-wide. Sales department economics use current assignments because historical receipts did not capture sale-time departments. The UI labels these bases.

Selcom and exchanges requiring mobile settlement are deferred for the initial walk-in launch. Keep those credentials empty. Multi-branch expansion and enterprise API access remain outside this batch. TRA is the final integration gate and stays disabled until the official integration and shop compliance requirements are verified.
