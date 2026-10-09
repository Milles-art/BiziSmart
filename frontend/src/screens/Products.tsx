import { Badge } from '@/components/ui/Primitives';
import type { Store as StoreType } from '@/store/useStore';
import { formatKES } from '@/store/useStore';
import type { Product } from '@/types';
import { Download,Edit2,Gift,History,Percent,Plus,Printer,Search,Upload,X } from 'lucide-react';
import { useMemo,useState } from 'react';

interface Props { store: StoreType; }

export function Products({ store }: Props) {
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [deptFilter, setDeptFilter] = useState('All');
  const [showAdd, setShowAdd] = useState(false);
  const [editProduct, setEditProduct] = useState<Product | null>(null);
  const [showPriceHistory, setShowPriceHistory] = useState<Product | null>(null);
  const [showPromotions, setShowPromotions] = useState(false);
  const [showMarkdown, setShowMarkdown] = useState<Product | null>(null);
  const [tab, setTab] = useState<'catalogue' | 'promotions'>('catalogue');

  const filtered = useMemo(() => {
    let result = store.products;
    if (categoryFilter !== 'All') result = result.filter(p => p.category === categoryFilter);
    if (deptFilter !== 'All') result = result.filter(p => p.department === deptFilter);
    if (search) { const q = search.toLowerCase(); result = result.filter(p => p.name.toLowerCase().includes(q) || p.barcode.includes(q)); }
    return result;
  }, [search, categoryFilter, deptFilter, store.products]);

  const totalValue = store.products.reduce((s, p) => s + p.cost * p.stock, 0);
  const retailValue = store.products.reduce((s, p) => s + p.price * p.stock, 0);
  const avgMargin = store.products.reduce((s, p) => s + ((p.price - p.cost) / p.price) * 100, 0) / store.products.length;
  const belowCost = store.products.filter(p => p.price <= p.cost).length;

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">Total Products</p><p className="mt-1 text-xl font-bold text-slate-800">{store.products.length}</p></div>
        <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">Inventory (Cost)</p><p className="mt-1 text-xl font-bold text-slate-800">{formatKES(totalValue)}</p></div>
        <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">Inventory (Retail)</p><p className="mt-1 text-xl font-bold text-emerald-600">{formatKES(retailValue)}</p></div>
        <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">Avg Margin</p><p className="mt-1 text-xl font-bold text-slate-800">{avgMargin.toFixed(1)}%{belowCost > 0 && <span className="ml-2 text-xs text-red-500">({belowCost} below cost!)</span>}</p></div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-lg bg-slate-200 p-1">
          {([{ id:'catalogue', label:'Catalogue' },{ id:'promotions', label:'Promotions & Pricing' }] as const).map(t => (
            <button key={t.id} onClick={() => setTab(t.id)} className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${tab === t.id ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{t.label}</button>
          ))}
        </div>
        <div className="flex gap-2">
          <button className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"><Upload className="h-4 w-4" /> Import CSV</button>
          <button className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"><Download className="h-4 w-4" /> Export</button>
          {tab === 'catalogue' && <button onClick={() => setShowAdd(true)} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" /> Add Product</button>}
          {tab === 'promotions' && <button onClick={() => setShowPromotions(true)} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Gift className="h-4 w-4" /> New Promotion</button>}
        </div>
      </div>

      {tab === 'catalogue' && (
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
            <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 text-slate-400" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search products..." className="w-40 bg-transparent text-sm outline-none placeholder:text-slate-400 sm:w-56" /></div>
            <select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 outline-none"><option>All</option>{store.categories.map(c => <option key={c}>{c}</option>)}</select>
            <select value={deptFilter} onChange={(e) => setDeptFilter(e.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 outline-none"><option>All</option>{store.departments.map(d => <option key={d}>{d}</option>)}</select>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="px-4 py-3 font-medium">Product</th><th className="px-4 py-3 font-medium">Barcode</th><th className="px-4 py-3 text-right font-medium">Cost</th><th className="px-4 py-3 text-right font-medium">Price</th><th className="px-4 py-3 text-right font-medium">Margin</th><th className="px-4 py-3 text-right font-medium">Pack/Carton</th><th className="px-4 py-3 text-right font-medium">Stock</th><th className="px-4 py-3 text-center font-medium">Actions</th></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {filtered.map(p => {
                  const margin = ((p.price - p.cost) / p.price) * 100;
                  const belowCostP = p.price <= p.cost;
                  return (
                    <tr key={p.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3"><div className="flex items-center gap-3"><div className={`h-9 w-9 rounded-lg ${p.imageColor}`} /><div><p className="font-medium text-slate-700">{p.name}</p><p className="text-xs text-slate-400">{p.department} · {p.unit}{p.weighed ? ' · Weighed' : ''}</p></div></div></td>
                      <td className="px-4 py-3 font-mono text-xs text-slate-500">{p.barcode}</td>
                      <td className="px-4 py-3 text-right text-slate-600">{formatKES(p.cost)}</td>
                      <td className="px-4 py-3 text-right font-semibold text-slate-700">{formatKES(p.price)}</td>
                      <td className="px-4 py-3 text-right"><span className={belowCostP ? 'text-red-600 font-semibold' : margin < 10 ? 'text-amber-600' : 'text-emerald-600'}>{margin.toFixed(1)}%</span></td>
                      <td className="px-4 py-3 text-right text-xs text-slate-500">{p.packSize ? `${p.packSize}× ${formatKES(p.packPrice ?? 0)}` : '—'}{p.cartonSize ? ` / ${p.cartonSize}× ${formatKES(p.cartonPrice ?? 0)}` : ''}</td>
                      <td className="px-4 py-3 text-right"><span className={p.stock <= p.reorderLevel ? 'font-semibold text-red-600' : 'text-slate-600'}>{p.stock} {p.unit}</span></td>
                      <td className="px-4 py-3"><div className="flex items-center justify-center gap-1">
                        <button onClick={() => setShowPriceHistory(p)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-blue-600" title="Price history"><History className="h-4 w-4" /></button>
                        {p.expiry && <button onClick={() => setShowMarkdown(p)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-amber-600" title="Markdown"><Percent className="h-4 w-4" /></button>}
                        <button onClick={() => setEditProduct(p)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-blue-600"><Edit2 className="h-4 w-4" /></button>
                        <button className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><Printer className="h-4 w-4" /></button>
                      </div></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'promotions' && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {store.promotions.map(promo => (
            <div key={promo.id} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3"><div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500"><Gift className="h-5 w-5 text-white" /></div><div><h3 className="text-sm font-bold text-slate-800">{promo.name}</h3></div></div>
                <button onClick={() => store.togglePromotion(promo.id)}><Badge status={promo.active ? 'Active' : 'Inactive'} variant={promo.active ? 'success' : 'neutral'} /></button>
              </div>
              <div className="mt-3 space-y-1 text-xs text-slate-500">
                <p><span className="font-medium text-slate-600">Type:</span> {promo.type === 'percentage' ? `${promo.value}% off` : promo.type === 'bxgy' ? `Buy ${promo.buyQty} Get ${promo.getQty} Free` : promo.type === 'bulk' ? `${promo.value}% off when buying ${promo.buyQty}+` : promo.type === 'fixed' ? `${formatKES(promo.value)} off` : 'Time-based'}</p>
                {promo.category && <p><span className="font-medium text-slate-600">Category:</span> {promo.category}</p>}
                {promo.productId && <p><span className="font-medium text-slate-600">Product:</span> {store.products.find(p => p.id === promo.productId)?.name}</p>}
                <p><span className="font-medium text-slate-600">Period:</span> {promo.startDate} to {promo.endDate}</p>
              </div>
            </div>
          ))}
          {store.priceChanges.length > 0 && (
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2 xl:col-span-3">
              <h3 className="mb-3 text-sm font-bold text-slate-800">Price Change History</h3>
              <div className="space-y-2">
                {store.priceChanges.slice(0, 6).map(pc => (
                  <div key={pc.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-2.5">
                    <div><p className="text-sm font-medium text-slate-700">{pc.productName}</p><p className="text-xs text-slate-400">{pc.date} by {pc.user}</p></div>
                    <div className="flex items-center gap-2"><span className="text-xs text-slate-400 line-through">{formatKES(pc.oldPrice)}</span><span className="text-xs text-slate-400">→</span><span className="text-sm font-semibold text-emerald-600">{formatKES(pc.newPrice)}</span>{pc.scheduled && <Badge status="Scheduled" variant="info" />}</div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Add/Edit Product Modal */}
      {(showAdd || editProduct) && (
        <ProductFormModal store={store} product={editProduct} onClose={() => { setShowAdd(false); setEditProduct(null); }} />
      )}

      {/* Price History Modal */}
      {showPriceHistory && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowPriceHistory(null)}>
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">Price History — {showPriceHistory.name}</h2><button onClick={() => setShowPriceHistory(null)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
            <div className="mb-4 rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Current Price</p><p className="text-2xl font-bold text-emerald-600">{formatKES(showPriceHistory.price)}</p></div>
            <div className="space-y-2">
              {store.priceChanges.filter(pc => pc.productId === showPriceHistory.id).map(pc => (
                <div key={pc.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-2.5">
                  <div><p className="text-xs text-slate-400">{pc.date}</p><p className="text-xs text-slate-500">by {pc.user}</p></div>
                  <div className="flex items-center gap-2"><span className="text-xs text-slate-400 line-through">{formatKES(pc.oldPrice)}</span><span className="text-sm font-semibold text-slate-700">{formatKES(pc.newPrice)}</span></div>
                </div>
              ))}
              {store.priceChanges.filter(pc => pc.productId === showPriceHistory.id).length === 0 && <p className="py-4 text-center text-sm text-slate-400">No price changes recorded</p>}
            </div>
            {/* Schedule new price */}
            <div className="mt-4 border-t border-slate-100 pt-4">
              <h3 className="mb-2 text-xs font-semibold text-slate-500">Schedule Price Change</h3>
              <div className="flex gap-2">
                <input type="number" placeholder="New price" id="newPriceInput" className="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" />
                <input type="date" id="scheduleDateInput" className="rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" />
                <button onClick={() => { const np = parseFloat((document.getElementById('newPriceInput') as HTMLInputElement).value); const sd = (document.getElementById('scheduleDateInput') as HTMLInputElement).value; if (np && sd) { store.updateProductPrice(showPriceHistory.id, np, sd); setShowPriceHistory(null); } }} className="rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600">Schedule</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Markdown Modal */}
      {showMarkdown && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowMarkdown(null)}>
          <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">Near-Expiry Markdown</h2><button onClick={() => setShowMarkdown(null)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
            <p className="mb-2 text-sm text-slate-600">{showMarkdown.name}</p>
            <p className="mb-4 text-xs text-slate-400">Current price: {formatKES(showMarkdown.price)} · Expires: {showMarkdown.expiry}</p>
            <div className="grid grid-cols-3 gap-2 mb-4">
              {[10, 20, 30, 40, 50, 75].map(pct => (
                <button key={pct} onClick={() => { store.applyMarkdown(showMarkdown.id, pct); setShowMarkdown(null); }} className="rounded-lg bg-amber-50 py-2.5 text-sm font-bold text-amber-600 hover:bg-amber-100">{pct}% off</button>
              ))}
            </div>
            {!store.hasPermission('markdown') && <div className="rounded-lg bg-amber-50 p-3 text-xs text-amber-600">Markdowns require manager approval. Your request will be queued.</div>}
          </div>
        </div>
      )}

      {/* Promotion Modal */}
      {showPromotions && <PromotionModal store={store} onClose={() => setShowPromotions(false)} />}
    </div>
  );
}

function ProductFormModal({ store, product, onClose }: { store: StoreType; product: Product | null; onClose: () => void }) {
  const [name, setName] = useState(product?.name ?? '');
  const [barcode, setBarcode] = useState(product?.barcode ?? '');
  const [category, setCategory] = useState(product?.category ?? '');
  const [department, setDepartment] = useState(product?.department ?? '');
  const [cost, setCost] = useState(String(product?.cost ?? ''));
  const [price, setPrice] = useState(String(product?.price ?? ''));
  const [unit, setUnit] = useState(product?.unit ?? 'each');
  const [stock, setStock] = useState(String(product?.stock ?? '0'));
  const [reorderLevel, setReorderLevel] = useState(String(product?.reorderLevel ?? '20'));
  const [packSize, setPackSize] = useState(String(product?.packSize ?? ''));
  const [packPrice, setPackPrice] = useState(String(product?.packPrice ?? ''));
  const [cartonSize, setCartonSize] = useState(String(product?.cartonSize ?? ''));
  const [cartonPrice, setCartonPrice] = useState(String(product?.cartonPrice ?? ''));
  const [weighed, setWeighed] = useState(product?.weighed ?? false);
  const [taxable, setTaxable] = useState(product?.taxable ?? true);
  const [expiry, setExpiry] = useState(product?.expiry ?? '');
  const [batch, setBatch] = useState(product?.batch ?? '');
  const [error, setError] = useState('');

  const handleSave = () => {
    if (!name || !barcode || !category || !department) { setError('Name, barcode, category, and department are required'); return; }
    const costNum = parseFloat(cost) || 0;
    const priceNum = parseFloat(price) || 0;
    if (priceNum <= 0) { setError('Price must be greater than 0'); return; }
    if (priceNum < costNum) { setError('Warning: Selling price is below cost price'); }
    if (product) {
      // edit — not fully implemented in store, but price change goes through
      if (priceNum !== product.price) store.updateProductPrice(product.id, priceNum);
    } else {
      store.addProduct({ name, barcode, category, department, cost: costNum, price: priceNum, unit: unit as Product['unit'], stock: parseInt(stock) || 0, reorderLevel: parseInt(reorderLevel) || 20, taxable, expiry: expiry || undefined, batch: batch || undefined, packSize: parseInt(packSize) || undefined, packPrice: parseFloat(packPrice) || undefined, cartonSize: parseInt(cartonSize) || undefined, cartonPrice: parseFloat(cartonPrice) || undefined, weighed });
    }
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-5 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">{product ? 'Edit Product' : 'Add New Product'}</h2><button onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
        {error && <div className="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-600">{error}</div>}
        <div className="grid grid-cols-2 gap-4">
          <div className="col-span-2"><label className="mb-1 block text-xs font-medium text-slate-500">Product Name</label><input value={name} onChange={(e) => setName(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="e.g. Sifted Maize Flour 2kg" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Barcode</label><input value={barcode} onChange={(e) => setBarcode(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="6001234500000" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Category</label><input value={category} onChange={(e) => setCategory(e.target.value)} list="cats" className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /><datalist id="cats">{store.categories.map(c => <option key={c} value={c} />)}</datalist></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Department</label><input value={department} onChange={(e) => setDepartment(e.target.value)} list="depts" className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /><datalist id="depts">{store.departments.map(d => <option key={d} value={d} />)}</datalist></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Unit</label><select value={unit} onChange={(e) => setUnit(e.target.value as Product['unit'])} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none"><option>each</option><option>kg</option><option>pack</option><option>carton</option></select></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Cost Price (KSh)</label><input type="number" value={cost} onChange={(e) => setCost(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Selling Price (KSh)</label><input type="number" value={price} onChange={(e) => setPrice(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          {!product && <div><label className="mb-1 block text-xs font-medium text-slate-500">Opening Stock</label><input type="number" value={stock} onChange={(e) => setStock(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>}
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Reorder Level</label><input type="number" value={reorderLevel} onChange={(e) => setReorderLevel(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Pack Size</label><input type="number" value={packSize} onChange={(e) => setPackSize(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="e.g. 12" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Pack Price</label><input type="number" value={packPrice} onChange={(e) => setPackPrice(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Carton Size</label><input type="number" value={cartonSize} onChange={(e) => setCartonSize(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="e.g. 24" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Carton Price</label><input type="number" value={cartonPrice} onChange={(e) => setCartonPrice(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Expiry Date</label><input type="date" value={expiry} onChange={(e) => setExpiry(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Batch No.</label><input value={batch} onChange={(e) => setBatch(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div className="col-span-2 flex gap-4">
            <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={weighed} onChange={(e) => setWeighed(e.target.checked)} className="rounded" /> Weighed product (scale)</label>
            <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={taxable} onChange={(e) => setTaxable(e.target.checked)} className="rounded" /> Taxable (16% VAT)</label>
          </div>
        </div>
        <div className="mt-6 flex gap-3"><button onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button><button onClick={handleSave} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">{product ? 'Save Changes' : 'Add Product'}</button></div>
      </div>
    </div>
  );
}

function PromotionModal({ store, onClose }: { store: StoreType; onClose: () => void }) {
  const [name, setName] = useState('');
  const [type, setType] = useState<'percentage'|'fixed'|'bxgy'|'bulk'|'time'>('percentage');
  const [value, setValue] = useState('');
  const [buyQty, setBuyQty] = useState('');
  const [getQty, setGetQty] = useState('');
  const [category, setCategory] = useState('');
  const [productId, setProductId] = useState('');
  const [startDate, setStartDate] = useState('2026-10-08');
  const [endDate, setEndDate] = useState('2026-10-31');

  const handleCreate = () => {
    if (!name) return;
    store.addPromotion({ name, type, value: parseFloat(value) || 0, buyQty: parseInt(buyQty) || undefined, getQty: parseInt(getQty) || undefined, category: category || undefined, productId: productId || undefined, active: true, startDate, endDate });
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">New Promotion</h2><button onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
        <div className="space-y-4">
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Promo Name</label><input value={name} onChange={(e) => setName(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="e.g. Weekend Sodas 10% Off" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Type</label><select value={type} onChange={(e) => setType(e.target.value as typeof type)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"><option value="percentage">Percentage Discount</option><option value="fixed">Fixed Amount Off</option><option value="bxgy">Buy X Get Y Free</option><option value="bulk">Bulk Discount (Qty+)</option><option value="time">Time-Based Discount</option></select></div>
          {(type === 'percentage' || type === 'fixed' || type === 'bulk' || type === 'time') && <div><label className="mb-1 block text-xs font-medium text-slate-500">{type === 'percentage' || type === 'bulk' ? 'Discount %' : 'Amount (KSh)'}</label><input type="number" value={value} onChange={(e) => setValue(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>}
          {type === 'bxgy' && <div className="flex gap-2"><div className="flex-1"><label className="mb-1 block text-xs font-medium text-slate-500">Buy Qty</label><input type="number" value={buyQty} onChange={(e) => setBuyQty(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none" /></div><div className="flex-1"><label className="mb-1 block text-xs font-medium text-slate-500">Get Free</label><input type="number" value={getQty} onChange={(e) => setGetQty(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none" /></div></div>}
          {type === 'bulk' && <div><label className="mb-1 block text-xs font-medium text-slate-500">Minimum Quantity</label><input type="number" value={buyQty} onChange={(e) => setBuyQty(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none" /></div>}
          {type === 'percentage' && <div><label className="mb-1 block text-xs font-medium text-slate-500">Category (optional)</label><select value={category} onChange={(e) => setCategory(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none"><option value="">All categories</option>{store.categories.map(c => <option key={c}>{c}</option>)}</select></div>}
          {(type === 'bxgy' || type === 'bulk') && <div><label className="mb-1 block text-xs font-medium text-slate-500">Product</label><select value={productId} onChange={(e) => setProductId(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none"><option value="">Select product...</option>{store.products.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>}
          <div className="flex gap-2"><div className="flex-1"><label className="mb-1 block text-xs font-medium text-slate-500">Start Date</label><input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none" /></div><div className="flex-1"><label className="mb-1 block text-xs font-medium text-slate-500">End Date</label><input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none" /></div></div>
        </div>
        <div className="mt-5 flex gap-3"><button onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button><button onClick={handleCreate} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">Create Promotion</button></div>
      </div>
    </div>
  );
}
