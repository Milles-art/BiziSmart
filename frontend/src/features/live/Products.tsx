import { useState, type FormEvent } from 'react';
import { Dialog } from '@mui/material';
import { Package, Plus, Edit2, Search, Archive, X } from 'lucide-react';
import { Badge, StatCard } from '../../components/ui/Primitives';
import { Packagings } from './Packagings';
import { errorMessage, useProductsQuery, useCreateProductMutation, useUpdateProductMutation, useArchiveProductMutation, type CatalogueProduct, type ProductInput, type CatalogueFilters, type ServerUser } from './api';

const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
const money = (amount: number) => `TSh ${amount.toLocaleString('en-TZ')}`;
function catalogueError(error: unknown) {
  const e = error as { data?: { error?: { code?: string } } };
  return e.data?.error?.code === 'NOT_FOUND' ? 'This product is no longer available. Refresh the catalogue.' : errorMessage(error);
}

export function Products({ actor }: { actor: ServerUser }) {
  const manage = actor.permissions.includes('manage_products');
  const showCost = actor.permissions.includes('view_buying_price');
  const [filters, setFilters] = useState<CatalogueFilters>({ page: 1 });
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [barcode, setBarcode] = useState('');
  const { currentData: data, error, isFetching, refetch } = useProductsQuery(filters);
  const [form, setForm] = useState<CatalogueProduct | 'new' | null>(null);
  const [selected, setSelected] = useState<CatalogueProduct | null>(null);
  const [packs, setPacks] = useState<CatalogueProduct | null>(null);
  const [notice, setNotice] = useState('');
  const [failure, setFailure] = useState('');
  const [archive, { isLoading: archiving }] = useArchiveProductMutation();
  async function confirm() {
    if (!selected || archiving) return;
    setFailure('');
    const pending = archive(selected.id);
    try { await pending.unwrap(); setSelected(null); setNotice('Product archived.'); setFilters((old) => ({ ...old, page: 1 })); }
    catch (e) { setFailure(catalogueError(e)); }
    finally { pending.reset(); }
  }
  function searchCatalogue(event: FormEvent) {
    event.preventDefault();
    setNotice('');
    setFilters({ page: 1, ...(search ? { search } : {}), ...(category ? { category } : {}), ...(barcode ? { barcode } : {}) });
  }
  return <div className="space-y-5 p-4 lg:p-6">
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4"><StatCard title="Matching products" value={data && !error ? String(data.meta.total) : '—'} icon={<Package className="h-5 w-5 text-white" />} color="bg-blue-500" /><StatCard title="Stock value" value="—" icon={<Package className="h-5 w-5 text-white" />} color="bg-emerald-500" /></div>
    <div className="flex items-center justify-between gap-3"><h2 className="text-sm font-semibold text-slate-700">Product catalogue</h2>{manage && <button onClick={() => { setForm('new'); setNotice(''); }} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" />Add Product</button>}</div>
    <p className="text-sm text-slate-500">Stock balances will appear after stock receiving is available.</p>
    {notice && <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{catalogueError(error)} <button onClick={refetch} className="underline">Try again</button></p>}
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <form onSubmit={searchCatalogue} className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <div className="min-w-0 flex-1"><label htmlFor="product-search" className="mb-1 block text-xs text-slate-500">Product name</label><div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 shrink-0 text-slate-400" /><input id="product-search" value={search} onChange={(e) => setSearch(e.target.value)} maxLength={160} placeholder="Search products…" className="min-w-0 w-full bg-transparent text-sm outline-none" /></div></div>
        <div className="w-full sm:w-36"><label htmlFor="product-category-search" className="mb-1 block text-xs text-slate-500">Category</label><input id="product-category-search" value={category} onChange={(e) => setCategory(e.target.value)} maxLength={100} className={inputClass} /></div>
        <div className="w-full sm:w-44"><label htmlFor="product-barcode-search" className="mb-1 block text-xs text-slate-500">Barcode</label><input id="product-barcode-search" value={barcode} onChange={(e) => setBarcode(e.target.value)} maxLength={100} className={inputClass} /></div>
        <button className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600">Search</button>
        <button type="button" onClick={() => { setSearch(''); setCategory(''); setBarcode(''); setFilters({ page: 1 }); }} className="rounded-lg px-3 py-2 text-sm text-slate-500">Clear filters</button>
      </form>
      {isFetching && <p role="status" className="p-4 text-sm text-slate-500">Loading products…</p>}
      {!error && data && <><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="px-4 py-3 font-medium">Product</th><th className="px-4 py-3 font-medium">Barcode</th>{showCost && <th className="px-4 py-3 text-right font-medium">Cost</th>}<th className="px-4 py-3 text-right font-medium">Price</th><th className="px-4 py-3 text-right font-medium">Reorder level</th><th className="px-4 py-3 font-medium">Status</th>{manage && <th className="px-4 py-3 font-medium">Actions</th>}</tr></thead>
        <tbody className="divide-y divide-slate-50">{data.products.map((p) => <tr key={p.id} aria-label={`Product ${p.name}`} className="hover:bg-slate-50"><td className="px-4 py-3"><div className="flex items-center gap-3"><div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50"><Package className="h-4 w-4 text-emerald-600" /></div><div><p className="font-medium text-slate-700">{p.name}</p>{p.selected_packaging && <p className="text-xs text-emerald-600">Selected pack: {p.selected_packaging.name} · {p.selected_packaging.base_qty} {p.unit} · {money(p.selected_packaging.selling_price)}</p>}<p className="text-xs text-slate-400">{p.category ?? 'Uncategorised'} · {p.unit}{p.quantity_type === 'weight' ? ' · Weighed' : ''}</p></div></div></td><td className="px-4 py-3 font-mono text-xs text-slate-500">{p.barcode ?? '—'}</td>{showCost && <td className="whitespace-nowrap px-4 py-3 text-right text-slate-600">{p.buying_price === undefined ? '—' : money(p.buying_price)}</td>}<td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-700">{money(p.selling_price)}{p.quantity_type === 'weight' && <span className="text-xs font-normal"> / kg</span>}</td><td className="whitespace-nowrap px-4 py-3 text-right text-slate-600">{p.reorder_level} {p.unit}</td><td className="px-4 py-3"><Badge status={p.is_active ? 'Active' : 'Inactive'} variant={p.is_active ? 'success' : 'neutral'} /></td>{manage && <td className="px-4 py-3"><div className="flex gap-1"><button aria-label={`Packs for ${p.name}`} onClick={() => setPacks(p)} className="rounded-md px-2 text-xs text-emerald-600 hover:bg-emerald-50">Packs</button><button aria-label={`Edit ${p.name}`} onClick={() => { setForm(p); setNotice(''); }} className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-blue-600"><Edit2 className="h-4 w-4" /></button><button aria-label={`Archive ${p.name}`} onClick={() => { setSelected(p); setFailure(''); setNotice(''); }} className="rounded-md p-2 text-slate-400 hover:bg-red-50 hover:text-red-600"><Archive className="h-4 w-4" /></button></div></td>}</tr>)}</tbody></table></div>
        {!data.products.length && <p className="p-8 text-center text-sm text-slate-500">{data.meta.total === 0 ? 'No products match. Clear your filters or add your first product.' : 'No products on this page.'}</p>}
        <div className="flex items-center justify-between gap-3 border-t border-slate-100 p-4 text-sm text-slate-500"><button disabled={filters.page <= 1 || isFetching} onClick={() => setFilters({ ...filters, page: filters.page - 1 })} className="disabled:opacity-40">Previous</button><span>Page {data.meta.page}</span><button disabled={filters.page * data.meta.per_page >= data.meta.total || isFetching} onClick={() => setFilters({ ...filters, page: filters.page + 1 })} className="disabled:opacity-40">Next</button></div></>}
    </div>
    {packs && manage && <Packagings product={packs} onClose={() => setPacks(null)} />}
    {form && manage && <ProductForm key={form === 'new' ? 'new' : form.id} product={form === 'new' ? undefined : form} onClose={() => setForm(null)} onSaved={() => { setForm(null); setNotice('Product saved.'); setFilters((old) => ({ ...old, page: 1 })); }} />}
    <Dialog open={!!selected} onClose={() => { if (!archiving) setSelected(null); }} aria-labelledby="archive-product-title" maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6"><h2 id="archive-product-title" className="text-lg font-bold text-slate-800">Archive {selected?.name}?</h2><p className="mt-3 text-sm text-slate-500">The product will leave the catalogue. Its history and barcode reservation are kept.</p>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{failure}</p>}<div className="mt-6 flex gap-3"><button disabled={archiving} onClick={() => setSelected(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">Cancel</button><button disabled={archiving} onClick={confirm} className="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-bold text-white">{archiving ? 'Archiving…' : 'Archive product'}</button></div></div></Dialog>
  </div>;
}

function ProductForm({ product, onClose, onSaved }: { product?: CatalogueProduct; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(product?.name ?? '');
  const [barcode, setBarcode] = useState(product?.barcode ?? '');
  const [category, setCategory] = useState(product?.category ?? '');
  const [type, setType] = useState<'counted' | 'weight'>(product?.quantity_type ?? 'counted');
  const [unit, setUnit] = useState(product?.unit ?? 'piece');
  const [buying, setBuying] = useState(String(product?.buying_price ?? ''));
  const [selling, setSelling] = useState(String(product?.selling_price ?? ''));
  const [reorder, setReorder] = useState(product?.reorder_level ?? '0');
  const [expiry, setExpiry] = useState(product?.tracks_expiry ?? false);
  const [active, setActive] = useState(product?.is_active ?? true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [create] = useCreateProductMutation();
  const [update] = useUpdateProductMutation();
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy) return;
    const buyingPrice = Number(buying), sellingPrice = Number(selling);
    if (![buying, selling].every((v) => /^[0-9]+$/.test(v)) || ![buyingPrice, sellingPrice].every(Number.isSafeInteger)) { setError('Enter whole TSh prices within the supported range.'); return; }
    const body: ProductInput = { name, barcode: barcode || null, category: category || null, quantity_type: type, unit,
      buying_price: buyingPrice, selling_price: sellingPrice, reorder_level: reorder, tracks_expiry: expiry, is_active: active };
    setBusy(true); setError('');
    const pending = product ? update({ id: product.id, body }) : create(body);
    try { await pending.unwrap(); onSaved(); } catch (e) { setError(catalogueError(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} aria-labelledby="product-form-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><form onSubmit={submit} className="p-6">
    <div className="mb-5 flex items-center justify-between"><h2 id="product-form-title" className="text-lg font-bold text-slate-800">{product ? 'Edit Product' : 'Add Product'}</h2><button type="button" aria-label="Close product form" disabled={busy} onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
    {error && <p role="alert" className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>}
    <fieldset disabled={busy} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <label className="text-xs text-slate-500 sm:col-span-2">Product name<input autoFocus required maxLength={160} value={name} onChange={(e) => setName(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">Barcode<input maxLength={100} pattern="[!-~]+" value={barcode} onChange={(e) => setBarcode(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">Category<input maxLength={100} value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">Quantity type<select disabled={!!product} value={type} onChange={(e) => { const selected = e.target.value as 'counted' | 'weight'; setType(selected); setUnit(selected === 'weight' ? 'kg' : 'piece'); }} className={inputClass}><option value="counted">Whole items</option><option value="weight">Weighed goods (kg)</option></select></label>
      <label className="text-xs text-slate-500">Unit<input required maxLength={32} readOnly={type === 'weight'} value={unit} onChange={(e) => setUnit(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">Buying price (TSh{type === 'weight' ? ' / kg' : ''})<input required inputMode="numeric" pattern="[0-9]+" value={buying} onChange={(e) => setBuying(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">Selling price (TSh{type === 'weight' ? ' / kg' : ''})<input required inputMode="numeric" pattern="[0-9]+" value={selling} onChange={(e) => setSelling(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">Reorder level ({unit})<input required inputMode={type === 'weight' ? 'decimal' : 'numeric'} maxLength={32} pattern={type === 'weight' ? '(0|[1-9][0-9]*)(\\.[0-9]{1,3})?' : '(0|[1-9][0-9]*)'} value={reorder} onChange={(e) => setReorder(e.target.value)} className={inputClass} /></label>
      <div className="space-y-3 pt-3"><label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={expiry} onChange={(e) => setExpiry(e.target.checked)} />Track expiry dates</label><label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />Active product</label></div>
    </fieldset>
    {type === 'weight' && <p className="mt-3 text-xs text-slate-500">Enter kilograms with up to three decimal places, for example 0.750 kg.</p>}
    <div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">Cancel</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? 'Saving…' : 'Save product'}</button></div>
  </form></Dialog>;
}
