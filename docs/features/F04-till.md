# F04 — Till Management
**Goal:** cash is accountable per cashier per shift.
**Reads:** rules §7; schema till_sessions/till_cash_entries/till_operations; API Till section.

## Flow
Open till (opening float) -> sell -> optional paid-outs -> close with counted cash -> server computes expected and variance -> if `abs(variance) > limit` closing needs supervisor approval (token from `/auth/supervisor-check`) -> session closed, immutable.

## Edge cases
- Second open attempt -> `TILL_ALREADY_OPEN`. Sale without open till -> `TILL_REQUIRED`.
- Refunds in cash reduce expected cash; debt payments in cash increase it.
- Owner can view all sessions; cashier only their own.

## Acceptance criteria
- [x] Worked example (float 20,000, sales 150,000, refunds 5,000, counted 164,000) gives expected 165,000, variance -1,000, closes without approval.
- [x] Counted 150,000 (variance -15,000) -> 403 `SUPERVISOR_REQUIRED` and the session stays open; with valid approval it closes.
- [x] A closed session cannot receive sales or edits.

## Supermarket extension
Multiple independent cashier tills, plan till limits and manager overview are in scope. F10.9 adds acknowledged cash drops: subtract them from expected drawer cash, separately from expenses/paid-outs. Verify float 20,000 + cash sales 150,000 - drop 100,000 = drawer cash 70,000.

## S6 preparation — 2026-10-10
- Plan till limits count concurrently open sessions across the entire business (D070); closing releases capacity. Enforced atomically under the shop lock by the lifecycle endpoints.
- TillCashService provides exact server-side expected-cash and reconciliation arithmetic using integer TSh. Its inputs must come from authoritative records; clients must never supply expected cash or cash-flow aggregates. Sales cash is net of change; drops and paid-outs remain separate deductions.
- Signed expected cash and variance must remain within the browser-safe money bounds; reject overflow, decimal, string and negative cash-flow inputs. Approval is required strictly beyond the configured limit in either direction.
- Manager branch/session scope is approved in D071 and implemented in TillAccessService. Lifecycle routes, persistence and connected till forms are implemented; acceptance verification is recorded in the task log.

## Approved resource scope — D071
- Owners view all tenant branches. Managers view tills only in their assigned branch. Cashiers view only their own sessions. Stockkeepers have no till access. Missing branch assignments grant managers no overview or variance authority.
- Opening, paid-outs and closing act on the signed-in staff member's own session in their assigned branch. Overview and approval permissions do not authorise a manager/owner to close another cashier's session.
- TillAccessService validates tenant membership for actor, branch and cashier and the active single approved role before applying view/manage/approve scope. TillService callers must load fresh locked actors/targets and apply these checks, including list filtering.
- Manager variance issuance requires the requester's current assigned branch to match the manager's assignment. Consumption requires branch_id in the authoritative server snapshot, resolves that branch within the tenant and rechecks the manager's current assignment; owner approvals can span branches. Invalid scope at issuance is APPROVER_NOT_AUTHORIZED; at consumption it is INVALID_APPROVAL without consuming the grant. Existing owner TOTP and single-use rules still apply.

## S6 implementation
TillService owns locked lifecycle, exact cash ledger totals, business-wide plan capacity and encrypted operation replays. Additive migration 000014 provides generated unique open-session enforcement and composite tenant foreign keys. The preserved Tills & Cash frontend uses RTK Query for current/overview/history, float entry, positive paid-outs and authoritative closing preview/approval. Closed rows and cash/operation histories are immutable. Owner above-limit variance notification is a persistent notice on Tills & Cash; SMS/WhatsApp/queue notifications are later infrastructure work. Cash sales, refunds, debt receipts and acknowledged drops remain their own workflows; no simulated revenue is injected. Product images remain approved future work.
