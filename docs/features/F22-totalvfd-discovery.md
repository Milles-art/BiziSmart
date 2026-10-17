# TotalVFD discovery — TRA remains last

**Paused by user under D099:** focus on BiziSmart software. The owner reports a one-year grace period; this is not independently verified and does not change tax calculations or certify ordinary receipts. No further provider research, Postman setup or fiscal implementation unless requested. Historical discovery/preparation notes below remain evidence only.

Read-only discovery on2026-10-15 found TotalVFD's October13 reply to “BiziSmart POS – Request for TotalVFD API Integration” in the connected Gmail account. It supplies developer documentation and sandbox access details. No email was sent, no sandbox login/submission performed, and no credentials copied to repository files.

Sources: https://developer.totalvfd.co.tz/ and the private provider email. The guide identifies a sandbox sales API, bearer authentication plus business scope, stable references for duplicate handling, and provider-returned fiscal verification fields. Production URL/access require provider approval.

Before implementation: confirm anonymous walk-in customer fields, per-item tax mapping and weighted precision, refund/correction/exchange semantics, ambiguous submission recovery, tenant-specific taxpayer onboarding and pricing/production approval. Sandbox credentials alone do not answer these contracts. Keep TRA disabled until the non-TRA hosting/design work is verified and these requirements are resolved. Never infer fiscal acceptance from an ordinary BiziSmart receipt or a test fixture.

## User-operated sandbox preparation (D098)

The user has now authorized preparing tests they will execute personally. See [15-totalvfd-sandbox-testing.md](../15-totalvfd-sandbox-testing.md) and `docs/sandbox/` for the blank private Postman environment, 15 fictional cash sale cases, exact replay and four invalid-request probes. The kit sends only when the operator supplies access, confirms applicable fixtures, arms one case and clicks Send. No sandbox login/submission was performed while preparing it. This is an isolated developer kit, not live POS fiscalization; production TRA remains disabled. Fiscal readiness is a commercial-launch gate, not a task to postpone until after real sales begin.
