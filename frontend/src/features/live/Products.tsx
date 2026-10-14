import { translate, useLanguage } from '../../i18n';
import { useCallback, useState, type FormEvent } from 'react';
import { Dialog } from '@mui/material';
import { Plus, Edit2, Search, Archive, X, Download, Upload, History, Printer } from 'lucide-react';
import { Badge, SummaryCard, ScreenTabs } from '../../components/ui/Primitives';
import { Packagings } from './Packagings';
import { Departments, DepartmentPicker } from './Departments';
import { StockReceiving } from './StockReceiving';
import {ProductPrices} from './ProductPrices';
import {ProductImportExport} from './ProductImportExport';
import {useSelector} from 'react-redux';
import type {CatalogueImportState} from './catalogueImportState';
import { ProductImage } from './ProductImage';
import { ProductImageForm } from './ProductImageForm';
import { usePrivateProductImageLoader, useProductImageCapabilitiesQuery, useUploadProductImageMutation, useRemoveProductImageMutation } from './productImagesApi';
import { errorMessage, useProductsQuery, useCreateProductMutation, useUpdateProductMutation, useArchiveProductMutation, type CatalogueProduct, type ProductInput, type CatalogueFilters, type ServerUser } from './api';

const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
const money = (amount: number) => `TSh ${amount.toLocaleString('en-TZ')}`;
function catalogueError(error: unknown) {
  const e = error as { data?: { error?: { code?: string } } };
  return e.data?.error?.code === 'NOT_FOUND' ? 'This product is no longer available. Refresh the catalogue.' : errorMessage(error);
}

