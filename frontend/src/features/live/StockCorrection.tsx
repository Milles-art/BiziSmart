import { translate, useLanguage } from '../../i18n';
import { useState } from 'react';
import { Dialog } from '@mui/material';
import { errorMessage, usePreviewCorrectionMutation, useCorrectStockMutation, useSupervisorCheckMutation, type CatalogueProduct, type StockBatch, type CorrectionPreview, type CorrectionCommit, type CorrectionResult } from './api';

function correctionError(error: unknown) {
  const e = error as { data?: { error?: { code?: string } } };
  return e.data?.error?.code === 'NOT_FOUND' ? 'This product or batch is no longer available. Close stock and refresh the catalogue.' : errorMessage(error);
}
const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
export function StockCorrection({ product, batch, onClose, onCorrected }: { product: CatalogueProduct; batch: StockBatch; onClose: () => void; onCorrected: (result: CorrectionResult) => void }) {
  useLanguage();
  const [qty, setQty] = useState(''); const [reason, setReason] = useState('');
  const [review, setReview] = useState<CorrectionPreview | null>(null);
  const [grant, setGrant] = useState<string | null>(null);
  const [phone, setPhone] = useState(''); const [pin, setPin] = useState(''); const [code, setCode] = useState('');
  const [uncertain, setUncertain] = useState<CorrectionCommit | null>(null);
  const [busy, setBusy] = useState(false); const [failure, setFailure] = useState('');
  const [preview] = usePreviewCorrectionMutation(); const [approve] = useSupervisorCheckMutation(); const [correct] = useCorrectStockMutation();
  async function inspect() {
    setBusy(true); setFailure(''); const pending = preview({ operation_id: crypto.randomUUID(), batch_id: batch.id, qty_change: qty, reason });
    try { setReview(await pending.unwrap()); setGrant(null); } catch (e) { setFailure(correctionError(e)); } finally { pending.reset(); setBusy(false); }
  }
  async function approval() {
    if (!review) return; setBusy(true); setFailure('');
    const pending = approve({ phone, pin, code, permission: 'adjust_stock', operation_id: review.snapshot.operation_id, payload_hash: review.payload_hash });
    try { setGrant((await pending.unwrap()).approval_token); } catch (e) { setFailure(correctionError(e)); }
    finally { setPin(''); setCode(''); setPhone(''); pending.reset(); setBusy(false); }
  }
  async function commit() {
    if (!review || busy) return;
    const body = uncertain ?? { operation_id: review.snapshot.operation_id, batch_id: batch.id, qty_change: review.snapshot.qty_change, reason: review.snapshot.reason, expected_qty: review.snapshot.qty_before, ...(grant ? { approval_token: grant } : {}) };
    setUncertain(body); setBusy(true); setFailure(''); const pending = correct(body);
    try { onCorrected(await pending.unwrap()); }
    catch (e) {
      const status = (e as { status?: number | string }).status;
      if (typeof status === 'number' && status >= 400 && status < 500 && status !== 408) { setUncertain(null); setReview(null); setGrant(null); }
      setFailure(correctionError(e));
    } finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy && !uncertain) onClose(); }} aria-labelledby="correction-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6">
    <h2 id="correction-title" className="text-lg font-bold text-slate-800">{translate("Correct stock ·")} {batch.batch_number}</h2>
    <p className="mt-3 text-sm text-slate-500">{translate("Enter a signed change in")} {product.unit}{translate(". Use a minus sign to remove stock. This changes quantity only.")}</p>
    {failure && <p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(failure)}</p>}
    {uncertain && !busy && <p className="mt-3 text-sm text-amber-700">{translate("The outcome is not confirmed. Retry this same correction before starting another; it will not be applied twice.")}</p>}
    {!review ? <form onSubmit={(e) => { e.preventDefault(); if (!busy) void inspect(); }}><fieldset disabled={busy} className="mt-4 space-y-4">
      <label className="block text-xs text-slate-500">{translate("Stock change (")}{product.unit})<input autoFocus required maxLength={32} value={qty} onChange={(e) => setQty(e.target.value)} className={inputClass} /></label>
      <label className="block text-xs text-slate-500">{translate("Correction reason")}<textarea required maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={inputClass} /></label>
    </fieldset><button disabled={busy} className="mt-5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">{busy ? translate("Reviewing…") : translate("Review correction")}</button></form> : <>
      <div className="mt-4 rounded-xl border border-slate-200 p-3 text-sm text-slate-600"><p>{translate("Before:")} {review.snapshot.qty_before} {product.unit}</p><p>{translate("Change:")} {review.snapshot.qty_change} {product.unit}</p><p className="font-semibold text-emerald-600">{translate("After:")} {review.qty_after} {product.unit}</p><p className="mt-2">{translate("Reason:")} {review.snapshot.reason}</p></div>
      {review.approval_required && !grant && <form onSubmit={(e) => { e.preventDefault(); if (!busy) void approval(); }} className="mt-4"><p className="text-sm text-slate-500">{translate("An owner must approve every stockkeeper correction.")}</p><fieldset disabled={busy} className="mt-3 space-y-3">
        <label className="block text-xs text-slate-500">{translate("Owner phone")}<input required autoComplete="off" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} className={inputClass} /></label>
        <label className="block text-xs text-slate-500">{translate("Owner PIN")}<input required type="password" autoComplete="off" inputMode="numeric" pattern="[0-9]{4,6}" value={pin} onChange={(e) => setPin(e.target.value)} className={inputClass} /></label>
        <label className="block text-xs text-slate-500">{translate("Owner authenticator code")}<input required autoComplete="off" inputMode="numeric" pattern="[0-9]{6}" value={code} onChange={(e) => setCode(e.target.value)} className={inputClass} /></label>
      </fieldset><button disabled={busy} className="mt-3 text-sm font-medium text-emerald-600 disabled:opacity-40">{translate("Request owner approval")}</button></form>}
      {grant && <p className="mt-3 text-sm text-emerald-600">{translate("Owner approval received for this correction.")}</p>}
      <div className="mt-5 flex flex-wrap gap-3"><button disabled={busy || !!uncertain} onClick={() => { setReview(null); setGrant(null); }} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 disabled:opacity-40">{translate("Edit correction")}</button><button disabled={busy || (review.approval_required && !grant && !uncertain)} onClick={() => void commit()} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">{busy ? translate("Confirming…") : uncertain ? translate("Retry correction") : translate("Confirm correction")}</button></div>
    </>}
    <button disabled={busy || !!uncertain} onClick={onClose} className="mt-5 text-sm text-slate-500 disabled:opacity-40">{translate("Cancel correction")}</button>
  </div></Dialog>;
}



