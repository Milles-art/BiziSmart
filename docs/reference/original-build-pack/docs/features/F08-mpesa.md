> HISTORICAL SOURCE ONLY. Superseded by the supermarket-only documents in the workspace root and docs/. Do not follow this file as current project instructions.

# F08 — M-Pesa STK Push
**Goal:** accept mobile money safely, never double-count.
**Reads:** rules §4; schema mpesa_transactions; API M-Pesa section.

## Flow
Cashier enters customer phone -> `POST /mpesa/stk-push` creates a `pending` row with the provider's `checkout_request_id` -> customer approves on phone -> provider calls webhook -> row becomes `confirmed`/`failed` -> UI polls `/mpesa/status` every 3s up to 60s -> on `confirmed` the sale is submitted with that id. Timeout -> `MPESA_TIMEOUT`.
A reconciliation job (every 15 min) finds confirmed payments with no sale and alerts the owner after 1 hour.

## Rules
- Webhook is public: verify the provider's secret/signature, tenant comes from the `mpesa_transactions` row (global unique `checkout_request_id`), never from the payload.
- Duplicate callback -> 200, no second record or sale.
- Provider specifics (AzamPay/Selcom payloads) go in a `MpesaGateway` interface with a fake implementation for tests.

## Acceptance criteria
- [ ] Success, failure, timeout and duplicate callbacks are all covered by tests using the fake gateway.
- [ ] A callback for an unknown `checkout_request_id` returns 200 and logs a warning, creating nothing.
- [ ] A sale cannot use a `checkout_request_id` from another tenant or one already used by another sale.
