import { liveApi } from './api';
export interface ImportRow { barcode: string; batch_number: string; qty: string; cost_price: number; expiry_date: string | null }
export interface ImportInput { operation_id: string; rows: ImportRow[] }
export interface ImportReviewRow extends ImportRow { row: number; product_id: string; product_name: string; packaging_id: string | null; packaging_name: string | null; packaging_version: number | null; selling_price: number; unit: string; base_qty: string }
export interface ImportReview { rows: ImportReviewRow[]; review_hash: string; row_count: number }
export interface ImportResult { import_id: string; row_count: number; replayed: boolean; batches: { row: number; batch_id: string; movement_id: string }[] }
export type ImportCommit = ImportInput & { review_hash: string };
export const catalogueImportApi = liveApi.injectEndpoints({ endpoints: (b) => ({
  previewStockImport: b.mutation<ImportReview, ImportInput>({ query: (body) => ({ url: 'products/import-stock/preview', method: 'POST', body }), transformResponse: (r: { data: ImportReview }) => r.data }),
  commitStockImport: b.mutation<ImportResult, ImportCommit>({ query: (body) => ({ url: 'products/import-stock', method: 'POST', body }), transformResponse: (r: { data: ImportResult }) => r.data, invalidatesTags: ['Products', 'Batches', 'Movements'] }),
  exportCatalogue: b.mutation<string, void>({ query: () => ({ url: 'products/export', responseHandler: (response: Response) => response.ok ? response.text() : response.json() }) }),
}) });
export const { usePreviewStockImportMutation, useCommitStockImportMutation, useExportCatalogueMutation } = catalogueImportApi;
