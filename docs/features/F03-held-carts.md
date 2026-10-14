# S7 held carts and saved receipt output

The user approved 24-hour branch-shared held carts. Authorised staff in the same branch may resume a draft once. Owners may select any active tenant branch. Holding reserves no stock, makes no payment and consumes no supervisor approval. Deleting an unsold held cart requires no financial approval.

## Contract

- `GET /held-carts?branch_id=br_...` lists up to 100 active unexpired drafts in that branch, oldest first.
- `POST /held-carts` accepts `branch_id`, `items` (`product_id`, nullable `packaging_id`, exact `qty`, optional integer TSh `discount`), optional `customer_id`, `credit_amount`, `discount` and `discount_reason`. No till, tender, payment or grant is accepted.
- `POST /held-carts/{hold}/resume` and `DELETE /held-carts/{hold}` accept no draft changes. All three writes require a UUID `Idempotency-Key`, bind actor/device/body/action and append an immutable operation result. Replay is permitted only for that original operation and current branch authority.
- Resume consumes the handoff once, validates current product/package identifiers and quantities, and returns the encrypted saved draft. Checkout then reprices through the ordinary sale preview, checks stock/credit and obtains fresh discount approval. Archive or expiry errors preserve the held record; they do not allocate stock or silently select another packaging version.
- Expired drafts cannot be resumed and disappear from the active list. They retain encrypted history; no destructive expiry job is needed.
- Browser pending operations are memory-only Redux state, survive component navigation and clear on sign-out. Retry retains the original UUID and immutable body, including when the server claim succeeded but subsequent product/customer restoration failed with a 4xx response. Late restoration cannot clear a newer session's pending operation. Current cart must be empty before resuming another draft.
- An undiscounted draft may omit its reason or submit an empty/null reason, which is stored canonically as an empty string. Positive discounts still require a reason and fresh approval at checkout.

## Saved receipt output

`ReceiptPrint` renders only the saved `CashSale` returned by the real sale API. Existing receipt reads retain original cashier/device authority. An isolated sandboxed iframe escapes every text value and provides keyboard-accessible browser printing. A lazy import of the existing approved jsPDF dependency downloads a paginated A4 PDF; no network upload, public receipt link, R2 job or new package is introduced. Browser printer settings determine physical paper size. Tanzania time, exact quantity strings, original discounts, VAT, paid cash/mobile/credit and change appear in output. The PDF uses the existing standard font; additional language fonts belong to the multilingual receipt feature.

Focused checks cover encrypted draft/no-financial-effects, same-branch single handoff, immutable replay, tenant/branch/device boundaries, expired/archived data, exact quantities, no-approval deletion, receipt injection escaping, split/discount snapshots, PDF pagination and keyboard preview.
