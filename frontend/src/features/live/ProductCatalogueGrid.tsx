import { useState } from 'react';
import { Menu, MenuItem } from '@mui/material';
import { Archive, Edit2, History, MoreHorizontal, Package, Printer, ScanLine, Boxes } from 'lucide-react';
import { translate, useLanguage } from '../../i18n';
import type { CatalogueProduct } from './api';
import { ProductImage, type ProductImageLoader } from './ProductImage';

export type ProductCardAction = 'details' | 'stock' | 'packs' | 'history' | 'labels' | 'archive';

export function ProductCatalogueGrid({ products, manage, stockAccess, lowStockFiltered, loadImage, onEdit, onAction }: {
  products: CatalogueProduct[];
  manage: boolean;
  stockAccess: boolean;
  lowStockFiltered: boolean;
  loadImage: ProductImageLoader;
  onEdit: (product: CatalogueProduct) => void;
  onAction: (action: ProductCardAction, product: CatalogueProduct) => void;
}) {
  useLanguage();
  const [menu, setMenu] = useState<{ anchor: HTMLElement; product: CatalogueProduct } | null>(null);
  function action(value: ProductCardAction) {
    if (!menu) return;
    const product = menu.product;
    setMenu(null);
    onAction(value, product);
  }
  return <div className="catalogue-grid-container">
    <div className="catalogue-product-grid" aria-label={translate('Product cards')}>
      {products.map(product => {
        // These are presentation states from canonical server values. Reorder/expiry
        // thresholds remain in Laravel; Low stock is shown only on its filtered result.
        const available = product.stock_available && product.stock !== null;
        const empty = available && product.stock === '0';
        const status = !product.is_active ? 'Inactive' : !available ? 'Stock unavailable' : empty ? 'Out of stock' : lowStockFiltered ? 'Low stock' : 'In stock';
        const tone = !product.is_active || !available ? 'bg-slate-100 text-slate-600' : empty || lowStockFiltered ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700';
        return <article key={product.id} aria-label={`Product ${product.name}`} className="catalogue-product-card rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm transition-shadow hover:shadow-md">
          <div className="catalogue-product-photo relative min-h-0 overflow-hidden rounded-lg bg-slate-100">
            <ProductImage productId={product.id} image={product.image} name={product.name} loadImage={loadImage} className="h-full w-full" />
            {!product.image && <span className="pointer-events-none absolute inset-x-0 bottom-3 text-center text-xs text-slate-500">{translate('No photo yet')}</span>}
            <button type="button" aria-label={`Actions for ${product.name}`} aria-haspopup="menu" aria-expanded={menu?.product.id === product.id} onClick={event => setMenu({ anchor: event.currentTarget, product })} className="absolute right-2 top-2 flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50"><MoreHorizontal className="h-5 w-5" /></button>
          </div>
          <div className="min-w-0 px-1 pt-3">
            <button type="button" title={product.name} onClick={() => onAction('details', product)} className="block w-full truncate text-left text-sm font-semibold text-slate-900 hover:text-emerald-700">{product.name}</button>
            <p title={[product.category, product.department_name, product.unit].filter(Boolean).join(' · ')} className="mt-0.5 truncate text-xs text-slate-500">{product.category ?? translate('Uncategorised')} · {product.unit}{product.quantity_type === 'weight' ? translate(' · Weighed') : ''}</p>
            <p className="mt-1 break-words text-lg font-bold tabular-nums text-slate-900">TSh {product.selling_price.toLocaleString('en-TZ')}{product.quantity_type === 'weight' && <span className="text-xs font-normal text-slate-500"> {translate('/ kg')}</span>}</p>
            <div className="mt-2 flex items-end justify-between gap-2">
              <div className="min-w-0" aria-label={`Available stock for ${product.name}`}>
                <span className={`inline-flex max-w-full items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium ${tone}`}><span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />{translate(status)}</span>
                {available && <p className="mt-0.5 truncate text-xs tabular-nums text-slate-500" title={`${product.stock} ${product.unit}`}>{product.stock} {product.unit}</p>}
              </div>
              {manage && <button type="button" aria-label={`Edit ${product.name}`} title={translate('Edit product')} onClick={() => onEdit(product)} className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700"><Edit2 className="h-4 w-4" /></button>}
            </div>
          </div>
        </article>;
      })}
    </div>
    <Menu anchorEl={menu?.anchor} open={!!menu} onClose={() => setMenu(null)} slotProps={{ list: { 'aria-label': menu ? `Actions for ${menu.product.name}` : translate('Product actions') } }}>
      <MenuItem onClick={() => action('details')}><ScanLine className="mr-2 h-4 w-4" />{translate('Product details')}</MenuItem>
      {manage && stockAccess && <MenuItem aria-label={`Stock for ${menu?.product.name}`} onClick={() => action('stock')}><Boxes className="mr-2 h-4 w-4" />{translate('Stock')}</MenuItem>}
      {manage && <MenuItem aria-label={`Packs for ${menu?.product.name}`} onClick={() => action('packs')}><Package className="mr-2 h-4 w-4" />{translate('Packs')}</MenuItem>}
      {manage && <MenuItem aria-label={`Price history for ${menu?.product.name}`} onClick={() => action('history')}><History className="mr-2 h-4 w-4" />{translate('Price history')}</MenuItem>}
      {manage && <MenuItem aria-label={`Shelf label for ${menu?.product.name}`} onClick={() => action('labels')}><Printer className="mr-2 h-4 w-4" />{translate('Shelf labels')}</MenuItem>}
      {manage && <MenuItem aria-label={`Archive ${menu?.product.name}`} onClick={() => action('archive')} className="!text-red-600"><Archive className="mr-2 h-4 w-4" />{translate('Archive')}</MenuItem>}
    </Menu>
  </div>;
}
