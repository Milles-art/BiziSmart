import { translate, useLanguage } from '../../i18n';
import { useState, type FormEvent } from 'react';
import { Dialog } from '@mui/material';
import { X } from 'lucide-react';
import { errorMessage, usePackagingsQuery, useCreatePackagingMutation, useUpdatePackagingMutation, useArchivePackagingMutation, type CatalogueProduct, type Packaging, type PackagingInput } from './api';

const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
export function Packagings({ product, onClose }: { product: CatalogueProduct; onClose: () => void }) {
  useLanguage();
  const [page, setPage] = useState(1);
  const { currentData: data, error, isFetching, refetch } = usePackagingsQuery({ product: product.id, page });
  const [editing, setEditing] = useState<Packaging | 'new' | null>(null);
  const [archive, setArchive] = useState<Packaging | null>(null);
  const [notice, setNotice] = useState('');
  const [failure, setFailure] = useState('');
  const [remove, { isLoading: removing }] = useArchivePackagingMutation();
  async function confirmArchive() {
    if (!archive || removing) return;
    const pending = remove({ product: product.id, id: archive.id }); setFailure('');
    try { await pending.unwrap(); setArchive(null); setNotice('Pack archived.'); }
    catch (e) { setFailure(errorMessage(e)); }
    finally { pending.reset(); }
  }
  return <Dialog open onClose={() => { if (!removing && !editing) onClose(); }} aria-labelledby="packagings-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}>
    <div className="p-6"><div className="flex items-center justify-between gap-3"><h2 id="packagings-title" className="text-lg font-bold text-slate-800">{translate("Packs & cartons ·")} {product.name}</h2><button disabled={!!editing || removing} aria-label={translate("Close packs")} onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
      <p className="mt-3 text-sm text-slate-500">{translate("Set the contents and selling price for each pack. All sizes share this product’s stock when receiving and sales are connected.")}</p>
      {notice && <p role="status" className="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
      {error && <p role="alert" className="mt-3 text-sm text-red-600">{errorMessage(error)} <button onClick={refetch} className="underline">{translate("Try again")}</button></p>}
      {isFetching && <p role="status" className="mt-3 text-sm text-slate-500">{translate("Loading packs…")}</p>}
      {!error && data && <div className="mt-4 space-y-3">{data.packagings.map((pack) => <div key={pack.id} aria-label={`Pack ${pack.name}`} className="rounded-xl border border-slate-200 p-3"><div className="flex flex-wrap justify-between gap-2"><div><p className="font-medium text-slate-700">{pack.name}</p><p className="text-xs text-slate-500">{pack.base_qty} {product.unit} {translate("· TSh")} {pack.selling_price.toLocaleString('en-TZ')} {translate("/ pack")}</p><p className="mt-1 text-xs text-slate-400">{pack.barcode ?? 'No barcode'} {translate("· Version")} {pack.version} · {pack.is_active ? translate("Active") : translate("Inactive")}</p></div><div className="flex items-center gap-3 text-sm"><button aria-label={`Edit pack ${pack.name}`} onClick={() => { setEditing(pack); setNotice(''); }} className="text-emerald-600">{translate("Edit")}</button><button aria-label={`Archive pack ${pack.name}`} onClick={() => { setArchive(pack); setFailure(''); setNotice(''); }} className="text-red-500">{translate("Archive")}</button></div></div></div>)}
        {!data.packagings.length && <p className="py-3 text-sm text-slate-500">{translate("No packs on this page.")}</p>}
        <div className="flex justify-between text-sm text-slate-500"><button disabled={page === 1 || isFetching} onClick={() => setPage(page - 1)} className="disabled:opacity-40">{translate("Previous packs")}</button><span>{translate("Page")} {data.meta.page}</span><button disabled={page * data.meta.per_page >= data.meta.total || isFetching} onClick={() => setPage(page + 1)} className="disabled:opacity-40">{translate("Next packs")}</button></div></div>}
      <button onClick={() => { setEditing('new'); setNotice(''); }} className="mt-5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white">{translate("Add pack")}</button>
    </div>
    {editing && <PackagingForm key={editing === 'new' ? "new" : editing.id} product={product} packaging={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); setNotice('Pack saved.'); setPage(1); }} />}
    <Dialog open={!!archive} onClose={() => { if (!removing) setArchive(null); }} aria-labelledby="archive-pack-title" maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6"><h2 id="archive-pack-title" className="text-lg font-bold text-slate-800">{translate("Archive")} {archive?.name}?</h2><p className="mt-3 text-sm text-slate-500">{translate("Its versions and barcode reservation will be kept.")}</p>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}<div className="mt-5 flex gap-3"><button disabled={removing} onClick={() => setArchive(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={removing} onClick={confirmArchive} className="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-bold text-white">{removing ? translate("Archiving…") : translate("Archive pack")}</button></div></div></Dialog>
  </Dialog>;
}

