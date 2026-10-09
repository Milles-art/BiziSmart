# F06 - Supermarket Operations

Status: approved capability scope; detailed API/schema design pending. This is the active F06 specification.

## Capabilities
- Departments: product assignment, stock and profit reports; department counts.
- Multiple cashier tills: independent sessions, plan limits and manager overview.
- Supported weighing scales and scale labels, with manual-weight fallback (F10.2).
- Loss prevention: supervisor approvals for voids, refunds and large discounts; reasons/actors logged.
- Promotions: Buy X Get Y, percentage, fixed-price, bulk and scheduled offers; stacking/rounding rules must be specified first.
- Customer display: cart/total updates without exposing staff controls, customer details or costs; supported device/session flow pending.
- Suspicious-activity alerts: owner notification for unusual void/refund patterns; configurable thresholds to be specified, not inferred.
- Department stock takes: scan/count, flag variance, approve corrections through StockService.

## Acceptance criteria
- Department reports/counts only include the selected department and tenant.
- Two cashiers maintain independent tills; an authorised manager sees their permitted overview.
- A supported scale/label produces the correct exact quantity and charge; manual entry works without hardware.
- Unauthorised overrides fail; authorised actions retain reasons and approver.
- Each promotion has tested eligibility, time boundaries and documented stacking rules.
- The customer display reveals only customer-facing cart/total information.
- Alerts do not duplicate for the same event; thresholds and delivery are covered once specified.
- A count correction writes exactly one movement per applied adjustment, without overselling races.

F10 specifies the 12 approved additions. Both documents apply to this one supermarket product.
