# Coordinated sales and product-tools delivery

User selected these five remaining areas on 2026-10-10. This is the delivery checklist for the grouped request, not a replacement for feature contracts or approval of unspecified policies. Existing design, real Laravel authority, exact TSh/quantities, tenant isolation and tests remain mandatory.

1. **POS:** S7 cash sale review/commit, FEFO/shared-base allocations and net till cash, followed by persistent server held carts, bound supervisor discount/void consumers and S8 printable/PDF receipts. Work in SaleService/StockService/TillService, sale/hold tables, API contracts, live POS and receipt jobs. Cash prerequisite: F03-cash-checkout.md.
2. **Customers/debts:** F05 customer CRUD, encrypted contacts/tenant phone uniqueness, DebtService credit limits, cash+credit splits and collections/aging. Additive customer/sale-credit/payment journals; connected customer picker and customer/debt screens. Test overpayment, limit races and cash collections against open own tills.
3. **Returns/refunds:** S15 receipt-linked partial/full returns/exchanges, D078 proportional discounted/debt-first capped allocation, original batch disposition, fresh supervisor review/grant and atomic cash/debt/stock journals. Specify cumulative rounding and cross-cashier/branch receipt visibility before their dependent endpoints. Test repeated/competing refunds, expired/recalled quarantine, prior collections and payment method allocation. Manual mobile refund records must never claim an external refund was executed.
4. **Selcom:** F08 gateway/callback/polling/reconciliation and confirmed-payment consumption in SaleService. User chose Selcom and has no merchant account/credentials. Keep collection unconfigured until private server configuration exists; use official provider authentication/status/callback documentation (https://developers.selcommobile.com/) and test fakes only in automated tests. Callback tenant comes from persisted provider-order mapping, never supplied business IDs; confirm signature, amount/currency and one-use sale allocation. No network calls inside retried DB callbacks.
5. **Product extras:** approved optional images via tenant-private R2 paths and safe upload/replace/remove lifecycle; camera/keyboard scanner entry; S16 import/export preview and exact opening-stock commits; S17 labels and price-history browsing. Preserve original product/POS components, load efficient thumbnails, validate scale/barcode formats separately and do not install unlisted packages without approval. Image/receipt storage configuration is a prerequisite for live R2 delivery.

## Completion gates

Approved follow-up policies: D080 requires staff-selected unpaid-sale allocations for collections. D081 permits branch-assigned cashiers/managers to return branch receipts with supervisor approval and owners to return receipts across the business; cash refunds use the operator's own open till. These rules must be enforced by the respective future services, not inferred from frontend visibility.

- [x] Cash prerequisite (S7a) verified and connected: 75 selected backend regression cases plus the final access case, 86 frontend tests and six desktop/phone browser checks pass. Current grouped software and external gates are tracked below.
- [x] POS software implemented and connected: server-held carts, supervisor-bound discounts and receipt voids, exact reversed financial journals, keyboard shortcuts and browser printable/PDF receipts.
- [x] Desktop/phone POS acceptance: complete 18-check browser run passes, including real holds, approved discounts, PDF bytes, void refunds and exact till reversal.
- [x] Customers, credit/split sales and cash debt collections verified (S9). S12 adds verified Selcom collections against staff-selected invoices without drawer cash.
- [x] Cash/credit receipt-linked returns/refunds and atomic exchanges verified (S15/D083): 11 return cases including MySQL races, 96 frontend tests and six desktop/phone browser checks; see F03-returns-exchanges.md.
- [x] Mobile receipt refund allocation/manual records: proportional remaining payment pools, debt first, supervisor-bound external refund attestation and unique references; no payout.
- [x] Selcom signed transport and callback signature helper with server-derived availability (S12a).
- [x] Persisted Selcom callback/reconciliation, confirmed-payment sales and selected-invoice collections verified with test-only provider fakes.
- [ ] External merchant/sandbox verification and production scheduler provisioning; merchant credentials remain unconfigured.
- [ ] Atomic exchanges needing mobile refunds require a settlement contract; cash/credit exchanges remain available.
- [x] Product extras software implemented and connected: optional private image upload/replace/remove and catalogue/POS thumbnails; native camera and queued keyboard scanning; stock-import preview/atomic commit, catalogue export, shelf labels and audited price-history browsing.
- [x] Desktop/phone catalogue extras acceptance: price history, labels, authoritative import/commit and CSV export pass. Image/scanner software has focused backend/frontend evidence; external storage and physical hardware gates below remain open.
- [ ] Private R2 bucket/credentials and external storage verification. Unconfigured storage remains explicitly unavailable; test storage fakes never enable live uploads.
- [ ] Pilot scanner/camera/printer hardware verification. Browser scanning has explicit unsupported-browser fallbacks; browser print/PDF support is not a claim of thermal-printer compatibility.
- [ ] Full backend suite and remote CI verified for this batch. Focused checks below do not replace these broader gates.

## Current verification — 2026-10-13

The selected financial backend suite passes 23 cases / 231 assertions, and the arithmetic/unit suite passes 28 cases / 106 assertions. Frontend coverage verifies 149 distinct cases across the 148-case main run and focused held-cart (five cases) and scanner-queue (four cases) checks. TypeScript and ESLint pass; the production Workbox build produces 11 application-shell entries / 1,415.35 KiB. All 18 real-Laravel desktop/phone browser checks pass in the final complete run; the earlier two focused POS checks also passed.

Scanner lookup now queues repeated and overlapping exact barcodes, prevents checkout while additions are pending, keeps failed codes visible and ignores late responses after logout/unmount. Local API startup uses the loopback PHP development server with Laravel's official router, CLI opcode caching, explicit timestamp validation and zero revalidation delay; this local performance change does not provide a production deployment gate. R2 configuration, Selcom merchant verification, production scheduling, physical hardware and remote CI remain open dependencies.

Check boxes only after their evidence exists. The live application keeps unavailable tools disabled. Provider and storage credentials are configured privately, never requested in chat, committed, logged or sent to the frontend.

Final browser evidence (2026-10-13): 18 checks pass in 7.3 minutes using the normal Playwright configuration. Owner signup/reload, catalogue/packs, stock/corrections, product extras, staff/devices, existing cash/credit/returns/exchanges, new POS tools and test-only Selcom UI pass on desktop and phone. No real provider charge or live storage upload was made. Normal development API/Vite restored.