export function Products({ actor, initialFilters = { page: 1 } }: { actor: ServerUser; initialFilters?: CatalogueFilters }) {
  useLanguage();
  const [priceTab,setPriceTab]=useState<'history'|'labels'>('history');const [priceProduct,setPriceProduct]=useState<CatalogueProduct|null>(null);const [importOpen,setImportOpen]=useState(false);
  const importPending=useSelector((s:{catalogueImport?:CatalogueImportState})=>s.catalogueImport?.pending??null);
  const manage = actor.permissions.includes('manage_products');
  const loadImage = usePrivateProductImageLoader();
  const stockAccess = actor.permissions.includes('receive_stock');
  const [lowStock, setLowStock] = useState(!!initialFilters.low_stock);
  const [expiring, setExpiring] = useState(initialFilters.expiring_in === undefined ? '' : String(initialFilters.expiring_in));
  const showCost = actor.permissions.includes('view_buying_price');
  const [filters, setFilters] = useState<CatalogueFilters>(initialFilters);
  const [search, setSearch] = useState(initialFilters.search ?? '');
  const [category, setCategory] = useState(initialFilters.category ?? '');
  const [barcode, setBarcode] = useState(initialFilters.barcode ?? '');
  const [department, setDepartment] = useState(initialFilters.department_id ?? '');
  const [manageDepartments, setManageDepartments] = useState(false);
  const { currentData: data, error, isFetching, refetch } = useProductsQuery(filters);
  const [form, setForm] = useState<CatalogueProduct | 'new' | null>(null);
  const [selected, setSelected] = useState<CatalogueProduct | null>(null);
  const [packs, setPacks] = useState<CatalogueProduct | null>(null);
  const [stock, setStock] = useState<CatalogueProduct | null>(null);
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
    setFilters({ page: 1, ...(search ? { search } : {}), ...(category ? { category } : {}), ...(barcode ? { barcode } : {}), ...(department ? { department_id: department } : {}), ...(stockAccess && lowStock ? { low_stock: 1 } : {}), ...(stockAccess && expiring !== '' ? { expiring_in: Number(expiring) } : {}) });
  }
  return <div className="space-y-5 p-4 lg:p-6">
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4"><SummaryCard title={translate("Matching products")} value={data && !error ? String(data.meta.total) : '—'} />{stockAccess && <SummaryCard title={translate("Low-stock matches")} value={data && !error && data.meta.low_stock_matches !== undefined ? String(data.meta.low_stock_matches) : '—'} emphasis />}{showCost && <SummaryCard title={translate("Inventory (Cost)")} value="—" />}<SummaryCard title={translate("Inventory (Retail)")} value="—" emphasis /></div>
    <div className="flex flex-wrap items-center justify-between gap-3"><ScreenTabs value="catalogue" onChange={() => {}} tabs={[{id:'catalogue',label:'Catalogue'},{id:'promotions',label:'Promotions & Pricing',unavailable:'Promotion and markdown services are not connected yet.'}]} /><div className="flex flex-wrap gap-2">{manage && <><button onClick={()=>setImportOpen(true)} title={translate("Preview CSV or Excel opening stock")} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600"><Upload className="h-4 w-4" />{translate("Import CSV/Excel")}</button><button onClick={()=>setImportOpen(true)} title={translate("Download current catalogue")} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600"><Download className="h-4 w-4" />{translate("Export")}</button><button onClick={() => setManageDepartments(true)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600">{translate("Manage departments")}</button><button onClick={() => { setForm('new'); setNotice(''); }} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" />{translate("Add Product")}</button></>}</div></div>
    <p className="text-xs text-slate-500">{translate("Inventory valuations will appear when reporting is connected. Available stock excludes expired, quarantined and recalled batches.")}</p>
    {notice && <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{catalogueError(error)} <button onClick={refetch} className="underline">{translate("Try again")}</button></p>}
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <form onSubmit={searchCatalogue} className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
        <div className="min-w-[12rem] flex-1"><label htmlFor="product-search" className="mb-1 block text-xs text-slate-500">{translate("Product name")}</label><div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 shrink-0 text-slate-400" /><input id="product-search" value={search} onChange={(e) => setSearch(e.target.value)} maxLength={160} placeholder={translate("Search products…")} className="min-w-0 w-full bg-transparent text-sm outline-none" /></div></div>
        <div className="w-full sm:w-36"><label htmlFor="product-category-search" className="mb-1 block text-xs text-slate-500">{translate("Category")}</label><input id="product-category-search" value={category} onChange={(e) => setCategory(e.target.value)} maxLength={100} className={inputClass} /></div>
        <div className="w-full sm:w-44"><DepartmentPicker label={translate("Department filter")} value={department} onChange={setDepartment} emptyLabel="All departments" /></div><div className="w-full sm:w-44"><label htmlFor="product-barcode-search" className="mb-1 block text-xs text-slate-500">{translate("Barcode")}</label><input id="product-barcode-search" value={barcode} onChange={(e) => setBarcode(e.target.value)} maxLength={100} className={inputClass} /></div>
        {stockAccess && <><label className="flex items-center gap-2 py-2 text-sm text-slate-600"><input type="checkbox" checked={lowStock} onChange={(e) => setLowStock(e.target.checked)} />{translate("Low stock only")}</label><div className="w-full sm:w-44"><label htmlFor="product-expiry-filter" className="mb-1 block text-xs text-slate-500">{translate("Expiring within")}</label><select id="product-expiry-filter" value={expiring} onChange={(e) => setExpiring(e.target.value)} className={inputClass}><option value="">{translate("Any expiry")}</option><option value="0">{translate("Today")}</option><option value="7">{translate("7 days")}</option><option value="30">{translate("30 days")}</option><option value="60">{translate("60 days")}</option><option value="90">{translate("90 days")}</option></select></div></>}
        <button className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600">{translate("Search")}</button>
        <button type="button" onClick={() => { setSearch(''); setCategory(''); setBarcode(''); setDepartment(''); setLowStock(false); setExpiring(''); setFilters({ page: 1 }); }} className="rounded-lg px-3 py-2 text-sm text-slate-500">{translate("Clear filters")}</button>
      </form>
      {isFetching && <p role="status" className="p-4 text-sm text-slate-500">{translate("Loading products…")}</p>}
      {!error && data && <><div className="overflow-x-auto"><table className="w-full min-w-[1100px] text-sm"><thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="px-4 py-3 font-medium">{translate("Product")}</th><th className="px-4 py-3 font-medium">{translate("Barcode")}</th>{showCost && <th className="px-4 py-3 text-right font-medium">{translate("Cost")}</th>}<th className="px-4 py-3 text-right font-medium">{translate("Price")}</th>{showCost && <th className="px-4 py-3 text-right font-medium">{translate("Margin")}</th>}<th className="px-4 py-3 font-medium">{translate("Pack/Carton")}</th><th className="px-4 py-3 text-right font-medium">{translate("Available stock")}</th><th className="px-4 py-3 text-right font-medium">{translate("Reorder level")}</th><th className="px-4 py-3 font-medium">{translate("Status")}</th>{manage && <th className="px-4 py-3 font-medium">{translate("Actions")}</th>}</tr></thead>
        <tbody className="divide-y divide-slate-50">{data.products.map((p) => <tr key={p.id} aria-label={`Product ${p.name}`} className="hover:bg-slate-50"><td className="min-w-[220px] px-4 py-3"><div className="flex items-center gap-3"><ProductImage productId={p.id} image={p.image} name={p.name} loadImage={loadImage} className="h-9 w-9" /><div><p className="font-medium text-slate-700">{p.name}</p>{p.selected_packaging && <p className="text-xs text-emerald-600">{translate("Selected pack:")} {p.selected_packaging.name} · {p.selected_packaging.base_qty} {p.unit} · {money(p.selected_packaging.selling_price)}</p>}<p className="text-xs text-slate-400">{p.category ?? 'Uncategorised'}{p.department_name ? ' · ' + p.department_name : ''} · {p.unit}{p.quantity_type === 'weight' ? translate(" · Weighed") : ''}</p></div></div></td><td className="px-4 py-3 font-mono text-xs text-slate-500">{p.barcode ?? '—'}</td>{showCost && <td className="whitespace-nowrap px-4 py-3 text-right text-slate-600">{p.buying_price === undefined ? '—' : money(p.buying_price)}</td>}<td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-700">{money(p.selling_price)}{p.quantity_type === 'weight' && <span className="text-xs font-normal"> {translate("/ kg")}</span>}</td>{showCost && <td title={translate("Margin reporting is not connected yet")} className="px-4 py-3 text-right text-slate-400">—</td>}<td className="min-w-[160px] px-4 py-3 text-xs text-slate-500">{p.packagings?.length ? <div className="space-y-1">{p.packagings.slice(0, 3).map(pack => <p key={pack.id}>{pack.name}: {pack.base_qty} {p.unit} · {money(pack.selling_price)}</p>)}{p.packagings.length > 3 && <p>{translate("More options in Packs")}</p>}</div> : '—'}</td><td aria-label={`Available stock for ${p.name}`} className="whitespace-nowrap px-4 py-3 text-right text-slate-600"><p>{p.stock_available && p.stock !== null ? `${p.stock} ${p.unit}` : '—'}</p>{stockAccess && p.stock_total !== undefined && <p className="text-xs text-slate-400">{translate("Held:")} {p.stock_total} {p.unit}</p>}{p.nearest_expiry && <p className="text-xs text-slate-400">{translate("Expiry:")} {p.nearest_expiry}</p>}</td><td className="whitespace-nowrap px-4 py-3 text-right text-slate-600">{p.reorder_level} {p.unit}</td><td className="px-4 py-3"><Badge status={p.is_active ? translate("Active") : translate("Inactive")} variant={p.is_active ? "success" : "neutral"} /></td>{manage && <td className="px-4 py-3"><div className="flex items-center gap-1 whitespace-nowrap">{actor.permissions.includes('receive_stock') && <button aria-label={`Stock for ${p.name}`} onClick={() => setStock(p)} className="rounded-md px-2 text-xs text-emerald-600 hover:bg-emerald-50">{translate("Stock")}</button>}<button aria-label={`Packs for ${p.name}`} onClick={() => setPacks(p)} className="rounded-md px-2 text-xs text-emerald-600 hover:bg-emerald-50">{translate("Packs")}</button><button aria-label={`Edit ${p.name}`} onClick={() => { setForm(p); setNotice(''); }} className="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-blue-600"><Edit2 className="h-4 w-4" /></button><button onClick={()=>{setPriceTab('history');setPriceProduct(p);}} aria-label={`Price history for ${p.name}`} title={translate("Price history")} className="rounded-md p-2 text-slate-300"><History className="h-4 w-4" /></button><button onClick={()=>{setPriceTab('labels');setPriceProduct(p);}} aria-label={`Shelf label for ${p.name}`} title={translate("Shelf labels")} className="rounded-md p-2 text-slate-300"><Printer className="h-4 w-4" /></button><button aria-label={`Archive ${p.name}`} onClick={() => { setSelected(p); setFailure(''); setNotice(''); }} className="rounded-md p-2 text-slate-400 hover:bg-red-50 hover:text-red-600"><Archive className="h-4 w-4" /></button></div></td>}</tr>)}</tbody></table></div>
        {!data.products.length && <p className="p-8 text-center text-sm text-slate-500">{data.meta.total === 0 ? translate("No products match. Clear your filters or add your first product.") : translate("No products on this page.")}</p>}
        <div className="flex items-center justify-between gap-3 border-t border-slate-100 p-4 text-sm text-slate-500"><button disabled={filters.page <= 1 || isFetching} onClick={() => setFilters({ ...filters, page: filters.page - 1 })} className="disabled:opacity-40">{translate("Previous")}</button><span>{translate("Page")} {data.meta.page}</span><button disabled={filters.page * data.meta.per_page >= data.meta.total || isFetching} onClick={() => setFilters({ ...filters, page: filters.page + 1 })} className="disabled:opacity-40">{translate("Next")}</button></div></>}
    </div>
    {(importOpen||importPending)&&manage&&<ProductImportExport onClose={()=>setImportOpen(false)} canImport={stockAccess&&showCost}/>}
    {priceProduct&&manage&&<ProductPrices initialTab={priceTab} product={priceProduct} onClose={()=>setPriceProduct(null)}/>}
    {packs && manage && <Packagings product={packs} onClose={() => setPacks(null)} />}
    {stock && actor.permissions.includes('receive_stock') && <StockReceiving product={stock} showCost={showCost} canAdjust={actor.permissions.includes('adjust_stock')} onClose={() => setStock(null)} />}
    {manageDepartments && manage && <Departments onClose={() => setManageDepartments(false)} />}
    {form && manage && <ProductForm key={form === 'new' ? "new" : form.id} product={form === 'new' ? undefined : form} onClose={() => setForm(null)} onSaved={() => { setForm(null); setNotice('Product saved.'); setFilters((old) => ({ ...old, page: 1 })); }} />}
    <Dialog open={!!selected} onClose={() => { if (!archiving) setSelected(null); }} aria-labelledby="archive-product-title" maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6"><h2 id="archive-product-title" className="text-lg font-bold text-slate-800">{translate("Archive")} {selected?.name}?</h2><p className="mt-3 text-sm text-slate-500">{translate("The product will leave the catalogue. Its history and barcode reservation are kept.")}</p>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}<div className="mt-6 flex gap-3"><button disabled={archiving} onClick={() => setSelected(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={archiving} onClick={confirm} className="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-bold text-white">{archiving ? translate("Archiving…") : translate("Archive product")}</button></div></div></Dialog>
  </div>;
}

function ProductForm({ product, onClose, onSaved }: { product?: CatalogueProduct; onClose: () => void; onSaved: () => void }) {
  useLanguage();
  const [name, setName] = useState(product?.name ?? '');
  const [barcode, setBarcode] = useState(product?.barcode ?? '');
  const [category, setCategory] = useState(product?.category ?? '');
  const [department, setDepartment] = useState(product?.department_id ?? '');
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
  const [image, setImage] = useState(product?.image ?? null);
  const loadImage = usePrivateProductImageLoader();
  const { data: imageCapabilities, error: imageCapabilityError, isFetching: imageCapabilityLoading, refetch: retryImageCapability } = useProductImageCapabilitiesQuery(undefined, { skip: !product });
  const [uploadImage] = useUploadProductImageMutation();
  const [removeImage] = useRemoveProductImageMutation();
  const productId=product?.id;
  const saveImage = useCallback(async (file: File, expectedRevision: string | null) => {
    if (!productId) throw new Error('Save the product before adding an image.');
    const pending = uploadImage({ id: productId, image: file, expectedRevision });
    try { const result = await pending.unwrap(); setImage(result.image); return result; }
    catch (failure) { throw new Error(catalogueError(failure)); } finally { pending.reset(); }
  }, [productId, uploadImage]);
  const deleteImage = useCallback(async (expectedRevision: string) => {
    if (!productId) throw new Error('Save the product before removing its image.');
    const pending = removeImage({ id: productId, expectedRevision });
    try { const result = await pending.unwrap(); setImage(result.image); return result; }
    catch (failure) { throw new Error(catalogueError(failure)); } finally { pending.reset(); }
  }, [productId, removeImage]);
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy) return;
    const buyingPrice = Number(buying), sellingPrice = Number(selling);
    if (![buying, selling].every((v) => /^[0-9]+$/.test(v)) || ![buyingPrice, sellingPrice].every(Number.isSafeInteger)) { setError('Enter whole TSh prices within the supported range.'); return; }
    const body: ProductInput = { name, barcode: barcode || null, category: category || null, department_id: department || null, quantity_type: type, unit,
      buying_price: buyingPrice, selling_price: sellingPrice, reorder_level: reorder, tracks_expiry: expiry, is_active: active };
    setBusy(true); setError('');
    const pending = product ? update({ id: product.id, body }) : create(body);
    try { await pending.unwrap(); onSaved(); } catch (e) { setError(catalogueError(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} aria-labelledby="product-form-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><form onSubmit={submit} className="p-6">
    <div className="mb-5 flex items-center justify-between"><h2 id="product-form-title" className="text-lg font-bold text-slate-800">{product ? translate("Edit Product") : translate("Add Product")}</h2><button type="button" aria-label={translate("Close product form")} disabled={busy} onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
    {error && <p role="alert" className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(error)}</p>}
    <fieldset disabled={busy} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2"><DepartmentPicker label={translate("Department")} value={department} onChange={setDepartment} currentName={product?.department_name} /></div>
      <label className="text-xs text-slate-500 sm:col-span-2">{translate("Product name")}<input autoFocus required maxLength={160} value={name} onChange={(e) => setName(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Barcode")}<input maxLength={100} pattern="[!-~]+" value={barcode} onChange={(e) => setBarcode(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Category")}<input maxLength={100} value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Quantity type")}<select disabled={!!product} value={type} onChange={(e) => { const selected = e.target.value as 'counted' | 'weight'; setType(selected); setUnit(selected === 'weight' ? 'kg' : 'piece'); }} className={inputClass}><option value="counted">{translate("Whole items")}</option><option value="weight">{translate("Weighed goods (kg)")}</option></select></label>
      <label className="text-xs text-slate-500">{translate("Unit")}<input required maxLength={32} readOnly={type === 'weight'} value={unit} onChange={(e) => setUnit(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Buying price (TSh")}{type === 'weight' ? translate(" / kg") : ''})<input required inputMode="numeric" pattern="[0-9]+" value={buying} onChange={(e) => setBuying(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Selling price (TSh")}{type === 'weight' ? translate(" / kg") : ''})<input required inputMode="numeric" pattern="[0-9]+" value={selling} onChange={(e) => setSelling(e.target.value)} className={inputClass} /></label>
      <label className="text-xs text-slate-500">{translate("Reorder level (")}{unit})<input required inputMode={type === 'weight' ? "decimal" : "numeric"} maxLength={32} pattern={type === 'weight' ? '(0|[1-9][0-9]*)(\\.[0-9]{1,3})?' : '(0|[1-9][0-9]*)'} value={reorder} onChange={(e) => setReorder(e.target.value)} className={inputClass} /></label>
      <div className="space-y-3 pt-3"><label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={expiry} onChange={(e) => setExpiry(e.target.checked)} />{translate("Track expiry dates")}</label><label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />{translate("Active product")}</label></div>
    </fieldset>
    <div className="mt-4">{product ? <>
      {imageCapabilityLoading && <p role="status" className="mb-2 text-sm text-slate-500">{translate("Checking image storage…")}</p>}
      {imageCapabilityError && <p role="alert" className="mb-2 text-sm text-red-600">{catalogueError(imageCapabilityError)} <button type="button" onClick={() => void retryImageCapability()} className="underline">{translate("Check image storage again")}</button></p>}
      <ProductImageForm productId={product.id} name={name} image={image} enabled={!!imageCapabilities?.enabled && !imageCapabilityError && !busy} loadImage={loadImage} upload={saveImage} remove={deleteImage} unavailableMessage={imageCapabilityError ? translate("Image storage could not be checked. Try again.") : imageCapabilityLoading || !imageCapabilities ? translate("Checking whether image uploads are available…") : busy ? translate("Save the product changes before uploading an image.") : undefined} />
    </> : <p className="text-sm text-slate-500">{translate("Product image (optional): save this product, then open Edit to add an image.")}</p>}</div>
    {type === 'weight' && <p className="mt-3 text-xs text-slate-500">{translate("Enter kilograms with up to three decimal places, for example 0.750 kg.")}</p>}
    <div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : translate("Save product")}</button></div>
  </form></Dialog>;
}
