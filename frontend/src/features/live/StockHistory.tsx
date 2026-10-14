import { translate, useLanguage } from '../../i18n';
import { useState } from 'react';
import { errorMessage, useMovementsQuery, type CatalogueProduct } from './api';

const recordedTime = new Intl.DateTimeFormat('en-TZ', { timeZone: 'Africa/Dar_es_Salaam', dateStyle: 'medium', timeStyle: 'short' });
function historyError(error: unknown) {
  const e = error as { data?: { error?: { code?: string } } };
  return e.data?.error?.code === 'NOT_FOUND' ? 'This product or batch is no longer available. Close stock and refresh the catalogue.' : errorMessage(error);
}

export function StockHistory({ product, batch, onAll }: { product: CatalogueProduct; batch: { id: string; name: string } | null; onAll: () => void }) {
  useLanguage();
  const [page, setPage] = useState(1);
  const { currentData: data, error, isFetching, refetch } = useMovementsQuery({ product: product.id, page, ...(batch ? { batch_id: batch.id } : {}) });
  return <div className="mt-4 space-y-3">
    <p className="text-sm text-slate-500">{batch ? `History for batch ${batch.name}.` : translate("All movements for this product.")} {translate("Balances below are recorded after each movement, per batch.")}</p>
    {batch && <button onClick={onAll} className="text-sm text-emerald-600 underline">{translate("All movements")}</button>}
    {error && <p role="alert" className="text-sm text-red-600">{historyError(error)} <button onClick={refetch} className="underline">{translate("Retry history")}</button></p>}
    {isFetching && <p role="status" className="text-sm text-slate-400">{translate("Loading movement history…")}</p>}
    {!error && data && <>{data.movements.map((movement) => <div key={movement.id} aria-label={`Movement ${movement.id}`} className="rounded-xl border border-slate-200 p-3">
      <div className="flex flex-wrap justify-between gap-2"><p className="font-medium text-slate-700">{movement.batch_number}</p><p className="text-sm capitalize text-emerald-600">{translate(movement.type.replace(/_/g,' '))}</p></div>
      <p className="mt-1 text-sm text-slate-700">{translate("Change:")} {movement.qty_change} {product.unit}</p>
      <p className="mt-1 text-sm text-slate-500">{translate("Batch balance after movement:")} {movement.qty_after} {product.unit}</p>
      <p className="mt-1 text-xs text-slate-400">{translate("Recorded:")} {recordedTime.format(new Date(movement.created_at))} {translate("(Tanzania)")}</p>
      {movement.receipt?.packaging_name && <p className="mt-1 text-xs text-slate-500">{translate("Received")} {movement.receipt.qty} × {movement.receipt.packaging_name} {translate("(version")} {movement.receipt.packaging_version}) · {movement.receipt.base_qty} {product.unit}</p>}
      {movement.reason && <p className="mt-1 text-sm text-slate-500">{translate("Reason:")} {movement.reason}</p>}
    </div>)}
    {!data.movements.length && <p className="py-3 text-sm text-slate-500">{translate("No stock movements match.")}</p>}
    <div className="flex justify-between gap-2 text-sm text-slate-500"><button disabled={page === 1 || isFetching} onClick={() => setPage(page - 1)} className="disabled:opacity-40">{translate("Previous history")}</button><span>{translate("Page")} {data.meta.page}</span><button disabled={page * data.meta.per_page >= data.meta.total || isFetching} onClick={() => setPage(page + 1)} className="disabled:opacity-40">{translate("Next history")}</button></div></>}
  </div>;
}



