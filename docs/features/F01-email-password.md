# F01 — Email/password authentication (D109)

The user selected email/password without an authenticator for shop users and platform administrators. This supersedes earlier mandatory MFA for email-enrolled accounts. Preserve tenant isolation, active-account/device approval, permissions, per-operation grants and session expiry. No public shop creation or open staff registration.

## Flows and rules

- Owner registration retains the private one-use shop invitation, name/contact phone, branch/scaffold rules; adds email/password/confirmation and signs in directly without TOTP.
- Returning staff use email/password on their own shop host. Email uniqueness is per business, case-insensitive, and reserves deleted-user addresses. Separate shops may use the same email without sharing access.
- New passwords require12characters minimum,72UTF-8 bytes maximum and no NUL. Reject long bcrypt inputs instead of silently truncating. Show/hide, paste and password-manager entry are supported.
- Existing phone accounts activate through a private30minute one-use link issued by a currently authorized owner or trusted CLI `bizismart:email-activate {subdomain} {public_user_id}`. The link binds tenant/user and cannot replace credentials of an already enrolled account. Existing authenticated legacy users may enroll after proving their current PIN. Never guess emails or reset owners automatically.
- Staff creation retains name/contact/role/branch; no temporary-PIN entry in the UI. The owner creates a private setup link so staff choose their own email/password.
- Forgot password sends a30minute one-use link to the existing email. Known/unknown addresses get the same generic response. Globally unconfigured mail returns503 PASSWORD_RESET_UNAVAILABLE. SMTP failure retires the uncertain token without retrying or exposing provider details. No token enters log mailers or plaintext queue jobs. Submission is not proof of inbox delivery.
- The user chose support@bizismart.com as sender. Actual transport/mailbox setup and delivery require verification; no credentials in source or chat.
- Super Admin retains its separate email/password guard, active/admin/IP/session/CSRF checks and disabled remembered recall. Remove mandatory MFA enrollment and action gates. Reset uses its own broker/table and synchronous notification.

## Added API

Existing JSON envelope, no-store/private and no-referrer; tenant from trusted shop host, never business_id input.

- POST `/auth/email/login`: email,password,device_uid,device_name → existing200session token/user; no challenge/MFA keys. Unknown credentials401 INVALID_CREDENTIALS. Five failures lock15minutes; tenant/email/IP rate limits remain.
- POST `/auth/email/setup`: invitation_token,name,phone,email,password,password_confirmation,device_uid,device_name →200session, authoritative invitation/branch rules.
- POST `/auth/email/forgot-password`: email → generic message, or global503 PASSWORD_RESET_UNAVAILABLE.
- POST `/auth/email/reset-password`: token,password,password_confirmation → completed:true.
- POST `/auth/email/activate`: token,email,password,password_confirmation → completed:true; sign in separately.
- POST `/auth/email/enrollment`: authenticated current user; email,password,password_confirmation,current_pin → completed:true; sessions revoked, sign in again.
- POST `/auth/email/activation-link`: authenticated owner; user_id → setup_url/expires_at. Only active tenant-owned unenrolled targets qualify. Link stays transient and mutation cache is cleared.
- POST `/auth/supervisor-check` additionally accepts email/password with permission,operation_id,payload_hash. Email-enrolled approvers need no code; preserve branch eligibility, exact snapshot/requester/token/device binding, five-minute expiry and single use. Wrong supervisor credentials must not sign the cashier out.

Private fragments #setup/#activate/#reset are removed from the address bar and remain only in component memory. Do not persist emails/passwords/challenges. Existing approved-sessionStorage/device rules remain.

## Storage and migration

Migration000042 adds nullable encrypted email/HMAC lookup/hashed password and tenant-scoped hashed credential tokens. Migration000043 adds separate admin reset storage. Retain historical PIN/TOTP columns; never falsify confirmation timestamps. Credential changes revoke access tokens, offline leases, auth challenges, supervisor grants and all credential links. Deactivation/deletion revokes links too. No stock/sales/account/APP_KEY overwrite.

Legacy PIN/TOTP endpoints serve only unmigrated accounts during explicit activation and backward-compatible consumers. Enrolled accounts cannot use old PIN login, approval or PIN reset. The shipped UI offers email login only. Domain owner-readiness accepts enrolled email accounts without historical TOTP metadata; device and authorization checks remain mandatory.

## Acceptance

Test invite/activation/reset expiry and replay, cross-shop lookup, email collisions, bad/locked credentials, pending/blocked devices, mail unavailable/failure, session/grant/lease revocation, admin limits/session/reset, email-only owner tills/sales/stock/approvals and reload behavior. Run guarded backend/browser suites sequentially. Production delivery, real-user activation and physical hardware are independent acceptance gates.
