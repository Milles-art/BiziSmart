# Trusted Beem SMS delivery reports

The primary Beem documentation was accessible through its Markdown index/OpenAPI on 2026-10-14. This completes the locally implementable SMS status lookup; actual credentials, sender approval, credits, real sandbox/send/report behavior and production scheduling still need external verification. No live message or provider query was made during implementation; HTTP tests use synthetic responses.

## Verified provider contract

[Beem delivery reports](https://docs.beem.africa/guides/sms/delivery-reports.md) and the [official SMS OpenAPI](https://docs.beem.africa/openapi/sms.json) specify a read-only `GET https://dlrapi.beem.africa/public/v1/delivery-reports`, authenticated with API-key/secret Basic Auth (or the separate documented access-token option). Queries supply the original `dest_addr` and `request_id`. Reports should first be requested at least five minutes after submission. A successful response is an array of objects with those exact identities and one of `PENDING`, `DELIVERED`, `UNDELIVERED`.

The implementation uses the existing privately tenant-bound Basic credentials, fixed HTTPS host/path, normal TLS validation, no redirect following and no automatic HTTP retries. It verifies a single unambiguous report's original request ID **as a string** and original destination phone. Missing/unknown/mismatching reports, malformed response bodies, HTTP failures or transport errors cannot establish delivery. One-recipient sends use the documented integer `recipient_id: 1`; the provider request ID still maps uniquely to the immutable local delivery row.

## Durable local behavior

`messaging:reconcile-sms` runs every five minutes without overlap and enters each active tenant explicitly. `SmsDeliveryReportService::reconcileTenant()` considers only accepted SMS with mapped `beem:` IDs and at least five minutes since acceptance/last check. The default batch is 25 oldest checks per tenant, rotating by `updated_at`.

A row lock rechecks current status/configuration/feature scope and records a durable five-minute GET claim in the existing mutable `message_deliveries.updated_at`; no migration is needed. The HTTP read occurs after that transaction completes. An apply transaction again compares current status, original provider ID and immutable recipient snapshot. Cancellation or terminal state changes while the query is in flight remain authoritative. `DELIVERED` marks delivered with the local verification time; `UNDELIVERED` marks failed/`PROVIDER_DELIVERY_FAILED`; `PENDING` or unknown evidence retains accepted status. Acceptance remains distinct from handset delivery. Crashes/timeouts can repeat a later read but cannot resend an SMS or create another charge.

## Encoding boundary

The [official Send SMS schema](https://docs.beem.africa/api-reference/send-sms.md) documents `encoding: 0` for GSM and an integer recipient reference. It does not establish a Unicode selector for this SMS API. The implementation therefore never guesses that selector or changes user-entered business/product text. A verified [ETSI GSM default/extension alphabet](https://www.etsi.org/deliver/etsi_TS/123000_123099/123038/03.03.00_60/ts_123038v030300p.pdf) check accepts representable Swahili Latin text, GSM accents/extensions and standard GSM line/page breaks; unsupported Unicode/control text returns blocked/`SMS_ENCODING_UNVERIFIED` before HTTP. Blocked retries remain zero-network and queued-message expiry still applies.

Beem must confirm its Unicode parameter and multipart/long-message handling with actual samples before non-GSM SMS can be enabled. This is an external provider-contract dependency, not a fabricated successful encoding. WhatsApp remains separately template/provider configured and is unaffected by the GSM guard.

## Evidence

Transport/encoding unit checks pass six cases / 30 assertions, covering fixed authenticated GET, exact leading-zero identities, pending/undelivered enums, malformed/mismatched/ambiguous reports, failed HTTP responses, missing credentials, GSM accents/extensions and unsupported Unicode. `SmsDeliveryReportTest.php` adds six guarded MySQL cases for durable cadence, tenant/config/feature isolation, terminal/in-flight cancellation, integer sender reference and unchanged supported/blocked unsupported messages. Those database cases await the lead agent's exclusive final verification; no pass is claimed before their captured result.
