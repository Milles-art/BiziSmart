import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

export type StaffRole = 'owner' | 'manager' | 'cashier' | 'stockkeeper';
export interface ServerUser { id: string; name: string; role: StaffRole; branch_id: string | null; permissions: string[] }
export interface ManagedUser extends ServerUser { phone: string; is_active: boolean; must_change_pin: boolean; two_factor_enabled: boolean }
export interface Device { id: string; name: string; status: 'pending' | 'approved' | 'blocked'; user_id: string | null; approved_by: string | null; last_seen_at: string | null }
export interface SessionResult { token: string; expires_at: string; user: ServerUser }
export interface Challenge { challenge_token: string; next_step: 'enroll_totp' | 'verify_totp' | 'change_pin'; expires_at: string; setup_secret?: string }
export type AuthResult = SessionResult | Challenge;
export interface ApprovalGrant { approved_by: string; approval_token: string; expires_at: string }
export interface ApprovalRequest { phone: string; pin: string; permission: 'refund' | 'void' | 'give_discount' | 'approve_variance'; operation_id: string; payload_hash: string; code?: string }
export interface CatalogueProduct { id: string; name: string; barcode: string | null; category: string | null; quantity_type: 'counted' | 'weight'; unit: string; selling_price: number; buying_price?: number; reorder_level: string; tracks_expiry: boolean; is_active: boolean; stock: string | null; stock_available: boolean; packagings?: Packaging[]; selected_packaging?: Packaging | null }
export interface ProductInput { name: string; barcode: string | null; category: string | null; quantity_type: 'counted' | 'weight'; unit: string; buying_price: number; selling_price: number; reorder_level: string; tracks_expiry: boolean; is_active: boolean }
export interface CatalogueFilters { page: number; search?: string; category?: string; barcode?: string }
export interface Packaging { id: string; product_id: string; name: string; base_qty: string; conversion_atoms: string; selling_price: number; barcode: string | null; version: number; is_active: boolean; archived: boolean }
export interface PackagingInput { name: string; base_qty: string; selling_price: number; barcode: string | null; is_active: boolean }
const initialState: { token: string | null; expiresAt: string | null; user: ServerUser | null; lastActiveAt: number | null } = { token: null, expiresAt: null, user: null, lastActiveAt: null };
const session = createSlice({ name: 'session', initialState, reducers: {
  signedIn: (_state, action: PayloadAction<SessionResult>) => ({ token: action.payload.token, expiresAt: action.payload.expires_at, user: action.payload.user, lastActiveAt: Date.now() }),
  sessionRestored: (_state, action: PayloadAction<{ token: string; expiresAt: string; lastActiveAt: number }>) => ({ ...action.payload, user: null }),
  signedOut: () => initialState,
} });
export const { signedIn, signedOut, sessionRestored } = session.actions;
export const sessionReducer = session.reducer;
export type SessionState = typeof initialState;
const baseQuery = fetchBaseQuery({ baseUrl: typeof window === 'undefined' ? 'http://localhost/api/v1/' : new URL('/api/v1/', window.location.origin).href, timeout: 15000, prepareHeaders: (headers, { getState }) => {
  headers.set('Accept', 'application/json');
  const token = (getState() as { session: SessionState }).session.token;
  if (token) headers.set('Authorization', `Bearer ${token}`);
  return headers;
} });
export const liveApi = createApi({ reducerPath: 'liveApi', tagTypes: ['Users', 'Devices', 'Products', 'Packagings'],
  baseQuery: async (args, api, extra) => {
    const response = await baseQuery(args, api, extra);
    const url = typeof args === 'string' ? args : args.url;
    const failure = response.error && 'data' in response.error ? response.error.data as { error?: { code?: string } } : undefined;
    const wrongSupervisorCredentials = url === 'auth/supervisor-check' && ['INVALID_PIN', 'INVALID_TOTP'].includes(failure?.error?.code ?? '');
    if (response.error?.status === 401 && !wrongSupervisorCredentials && (api.getState() as { session: SessionState }).session.token) {
      api.dispatch(signedOut()); api.dispatch(liveApi.util.resetApiState());
    }
    return response;
  },
  endpoints: (b) => ({
    packagings: b.query<{ packagings: Packaging[]; meta: { page: number; per_page: number; total: number } }, { product: string; page: number }>({ query: ({ product, page }) => ({ url: `products/${product}/packagings`, params: { page } }), providesTags: ['Packagings'], transformResponse: (r: { data: { packagings: Packaging[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    createPackaging: b.mutation<Packaging, { product: string; body: PackagingInput }>({ query: ({ product, body }) => ({ url: `products/${product}/packagings`, method: 'POST', body }), invalidatesTags: ['Products', 'Packagings'], transformResponse: (r: { data: Packaging }) => r.data }),
    updatePackaging: b.mutation<Packaging, { product: string; id: string; body: PackagingInput }>({ query: ({ product, id, body }) => ({ url: `products/${product}/packagings/${id}`, method: 'PUT', body }), invalidatesTags: ['Products', 'Packagings'], transformResponse: (r: { data: Packaging }) => r.data }),
    archivePackaging: b.mutation<unknown, { product: string; id: string }>({ query: ({ product, id }) => ({ url: `products/${product}/packagings/${id}`, method: 'DELETE' }), invalidatesTags: ['Products', 'Packagings'] }),
    products: b.query<{ products: CatalogueProduct[]; meta: { page: number; per_page: number; total: number } }, CatalogueFilters>({ query: (params) => ({ url: 'products', params: { ...params, per_page: 50 } }), providesTags: ['Products'], transformResponse: (r: { data: { products: CatalogueProduct[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    createProduct: b.mutation<CatalogueProduct, ProductInput>({ query: (body) => ({ url: 'products', method: 'POST', body }), invalidatesTags: ['Products'], transformResponse: (r: { data: CatalogueProduct }) => r.data }),
    updateProduct: b.mutation<CatalogueProduct, { id: string; body: ProductInput }>({ query: ({ id, body }) => ({ url: `products/${id}`, method: 'PUT', body }), invalidatesTags: ['Products'], transformResponse: (r: { data: CatalogueProduct }) => r.data }),
    archiveProduct: b.mutation<unknown, string>({ query: (id) => ({ url: `products/${id}`, method: 'DELETE' }), invalidatesTags: ['Products'] }),
    login: b.mutation<AuthResult, Record<string, string>>({ query: (body) => ({ url: 'auth/login', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    setupOwner: b.mutation<AuthResult, Record<string, string>>({ query: (body) => ({ url: 'auth/owner/setup', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    verifyCode: b.mutation<AuthResult, { challenge_token: string; code: string }>({ query: (body) => ({ url: 'auth/totp/verify', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    changePin: b.mutation<AuthResult, { challenge_token: string; new_pin: string }>({ query: (body) => ({ url: 'auth/pin/change', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    supervisorCheck: b.mutation<ApprovalGrant, ApprovalRequest>({ query: (body) => ({ url: 'auth/supervisor-check', method: 'POST', body }), transformResponse: (r: { data: ApprovalGrant }) => r.data }),
    logout: b.mutation<unknown, void>({ query: () => ({ url: 'auth/logout', method: 'POST' }) }),
    me: b.query<{ user: ServerUser; business: { name: string } }, void>({ query: () => 'me', transformResponse: (r: { data: { user: ServerUser; business: { name: string } } }) => r.data }),
    users: b.query<{ users: ManagedUser[]; meta: { page: number; total: number } }, number>({ query: (page) => `users?page=${page}&per_page=50`, providesTags: ['Users'], transformResponse: (r: { data: { users: ManagedUser[] }; meta: { page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    createUser: b.mutation<ManagedUser, Record<string, unknown>>({ query: (body) => ({ url: 'users', method: 'POST', body }), invalidatesTags: ['Users'], transformResponse: (r: { data: ManagedUser }) => r.data }),
    updateUser: b.mutation<ManagedUser, { id: string; body: Record<string, unknown> }>({ query: ({ id, body }) => ({ url: `users/${id}`, method: 'PUT', body }), invalidatesTags: ['Users'], transformResponse: (r: { data: ManagedUser }) => r.data }),
    deactivateUser: b.mutation<unknown, string>({ query: (id) => ({ url: `users/${id}/deactivate`, method: 'POST' }), invalidatesTags: ['Users'] }),
    resetPin: b.mutation<unknown, { user_id: string; new_pin: string }>({ query: (body) => ({ url: 'auth/pin/reset', method: 'POST', body }), invalidatesTags: ['Users'] }),
    devices: b.query<Device[], void>({ query: () => 'devices', providesTags: ['Devices'], transformResponse: (r: { data: { devices: Device[] } }) => r.data.devices }),
    deviceStatus: b.mutation<unknown, { id: string; action: 'approve' | 'block' }>({ query: ({ id, action }) => ({ url: `devices/${id}/${action}`, method: 'POST' }), invalidatesTags: ['Devices'] }),
  }),
});
export const { useLoginMutation, useSetupOwnerMutation, useVerifyCodeMutation, useChangePinMutation, useSupervisorCheckMutation, useLogoutMutation,
  useMeQuery, useUsersQuery, useCreateUserMutation, useUpdateUserMutation, useDeactivateUserMutation, useResetPinMutation,
  useDevicesQuery, useDeviceStatusMutation, useProductsQuery, useCreateProductMutation, useUpdateProductMutation, useArchiveProductMutation,
  usePackagingsQuery, useCreatePackagingMutation, useUpdatePackagingMutation, useArchivePackagingMutation } = liveApi;
export function errorMessage(error: unknown): string {
  const e = error as { data?: { error?: { code?: string; message?: string; details?: { errors?: Record<string, string[]> } } } };
  if (e.data?.error?.code === 'NOT_FOUND') return 'Open your shop’s BiziSmart address to sign in.';
  const errors = e.data?.error?.details?.errors;
  return errors ? Object.values(errors).flat().join(' ') : e.data?.error?.message ?? 'Could not connect. Please try again.';
}