function PackagingForm({ product, packaging, onClose, onSaved }: { product: CatalogueProduct; packaging?: Packaging; onClose: () => void; onSaved: () => void }) {
  useLanguage();
  const [name, setName] = useState(packaging?.name ?? '');
  const [quantity, setQuantity] = useState(packaging?.base_qty ?? '');
  const [selling, setSelling] = useState(String(packaging?.selling_price ?? ''));
  const [barcode, setBarcode] = useState(packaging?.barcode ?? '');
  const [active, setActive] = useState(packaging?.is_active ?? true);
  const [failure, setFailure] = useState('');
  const [busy, setBusy] = useState(false);
  const [create] = useCreatePackagingMutation(); const [update] = useUpdatePackagingMutation();
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy) return;
    const price = Number(selling);
    if (!/^[0-9]+$/.test(selling) || !Number.isSafeInteger(price)) { setFailure('Enter a whole TSh price within the supported range.'); return; }
    const body: PackagingInput = { name, base_qty: quantity, selling_price: price, barcode: barcode || null, is_active: active };
    const pending = packaging ? update({ product: product.id, id: packaging.id, body }) : create({ product: product.id, body });
    setBusy(true); setFailure('');
    try { await pending.unwrap(); onSaved(); } catch (e) { setFailure(errorMessage(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} aria-labelledby="pack-form-title" maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><form onSubmit={submit} className="p-6"><h2 id="pack-form-title" className="text-lg font-bold text-slate-800">{packaging ? translate("Edit pack") : translate("Add pack")}</h2>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}<fieldset disabled={busy} className="mt-4 space-y-4">
    <label className="block text-xs text-slate-500">{translate("Pack name")}<input autoFocus required maxLength={160} value={name} onChange={(e) => setName(e.target.value)} className={inputClass} /></label>
    <label className="block text-xs text-slate-500">{translate("Contents (")}{product.unit})<input required maxLength={32} inputMode={product.quantity_type === 'weight' ? "decimal" : "numeric"} value={quantity} onChange={(e) => setQuantity(e.target.value)} className={inputClass} /></label>
    <label className="block text-xs text-slate-500">{translate("Pack selling price (TSh)")}<input required inputMode="numeric" pattern="[0-9]+" value={selling} onChange={(e) => setSelling(e.target.value)} className={inputClass} /></label>
    <label className="block text-xs text-slate-500">{translate("Pack barcode")}<input maxLength={100} pattern="[!-~]+" value={barcode} onChange={(e) => setBarcode(e.target.value)} className={inputClass} /></label>
    <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />{translate("Active pack")}</label>
    </fieldset><p className="mt-3 text-xs text-slate-500">{product.quantity_type === 'weight' ? translate("Enter kg, for example 0.500 for a 500 g pack.") : translate("Enter whole base items, for example 24 bottles in a carton.")} {packaging && 'Saving changes keeps the previous version.'}</p><div className="mt-5 flex gap-3"><button type="button" disabled={busy} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : translate("Save pack")}</button></div></form></Dialog>;
}
