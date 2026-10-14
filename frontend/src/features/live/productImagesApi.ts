import { useCallback } from 'react';
import { errorMessage, liveApi } from './api';
import type { ProductImageMetadata } from './ProductImage';

export interface ProductImageCapabilities { configured: boolean; enabled: boolean; reason: 'ready' | 'storage_setup_required'; max_bytes: number; formats: string[] }
export interface ProductImageResult { product_id: string; image: ProductImageMetadata | null }

const imageApi = liveApi.injectEndpoints({ endpoints: builder => ({
  productImageCapabilities: builder.query<ProductImageCapabilities, void>({
    query: () => 'products/image-capabilities', transformResponse: (response: { data: ProductImageCapabilities }) => response.data,
  }),
  privateProductImage: builder.query<string, { id: string; revision: string }>({
    query: ({ id, revision }) => ({
      url: `products/${id}/image/${revision}`,
      responseHandler: async (response: Response) => {
        if (!response.ok) return response.json();
        const mime = (response.headers.get('Content-Type') ?? '').split(';')[0];
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(mime)) throw new Error('The server returned an invalid image.');
        const bytes = new Uint8Array(await response.arrayBuffer());
        if (bytes.length > 2 * 1024 * 1024) throw new Error('The server returned an oversized image.');
        let binary = ''; for (let offset = 0; offset < bytes.length; offset += 8192) binary += String.fromCharCode(...bytes.subarray(offset, offset + 8192));
        return `data:${mime};base64,${btoa(binary)}`;
      },
    }), keepUnusedDataFor: 0,
  }),
  uploadProductImage: builder.mutation<ProductImageResult, { id: string; image: File; expectedRevision: string | null }>({
    query: ({ id, image, expectedRevision }) => {
      const body = new FormData(); body.append('image', image); body.append('expected_revision', expectedRevision ?? '');
      return { url: `products/${id}/image`, method: 'POST', body };
    }, invalidatesTags: ['Products'], transformResponse: (response: { data: ProductImageResult }) => response.data,
  }),
  removeProductImage: builder.mutation<ProductImageResult, { id: string; expectedRevision: string }>({
    query: ({ id, expectedRevision }) => ({ url: `products/${id}/image`, method: 'DELETE', body: { expected_revision: expectedRevision } }),
    invalidatesTags: ['Products'], transformResponse: (response: { data: ProductImageResult }) => response.data,
  }),
}) });

export const { useProductImageCapabilitiesQuery, useLazyPrivateProductImageQuery, useUploadProductImageMutation, useRemoveProductImageMutation } = imageApi;

export function privateImageBlob(dataUrl: string): Blob {
  const match = /^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$/.exec(dataUrl);
  if (!match) throw new Error('The product image could not be read.');
  const binary = atob(match[2]); const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
  return new Blob([bytes], { type: match[1] });
}

export function usePrivateProductImageLoader() {
  const [read] = useLazyPrivateProductImageQuery();
  return useCallback(async (id: string, revision: string) => {
    const pending = read({ id, revision }, true);
    try { return privateImageBlob(await pending.unwrap()); }
    catch (error) { throw new Error(errorMessage(error)); }
    finally { pending.unsubscribe(); }
  }, [read]);
}
