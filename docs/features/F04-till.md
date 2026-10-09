# F04 — Till Management
**Goal:** cash is accountable per cashier per shift.
**Reads:** rules §7; schema till_sessions/till_paid_outs; API Till section.

## Flow
Open till (opening float) -> sell -> optional paid-outs -> close with counted cash -> server computes expected and variance -> if `abs(variance) > limit` closing needs supervisor approval (token from `/auth/supervisor-check`) -> session closed, immutable.

## Edge cases
- Second open attempt -> `TILL_ALREADY_OPEN`. Sale without open till -> `TILL_REQUIRED`.
- Refunds in cash reduce expected cash; debt payments in cash increase it.
- Owner can view all sessions; cashier only their own.

## Acceptance criteria
- [ ] Worked example (float 20,000, sales 150,000, refunds 5,000, counted 164,000) gives expected 165,000, variance -1,000, closes without approval.
- [ ] Counted 150,000 (variance -15,000) -> 403 `SUPERVISOR_REQUIRED` and the session stays open; with valid approval it closes.
- [ ] A closed session cannot receive sales or edits.

## Supermarket extension
Multiple independent cashier tills, plan till limits and manager overview are in scope. F10.9 adds acknowledged cash drops: subtract them from expected drawer cash, separately from expenses/paid-outs. Verify float 20,000 + cash sales 150,000 - drop 100,000 = drawer cash 70,000.
