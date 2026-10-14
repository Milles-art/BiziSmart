# F01 — Auth, Devices, Roles
**Goal:** staff log in fast with phone + PIN on approved devices; each role sees and does only what it should.
**Reads:** rules §8; schema users/devices/spatie; API Auth section.

## Flow
1. Tenant is resolved from the subdomain (`ResolveTenant`). Unknown/inactive subdomain -> 404.
2. `POST /auth/login` with phone, PIN, device_uid. Wrong phone or PIN -> `INVALID_PIN` (same message for both). 5 failures -> `ACCOUNT_LOCKED` 15 min.
3. Unknown device: create `devices` row `pending`; the first owner device is approved only after completing TOTP and any forced PIN change. All subsequent unknown devices get `DEVICE_NOT_APPROVED` and the owner is notified (public-ID log for now, WhatsApp later). Approved device plus completed authentication -> issue Sanctum token (expiry 30 days, rotate endpoint) named after the device.
4. Owner/Super Admin additionally require TOTP 2FA (Owner: after PIN, before token).
5. `GET /me` and `GET /me/features` drive the React UI (menus, hidden actions).
6. Frontend logs out after 30 min inactivity and on 401.

## Approved permission baseline
The user approved the Manager column on 2026-10-09. Managers may sell, manage customers/tills and approve refunds, voids, discounts and till variances. They cannot see costs/financial reports, manage products/stock or change staff/settings. The role permits these operations; detailed till/department resource scope is specified with the respective feature. There is no department-assignment policy yet.
| Permission | Owner | Manager | Cashier | Stockkeeper |
|---|---|---|---|---|
| sell | Y | Y | Y | N |
| refund / void | Y | Y | N | N |
| give_discount | Y | Y | N | N |
| view_buying_price | Y | N | N | Y |
| manage_products | Y | N | N | Y |
| receive_stock / adjust_stock | Y | N | N | Y |
| manage_customers | Y | Y | Y | N |
| manage_till | Y | Y | Y | N |
| approve_variance | Y | Y | N | N |
| view_reports | Y | N | N | N |
| manage_users / manage_settings | Y | N | N | N |

## Role implementation
Spatie permission teams use `business_id` from TenantContext; they cannot select a different business. The permission vocabulary is global and each shop owns its four roles. Role/membership models fail closed, membership composite foreign keys require staff and role to belong to the same shop, and role-permission writes validate the role's shop. Permission cache keys include the shop; nested jobs restore the earlier permission context. Cached permission checks on a retained user from another shop fail.

`StaffRoleService::provision()` idempotently installs this matrix for the current shop. `assign()` replaces the user's role and clears direct grants; `role_hint` alone gives no authority. Laravel Gate and the `permission:<name>` API middleware enforce permissions. These internal services have no public role-edit endpoint. Demo owners receive the owner role but remain disabled with no usable PIN/token.

Login/lockout, owner TOTP enrolment/verification, forced PIN change, owner PIN reset, device approval/blocking, logout/rotation and session endpoints are implemented. React supports sign-in, authenticator setup/verification, PIN change and sign-out. User-approved remembered sign-in now survives reload in the same tab: sessionStorage contains only the device-bound token, expiry, last activity and device UUID. Restored Redux identity is null and `/me` must succeed before displaying the workspace; permissions/user data are fetched afresh. PINs, challenges and authenticator secrets are never persisted. Reject expired/idle/malformed/device-mismatched records; reload cannot extend the 30-minute inactivity window. Storage failures fall back to memory-only access. Explicit sign-out revokes the server token and clears storage/cache; inactivity clears the local remembered token rather than shortening server expiry. Only a non-secret browser device UUID is stored in localStorage. Mutation results are removed after each authentication request and unauthorized-session 401s clear the session/cache/storage. Supervisor PIN/TOTP errors preserve the requester session while rejecting approval. Laravel device blocking and security-change revocation remain authoritative.

Owner staff list/create/update/deactivation API is implemented with active-staff plan limits, canonical phone uniqueness, approved roles, active branch validation, last-owner protection and token/challenge revocation after profile changes. Staff management audit records list changed fields without sensitive values. Owner staff/device management UI and trusted local initial onboarding are implemented. Supervisor operation approval is implemented; production verified onboarding/TOTP recovery remains pending. Model deactivation/deletion already revokes tokens/challenges. Laravel demo owners remain disabled; backend browser fixtures use synthetic staff only in the separate test database. Super Admin authentication belongs to F07.

The active BiziSmart-fixed.zip design is connected to Laravel through src/features/live. Initial owner setup, authenticator enrolment/verification, forced PIN change, staff management and device controls use real endpoints. No prototype account can select a server role/token. A trusted local operator creates a hashed, one-use 90-minute invitation; the owner privately chooses phone/PIN and must enrol TOTP. Existing operational owners, including inactive/deleted owners, cannot be reset through initial onboarding. Only the exact untouched disabled seed scaffold can be converted; later seeding does not recreate it. Production onboarding/recovery remains pending.

