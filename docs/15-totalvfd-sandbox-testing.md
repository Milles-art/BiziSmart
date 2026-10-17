# TotalVFD sandbox testing — operated by the owner

Prepared 2026-10-15 from the [TotalVFD guide](https://developer.totalvfd.co.tz/). This kit is separate from BiziSmart POS and its stock, tills, customer records and databases. It prepares manually operated API checks; it does not implement production fiscalization. All products/prices in the kit are fictional fixtures. No provider login or request has been performed by preparing or checking it.

## Start here

Use the current **Postman desktop application** as the test screen. No terminal is needed to operate the requests. You sign in to TotalVFD yourself, supply your private test access, review a request, and click **Send**. The account owner/provider may see the resulting activity. The local operator label is your own note, not verified identity or a replacement for separate credentials. AI assistance is not hidden; browser identities, account history and user agents are not impersonated.

1. Obtain permission for this sandbox business and serial. Ask TotalVFD for approved test customer identities, test mobile number, fictional tax-group fixtures and confirmation of anonymous/NIL fields. Do not use someone else's real customer data or invent identities that appear genuine. Case 01 can be run first with the approved TIN fixture; unavailable identity cases remain blocked.
2. Import `docs/sandbox/BiziSmart-TotalVFD.postman_collection.json` and `BiziSmart-TotalVFD.blank.postman_environment.json` into a **private, local Postman workspace**. Avoid cloud synchronization/sharing of populated environments, history and responses. If local/private secret handling cannot be assured, stop and arrange an approved developer workspace before entering credentials.
3. Open the provider's [sandbox login page](https://test.totalvfd.co.tz/api/login) yourself. Obtain the bearer token/business ID/assigned serial using their instructions. No login endpoint or credential extraction script is supplied. Ask the provider for help if these values are not available. Do not paste them into this chat.
4. Select the imported environment. Enter the private values below only in the local environment; keep shared values blank, mark the token sensitive, and do not export/sync the populated environment. Masking a field is not access control. Leave `_run_binding`, `_frozen_requests` and `_results` blank initially; the kit maintains these.
5. In Postman's request settings, turn **Automatically follow redirects OFF** and keep TLS certificate verification ON. Each request also specifies `followRedirects: false`. Use a client supporting [`pm.execution.skipRequest()`](https://learning.postman.com/v11/docs/tests-and-scripts/write-scripts/postman-sandbox-reference/pm-execution). Before entering secrets, click Send with the blank environment: it must be skipped with **no HTTP response**. If it is sent, stop and update/fix the client.
6. Read the request's Body and the matching case in `fictional-cases.json`. Body placeholders are for review; the pre-request script builds the same fixed fixture using your environment. Editing Body is not how to customize these checks. Review the Scripts tab too; there are no hidden/background requests.
7. Set `send_case` to that request's ID, for example `01`. Click **Send once**. This value is consumed, so another submission needs another deliberate arm. Do not use Collection Runner, monitors, CLI automation or scheduled runs. Do not launch concurrent copies of the same environment.
8. Inspect **Test Results**, the HTTP status and response body. Record the outcome in the results sheet. Returned `pending` remains pending; a passing response-shape check is not proof of fiscal acceptance, certification or legal validity. Do not give sandbox receipts to customers as legal receipts.

## Private environment values

- `sandbox_token`: token only, without `Bearer `. Never put it in source code or a frontend application. This kit uses a private developer HTTP client, not the public shop frontend.
- `sandbox_business_id`, `sandbox_serial`: the business and linked sandbox VFD assigned by TotalVFD. Confirm them before the first send; wrong credentials may still create activity on a business the account can access.
- `sandbox_access_confirmed`: `YES` only after confirming authorization for that business/serial.
- `operator_label`: your actual name or agreed tester label. Stored in local evidence only; not sent as a fabricated salesperson/account identity.
- `sandbox_run_id`: a new label such as `BIZISMART-TEST-SHOP1-20261015-01`. Do not reuse another tester's label. The kit binds the environment to its original business, serial and run after the first attempt.
- `test_customer_name`, `test_customer_mobile`: provider-approved fictional customer values. Mobile must be a local ten-digit number. There is deliberately no prefilled number that could belong to a real person.
- `test_tin`, `test_dl`, `test_voter_id`, `test_passport`, `test_nid`: approved test values for their corresponding identity types. Fill only cases the provider permits. A format check does not establish the identity's validity.
- `approved_test_tax_groups`: comma-separated groups the provider permits for fictional fixtures, for example `A,C,D,E` **only if confirmed**. These are not your shop's product tax assignments. Group B is excluded pending eligibility/eMoney contracts; the guide flags its rollout as requiring confirmation.
- `nil_contract_confirmed`: `YES` only after TotalVFD confirms the anonymous customer's name, mobile and `idValue` for type 6. Then set `test_nil_value` to the confirmed nonempty test value. If an empty NIL value/mobile is required, leave case 06 blocked and report the contract so we can adapt it; do not invent substitute identity data.
- `two_decimal_weights_confirmed`: `YES` after approval to probe the supplied two-decimal quantities. This does not resolve BiziSmart's exact three-decimal kilogram mapping.
- `negative_tests_confirmed`: `YES` only after the provider permits intentional invalid sandbox requests.
- `send_case`: exact ID of the single reviewed request you will send next. It starts blank and is cleared before every attempted request.

## Prepared cases and what to inspect

These are cash-only provider fixtures. `price` is the **whole line total**, not a unit price. Discounts are on quantity-one lines to avoid assuming undocumented per-unit discount semantics. Integer money and supplied line totals are fixtures, not a replacement production tax/rounding calculator.

1. **01:** one item, TSh 2,500; TIN identity type 1. Start here.
2. **02:** quantity 2, whole line TSh 5,000; driving licence type 2. Check the provider does not multiply the line total again.
3. **03:** two lines, TSh 3,500; voter identity type 3.
4. **04:** TSh 2,500 less TSh 200, payable TSh 2,300; passport type 4.
5. **05:** two discounted lines, payable TSh 3,700; NID type 5.
6. **06:** anonymous/NIL type 6, TSh 1,000; blocked until the anonymous contract is confirmed.
7. **07:** quantity 1.25, line TSh 5,000. A quantity probe; it establishes no three-decimal support.
8. **08:** quantity 0.01, line TSh 100. Same qualification.
9. **09:** TSh 1,101. Inspect provider-returned tax/total rounding; do not infer production rounding rules from one result.
10. **10:** fictional group C, TSh 1,000.
11. **11:** fictional group D, TSh 1,000. C and D are not assumed interchangeable for real products.
12. **12:** fictional exempt E, TSh 1,000.
13. **13:** mixed A/C/E, TSh 4,000. Inspect returned tax breakdown per group.
14. **14:** quantity 3, whole line TSh 7,500.
15. **15:** weighted and counted lines, payable TSh 3,500.

The provider guide asks for at least 15 transactions plus applicable identity, tax and payment coverage. Fifteen prepared cases are **not** fifteen completed or approved transactions. If a case is blocked, it does not count. Ask the provider which cases/payment types are required for the shop's initial cash walk-in scope; this kit does not claim their full certification checklist is satisfied. Card, cheque, invoice and eMoney cases are not silently enabled. Mobile settlement stays deferred.

Additional checks:

- **DUP:** repeats case 01 using its exact saved body and reference. Expect HTTP 409 with the same existing receipt and a duplicate message. A new 201 here fails the duplicate check; investigate rather than inventing another reference.
- **NEG-TOTAL:** payable exceeds item total by TSh 1. Expect validation rejection.
- **NEG-QTY:** zero quantity. Expect validation rejection.
- **NEG-TAX:** invalid tax group. Expect validation rejection.
- **NEG-SERIAL:** serial omitted. Expect validation rejection.

Invalid probes pass only for 400/422. A 401/403 is an access problem, not evidence that the invalid data was checked. A 201/409, unexpected status or different error contract needs review with TotalVFD. Stop rather than repeatedly submitting.

## Failed, uncertain and pending requests

The request body is frozen locally **before** a network attempt. The environment records `awaiting-response-or-uncertain` before sending. No automatic retry occurs. If a timeout occurs or you close the app after Send, do not change the run/reference, customer or item data to try again. Re-arm the **same case** and send its saved request. A 409 is handled using the existing sale in `data`; a first successful attempt uses the direct 201 sale object. The check compares reference, serial, total and required receipt fields. Review the provider's actual status separately.

Keep the same environment/private journal across restarts. Do not clear `_frozen_requests`, `_run_binding` or `_results` while any request is unresolved. If a journal is lost, recover/reconcile with TotalVFD before another send. This manual client has no server-side lock, automatic status polling or independent receipt-verification service; it is not the production retry ledger. Never retry a live shop sale through this kit.

`_results` stores local operator labels, attempt counts, references, HTTP/provider status and returned receipt fields. It deliberately stores no token or full provider response. Postman itself may retain request/response history, including sensitive access data: keep the workspace private, follow its secret-handling policy, and remove credentials before any exports/screenshots. Copy only reviewed, nonsecret results into `results-template.md`; share no credentials or real customer identifiers.

## Still requires implementation or provider confirmation

- Anonymous walk-in fields, shop-specific tax mapping and taxpayer/serial onboarding.
- Exact three-decimal kg mapping and pricing/discount/rounding semantics for the live POS.
- Refunds, cancellations, exchanges and credit-note contracts. The public guide does not document refund endpoints; none are invented here.
- Provider status polling/final fiscal acceptance, outage procedure, durable tenant-scoped retry ledger and production receipt QR/reprint integration.
- Supported payment coverage, Z-report reconciliation, account audit visibility, certification and production activation.

Staging and training can proceed with test records. **Commercial launch as the shop's sole receipt system remains blocked** until production fiscalization is approved and verified. Any temporary use alongside an existing approved EFD/VFD requires the business to confirm its compliant process with TRA or its tax adviser; ordinary BiziSmart receipts must not be represented as fiscal receipts.

## Local maintenance and verification

From the repository root, developers can regenerate the blank files with `node infrastructure/sandbox/build-totalvfd-kit.mjs` and check them with `node --test infrastructure/sandbox/totalvfd-kit.test.mjs`. Neither command makes network calls. The checks execute the generated Postman JavaScript using synthetic offline responses; they do not establish a real Postman import or provider acceptance. No app route, dependency, database or shop setting changes for this kit.
