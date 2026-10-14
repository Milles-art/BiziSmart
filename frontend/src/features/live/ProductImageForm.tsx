import { translate, useLanguage } from '../../i18n';
import { useEffect, useRef, useState } from 'react';
import { ProductImage, type ProductImageLoader, type ProductImageMetadata } from './ProductImage';

import {compressProductImage} from './prepareProductImage';

export function ProductImageForm({ productId, name, image, enabled, loadImage, upload, remove, onChanged, unavailableMessage = 'Image uploads are unavailable until private storage is connected.' }: {
  productId: string; name: string; image?: ProductImageMetadata | null; enabled: boolean;
  loadImage: ProductImageLoader; upload: (file: File, expectedRevision: string | null) => Promise<unknown>;
  remove: (expectedRevision: string) => Promise<unknown>; onChanged?: () => void; unavailableMessage?: string;
}) {
  useLanguage();
  const input = useRef<HTMLInputElement>(null);
  const active = useRef(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [status, setStatus] = useState('');
  const [confirmRemove, setConfirmRemove] = useState(false);
  useEffect(() => { active.current = true; return () => { active.current = false; }; }, []);
  async function choose(file?: File) {
    if (!file || busy) return;
    setBusy(true); setError(''); setStatus('');
    try {
      const prepared = await compressProductImage(file);
      if (!active.current) return;
      await upload(prepared, image?.revision ?? null);
      if (active.current) { setStatus('Product image saved.'); onChanged?.(); }
    } catch (reason) {
      if (active.current) setError(reason instanceof Error ? reason.message : 'The image could not be saved. Refresh the product before trying again.');
    } finally { if (active.current) setBusy(false); if (input.current) input.current.value = ''; }
  }
  async function deleteImage() {
    if (!image || busy) return;
    setBusy(true); setError(''); setStatus('');
    try { await remove(image.revision); if (active.current) { setStatus('Product image removed.'); setConfirmRemove(false); onChanged?.(); } }
    catch (reason) { if (active.current) setError(reason instanceof Error ? reason.message : 'The image could not be removed. Refresh the product before trying again.'); }
    finally { if (active.current) setBusy(false); }
  }
  return <section className="rounded-xl border border-slate-200 p-4" aria-label={translate("Product image")}>
    <div className="flex items-center gap-3">
      <ProductImage productId={productId} image={image} name={name} loadImage={loadImage} className="h-16 w-16" />
      <div className="min-w-0"><h3 className="text-sm font-medium text-slate-800">{translate("Product image")} <span className="font-normal text-slate-500">{translate("(optional)")}</span></h3>
        <p className="mt-1 text-xs text-slate-500">{translate("JPEG, PNG or WebP. Images are resized before uploading.")}</p>
      </div>
    </div>
    {!enabled && <p className="mt-3 text-sm text-amber-700" role="status">{unavailableMessage}</p>}
    <div className="mt-3 flex flex-wrap items-center gap-3">
      <label className={`rounded-lg px-3 py-2 text-sm font-medium ${enabled && !busy ? 'cursor-pointer bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-slate-100 text-slate-400'}`}>
        {busy ? translate("Saving image…") : image ? translate("Replace image") : translate("Choose image")}
        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" disabled={!enabled || busy} onChange={event => void choose(event.target.files?.[0])} className="sr-only" aria-label={translate("Choose product image")} />
      </label>
      {image && !confirmRemove && <button type="button" disabled={!enabled || busy} onClick={() => setConfirmRemove(true)} className="text-sm text-red-600 disabled:text-slate-400">{translate("Remove image")}</button>}
    </div>
    {confirmRemove && <div className="mt-3 flex flex-wrap items-center gap-3 text-sm"><span>{translate("Remove this product image?")}</span><button type="button" disabled={busy} onClick={() => void deleteImage()} className="text-red-600">{translate("Confirm removal")}</button><button type="button" disabled={busy} onClick={() => setConfirmRemove(false)} className="text-slate-600">{translate("Cancel")}</button></div>}
    {error && <p role="alert" className="mt-3 text-sm text-red-600">{translate(error)}</p>}
    {status && <p role="status" className="mt-3 text-sm text-emerald-700">{status}</p>}
  </section>;
}



