> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# F01 — Auth, Devices, Roles
**Goal:** staff log in fast with phone + PIN on approved devices; each role sees and does only what it should.
**Reads:** rules §8; schema users/devices/spatie; API Auth section.

## Flow
1. Tenant is resolved from the subdomain (`ResolveTenant`). Unknown/inactive subdomain -> 404.
2. `POST /auth/login` with phone, PIN, device_uid. Wrong phone or PIN -> `INVALID_PIN` (same message for both). 5 failures -> `ACCOUNT_LOCKED` 15 min.
3. Unknown device: create `devices` row `pending`; Owner is auto-approved on first device; others get `DEVICE_NOT_APPROVED` and the owner is notified (log for now, WhatsApp later). Approved device -> issue Sanctum token (expiry 30 days, rotate endpoint) named after the device.
4. Owner/Super Admin additionally require TOTP 2FA (Owner: after PIN, before token).
5. `GET /me` and `GET /me/features` drive the React UI (menus, hidden actions).
6. Frontend logs out after 30 min inactivity and on 401.

## Permission matrix (default roles)
| Permission | Owner | Pharmacist | Cashier | Stockkeeper |
|---|---|---|---|---|
| sell | Y | Y | Y | N |
| dispense_rx | Y | Y | N | N |
| refund / void | Y | Y | N | N |
| give_discount | Y | Y | N | N |
| view_buying_price | Y | N | N | Y |
| manage_products | Y | N | N | Y |
| receive_stock / adjust_stock | Y | N | N | Y |
| manage_customers | Y | Y | Y | N |
| manage_till | Y | Y | Y | N |
| approve_variance | Y | Y | N | N |
| view_reports | Y | N | N | N |
| view_controlled_register | Y | Y | N | N |
| manage_users / manage_settings | Y | N | N | N |

## Edge cases
- Deactivated user: tokens revoked immediately. Device blocked: tokens for that device revoked.
- Same phone in two businesses is allowed (unique per business).
- PIN reset by Owner; user must change PIN at next login (`must_change_pin`).
- Never log the PIN. Throttle login by phone+IP.

## Acceptance criteria
- [ ] Correct login returns token + permissions; wrong PIN returns `INVALID_PIN` without revealing which field was wrong.
- [ ] 5 wrong PINs lock the account for 15 minutes (`ACCOUNT_LOCKED`).
- [ ] New device for a non-owner returns `DEVICE_NOT_APPROVED`; after owner approval login works.
- [ ] A cashier token cannot call a `manage_products` endpoint (403 `PERMISSION_DENIED`).
- [ ] A token from business A used against business B's subdomain returns 401/404.
- [ ] Tenant leak tests in `08-test-checklist.md` section A pass.
