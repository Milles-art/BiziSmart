# F07 — Super Admin (Filament)
**Goal:** we can onboard and manage clients without touching the database.
**Reads:** schema platform tables; product overview plans.

## Screens (Filament resources at admin.bizismart.co.tz)
Clients (create with name, subdomain, owner phone, plan), Plans, Subscriptions and payments (record payment, extend, suspend), Feature flags per client (Pennant), Users search, Client activity/health (basic). Separate `admin_users`, TOTP 2FA required, IP whitelist optional, session timeout 30 min.

## Onboarding action ("Create client")
Creates business + default roles + owner user (temp PIN) + branch + feature flags from plan + subscription. Sends/prints handover text (WhatsApp later).

## Rules
- Admin code is the only place allowed to use `withoutGlobalScopes()`; keep it inside `App\Filament\*`.
- Suspended/expired subscription -> tenant API returns 403 `SUBSCRIPTION_INACTIVE` (add to contract) except read-only login message.

## Acceptance criteria
- [ ] Creating a client produces a working subdomain login for the owner.
- [ ] Toggling a feature off makes tenant endpoints return `FEATURE_DISABLED` and hides it in `/me/features`.
- [ ] Admin pages are unreachable without admin login + 2FA; tenant tokens do not work there.

## Single-product onboarding
Every client is a supermarket or mini-mart using this one product. Do not build a business-type selector or module-template system. Use the supermarket plans, roles, limits and feature flags.
