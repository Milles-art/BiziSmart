# Email/password rollout — D109

User-approved policy: email/password only, no authenticator for shops or platform admin. Source contract: features/F01-email-password.md. Sender selected by user: support@bizismart.com. Implementation is local until final checks/deployment evidence below is filled; D108 remains last deployed baseline.

## Existing accounts

Never assign guessed addresses or passwords and never recreate an owner. Nullable credential columns preserve current phones, branches, journals and historical auth metadata. After the additive migrations, an existing signed-in owner may open Staff and create a private sign-in link for their own or another active unenrolled account. Give each link only to the verified account holder. They choose email/password; consuming it invalidates every prior session/grant/offline lease and requires sign-in again. Already enrolled accounts use self-service email reset rather than owner credential replacement.

If no shop session is available, a trusted deployment operator uses `php artisan bizismart:email-activate freshchoice usr_EXACT_PUBLIC_IDENTIFIER` in the private backend directory. Use the actual public owner identifier from a tenant-scoped trusted inspection. Do not substitute a phone number or assume all shop users are owners. This prints a private30minute one-use link; do not put it in Git, logs, public releases or external messages. Never expose an unrestricted public phone-claim endpoint.

Legacy auth endpoints remain only for unmigrated compatibility; the new interface offers email sign-in. Once enrolled, the old PIN cannot authenticate or approve. No TOTP timestamp is forged and historical secrets are not deleted. Standard device approval remains, including the initial-owner first-device bootstrap and blocked/pending device refusals.

## Mail

Read-only host check found executable sendmail paths but no support mailbox at the expected account maildir. This is not delivery acceptance. Create/confirm support@bizismart.com in cPanel Email Accounts; retain its credentials privately. Configure a real SMTP transport or reviewed sendmail path and valid MAIL_FROM_ADDRESS. Do not enable log/array/failover for password reset tokens. SMTP timeout defaults10seconds. Confirm actual reset delivery to a specifically authorized recipient and consume the link once; a generic request response does not prove inbox delivery. Until real transport is configured the shop API returns PASSWORD_RESET_UNAVAILABLE and admin UI reports email unavailable.

## Release boundaries

Only reviewed application/auth/config/migration files and the fully built public assets belong in the update. Preserve .env, APP_KEY, vendor, private storage, other sites and current gateway/.htaccess. Obtain a fresh encrypted source/config/database backup, verify it, stage and hash the update privately, then apply000042/000043 while preventing requests against a partially switched backend. Keep prior code/public files as a private rollback archive. Do not roll back migrations destructively or revert an enrolled account to weak legacy credentials. Check readiness/public files/admin login after install, then hand private activation to the verified owner. Mailbox setup and actual delivery may remain external gates but must be stated clearly.

## Verification in progress

Local migrations42/43 applied to guarded *_test. Focused staff/session12checks pass; focused email/refund/offline17checks pass. Initial broad frontend run239pass/2fail: invalid old phone test data in an email field corrected, unchanged offline async case passes on focused rerun. Initial backend59pass/1test fixture-capacity failure corrected; full final backend and frontend checks are running. A prior backend attempt lost its local MySQL child when its startup shell exited; no operational database was involved. Keep local MySQL running in a foreground tool session and run DB suites sequentially. Do not claim the incomplete attempts passed.
