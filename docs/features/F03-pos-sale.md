# F03 — POS Sale (online)
**Goal:** a cashier completes a correct sale in under 30 seconds.
**Reads:** rules §1, §3, §4, §5, §10; schema sales/sale_items/sale_payments; API Sales section.

## Flow (server, `SaleService::complete`)
1. Validate: open till (`TILL_REQUIRED`), items exist and active, qty > 0, payments valid.
2. Inside `DB::transaction`: for each item, pick FEFO batches with `lockForUpdate()`; if not enough sellable stock -> `INSUFFICIENT_STOCK` (rollback everything).
3. Validate supermarket eligibility: archived/quarantined/expired stock, packaging conversions, weighted quantities and any promotion/markdown approval.
4. Recompute prices from DB (override client price unless `give_discount`), discounts, subtotal, total, VAT.
5. Check payments cover total (`PAYMENT_MISMATCH`); credit part requires customer and limit check.
6. Insert sale, sale_items (one row per batch used), sale_payments; decrement batches via `StockService` (`sale` movements); update customer balance for credit; activity log.
7. After commit: dispatch `GenerateReceipt` job (PDF to R2) with `businessId`.
8. Return the sale. `Idempotency-Key`: same key + same payload returns the original sale.

## Frontend (POS screen)
Search/scan bar, product grid/list, cart (Redux), qty +/-, line discount (permission), customer picker, payment panel (cash, M-Pesa, split, credit), hold/resume, receipt view/print/share. Shows server errors in plain language (e.g. "Only 3 left").

## Edge cases
- Two cashiers sell the last unit: one succeeds, the other gets `INSUFFICIENT_STOCK` and the cart updates.
- Cart total changes on server (price changed): return the sale with server totals; UI shows them.
- M-Pesa part: the frontend starts STK push first (F08); sale is submitted with the confirmed `checkout_request_id`.
- Network failure during submit: retry with the same `Idempotency-Key`; no duplicate sale.

## Acceptance criteria
- [ ] Example from rules §3 (batches A/B/C, sell 15) produces items (A,10),(B,5) and stock A=0,B=15.
- [ ] Cash 10,000 for total 8,500 -> change 1,500; total VAT 1,297 at 18%.
- [ ] Split cash 5,000 + M-Pesa 3,000 for total 8,500 -> `PAYMENT_MISMATCH`.
- [ ] Credit sale over limit -> `CREDIT_LIMIT_EXCEEDED`; within limit increases outstanding balance.
- [ ] Failure at any step leaves no partial data (rollback test).
- [ ] Repeating the request with the same Idempotency-Key creates one sale.

## Supermarket extension
F10 adds hardware shortcuts, packaging/weighted lines, exchanges, multilingual checkout, batch markdowns and restricted margin warnings. Define price/promotion precedence and exact quantity formats before implementing these paths. The server remains the authority on totals.
