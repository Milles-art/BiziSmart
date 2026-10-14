import { liveApi } from './api';

export interface PriceEntry { id: string; product_name: string; packaging_id: string | null; packaging_name: string | null; packaging_version: number | null; unit: string; base_qty: string; old_price: number | null; new_price: number; actor_id: string; actor_name: string; created_at: string }
export interface ShelfLabel { id: string; product_id: string; product_name: string; packaging_name: string | null; packaging_version: number | null; base_qty: string; unit: string; barcode: string | null; selling_price: number }
export const productPricesApi = liveApi.injectEndpoints({ endpoints: (b) => ({
  productPrices: b.query<{ prices: PriceEntry[]; meta: { page: number; per_page: number; total: number } }, { product: string; page: number }>({
    query: ({ product, page }) => ({ url: `products/${product}/price-history`, params: { page } }), providesTags: ['Products', 'Packagings'],
    transformResponse: (r: { data: { prices: PriceEntry[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }),
  }),
  shelfLabels: b.query<{ labels: ShelfLabel[]; generated_at: string }, string>({
    query: (product) => `products/${product}/shelf-labels`, providesTags: ['Products', 'Packagings'],
    transformResponse: (r: { data: { labels: ShelfLabel[]; generated_at: string } }) => r.data,
  }),
}) });
export const { useProductPricesQuery, useShelfLabelsQuery } = productPricesApi;
