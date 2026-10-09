import { Badge,SectionCard,StatCard } from '@/components/ui/Primitives';
import type { Store as StoreType } from '@/store/useStore';
import { formatKES } from '@/store/useStore';
import { AlertTriangle,Boxes,CheckCircle,Minus,Plus,Printer,Search,TrendingDown,Upload,X } from 'lucide-react';
import { useState } from 'react';

interface Props { store: StoreType; }

export function Inventory({ store }: Props) {
  const [tab, setTab] = useState<'overview'|'movements'|'adjustments'|'stocktake'>('overview');
  const [adjustProduct, setAdjustProduct] = useState('');
  const [adjustType, setAdjustType] = useState<'Damage'|'Wastage'|'Adjustment'|'Transfer'>('Damage');
  const [adjustQty, setAdjustQty] = useState('');
  const [adjustReason, setAdjustReason] = useState('');
  const [adjustNotice, setAdjustNotice] = useState('');
  const [showLabel, setShowLabel] = useState<string | null>(null);
  const [showImport, setShowImport] = useState(false);
  const [csvText, setCsvText] = useState('');
  const [importPreview, setImportPreview] = useState<{ name: string; barcode: string; price: string; stock: string; valid: boolean }[] | null>(null);

  const totalStock = store.products.reduce((s, p) => s + p.stock, 0);
  const totalValue = store.products.reduce((s, p) => s + p.cost * p.stock, 0);
  const damagedValue = store.stockMovements.filter(m => m.type === 'Damage' || m.type === 'Wastage').reduce((s, m) => { const p = store.products.find(pr => pr.id === m.productId); return s + Math.abs(m.qty) * (p?.cost ?? 0); }, 0);

  const handleAdjust = () => {
    if (!adjustProduct || !adjustReason) { setAdjustNotice('Product and reason are required'); return; }
    const qty = parseInt(adjustQty) || 0;
    if (qty === 0) { setAdjustNotice('Quantity must be non-zero'); return; }
    const result = store.submitStockAdjustment(adjustProduct, qty, adjustType, adjustReason);
    if (result.needsApproval) {
      setAdjustNotice(`Adjustment of ${qty} units submitted for supervisor approval (>5 units requires approval)`);
    } else {
      setAdjustNotice(`Stock adjusted by ${qty > 0 ? '+' : ''}${qty} units — inventory updated`);
    }
    setAdjustProduct(''); setAdjustQty(''); setAdjustReason('');
    setTimeout(() => setAdjustNotice(''), 5000);
  };

  const handleImportPreview = () => {
    const lines = csvText.trim().split('\n');
    const preview = lines.slice(0, 10).map(line => {
      const [name, barcode, price, stock] = line.split(',');
      const valid = !!name && !!barcode && !!price && !!stock;
      return { name: name ?? '', barcode: barcode ?? '', price: price ?? '', stock: stock ?? '', valid };
    });
    setImportPreview(preview);
  };

  const handleStockTakeComplete = () => {
    const result = store.completeStockTake();
    setAdjustNotice(`Stock take completed: ${result.variances} variances found, ${result.adjusted} items adjusted`);
    setTimeout(() => setAdjustNotice(''), 6000);
  };

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard title="Total Stock Units" value={String(totalStock)} icon={<Boxes className="h-5 w-5 text-white" />} color="bg-blue-500" />
        <StatCard title="Inventory Value" value={formatKES(totalValue)} icon={<TrendingDown className="h-5 w-5 text-white" />} color="bg-emerald-500" />
        <StatCard title="Low Stock Items" value={String(store.lowStockProducts.length)} icon={<AlertTriangle className="h-5 w-5 text-white" />} color="bg-amber-500" />
        <StatCard title="Wastage Value" value={formatKES(damagedValue)} icon={<TrendingDown className="h-5 w-5 text-white" />} color="bg-red-500" />
      </div>

      {adjustNotice && <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">{adjustNotice}</div>}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-lg bg-slate-200 p-1">
          {([{ id:'overview', label:'Overview' },{ id:'movements', label:'Stock Movements' },{ id:'adjustments', label:'Adjustments' },{ id:'stocktake', label:'Stock Take' }] as const).map(t => (
            <button key={t.id} onClick={() => setTab(t.id)} className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${tab === t.id ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{t.label}</button>
          ))}
        </div>
        <div className="flex gap-2">
          <button onClick={() => setShowImport(true)} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"><Upload className="h-4 w-4" /> Import</button>
          <button onClick={() => setShowLabel('p1')} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"><Printer className="h-4 w-4" /> Labels</button>
        </div>
      </div>

      {tab === 'overview' && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <SectionCard title="Low Stock — Reorder Needed">
            <div className="space-y-2">
              {store.lowStockProducts.map(p => (
                <div key={p.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-3 hover:border-slate-200">
                  <div className="flex items-center gap-3"><div className={`h-9 w-9 rounded-lg ${p.imageColor}`} /><div><p className="text-sm font-medium text-slate-700">{p.name}</p><p className="text-xs text-slate-400">Reorder at: {p.reorderLevel} {p.unit}</p></div></div>
                  <div className="text-right"><p className="text-sm font-bold text-red-600">{p.stock} {p.unit}</p><button onClick={() => store.setScreen('suppliers')} className="text-xs font-medium text-emerald-600 hover:text-emerald-700">Create PO</button></div>
                </div>
              ))}
              {store.lowStockProducts.length === 0 && <p className="py-4 text-center text-sm text-slate-400">All products above reorder level</p>}
            </div>
          </SectionCard>
          <SectionCard title="Near-Expiry — Markdown Candidates">
            <div className="space-y-2">
              {store.expiringProducts.map(p => {
                const days = Math.ceil((new Date(p.expiry!).getTime() - new Date('2026-10-08').getTime()) / (1000*60*60*24));
                return (
                  <div key={p.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-3 hover:border-slate-200">
                    <div className="flex items-center gap-3"><div className={`h-9 w-9 rounded-lg ${p.imageColor}`} /><div><p className="text-sm font-medium text-slate-700">{p.name}</p><p className="text-xs text-slate-400">Batch: {p.batch} · Expires: {p.expiry}</p></div></div>
                    <div className="flex flex-col items-end gap-1"><Badge status={`${days}d left`} variant={days<=2?'danger':'warning'} /><button onClick={() => store.setScreen('products')} className="text-xs font-medium text-emerald-600 hover:text-emerald-700">Markdown</button></div>
                  </div>
                );
              })}
              {store.expiringProducts.length === 0 && <p className="py-4 text-center text-sm text-slate-400">No products expiring soon</p>}
            </div>
          </SectionCard>
        </div>
      )}

      {tab === 'movements' && (
        <SectionCard title="Stock Movement History">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="pb-2 font-medium">Product</th><th className="pb-2 font-medium">Type</th><th className="pb-2 text-right font-medium">Qty</th><th className="pb-2 font-medium">User</th><th className="pb-2 font-medium">Reason</th><th className="pb-2 font-medium">Time</th></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {store.stockMovements.map(m => (
                  <tr key={m.id} className="hover:bg-slate-50">
                    <td className="py-3 font-medium text-slate-700">{m.productName}</td>
                    <td className="py-3"><Badge status={m.type} variant={m.type==='Received'?'success':m.type==='Sale'?'info':m.type==='Wastage'||m.type==='Damage'?'danger':m.type==='Return'?'info':'warning'} /></td>
                    <td className={`py-3 text-right font-semibold ${m.qty > 0 ? 'text-emerald-600' : 'text-red-600'}`}>{m.qty > 0 ? '+' : ''}{m.qty}</td>
                    <td className="py-3 text-slate-600">{m.user}</td>
                    <td className="py-3 text-slate-500">{m.reason || '—'}</td>
                    <td className="py-3 text-slate-500">{m.time}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      )}

      {tab === 'adjustments' && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <SectionCard title="New Stock Adjustment" className="lg:col-span-2">
            <div className="space-y-4">
              <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2.5"><Search className="h-4 w-4 text-slate-400" /><select value={adjustProduct} onChange={(e) => setAdjustProduct(e.target.value)} className="flex-1 bg-transparent text-sm outline-none"><option value="">Select product...</option>{store.products.map(p => <option key={p.id} value={p.id}>{p.name} (Stock: {p.stock})</option>)}</select></div>
              <div className="grid grid-cols-2 gap-4">
                <div><label className="mb-1 block text-xs font-medium text-slate-500">Adjustment Type</label><select value={adjustType} onChange={(e) => setAdjustType(e.target.value as typeof adjustType)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"><option>Damage / Spoilage</option><option>Wastage</option><option>Adjustment</option><option>Transfer</option></select></div>
                <div><label className="mb-1 block text-xs font-medium text-slate-500">Quantity Change</label><div className="flex gap-2"><button onClick={() => setAdjustQty(String((parseInt(adjustQty)||0)-1))} className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 hover:bg-slate-200"><Minus className="h-4 w-4" /></button><input type="number" value={adjustQty} onChange={(e) => setAdjustQty(e.target.value)} placeholder="0" className="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /><button onClick={() => setAdjustQty(String((parseInt(adjustQty)||0)+1))} className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 hover:bg-slate-200"><Plus className="h-4 w-4" /></button></div></div>
              </div>
              <div><label className="mb-1 block text-xs font-medium text-slate-500">Reason (required)</label><textarea value={adjustReason} onChange={(e) => setAdjustReason(e.target.value)} placeholder="Describe the reason for adjustment..." className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" rows={3} /></div>
              <div className="rounded-lg bg-amber-50 p-3 text-xs text-amber-600">Adjustments over 5 units require supervisor approval. Damaged/wasted items are tracked separately from sellable stock.</div>
              <button onClick={handleAdjust} disabled={!adjustProduct || !adjustReason} className="w-full rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40">Submit Adjustment</button>
            </div>
          </SectionCard>
          <SectionCard title="Returned Stock Handling">
            <p className="mb-3 text-xs text-slate-400">Returned items from refunds are automatically restocked as sellable or damaged based on condition.</p>
            <div className="space-y-2">
              {store.stockMovements.filter(m => m.type === 'Return').map(m => (
                <div key={m.id} className="rounded-lg border border-slate-100 p-3"><div className="flex items-center justify-between"><p className="text-sm font-medium text-slate-700">{m.productName}</p><Badge status="Sellable" variant="success" /></div><p className="mt-1 text-xs text-slate-400">{m.reason}</p></div>
              ))}
              {store.stockMovements.filter(m => m.type === 'Return').length === 0 && <p className="py-4 text-center text-sm text-slate-400">No returned stock</p>}
            </div>
          </SectionCard>
        </div>
      )}

      {tab === 'stocktake' && (
        <SectionCard title={`Stock Take — ${store.stockTake.department} Department`}>
          <div className="mb-4 flex items-center justify-between">
            <div><p className="text-sm font-medium text-slate-700">Counted by: {store.stockTake.countedBy}</p><p className="text-xs text-slate-400">Started: {store.stockTake.startedAt}</p></div>
            <Badge status={store.stockTake.status === 'in_progress' ? 'In Progress' : 'Completed'} variant={store.stockTake.status === 'in_progress' ? 'warning' : 'success'} />
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="pb-2 font-medium">Product</th><th className="pb-2 text-right font-medium">System Qty</th><th className="pb-2 text-right font-medium">Counted Qty</th><th className="pb-2 text-right font-medium">Variance</th><th className="pb-2 text-center font-medium">Status</th></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {store.stockTake.items.map(it => (
                  <tr key={it.productId} className="hover:bg-slate-50">
                    <td className="py-2.5 font-medium text-slate-700">{it.productName}</td>
                    <td className="py-2.5 text-right text-slate-600">{it.systemQty}</td>
                    <td className="py-2.5 text-right"><input type="number" value={it.countedQty ?? ''} onChange={(e) => store.updateStockTakeCount(it.productId, parseInt(e.target.value) || 0)} placeholder="—" className="w-20 rounded-md border border-slate-200 px-2 py-1 text-right text-sm outline-none focus:border-emerald-400" /></td>
                    <td className={`py-2.5 text-right font-semibold ${it.variance !== 0 ? (it.variance > 0 ? 'text-emerald-600' : 'text-red-600') : 'text-slate-400'}`}>{it.countedQty !== null && it.variance !== 0 ? (it.variance > 0 ? '+' : '') + it.variance : '—'}</td>
                    <td className="py-2.5 text-center">{it.countedQty === null ? <Badge status="Pending" variant="neutral" /> : it.variance === 0 ? <Badge status="Match" variant="success" /> : <Badge status="Variance" variant="warning" />}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-4 flex justify-end gap-3"><button className="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Save Draft</button><button onClick={handleStockTakeComplete} disabled={store.stockTake.status === 'completed'} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40">Complete & Adjust Stock</button></div>
        </SectionCard>
      )}

      {/* Label Modal */}
      {showLabel && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowLabel(null)}>
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">Barcode Label Preview</h2><button onClick={() => setShowLabel(null)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
            <div className="space-y-3">
              {store.products.slice(0, 4).map(p => (
                <div key={p.id} className="flex items-center justify-between rounded-lg border-2 border-dashed border-slate-300 p-3">
                  <div><p className="text-sm font-bold text-slate-800">{p.name}</p><p className="text-lg font-bold text-emerald-600">{formatKES(p.price)}</p></div>
                  <div className="flex flex-col items-end gap-1"><div className="flex gap-0.5">{p.barcode.split('').map((d,i) => <div key={i} className="w-0.5 bg-slate-800" style={{height: `${parseInt(d)*3+8}px`}} />)}</div><p className="font-mono text-[10px] text-slate-500">{p.barcode}</p></div>
                </div>
              ))}
            </div>
            <button onClick={() => setShowLabel(null)} className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-slate-100 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-200"><Printer className="h-4 w-4" /> Print Labels</button>
          </div>
        </div>
      )}

      {/* Import Modal */}
      {showImport && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowImport(false)}>
          <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">Import Products (CSV)</h2><button onClick={() => setShowImport(false)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
            <p className="mb-3 text-xs text-slate-500">Format: name,barcode,price,stock (one product per line)</p>
            <textarea value={csvText} onChange={(e) => setCsvText(e.target.value)} placeholder="Sifted Maize Flour 2kg,6001234500011,185,240&#10;Sugar 1kg,6001234500028,175,180" className="h-32 w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-xs outline-none focus:border-emerald-400" />
            <button onClick={handleImportPreview} disabled={!csvText.trim()} className="mt-3 w-full rounded-lg bg-slate-100 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200">Preview</button>
            {importPreview && (
              <div className="mt-4">
                <h3 className="mb-2 text-xs font-semibold text-slate-500">Preview ({importPreview.length} rows)</h3>
                <div className="space-y-1">
                  {importPreview.map((row, i) => (
                    <div key={i} className={`flex items-center justify-between rounded-lg p-2 text-xs ${row.valid ? 'bg-emerald-50' : 'bg-red-50'}`}>
                      <span className="text-slate-700">{row.name || '(empty)'}</span>
                      <div className="flex items-center gap-2"><span className="text-slate-400">{row.barcode}</span><span className="text-slate-600">{row.price}</span>{row.valid ? <CheckCircle className="h-3 w-3 text-emerald-500" /> : <AlertTriangle className="h-3 w-3 text-red-500" />}</div>
                    </div>
                  ))}
                </div>
                <button className="mt-3 w-full rounded-lg bg-emerald-500 py-2 text-sm font-bold text-white hover:bg-emerald-600">Import {importPreview.filter(r => r.valid).length} Products</button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
