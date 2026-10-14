# Loyalty, commissions and historical demand

D087 approves owner-configured business rates and immutable paid-sale earning/reversal journals. D088 approves whole-TSh loyalty redemption with the existing supervisor-approved checkout discount, no cash payout or point expiry, proportional restoration on returns and negative carry rather than blocking refunds.

## Owner configuration and APIs

`GET /incentives/configuration`, `POST /incentives/configuration/preview` and `POST /incentives/configuration` are approved-device, owner-only endpoints. Rates are nullable positive integer `loyalty_tsh_per_point`, nullable positive integer `loyalty_point_value`, and nullable `commission_basis_points` (1–10000), with reason and operation UUID. Null disables its capability. Preview provides a server snapshot/review hash; commit freezes that body and replays only for the same tenant/actor/device/fingerprint. New configuration versions do not edit old sale terms.

`GET /customers/{customer}/loyalty` requires existing `manage_customers` and exposes points, positive available points, refund carry and current public redemption value. It exposes no buying costs or commission information. `GET /incentives/commissions` is owner-only, paginated and attributes earned/paid/payable/carry to the original cashier. External commission payout `/preview` and commit require owner review, whole amount within the positive earned balance, paid date, unique external reference, reason and completed-payment attestation. They record payment already made externally; BiziSmart does not transfer money, remove drawer cash or infer payroll/wages.

## Source and earning

Every new sale captures its configuration (or disabled terms) once. Existing pre-release sales without captured terms do not retrospectively earn. Loyalty belongs to the original selected customer; commission belongs to the original cashier, even when another staff member collects debt. Paid cash/mobile plus explicitly allocated invoice collections, minus paid cash/mobile refunds, are proportionally attributed to original net value excluding stored VAT. Cumulative floor points and cumulative half-up commission prevent fragmented collections earning a bonus. Unpaid credit earns nothing until collected; reducing unpaid debt is not a paid earning/refund event. Immutable source-event keys and sale-account journals make repeated hooks harmless. All writes commit with their originating stock/payment transaction.

## Redemption and returns

Checkout review calculates whole redeemable points within positive balance and remaining cart value, using the current owner-configured whole-TSh value. Points whose complete value cannot fit are rejected rather than silently wasting value. The resulting discount requires existing `give_discount` approval and is included in the original sale fingerprint/review and final net line entitlements. Each line separately snapshots its loyalty discount share.

Commit consumes points once inside the sale transaction, before earning that same sale's new points, and verifies customer/value/configuration/balance. Returns refund original final net line totals, never point face value as cash. Returned logical quantities proportionally restore the original spent points, including fully point-funded zero-net goods. Earned points/commissions reverse cumulatively. Negative points or already-paid commission carry remain visible and are offset by future earnings; they do not reject an otherwise valid refund. No point expiry or cash redemption is inferred.

## Forecast

`GET /incentives/forecast?from=YYYY-MM-DD&to=YYYY-MM-DD&coverage_days=7` is owner-only. It accepts at most 366 Tanzania calendar days through today and 1–90 target coverage days, with product pagination/search. Recorded sale atoms use original occurrence dates when available; returns use their own event dates. Net demand is sold minus returns, floored at zero and flagged when window returns exceed sales. Exact average is reported as an atom numerator/day denominator; coverage target ceilings that rational demand, and suggested reorder subtracts current eligible stock. Products and units remain separate. No-sales, short-window and unresolved-negative-stock flags expose data limits. The method does not model seasonality, lost sales, supplier lead time or forecast accuracy, and never creates purchase orders.

## Checks

Required tests cover disabled/historical terms, original-cashier attribution, selected-invoice collections, VAT exclusion/cumulative arithmetic, proportional reversals and paid commission carry, point balance/config/approval boundaries, full zero-money point returns, duplicate events, source transaction rollback, owner/customer/tenant permissions, real quantity windows and server-only reorder calculations. Configuration/payout uncertainty stays in memory Redux through navigation, clears at sign-out and retries the exact frozen request.
