import { translate, useLanguage } from '../../i18n';
import { useEffect, useState } from 'react';
import { Dialog } from '@mui/material';
import { useDispatch, useSelector,useStore } from 'react-redux';
import { errorMessage, type SessionState } from './api';
import { usePreviewStockImportMutation, useCommitStockImportMutation, useExportCatalogueMutation, type ImportInput, type ImportReview, type ImportResult } from './catalogueImportApi';
import { importFinished, importPending, type CatalogueImportState } from './catalogueImportState';
import { readImportFile } from './importFiles';
const input = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700';
const columns = ['barcode', 'batch_number', 'qty', 'cost_price', 'expiry_date'] as const;
type Mapping = Record<typeof columns[number], number>;
function download(content: string, name: string) { const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' })); const anchor = document.createElement('a'); anchor.href = url; anchor.download = name; anchor.click(); URL.revokeObjectURL(url); }
export function ProductImportExport({ onClose, canImport = true }: { onClose: () => void; canImport?: boolean }) {
  useLanguage();
  const store=useStore<{session:SessionState}>();
  const dispatch = useDispatch(); const pending = useSelector((s: { catalogueImport: CatalogueImportState }) => s.catalogueImport.pending);
  const token = useSelector((s: { session: SessionState }) => s.session.token);
  const [table, setTable] = useState<string[][] | null>(null); const [mapping, setMapping] = useState<Mapping>({ barcode: 0, batch_number: 1, qty: 2, cost_price: 3, expiry_date: 4 });
  const [body, setBody] = useState<ImportInput | null>(null); const [review, setReview] = useState<ImportReview | null>(null); const [saved, setSaved] = useState<ImportResult | null>(null); const [error, setError] = useState(''); const [busy, setBusy] = useState(false);
  const [preview] = usePreviewStockImportMutation(); const [commit] = useCommitStockImportMutation(); const [exportCatalogue] = useExportCatalogueMutation();
  useEffect(() => { if (!pending) return; const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; }; window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn); }, [pending]);
  async function fileSelected(file: File) {
    setBusy(true); setError(''); setReview(null); setBody(null); setSaved(null);
    try { const rows = await readImportFile(file); setTable(rows); setMapping(Object.fromEntries(columns.map((key) => [key, rows[0].findIndex((name) => name.trim().toLowerCase() === key)])) as Mapping); }
    catch (failure) { setError(failure instanceof Error ? failure.message : 'Cannot read this file.'); setTable(null); } finally { setBusy(false); }
  }
  async function reviewRows() {
    if (!table || busy || pending) return; setError(''); setBusy(true);
    try {
      if (columns.slice(0, 4).some((key) => mapping[key] < 0)) throw new Error('Map barcode, batch number, quantity and cost price.');
      const used = columns.filter((key) => mapping[key] >= 0).map((key) => mapping[key]); if (new Set(used).size !== used.length) throw new Error('Map each field to a different column.');
      const rows = table.slice(1).map((row, index) => {
        const cost = row[mapping.cost_price]?.trim() ?? ''; if (!/^(0|[1-9][0-9]*)$/.test(cost) || !Number.isSafeInteger(Number(cost))) throw new Error(`Row ${index + 1}: cost must be a whole TSh amount.`);
        return { barcode: row[mapping.barcode]?.trim() ?? '', batch_number: row[mapping.batch_number]?.trim() ?? '', qty: row[mapping.qty]?.trim() ?? '', cost_price: Number(cost), expiry_date: mapping.expiry_date >= 0 ? row[mapping.expiry_date]?.trim() || null : null };
      });
      const next = { operation_id: crypto.randomUUID(), rows }; const result = await preview(next).unwrap(); if (!store.getState().session.token || store.getState().session.token !== token) return; setBody(next); setReview(result);
    } catch (failure) { setError(failure instanceof Error ? failure.message : errorMessage(failure)); } finally { setBusy(false); }
  }
  async function commitRows() {
    if (busy || (!pending && (!body || !review))) return; const frozen = pending ?? { ...body!, review_hash: review!.review_hash };
    dispatch(importPending(frozen)); setBusy(true); setError('');
    try { const result = await commit(frozen).unwrap(); if (!store.getState().session.token || store.getState().session.token !== token) return; dispatch(importFinished()); setSaved(result); setReview(null); setBody(null); setTable(null); }
    catch (failure) {
      if (!store.getState().session.token || store.getState().session.token !== token) return;
      const code = (failure as { data?: { error?: { code?: string } } }).data?.error?.code;
      if (['IMPORT_REVIEW_CHANGED', 'IMPORT_ROW_INVALID', 'VALIDATION_ERROR', 'PRODUCT_INACTIVE', 'PACKAGING_VERSION_CHANGED'].includes(code ?? '')) { dispatch(importFinished()); setReview(null); setBody(null); }
      setError(errorMessage(failure));
    } finally { setBusy(false); }
  }
  async function exportRows() { setBusy(true); setError(''); try { const csv = await exportCatalogue().unwrap(); if (!store.getState().session.token || store.getState().session.token !== token) return; download(csv, 'bizismart-catalogue.csv'); } catch (failure) { if (store.getState().session.token === token) setError(errorMessage(failure)); } finally { setBusy(false); } }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} maxWidth="sm" fullWidth aria-labelledby="product-import-title" slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-5 sm:p-6"><div className="flex justify-between gap-3"><h2 id="product-import-title" className="text-lg font-bold text-slate-800">{translate("Import & export")}</h2><button disabled={busy} onClick={onClose} className="text-sm text-slate-500">{translate("Close")}</button></div><p className="mt-2 text-sm text-slate-500">{translate("Export your catalogue or receive opening stock against registered product/pack barcodes. Imports create stock receipts, not new products.")}</p>
    <button disabled={busy} onClick={exportRows} className="mt-4 rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-600">{translate("Export catalogue (CSV)")}</button>
    {error && <p role="alert" className="mt-3 text-sm text-red-600">{translate(error)}</p>}{busy && <p role="status" className="mt-3 text-sm text-slate-400">{translate("Working…")}</p>}
    {saved && <p role="status" className="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">{saved.row_count} {translate("stock rows received. Import")} {saved.import_id}{saved.replayed ? translate(" (already completed)") : ''}.</p>}
    {pending ? <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3"><p className="text-sm text-amber-800">{translate("This import has an unresolved response. Retry the saved request to confirm its result. It cannot receive stock twice.")}</p><button disabled={busy} onClick={commitRows} className="mt-3 rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-white">{translate("Retry saved import")}</button></div> : canImport && <><p className="mt-5 text-xs text-slate-500">{translate("CSV or .xlsx first worksheet, up to 200 rows and 2 MB. Store barcodes as text and dates as YYYY-MM-DD. Quantity uses base units/kg or whole packs; cost is TSh per base pricing unit, including per kg for weighted goods. No formulas or macros.")}</p><button onClick={() => download('barcode,batch_number,qty,cost_price,expiry_date\r\n', 'opening-stock-template.csv')} className="mt-2 text-sm text-emerald-600 underline">{translate("Download column template")}</button><label className="mt-3 block text-xs text-slate-500">{translate("Opening stock file")}<input className={input} type="file" accept=".csv,.xlsx" disabled={busy} onChange={(event) => { const file = event.target.files?.[0]; if (file) void fileSelected(file); event.target.value = ''; }} /></label>
      {table && !review && <><div className="mt-4 grid gap-3 sm:grid-cols-2">{columns.map((key) => <label key={key} className="text-xs text-slate-500">{key.replace(/_/g, ' ')}<select className={input} value={mapping[key]} disabled={busy} onChange={(event) => setMapping({ ...mapping, [key]: Number(event.target.value) })}><option value={-1}>{key === 'expiry_date' ? translate("No expiry column") : translate("Choose column")}</option>{table[0].map((name, index) => <option key={index} value={index}>{name || `Column ${index + 1}`}</option>)}</select></label>)}</div><button disabled={busy} onClick={reviewRows} className="mt-4 rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-white">{translate("Review")} {table.length - 1} {translate("rows")}</button></>}
      {review && <><h3 className="mt-4 font-semibold text-slate-700">{translate("Review")} {review.row_count} {translate("stock rows")}</h3><div className="mt-3 max-h-64 space-y-2 overflow-y-auto">{review.rows.map((row) => <div key={row.row} className="rounded-lg border border-slate-200 p-3 text-sm"><p className="font-semibold text-slate-700">{translate("Row")} {row.row}: {row.product_name}{row.packaging_name && ` · ${row.packaging_name}`}</p><p className="text-slate-500">{row.qty} {row.packaging_name ?? row.unit} → {row.base_qty} {row.unit} {translate("· Cost TSh")} {row.cost_price.toLocaleString('en-TZ')} / {row.unit}</p><p className="text-xs text-slate-500">{translate("Batch")} {row.batch_number} {translate("· Expiry")} {row.expiry_date ?? 'none'} {translate("· Selling TSh")} {row.selling_price.toLocaleString('en-TZ')}</p></div>)}</div><div className="mt-4 flex gap-3"><button disabled={busy} onClick={() => { setReview(null); setBody(null); }} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">{translate("Change mapping")}</button><button disabled={busy} onClick={commitRows} className="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-white">{translate("Receive all stock")}</button></div></>}
    </>}
  </div></Dialog>;
}



