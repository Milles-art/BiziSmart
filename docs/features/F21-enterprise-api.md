# F21 — Enterprise API (read-only integrations)

Owner-managed headless access for a shop's own systems (accounting exports, dashboards). Read-only by design: no financial mutation is reachable, so no mutation authorization matrix is needed. Mutations remain interactive staff workflows.

## Credentials

- Owners issue credentials at `POST /enterprise/tokens` `{name, expires_in_days?: 1-365 (default 90)}`. Response `{id: tok_ULID, name, abilities: ["enterprise:read"], token, expires_at}`. The plaintext `token` is shown once and never stored or listed.
- `GET /enterprise/tokens` lists metadata only (id, name, abilities, expiry, last use, creation). `DELETE /enterprise/tokens/{token}` revokes immediately; the token stops authenticating. Revoking a staff session id returns 404 and reveals nothing.
- Tokens are bound to the issuing owner and business (`personal_access_tokens.business_id` + composite user FK). Headless reads require an active current owner with exactly one role, no pending PIN change and confirmed TOTP; deactivation, expiry, owner demotion and revocation stop access. Cross-business tokens are rejected (401). Device blocking affects the originating interactive session, while a separately issued device-free integration key remains independently revocable/expiring.
- Interactive staff tokens carry `*` and are rejected on enterprise reads (403), even though Sanctum's `tokenCan` would accept them. Enterprise middleware accepts only a dedicated device-free token whose abilities are exactly `["enterprise:read"]` and whose expiry is present and future. Wildcard, mixed-ability and device-bound credentials cannot act as headless keys. An integration token cannot invoke ordinary interactive writes or issue/list/revoke integration credentials.
- Credential management refreshes and locks the interactive user, original token and approved device through the existing `FinancialActorService`, then verifies sole owner authority, exact interactive `*` abilities and an active locked business. Session revocation, device blocking, expiry, pending PIN/TOTP setup and role changes between middleware and service cannot mint or manage long-lived access using stale authority.

## Reads

All `GET`, tenant-scoped, public IDs, standard envelope and private/no-store. Collection endpoints retain their existing controller pagination contracts and `meta{page,per_page,total}` where provided; this does not add pagination to product detail or aggregate summary. Product/department/batch/movement/stock-alert/report collections accept their existing limits up to 100; packaging lists are fixed at 50 per page and till lists accept at most 50. Token metadata listing is not paginated. Routes:

- `/enterprise/products`, `/enterprise/products/{product}`, `/enterprise/products/{product}/packagings`
- `/enterprise/products/{product}/batches`, `/enterprise/products/{product}/movements`
- `/enterprise/stock/reorder-alerts`, `/enterprise/stock/expiry-alerts`
- `/enterprise/reports/summary`, `/enterprise/reports/sales`, `/enterprise/reports/stock`
- `/enterprise/departments`, `/enterprise/till-sessions`

They reuse the existing controllers/services, so money stays integer TSh, quantities stay exact strings, and buying prices appear only because the token acts as the owner (the owner's own data, at the owner's choice). Excluded by design: customers/debt (PII), payments, staff, devices, messaging, pricing configuration, and every write endpoint.

## Enforcement

- Feature flag `api_access` (`config/platform.features`, enforced per path prefix in `EnforceSubscriptionFeatures`) is an additional requirement. Aliases preserve the equivalent ordinary endpoint's module flags: reports, departments, inventory stock alerts and product batch/movement history cannot bypass disabled features through the enterprise prefix. Disabled shops get `FEATURE_DISABLED` (403). Baseline plans leave assignment to the platform administrator.
- Per-key throttle `throttle:enterprise` (120/min per business+token); excess gets `RATE_LIMITED` (429) with retry headers.
- Unknown methods/paths keep the standard 405/404 envelope.

## Verification

`EnterpriseApiTest`: issue/list/revoke with one-time plaintext, owner-only management, staff-session hiding, all twelve owner-level read aliases, dedicated-token enforcement, tenant isolation, `api_access` plus underlying module gating, and 405 on enterprise writes. The complete read-surface case seeds real packaging/receipt/cash-sale/till journals through existing services and checks nonempty response fields, exact remaining stock, expected drawer cash, standard envelopes and private/no-store headers. Additional regressions cover captured requester session revocation/device blocking/expiry at service entry, current PIN/TOTP/role requirements, headless expiry/demotion/deactivation and ordinary interactive write/credential-management rejection. All 15 enterprise cases passed guarded MySQL execution. Final enterprise/readiness/API-foundation header regression passes 40 cases / 387 assertions, including early feature/tenant/method failures; full prior backend regression passed 525 / 5317. See 08-test-checklist.md for exact sequencing. No live integration exists; credentials in tests are disposable.
