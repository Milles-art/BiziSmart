> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# 06 — Task List and Progress Log

Work top to bottom. One task per AI session. Tick the box and add a log line when done. Paste the **Progress log** (last ~10 lines) into each new session.

## Phase 1 — Core, online-first
- [ ] **S0 Setup:** monorepo (`/backend` Laravel, `/frontend` React+Vite+MUI+RTK), Git, `.env.example`, Pest/Vitest/Playwright installed, GitHub Actions running tests, response envelope + exception handler + `request_id`. *Done when:* `php artisan test` and `npm run test` run green on an empty skeleton in CI.
- [ ] **S1 Tenancy + base migrations:** migrations for plans, businesses, branches, users, devices, spatie, Pennant; `CurrentBusiness`, `BelongsToBusiness`, `ResolveTenant` (subdomain), seeders (1 plan, 2 demo businesses). *Done when:* tenant leak tests (checklist A) pass in CI.
- [ ] **S2 Auth (F01):** login, devices, roles/permissions seed, `/me`, `/me/features`, lockout, supervisor check. React: login screen, token storage, auto-logout. *Done when:* F01 acceptance criteria pass.
- [ ] **S3 Products & batches API (F02) + tests.**
- [ ] **S4 Products UI:** list, form, receive stock, barcode scan. *Done when:* a stockkeeper can add a product and receive a batch on a phone.
- [ ] **S5 Stock service + movements:** `StockService`, FEFO picker (with the rules §3 example as a test).
- [ ] **S6 Sale API, cash only (F03 steps 1-8) + tests** (totals, VAT, change, rollback, idempotency).
- [ ] **S7 POS screen:** search/scan, cart, cash payment, receipt view. *Done when:* a cash sale works end to end on a phone.
- [ ] **S8 Till (F04)** API + UI.
- [ ] **S9 Customers & debt (F05)** + credit payments in POS.
- [ ] **S10 Receipts:** PDF job, R2 upload, signed URL, print/share.
- [ ] **S11 Basic reports (F09).**
- [ ] **S12 Super Admin (F07)** Filament: clients, plans, subscriptions, feature flags, onboarding action.

*Phase 1 exit gate:* works end to end in a test shop.

## Phase 2 — Pharmacy MVP
- [ ] **S13 Pharmacy (F06):** Rx/controlled flags in sale, prescriptions, controlled register, expiry alerts.
- [ ] **S14 M-Pesa (F08)** with fake gateway, then the real AzamPay/Selcom sandbox.
- [ ] **S15 Hardening:** rate limits, CSP header, backups job, Sentry/Pulse, load check on a cheap phone.
- [ ] **S16 Pilot:** deploy to VPS, onboard 1 pharmacy, fix what real use breaks.

*Phase 2 exit gate:* 3-5 paying pharmacies use it daily.

## Later (do not start until the gate is met)
Phase 3 offline selling (sync endpoints, Dexie, accept-and-flag) · Phase 4 NHIF, batch recall, WhatsApp alerts · Phase 5 second vertical.

## Progress log
| Date | Session | What was done | Open issues / notes |
|---|---|---|---|
| 2026-10-08 | Docs | Docs pack created; stack = React + Laravel API + MySQL | Pending: confirm VAT rate, TRA process, WhatsApp/gateway prices |
