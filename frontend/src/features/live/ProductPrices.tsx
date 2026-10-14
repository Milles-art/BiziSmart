import { translate, useLanguage } from '../../i18n';
import { useState } from 'react';
import { Dialog } from '@mui/material';
import { errorMessage, type CatalogueProduct } from './api';
import { useProductPricesQuery, useShelfLabelsQuery, type PriceEntry } from './productPricesApi';
import { code128Bars } from './code128';

const time = new Intl.DateTimeFormat('en-TZ', { timeZone: 'Africa/Dar_es_Salaam', dateStyle: 'medium', timeStyle: 'short' });
const money = (value: number) => `TSh ${value.toLocaleString('en-TZ')}`;
const button = 'rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 disabled:opacity-40';
import {priceHistoryCsv} from './priceHistoryCsv';
function downloadHistory(entries: PriceEntry[], product: string, page: number) {
  const url = URL.createObjectURL(new Blob(['\ufeff', priceHistoryCsv(entries)], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a'); link.href = url; link.download = `price-history-${product}-page-${page}.csv`; link.click(); URL.revokeObjectURL(url);
}
function Barcode({ value }: { value: string }) {
  useLanguage();
  const code = code128Bars(value);
  return <svg aria-label={`Barcode ${value}`} role="img" viewBox={`0 0 ${code.width} 40`} width="100%" height="40" preserveAspectRatio="xMidYMid meet"><rect width={code.width} height="40" fill="white" />{code.bars.map((bar) => <rect key={bar.x} x={bar.x} y="0" width={bar.width} height="40" fill="black" />)}</svg>;
}
export function ProductPrices({ product, onClose,initialTab='history' }: { product: CatalogueProduct; onClose: () => void;initialTab?:'history'|'labels' }) {
  useLanguage();
  const [tab, setTab] = useState<'history' | 'labels'>(initialTab); const [page, setPage] = useState(1);
  const history = useProductPricesQuery({ product: product.id, page }, { skip: tab !== 'history' });
  const labels = useShelfLabelsQuery(product.id, { skip: tab !== 'labels' });
  const query = tab === 'history' ? history : labels;
  return <Dialog open onClose={onClose} maxWidth="sm" fullWidth aria-labelledby="product-prices-title" slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-5 sm:p-6">
    <div className="flex items-start justify-between gap-3"><div><h2 id="product-prices-title" className="text-lg font-bold text-slate-800">{translate("Prices & labels")}</h2><p className="text-sm text-slate-500">{product.name}</p></div><button onClick={onClose} className={button}>{translate("Close")}</button></div>
    <div className="my-4 flex gap-2"><button aria-pressed={tab === 'history'} onClick={() => setTab('history')} className={button}>{translate("Price history")}</button><button aria-pressed={tab === 'labels'} onClick={() => setTab('labels')} className={button}>{translate("Shelf labels")}</button></div>
    {query.isFetching && <p role="status" className="text-sm text-slate-400">{translate("Loading prices…")}</p>}{query.error && <p role="alert" className="text-sm text-red-600">{errorMessage(query.error)} <button onClick={() => query.refetch()} className="underline">{translate("Retry prices")}</button></p>}
    {tab === 'history' && history.currentData && !history.error && <><p className="mb-3 text-xs text-slate-500">{translate("Recorded catalogue changes. Older changes before history recording began are unavailable. Historical receipts keep their original prices.")}</p>{history.currentData.prices.map((entry) => <div key={entry.id} className="mb-3 rounded-xl border border-slate-200 p-3"><p className="text-sm font-semibold text-slate-700">{entry.packaging_name ?? `Base ${entry.unit}`}{entry.packaging_version && ` · version ${entry.packaging_version}`}</p><p className="mt-1 text-sm text-emerald-700">{entry.old_price === null ? translate("Initial price") : money(entry.old_price)} → {money(entry.new_price)}</p><p className="mt-1 text-xs text-slate-500">{time.format(new Date(entry.created_at))} {translate("(Tanzania) ·")} {entry.actor_name}</p></div>)}{!history.currentData.prices.length && <p className="py-3 text-sm text-slate-500">{translate("No recorded price changes.")}</p>}<div className="mt-3 flex flex-wrap items-center justify-between gap-2"><button className={button} disabled={!history.currentData.prices.length} onClick={() => downloadHistory(history.currentData!.prices, product.id, page)}>{translate("Export this page (CSV)")}</button><div className="flex items-center gap-3 text-sm"><button disabled={page <= 1 || history.isFetching} className="disabled:opacity-40" onClick={() => setPage(page - 1)}>{translate("Previous")}</button><span>{translate("Page")} {page}</span><button disabled={page * history.currentData.meta.per_page >= history.currentData.meta.total || history.isFetching} className="disabled:opacity-40" onClick={() => setPage(page + 1)}>{translate("Next")}</button></div></div></>}
    {tab === 'labels' && labels.currentData && !labels.error && <><p className="mb-3 text-xs text-slate-500">{translate("Current catalogue prices. Refresh before printing after a price change. Use your browser's print dialog to print or save as PDF.")}</p><div id="bizismart-shelf-labels" className="grid gap-3 sm:grid-cols-2">{labels.currentData.labels.map((label) => <div key={label.id} className="break-inside-avoid rounded-lg border border-slate-300 bg-white p-3 text-center"><p className="font-semibold text-slate-900">{label.product_name}</p><p className="text-xs text-slate-600">{label.packaging_name ?? 'Base unit'} · {label.base_qty} {label.unit}</p><p className="my-2 text-2xl font-bold text-black">{money(label.selling_price)}</p>{label.barcode ? <><Barcode value={label.barcode} /><p className="mt-1 break-all text-xs text-black">{label.barcode}</p></> : <p className="text-xs text-slate-500">{translate("No barcode assigned")}</p>}</div>)}</div><div className="mt-4 flex gap-2"><button className={button} disabled={labels.isFetching} onClick={() => labels.refetch()}>{translate("Refresh labels")}</button><button className="rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-white" disabled={labels.isFetching} onClick={() => window.print()}>{translate("Print labels / PDF")}</button></div><style>{`@media print { body * { visibility: hidden !important; } #bizismart-shelf-labels, #bizismart-shelf-labels * { visibility: visible !important; } #bizismart-shelf-labels { position: absolute; inset: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4mm; padding: 5mm; } .MuiDialog-container { display: block !important; overflow: visible !important; } .MuiDialog-paper { max-height: none !important; height: auto !important; overflow: visible !important; box-shadow: none !important; margin: 0 !important; max-width: none !important; } }`}</style></>}
  </div></Dialog>;
}



