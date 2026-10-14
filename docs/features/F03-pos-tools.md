# POS tools: holds, discounts, voids and receipts

The user approved this coordinated batch and parallel work on 2026-10-12. Preserve the existing emerald/slate POS; Laravel owns every financial calculation and permission check.

## Approved contracts

- Every manual discount requires fresh five-minute, single-use supervisor approval, including an owner/manager selling personally. Managers approve only their assigned branch. The grant binds the operation UUID and complete authoritative checkout review.
- `items[].discount` is an exact integer TSh reduction on that logical line. Top-level `discount` reduces the post-line-discount subtotal. Neither may exceed its charge. A reason is required.
- Cart discounts use cumulative proportional half-up allocation over net lines. Shares sum exactly to the cart discount. Saved lines expose `gross_total`, `line_discount`, `cart_discount`, total `discount` and final `line_total`. Returns use final net line values; VAT, credit limits and payment splits use the discounted total.
- Mobile initiation verifies discount approval before contacting Selcom without consuming it. Completion consumes approval atomically with sale, stock and cash journals. An expired grant can be replaced for the original unchanged confirmed checkout; no second push or changed money is permitted.
- Held drafts expire after 24 hours, reserve no stock and have no cash effect. Authorised same-branch cashiers may claim a cart once. Current product/pack versions, quantities, prices, credit and approvals are revalidated. Deleting a hold needs no financial approval.
- A completed-sale void uses the existing receipt-linked refund service and preserves the original immutable receipt. Selecting all remaining quantities still requires a reason, authoritative review and supervisor approval; mobile refund portions still require a completed external-transfer record.
- Only saved receipt snapshots feed printing and downloadable PDFs. Browser printing offers printer/PDF fallback. Physical scanner and thermal-printer compatibility require pilot hardware verification. Printing sends no provider transfer or external message.

## Interfaces

`POST /sales/preview` accepts optional line/cart discounts and `discount_reason`. `POST /sales` additionally accepts `approval_token`; Idempotency-Key is also the approval operation UUID. Missing/stale/wrong-branch approval creates no sale, stock deduction, debt or drawer entry. Completed immutable replay does not consume approval twice.

`POST /payments/{payment}/review` returns the original UUID and canonical review of a confirmed unused checkout. `POST /payments/{payment}/complete` may accept a fresh discount `approval_token`; cart, money and payer details cannot be replaced. Allocated replay returns the saved sale.

See F03-held-carts.md for held recovery, F02-product-images.md for images and F12/F13 for prices, labels and imports.

## Checks

Cover exact tiny/full discounts, large baskets above one million TSh and browser-safe integer limits, net refunds, owner TOTP, grant scope/expiry, credit limits, provider uncertainty, rollback and concurrent duplicates. Verify held handoff races, post-claim restoration failures and frozen retries. Repeated identical and overlapping different barcode scans must each add their item once; failed lookups remain visible and logout/unmount stops late additions. Desktop/phone keyboard checkout, escaped output, actual PDF bytes/pagination, full void and exact till closure remain acceptance gates. Completion evidence is recorded separately in docs/06 and docs/08.
