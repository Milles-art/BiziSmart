import { liveApi } from './api';
export type PricingKind = 'schedule' | 'fixed_price' | 'percentage' | 'buy_get' | 'markdown';
export interface PricingTarget { product_id: string; product_name?: string; packaging_id?: string | null; packaging_name?: string | null; packaging_version?: number | null; batch_id?: string | null; batch_number?: string | null; unit?: string }
export interface PricingInput { operation_id: string; name: string; kind: PricingKind; priority: number; reason: string; starts_at: string; ends_at: string | null; price?: number | null; basis_points?: number | null; buy_qty?: number | null; free_qty?: number | null; max_qty?: string | null; targets: PricingTarget[] }
export interface PricingReview { snapshot: Record<string, unknown>; review_hash: string }
export interface PricingRule extends Omit<PricingInput, 'operation_id'> { id: string; created_at: string; cancelled_at: string | null; used_qty: string | null }
export const pricingApi = liveApi.injectEndpoints({ endpoints: (b) => ({
  pricingRules: b.query<{ rules: PricingRule[]; meta: { page: number; per_page: number; total: number } }, number>({ query: (page) => ({ url: 'pricing/rules', params: { page } }), providesTags: ['Products'], transformResponse: (r: { data: { rules: PricingRule[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
  previewPricing: b.mutation<PricingReview, PricingInput>({ query: (body) => ({ url: 'pricing/rules/preview', method: 'POST', body }), transformResponse: (r: { data: PricingReview }) => r.data }),
  savePricing: b.mutation<{ rule: PricingRule; replayed: boolean }, PricingInput & { review_hash: string }>({ query: (body) => ({ url: 'pricing/rules', method: 'POST', body }), invalidatesTags: ['Products', 'Packagings'], transformResponse: (r: { data: { rule: PricingRule; replayed: boolean } }) => r.data }),
  previewCancelPricing: b.mutation<PricingReview, { id: string; body: { operation_id: string; reason: string } }>({ query: ({ id, body }) => ({ url: `pricing/rules/${id}/cancel/preview`, method: 'POST', body }), transformResponse: (r: { data: PricingReview }) => r.data }),
  cancelPricing: b.mutation<{ rule: PricingRule; replayed: boolean }, { id: string; body: { operation_id: string; reason: string; review_hash: string } }>({ query: ({ id, body }) => ({ url: `pricing/rules/${id}/cancel`, method: 'POST', body }), invalidatesTags: ['Products', 'Packagings'], transformResponse: (r: { data: { rule: PricingRule; replayed: boolean } }) => r.data }),
}) });
export const { usePricingRulesQuery, usePreviewPricingMutation, useSavePricingMutation, usePreviewCancelPricingMutation, useCancelPricingMutation } = pricingApi;
