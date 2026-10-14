import { translate, useLanguage } from '../../i18n';
import { useEffect, useRef, useState } from 'react';
import { Package } from 'lucide-react';

export interface ProductImageMetadata { revision: string; mime: string; bytes: number; width: number; height: number }
export type ProductImageLoader = (productId: string, revision: string) => Promise<Blob>;

/** Authenticated image bytes stay in component memory; no signed URL or browser persistence. */
export function ProductImage({ productId, image, name, loadImage, className = 'h-10 w-10' }: {
  productId: string; image?: ProductImageMetadata | null; name: string; loadImage: ProductImageLoader; className?: string;
}) {
  useLanguage();
  const revision=image?.revision;
  const container = useRef<HTMLSpanElement>(null);
  const [visible, setVisible] = useState(false);
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    if (!container.current || !revision) return;
    if (typeof IntersectionObserver === 'undefined') { setVisible(true); return; }
    const observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) { setVisible(true); observer.disconnect(); }
    }, { rootMargin: '100px' });
    observer.observe(container.current);
    return () => observer.disconnect();
  }, [revision]);
  useEffect(() => {
    setUrl(null); setFailed(false);
    if (!visible || !revision) return;
    let active = true;
    let objectUrl: string | undefined;
    loadImage(productId, revision).then(blob => {
      if (!active) return;
      objectUrl = URL.createObjectURL(blob); setUrl(objectUrl);
    }).catch(() => { if (active) setFailed(true); });
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [productId, revision, visible, loadImage, retry]);
  return <span ref={container} className={`${className} inline-flex shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-slate-400`}>
    {url ? <img src={url} alt={name} className="h-full w-full object-contain" loading="lazy" />
      : failed ? <button type="button" title={translate("Image unavailable. Try again")} aria-label={`Retry image for ${name}`} onClick={() => setRetry(value => value + 1)} className="h-full w-full text-xs text-slate-500 focus:outline-emerald-500">{translate("Retry")}</button>
      : <Package size={20} aria-label={image ? `Loading image for ${name}` : `No image for ${name}`} />}
  </span>;
}



