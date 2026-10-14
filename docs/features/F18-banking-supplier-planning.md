# Banking and supplier planning

Owner-only continuation of D087 accounting. Laravel locks fresh actor/device authority and the tenant business before all reads/writes. No payment provider calls or money movements are performed by this slice.

## Contract

- `GET /api/v1/banking`: manual statement evidence, completed bank supplier/expense payment candidates (including expense refund credits), and selected-invoice payment plans. All IDs use public prefixes; financial fields are integer TSh.
- `GET /api/v1/supplier-price-comparisons`: last three received cost observations for each exact product, newest invoice date/ID first. Stored costs already mean TSh per original base item or kilogram, including packaged receipts. These are historical receipts rather than current quotations. Buying costs remain owner-only.
- `POST /api/v1/banking/{statement|match|schedule|cancel}`: required UUID `operation_id`. Tenant-scoped immutable actor/device/payload journal provides safe replay; changed input conflicts. No request `business_id` accepted.
- Statement: `account` (private label), `booked_on` (`YYYY-MM-DD`), `direction` (`debit|credit`), positive integer `amount`, unique account/reference. Private labels/references encrypted. Statement evidence is immutable. Duplicate account/reference is rejected rather than importing again.
- Match: `line_id`, `payment_type` (`supplier|expense`), `payment_id`. Exact same amount and debit/credit direction required for completed `bank` source records. Expense refunds match credits; supplier/expense payments match debits. One-to-one matching only; unmatched evidence stays visible. Matching does not update balances, expenses, supplier payments or tills.
- Schedule: `invoice_id`, `due_on`, positive integer `amount`. Sum of active scheduled amounts cannot exceed the current invoice payable at creation. Plans reserve no cash, create no payment, and do not automatically execute. Current unpaid amount is displayed separately, so actual subsequent payments/credits do not silently alter a plan. Owner cancels obsolete plans explicitly using `schedule_id`. Overdue means active plan, currently unpaid invoice, due date before current Tanzania date.

## UI and local verification

Owner Suppliers screen contains the retained emerald/slate Banking & supplier planning section with Payment plans, Bank statement and Price comparison tabs. Actual APIs own validation; forms retain the same operation for uncertain retry and release confirmed client failures for correction. No browser financial totals are persisted.

Manual statement input supports local reconciliation without bank credentials. Bank feeds, provider-specific statement import formats, fee/adjustment journals, many-to-one matches and automatic transfers are outside this conservative slice. Physical bank evidence and real completed-transfer verification remain operator responsibilities; no simulated bank connection is shown.

Migration: `2026_10_14_000035_create_bank_reconciliation.php` (additive). Focused test: `BankingTest.php`; final evidence maintained in the coordinated handoff by the lead agent.

Focused frontend evidence: banking/supplier tests pass all five cases after final token-change protection, including immutable uncertain retries through navigation and exact selected bank-payment matching. TypeScript passes. PHP service/fixture and E2E JavaScript syntax pass. The initial backend migration/test process disappeared without a recoverable final result; no backend passing claim is made by this slice. Lead agent must run `BankingTest.php` plus supplier regression under the guarded database window. Prepared `tests/e2e/banking.spec.js` covers desktop/phone payment planning, historical costs, manual matching and overflow; its dedicated guarded fixture must be included from the normal E2E setup before execution.

Latest focused frontend checks pass all ten banking/return cases after adding HTTP 408 uncertainty recovery alongside 504 and exact JSON-integer money encoding. Canonical safe whole-TSh input is serialized as a number; invalid/unsafe text is preserved for API rejection without rounding. The first recovered backend BankingTest run exposed string-valued fixture amounts; fixtures are corrected to the existing strict integer Money contract and the lead agent is rerunning them. No passing backend claim follows from a corrected fixture alone.
