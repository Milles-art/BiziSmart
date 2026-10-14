# F08 — Selcom mobile-money payments

D077 selects Selcom and leaves merchant configuration empty until the owner obtains a merchant account. BiziSmart implements real endpoints; HTTP/payment fakes exist only in automated tests. No live screen synthesizes payment success.

## Transport and authority

MobileMoneyGateway binds to SelcomGateway. The server-only configuration is explicitly bound to one business public ID, with no credential fallback for other shops. Requests follow the official [Selcom API reference](https://developers.selcommobile.com/) reviewed on 2026-10-10: HS256 authentication, create-order-minimal, wallet-payment and order-status. Require actual buyer name/email and canonical Tanzania phone; never invent contacts. Monetary values are exact integer TSh/TZS.

HTTPS, bounded timeouts, no redirects/automatic retries and no network calls in retried database transactions. Acceptance of an order/push does not mean paid. Only an authenticated status query matching the saved order and amount, completed state and unique nonempty transaction/reference/channel may confirm it. Unknown, transport and malformed outcomes remain uncertain. Optional returned currency/vendor must match when present.

## Persisted lifecycle

Migration 000018 stores tenant/actor/device/branch/till-bound immutable encrypted requests and checkout reviews, operation UUID/fingerprint, merchant fingerprint, exact amount and globally unique provider references. Migration 000021 distinguishes checkout from selected-invoice debt collection and adds unique customer-payment allocation links. A payment is consumed once, by its original operation and request, inside the same transaction as the resulting stock/till/debt journals.

Persist the dispatch claim before external create/push. Replaying an initiation returns the same payment and never repeats either external request. Allow only one unresolved payment per actor/device. Reload recovery reads server records; uncertain client bodies/UUIDs stay only in memory. Saved responses remove payer details from pending Redux state. Logout clears all financial state and late responses cannot restore it.

The public callback resolves its business exclusively through the persisted globally unique order. Verify exact raw JSON, HS256 headers, required signed fields and five-minute freshness. Reject invalid signatures; acknowledge unknown orders without writes. Deduplicate authenticated notification identity independently of the signature timestamp. Notifications only make the saved order due for a trusted query: unsigned amount/phone never authorize payment. Confirmed, allocated and manually refunded records cannot be downgraded by late callbacks.

`payments:reconcile`, scheduled every minute, uses a durable three-minute per-order database lease. Provider calls occur outside transactions; the UI may poll the local status endpoint every three seconds, but this does not bypass the provider lease. The scheduler iterates explicit persisted businesses and never relies on request tenant context. Configuration changes and reused provider references require owner review. Production scheduler provisioning remains a deployment requirement.

## Connected checkout and debt collection

POS → payment review → Pay with Selcom collects the mobile portion; cash/mobile/customer-credit splits must exactly cover the server total. At completion, recheck current staff/device/branch, own open till, prices, customer limit and sellable stock. Allocate original FEFO quantities through StockService. Only the cash portion changes drawer cash. Keep verified money recorded and unallocated if the sale cannot complete; do not recharge the payer.

Customers → selected invoices → Pay selected invoices with Selcom uses the same lifecycle. The persisted debt review fixes customer, selected invoice allocations, amount and own till. After confirmation, DebtService settles only those invoices atomically, with no drawer cash entry. Changed invoice balances leave the verified payment unallocated for owner review. Recovery remains available in Customers, separately from POS checkout recovery.

Owner dashboard lists unresolved payments and highlights confirmed, unallocated money after one hour. Migration 000019 adds an append-only full external-refund record for a confirmed unallocated payment. Owner-only preview/commit requires a fresh owner supervisor grant, external reference and explicit completed-refund attestation. The record is immutable/idempotent, marks the payment unavailable, and changes no stock/debt/cash. BiziSmart sends no refund transfer.

## Receipt mobile refunds

D078 reduces original unpaid debt first and divides the paid refund proportionally over the remaining refundable cash/mobile pools, rounding the cash share half up and assigning the exact remainder to mobile. Original discounted line entitlements remain cumulative; sold quantities and both payment pools cap refunds. Prior selected-invoice collections contribute to their recorded method, never automatically to cash.

Migration 000020 appends mobile amount and encrypted external reference to the receipt return journal. A positive mobile refund requires a completed external refund and reference in the supervisor-bound review. References cannot be reused across receipt and unallocated refunds. Mobile amounts never change drawer cash; existing cash/debt/stock/approval/replay operations remain atomic. Review may show the required mobile amount before external-refund details are added; review again after adding them, then request approval. Responses explicitly state `external_transfer_executed: false`.

Cash/credit atomic exchanges remain available. A return needing mobile money back cannot use the atomic exchange path: record the external refund as a return and review the replacement separately. Reusing mobile value inside an atomic replacement is not implemented or claimed; its retained-credit versus external-payout settlement requires a separate contract.

## Routes

All staff payment routes require current approved-device authentication and sell; collection also checks manage_customers in DebtService. Owner list/manual-refund endpoints enforce owner authority. All payment responses, including errors, use no-store/private.

- GET /payments/capabilities: configured/enabled are true only for valid bound private configuration; otherwise merchant_setup_required.
- POST /payments/selcom: Idempotency-Key UUID, payer name/email/phone and exactly one canonical sale or collection review.
- GET /payments/mine: original actor/device/branch unfinished records, without payer contacts or encrypted requests.
- GET /payments/{payment}: same authority, lease-controlled reconciliation and public status.
- POST /payments/{payment}/complete: original saved checkout/collection only; returns immutable sale or customer-payment result.
- POST /payments/selcom/callback: public signed notification receiver; no host-selected tenant.
- GET /payments: owner-only unresolved records.
- POST /payments/{payment}/manual-refund/preview and /manual-refund: owner external-refund recording.

## External launch gate

Leave SELCOM_* empty in the live local environment until privately provisioned. Before collecting real money, verify merchant/sandbox order creation, phone/operator behavior, signed callbacks through public HTTPS, exact status field/amount encodings, weighted-cart item-count semantics, uncertain outcomes and merchant settlement with Selcom. Automated tests cannot certify provider acceptance. Mandatory buyer email remains visible and required. Provision a production scheduler and monitor unreconciled money. No actual provider request or charge is authorized by test fixtures.