## Edge cases
### S2 auth contract details
- Staff phones accept `0` plus nine digits, `255` plus nine digits or `+255` plus nine digits; spaces/hyphens are removed and encrypted storage/lookup use `255XXXXXXXXX`. Other country formats are rejected. Existing staff phone indexes are rebuilt by the new migration without changing APP_KEY.
- A correct PIN returns either a full 30-day device-bound token or HTTP 202 with a random, hashed, five-minute `challenge_token`, `next_step` and `expires_at`. Challenges are not Sanctum tokens. Starting another successful login invalidates that user's older challenges.
- Owner next steps are `enroll_totp` (no confirmed secret) or `verify_totp` before any forced `change_pin`. Other staff use `change_pin` directly when required. Completing all steps returns the normal token response. New PIN must differ from the current PIN.
- TOTP uses six digits, 30-second periods and a one-period clock window. Accepted time steps cannot be reused, including across simultaneous challenges. Enrolment returns a local setup secret/otpauth URI only on its challenge response; no external QR service sees the secret. Five wrong codes invalidate the challenge. TOTP recovery/reset requires a separate verified onboarding/admin recovery design; no PIN-only bypass or reset endpoint.
- Devices represent shared shop hardware, not exclusive user ownership. `user_id` records the registrant. The shop owner may approve the first owner device only after completing TOTP, with the business row locked to prevent two first devices. All subsequent unknown devices, including owner devices, stay pending until approved from an existing owner session. Blocking revokes every token for that hardware.
- Full tokens require an approved device, an active user, no pending PIN change and (for owners) confirmed TOTP. Nullable device IDs on old internal/test tokens cannot access the published auth/session routes.
- Changes use user/business row locks and transactions. Failed PIN/TOTP counts commit before returning errors. Same user lock order serializes reset, lockout, challenge completion and token issuance.
- Auth responses use `Cache-Control: no-store`; sensitive fields are hidden from model JSON/validation flash/logs. Device notifications log public IDs only until messaging is implemented.

- Deactivated user: tokens revoked immediately. Device blocked: tokens for that device revoked.
- Same phone in two businesses is allowed (unique per business).
- PIN reset by Owner; user must change PIN at next login (`must_change_pin`).
- Never log the PIN. Throttle login by phone+IP.

## Acceptance criteria
- [x] Approved matrix grants exactly the 14 permissions through Laravel Gate; manager/cashier cost/report restrictions and stockkeeper restrictions are tested.
- [x] Tenant roles, memberships and permission caches are isolated, including nested contexts and retained models; role changes remove prior grants.
- [x] Correct login returns token + permissions; wrong PIN returns `INVALID_PIN` without revealing which field was wrong.
- [x] 5 wrong PINs lock the account for 15 minutes (`ACCOUNT_LOCKED`).
- [x] New device for a non-owner returns `DEVICE_NOT_APPROVED`; after owner approval login works.
- [ ] A cashier token cannot call a `manage_products` endpoint (403 `PERMISSION_DENIED`).
- [x] A token from business A used against business B's subdomain returns 401/404.
- [x] Implemented auth/core tenant leak tests pass; product/stock cases remain pending with their features.
- [x] Owner TOTP enrolment/verification, code replay rejection and forced PIN order are tested.
- [x] PIN reset, device blocking, user deactivation/deletion and rotation revoke the relevant tokens/challenges.
- [x] Frontend challenge steps, errors, mutation cleanup, protected 401 and 30-minute inactivity are tested.

## Supervisor operation approvals
S2 now implements authenticated `/auth/supervisor-check` and `SupervisorApprovalService`. Owners/managers can approve only refund, void, discount and till variance permissions. Managers enter phone/PIN; owners also provide a fresh authenticator code. A grant lasts five minutes and authorises exactly one operation UUID/snapshot on the requester's current shop/session/device. It does not sign in the supervisor, issue broad permissions, create a sale or bypass business rules. The consume service locks/revalidates the actors/session/device/grant, rejects any changed binding, and atomically commits consumption with the domain operation. Profile/role/PIN/activation/session revocation invalidates outstanding grants. No standalone approval UI exists before real till/refund/discount workflows. See the API contract for exact fields, canonical snapshot hashing and errors.

D071 (2026-10-10) resolves till resource scope: managers oversee and approve variances within their assigned branch; cashiers manage their own till; owners oversee all tenant branches. Enforced variance grants use TillAccessService and recheck current branch assignment at consumption. Till lifecycle remains pending F04 implementation.
