import { translate, useLanguage } from '../../i18n';
import { useState, type FormEvent } from 'react';
import { Dialog, Tabs, Tab } from '@mui/material';
import { X } from 'lucide-react';
import { errorMessage, useBatchesQuery, usePackagingsQuery, useReceiveStockMutation, type CatalogueProduct, type Packaging, type ReceiveInput, type ReceiveResult, type StockBatch, type CorrectionResult } from './api';

import { StockCorrection } from './StockCorrection';
import { StockLossCount } from './StockLossCount';
import { StockHistory } from './StockHistory';

const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
export function StockReceiving({ product, showCost, canAdjust = false, initialView = 'batches', actionMode = 'adjustment', onClose }: { product: CatalogueProduct; showCost: boolean; canAdjust?: boolean; initialView?: 'batches' | 'history'; actionMode?: 'adjustment'|'loss'|'count'; onClose: () => void }) {
  useLanguage();
  const [correcting, setCorrecting] = useState<StockBatch | null>(null);
  const [correction, setCorrection] = useState<CorrectionResult | null>(null);
  const [view, setView] = useState<'batches' | 'history'>(initialView);
  const [batchFilter, setBatchFilter] = useState<{ id: string; name: string } | null>(null);
  const [page, setPage] = useState(1);
  const { currentData: data, error, isFetching, refetch } = useBatchesQuery({ product: product.id, page }, { skip: view !== 'batches' });
  const [adding, setAdding] = useState(false);
  const [receipt, setReceipt] = useState<ReceiveResult | null>(null);
  return <Dialog open onClose={() => { if (!adding && !correcting) onClose(); }} aria-labelledby="stock-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6">
    <div className="flex items-center justify-between gap-3"><h2 id="stock-title" className="text-lg font-bold text-slate-800">{translate("Stock batches ·")} {product.name}</h2><button disabled={adding || !!correcting} aria-label={translate("Close stock batches")} onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
    <p className="mt-3 text-sm text-slate-500">{translate("Real batch balances. Stock stays eligible through its expiry date; expired deliveries are rejected.")}</p>
    {correction && <p role="status" className="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate("Stock corrected:")} {correction.movement.qty_change} {product.unit}{translate(". Recorded batch balance:")} {correction.movement.qty_after} {product.unit}.{correction.replayed ? translate(" Existing correction confirmed; no change applied twice.") : ''}</p>}
    {receipt && <p role="status" className="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate("Stock received:")} {receipt.batch.receipt.base_qty} {product.unit}{translate(". Batch")} {receipt.batch.batch_number}.{receipt.replayed ? translate(" Existing receipt confirmed; no stock added twice.") : ''}</p>}
    <Tabs value={view} onChange={(_event, value) => { setView(value); setBatchFilter(null); }} aria-label={translate("Stock views")} className="mt-3"><Tab value="batches" label={translate("Batches")} /><Tab value="history" label={translate("Movement history")} /></Tabs>
    {view === 'history' && <StockHistory key={batchFilter?.id ?? 'all'} product={product} batch={batchFilter} onAll={() => setBatchFilter(null)} />}
    {view === 'batches' && <>
    {error && <p role="alert" className="mt-3 text-sm text-red-600">{errorMessage(error)} <button onClick={refetch} className="underline">{translate("Retry batches")}</button></p>}
    {isFetching && <p role="status" className="mt-3 text-sm text-slate-400">{translate("Loading stock batches…")}</p>}
    {!error && data && <div className="mt-4 space-y-3">{data.batches.map((batch) => <div key={batch.id} aria-label={`Batch ${batch.batch_number}`} className="rounded-xl border border-slate-200 p-3"><div className="flex justify-between gap-3"><p className="font-medium text-slate-700">{batch.batch_number}</p><p className="font-semibold text-emerald-600">{batch.qty_remaining} {product.unit}</p></div><p className="mt-1 text-xs text-slate-500">{batch.expiry_date ? `Expiry: ${batch.expiry_date}` : translate("No expiry date")} · {batch.sellable ? translate("Eligible stock") : translate("Not sellable")}</p>{batch.receipt.packaging_name && <p className="mt-1 text-xs text-slate-500">{translate("Received")} {batch.receipt.qty} × {batch.receipt.packaging_name} {translate("(version")} {batch.receipt.packaging_version})</p>}{showCost && batch.cost_price !== undefined && <p className="mt-1 text-xs text-slate-500">{translate("Cost: TSh")} {batch.cost_price.toLocaleString('en-TZ')} / {product.unit}</p>}<button onClick={() => { setBatchFilter({ id: batch.id, name: batch.batch_number }); setView('history'); }} aria-label={`History for ${batch.batch_number}`} className="mt-2 text-xs text-emerald-600 underline">{translate("View history")}</button>{canAdjust && <button onClick={() => { setCorrecting(batch); setCorrection(null); setReceipt(null); }} aria-label={`${translate(actionMode === "loss" ? "Record loss" : actionMode === "count" ? "Count batch" : "Correct stock")} for ${batch.batch_number}`} className="ml-4 mt-2 text-xs text-emerald-600 underline">{translate(actionMode === "loss" ? "Record loss" : actionMode === "count" ? "Count batch" : "Correct stock")}</button>}</div>)}{!data.batches.length && <p className="py-3 text-sm text-slate-500">{translate("No stock received for this product.")}</p>}<div className="flex justify-between gap-2 text-sm text-slate-500"><button disabled={page === 1 || isFetching} onClick={() => setPage(page - 1)} className="disabled:opacity-40">{translate("Previous batches")}</button><span>{translate("Page")} {data.meta.page}</span><button disabled={page * data.meta.per_page >= data.meta.total || isFetching} onClick={() => setPage(page + 1)} className="disabled:opacity-40">{translate("Next batches")}</button></div></div>}
    <button disabled={!product.is_active || adding} onClick={() => { setAdding(true); setReceipt(null); }} className="mt-5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">{translate("Receive stock")}</button></>}
    {correcting && (actionMode === 'adjustment' ? <StockCorrection product={product} batch={correcting} onClose={() => setCorrecting(null)} onCorrected={(result) => { setCorrection(result); setCorrecting(null); }} /> : <StockLossCount product={product} batch={correcting} count={actionMode==='count'} onClose={()=>setCorrecting(null)} onSaved={result=>{setCorrection(result);setCorrecting(null);}} />)}
    {adding && <ReceiveForm product={product} onClose={() => setAdding(false)} onReceived={(result) => { setReceipt(result); setAdding(false); setPage(1); }} />}
  </div></Dialog>;
}

function ReceiveForm({ product, onClose, onReceived }: { product: CatalogueProduct; onClose: () => void; onReceived: (result: ReceiveResult) => void }) {
  useLanguage();
  const [operationId] = useState(() => crypto.randomUUID());
  const [batchNumber, setBatchNumber] = useState(''); const [qty, setQty] = useState('');
  const [expiry, setExpiry] = useState(''); const [cost, setCost] = useState('');
  const [pack, setPack] = useState<Packaging | null>(null); const [packPage, setPackPage] = useState(1);
  const { currentData: packData, error: packError, isFetching: fetchingPacks, refetch: refreshPacks } = usePackagingsQuery({ product: product.id, page: packPage });
  const [uncertain, setUncertain] = useState<ReceiveInput | null>(null);
  const [busy, setBusy] = useState(false); const [failure, setFailure] = useState('');
  const [receive] = useReceiveStockMutation();
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy) return;
    const amount = Number(cost);
    if (!uncertain && (!/^[0-9]+$/.test(cost) || !Number.isSafeInteger(amount))) { setFailure('Enter a whole TSh cost within the supported range.'); return; }
    const body = uncertain ?? { operation_id: operationId, batch_number: batchNumber, qty, cost_price: amount, expiry_date: expiry || null, packaging_id: pack?.id ?? null };
    setUncertain(body); setBusy(true); setFailure(''); const pending = receive({ product: product.id, body });
    try { onReceived(await pending.unwrap()); }
    catch (e) {
      const status = (e as { status?: number | string }).status;
      // A definitive rejection permits correcting input. Transport/5xx outcomes retain the identical retry.
      if (typeof status === 'number' && status >= 400 && status < 500 && status !== 408) setUncertain(null);
      setFailure(errorMessage(e));
    } finally { pending.reset(); setBusy(false); }
  }
  const unit = pack ? 'packs' : product.unit;
  return <Dialog open onClose={() => { if (!busy && !uncertain) onClose(); }} aria-labelledby="receive-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><form onSubmit={submit} className="p-6"><h2 id="receive-title" className="text-lg font-bold text-slate-800">{translate("Receive stock ·")} {product.name}</h2><p className="mt-3 text-sm text-slate-500">{translate("Enter cost per")} {product.unit}{translate(", including when receiving packs. This receipt does not change catalogue prices.")}</p>
    {failure && <p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(failure)}</p>}
    {uncertain && !busy && <p className="mt-3 text-sm text-amber-700">{translate("The outcome is not confirmed. Retry this same receipt before starting another; stock will not be added twice.")}</p>}
    <fieldset disabled={busy || !!uncertain} className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
      <label className="text-xs text-slate-500 sm:col-span-2">{translate("Batch number")}<input autoFocus required maxLength={100} value={batchNumber} onChange={(e) => setBatchNumber(e.target.value)} className={inputClass} /></label>
      <div className="sm:col-span-2"><label className="text-xs text-slate-500">{translate("Receive unit")}<select aria-label={translate("Receive unit")} value={pack?.id ?? ''} onChange={(e) => { setPack(packData?.packagings.find((p) => p.id === e.target.value) ?? null); setQty(''); }} className={inputClass}><option value="">{translate("Base unit (")}{product.unit})</option>{pack && !packData?.packagings.some((p) => p.id === pack.id) && <option value={pack.id}>{pack.name} {translate("(selected version")} {pack.version})</option>}{packData?.packagings.filter((p) => p.is_active && !p.archived).map((p) => <option key={p.id} value={p.id}>{p.name} ({p.base_qty} {product.unit})</option>)}</select></label><button type="button" disabled={fetchingPacks} onClick={refreshPacks} className="mt-1 text-xs text-emerald-600">{translate("Refresh pack choices")}</button>{fetchingPacks && <p className="text-xs text-slate-400">{translate("Loading packs…")}</p>}{packError && <p className="text-xs text-red-600">{translate("Pack choices unavailable. You can receive in the base unit.")}</p>}{packData && packData.meta.total > packData.meta.per_page && <div className="mt-2 flex justify-between text-xs text-slate-500"><button type="button" disabled={packPage === 1 || fetchingPacks} onClick={() => setPackPage(packPage - 1)}>{translate("Previous pack choices")}</button><button type="button" disabled={packPage * packData.meta.per_page >= packData.meta.total || fetchingPacks} onClick={() => setPackPage(packPage + 1)}>{translate("Next pack choices")}</button></div>}</div>
      <label className="text-xs text-slate-500">{translate("Quantity (")}{unit})<input required inputMode="decimal" maxLength={32} value={qty} onChange={(e) => setQty(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Cost (TSh /")} {product.unit})<input required inputMode="numeric" pattern="[0-9]+" value={cost} onChange={(e) => setCost(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500 sm:col-span-2">{translate("Expiry date")}<input type="date" required={product.tracks_expiry} value={expiry} onChange={(e) => setExpiry(e.target.value)} className={inputClass} /></label>
    </fieldset><div className="mt-6 flex gap-3"><button type="button" disabled={busy || !!uncertain} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600 disabled:opacity-40">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Receiving…") : uncertain ? translate("Retry receipt") : translate("Confirm receipt")}</button></div>
  </form></Dialog>;
}






