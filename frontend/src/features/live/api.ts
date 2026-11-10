import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { currentLanguage, translate } from '../../i18n';
import { translatedApiError } from '../../i18n/errors';
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';


export interface PaymentCapabilities { provider: 'selcom'; configured: boolean; enabled: boolean; reason: 'merchant_setup_required' | 'ready' }
export interface Till { id: string; branch_id: string; branch_name: string; cashier_id: string; cashier_name: string; status: 'open' | 'closed'; opening_float: number; expected_cash: number | null; closing_cash: number | null; variance: number | null; revision: number; opened_at: string; closed_at: string | null; approved_by: string | null; owner_review_required: boolean }
export interface TillResult { till: Till; replayed: boolean; entry_id?: string }
export interface TillCloseInput { operation_id: string; till_id: string; counted_cash: number }
export interface TillCloseChallenge { operation_id: string; till_id: string; counted_cash: number; payload_hash: string }
export interface VarianceReview { expected_cash: number; counted_cash: number; variance: number; variance_limit: number }
export type TillCloseCommit = TillCloseInput & { approval_token?: string };

export type StaffRole = 'owner' | 'manager' | 'cashier' | 'stockkeeper';
export interface ServerUser { id: string; name: string; role: StaffRole; branch_id: string | null; permissions: string[] }
export interface ManagedUser extends ServerUser { phone: string; is_active: boolean; must_change_pin: boolean; two_factor_enabled: boolean; email?:string|null; email_login_enabled?:boolean }
export interface Device { id: string; name: string; status: 'pending' | 'approved' | 'blocked'; user_id: string | null; approved_by: string | null; last_seen_at: string | null }
export interface SessionResult { token: string; expires_at: string; user: ServerUser }
export interface Challenge { challenge_token: string; next_step: 'enroll_totp' | 'verify_totp' | 'change_pin'; expires_at: string; setup_secret?: string }
export type AuthResult = SessionResult | Challenge;
export interface ApprovalGrant { approved_by: string; approval_token: string; expires_at: string; review?: VarianceReview }
export interface ApprovalRequest { phone?: string; pin?: string; email?:string; password?:string; permission: 'refund' | 'void' | 'give_discount' | 'approve_variance' | 'adjust_stock' | 'manage_settings'; operation_id: string; payload_hash: string; code?: string; till_id?: string; counted_cash?: number }
export interface CatalogueProduct { id: string; name: string; barcode: string | null; category: string | null; quantity_type: 'counted' | 'weight'; unit: string; selling_price: number; buying_price?: number; reorder_level: string; tracks_expiry: boolean; is_active: boolean; stock: string | null; stock_available: boolean; stock_total?: string; nearest_expiry?: string | null; department_id?: string | null; department_name?: string | null; packagings?: Packaging[]; selected_packaging?: Packaging | null; image?: import('./ProductImage').ProductImageMetadata | null }
export interface ProductInput { department_id?: string | null; name: string; barcode: string | null; category: string | null; quantity_type: 'counted' | 'weight'; unit: string; buying_price: number; selling_price: number; reorder_level: string; tracks_expiry: boolean; is_active: boolean }
export interface CatalogueFilters { page: number; search?: string; category?: string; barcode?: string; department_id?: string; low_stock?: number; expiring_in?: number }
export interface Department { id: string; name: string; assigned_products_count?: number }
export interface StockBatch { id: string; product_id: string; batch_number: string; expiry_date: string | null; qty_remaining: string; cost_price?: number; received_at: string; sellable: boolean; is_quarantined: boolean; is_recalled: boolean; receipt: { operation_id: string; qty: string; base_qty: string; packaging_id: string | null; packaging_name: string | null; packaging_version: number | null; conversion_atoms: string } }
export interface StockMovement { id: string; product_id: string; batch_id: string; batch_number: string; type: string; qty_change: string; qty_after: string; created_at: string; user_id: string; device_id: string | null; receipt: StockBatch['receipt'] | null; reason?: string | null; approved_by?: string | null }
export interface ExpiryAlert extends StockBatch { product_name: string; unit: string; quantity_type: "counted" | "weight" }
export interface CorrectionInput { operation_id: string; batch_id: string; qty_change: string; reason: string }
export interface CorrectionPreview { snapshot: CorrectionInput & { product_id: string; qty_before: string }; payload_hash: string; qty_after: string; approval_required: boolean }
export interface CorrectionCommit extends CorrectionInput { expected_qty: string; approval_token?: string }
export interface CorrectionResult { movement: StockMovement; replayed: boolean }
export interface ReceiveInput { operation_id: string; batch_number: string; qty: string; cost_price: number; expiry_date: string | null; packaging_id: string | null }
export interface ReceiveResult { batch: StockBatch; movement: { id: string; type: string; qty_change: string; qty_after: string }; replayed: boolean }
export interface Packaging { id: string; product_id: string; name: string; base_qty: string; conversion_atoms: string; selling_price: number; barcode: string | null; version: number; is_active: boolean; archived: boolean }
export interface PackagingInput { name: string; base_qty: string; selling_price: number; barcode: string | null; is_active: boolean }
export interface Customer { id: string; name: string; phone: string | null; credit_limit: number; outstanding_balance: number; created_at: string }
export interface CustomerInput { name: string; phone: string | null; credit_limit: number }
export interface CustomerStatement { customer:{id:string;name:string;phone:string|null;credit_limit:number};period:{from:string|null;to:string|null;timezone:string};opening_balance:number;charges:number;payments:number;closing_balance:number;outstanding_balance:number;currency:string;generated_at:string;entries:{date:string;at:string;kind:'credit_sale'|'return'|'collection';label:string;charge:number;payment:number;debt_reduction:number;balance:number}[];meta:{count:number;total:number;page:number;per_page:number} }

export interface CustomerDebt { customer_id: string; outstanding_balance: number; credit_limit: number; aging: Record<string,number>; open_sales: {sale_id:string;receipt_number:string;created_at:string;original_amount:number;remaining_amount:number}[] }
export interface CollectionInput { till_id: string; method: 'cash'|'mpesa'; amount: number; allocations: {sale_id:string;amount:number}[] }
export interface CollectionReview extends CollectionInput { customer_id:string; outstanding_balance:number; balance_after:number; review_hash:string }
export interface CollectionPayment { id:string; customer_id:string; amount:number; method:'cash'|'mpesa'; created_at:string; replayed:boolean }
export interface ManualRefundInput {operation_id:string;external_reference:string;reason:string;refund_completed:true}
export interface ManualRefundReview {amount:number;review_hash:string;external_transfer_executed:false}
export interface ManualRefundCommit extends ManualRefundInput {review_hash:string;approval_token:string}
export interface ManualRefundResult {id:string;amount:number;external_reference:string;external_transfer_executed:false;replayed:boolean}
export interface MobilePayment { operation_id?:string;discount_required?:boolean; purpose?:'checkout'|'debt';collection?:CollectionPayment|null; id:string; status:'dispatching'|'awaiting'|'unknown'|'confirmed'|'failed'|'allocated'|'manual_refunded'; amount:number;currency:'TZS';phone_suffix:string;created_at:string;confirmed_at:string|null;reference:string|null;last_error:string|null;owner_review_required:boolean;sale:CashSale|null }
export type BeginMobilePayment = ({sale:CashSaleInput;collection?:never}|{collection:CollectionInput & {customer_id:string;review_hash:string};sale?:never}) & {buyer_name:string;buyer_email:string;phone:string};
export interface SaleInput { loyalty_points?:number; discount?:number;discount_reason?:string; mobile_amount?:number; branch_id: string; till_id: string; customer_id?: string | null; credit_amount?: number; items: { product_id: string; qty: string; packaging_id?: string | null;discount?:number }[] }
export interface SaleLine { automatic_discount?:number;loyalty_discount?:number; gross_total?:number;line_discount?:number;cart_discount?:number; product_id: string; name: string; quantity_type: 'counted' | 'weight'; unit: string; qty: string; base_qty: string; packaging_id: string | null; packaging_name: string | null; packaging_version: number | null; conversion_atoms: string; unit_price: number; discount: number; line_total: number }
export interface SaleReview { manual_cart_discount?:number;loyalty_redemption?:{points:number;discount_amount:number}; discount_reason?:string; branch_id: string; till_id: string; lines: SaleLine[]; subtotal: number; discount: number; total: number; vat_rate: number; vat_amount: number; review_hash: string; mobile_amount?:number; customer_id?: string | null; customer_name?:string; credit_amount?:number; cash_allocated?:number; margin_warnings?: MarginWarning[] }
/** Server-computed S22 warn-only margin entry. Cost fields are present only for actors with view_buying_price; React must render them verbatim and never derive costs. Absence of the array means no warnings and no cost disclosure. */
export interface MarginWarning { code: 'MARGIN_BELOW_COST'; line_index: number; product_id: string; product_name: string; batch_id: string; batch_number: string; base_qty: string; net_total: number; cost_total?: number; shortfall?: number }
export interface CashSaleInput extends SaleInput { approval_token?:string; payment_id?:string; cash_tendered: number; review_hash: string }
export interface CashSale extends Omit<SaleReview, 'review_hash'> { id: string; receipt_number: string; status: string; cash_tendered: number; change_given: number; created_at: string; occurred_at?:string|null; is_offline?:boolean; replayed: boolean; receipt_url: string | null; payments?: {id:string;method:string;amount:number}[] }
export interface ReturnReceipt { has_mobile_payment?:boolean; sale_id:string; receipt_number:string; branch_id:string; total:number; return_status:string; lines:(SaleLine & {line_index:number;returned_qty:string;remaining_qty:string})[] }
export interface ReturnInput {mobile_refund_reference?:string;mobile_refund_completed?:boolean;operation_id:string;sale_id:string;till_id:string;reason:string;items:{line_index:number;qty:string;disposition:'sellable'|'damaged'}[];replacement?:{items:SaleInput['items'];credit_amount:number;cash_tendered:number}}
export interface ReturnReview {mobile_refund?:number;external_transfer_executed?:false;review_hash:string;refund_total:number;debt_reduction:number;cash_refund:number;cash_required:number;cash_paid:number;additional_cash:number;replacement:SaleReview|null;lines:{line_index:number;name:string;qty:string;unit:string;amount:number}[];allocations:{quarantined:boolean}[]}
export interface ReturnCommit extends ReturnInput {review_hash:string;approval_token:string}
export interface ReturnResult {mobile_refund?:number;mobile_refund_reference?:string|null;external_transfer_executed?:false;id:string;sale_id:string;refund_total:number;debt_reduction:number;cash_refund:number;cash_collected:number;cash_paid:number;replacement:CashSale|null;replayed:boolean}
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
export const liveApi = createApi({ reducerPath: 'liveApi', tagTypes: ['MobilePayments','Returns','Customers','Debts','Sales', 'Tills', 'Users', 'Devices', 'Products', 'Packagings', 'Departments', 'Batches', 'Movements'],
  baseQuery: async (args, api, extra) => {
    const response = await baseQuery(args, api, extra);
    const url = typeof args === 'string' ? args : args.url;
    const failure = response.error && 'data' in response.error ? response.error.data as { error?: { code?: string } } : undefined;
    const wrongSupervisorCredentials = url === 'auth/supervisor-check' && ['INVALID_CREDENTIALS', 'INVALID_PIN', 'INVALID_TOTP'].includes(failure?.error?.code ?? '');
    if (response.error?.status === 401 && !wrongSupervisorCredentials && (api.getState() as { session: SessionState }).session.token) {
      api.dispatch(signedOut()); api.dispatch(liveApi.util.resetApiState());
    }
    return response;
  },
  endpoints: (b) => ({
    previewManualRefund: b.mutation<ManualRefundReview,{id:string;body:ManualRefundInput}>({query:({id,body})=>({url:`payments/${id}/manual-refund/preview`,method:'POST',body}),transformResponse:(r:{data:ManualRefundReview})=>r.data}),
    recordManualRefund: b.mutation<ManualRefundResult,{id:string;body:ManualRefundCommit}>({query:({id,body})=>({url:`payments/${id}/manual-refund`,method:'POST',body}),invalidatesTags:['MobilePayments'],transformResponse:(r:{data:ManualRefundResult})=>r.data}),
    mobilePayments: b.query<{payments:MobilePayment[]}, void>({query:()=> 'payments/mine',providesTags:['MobilePayments'],transformResponse:(r:{data:{payments:MobilePayment[]}})=>r.data}),
    ownerPayments: b.query<{payments:MobilePayment[]}, void>({query:()=> 'payments',providesTags:['MobilePayments'],transformResponse:(r:{data:{payments:MobilePayment[]}})=>r.data}),
    mobilePayment: b.query<MobilePayment,string>({query:(id)=>`payments/${id}`,providesTags:['MobilePayments'],transformResponse:(r:{data:MobilePayment})=>r.data}),
    beginMobilePayment: b.mutation<MobilePayment,{key:string;body:BeginMobilePayment}>({query:({key,body})=>({url:'payments/selcom',method:'POST',body,headers:{'Idempotency-Key':key}}),invalidatesTags:['MobilePayments'],transformResponse:(r:{data:MobilePayment})=>r.data}),
    completeMobileCollection: b.mutation<CollectionPayment,string>({query:(id)=>({url:`payments/${id}/complete`,method:'POST'}),invalidatesTags:['MobilePayments','Customers','Debts'],transformResponse:(r:{data:CollectionPayment})=>r.data}),
    completeMobilePayment: b.mutation<CashSale,string|{id:string;approval_token:string}>({query:(input)=>({url:`payments/${typeof input==='string'?input:input.id}/complete`,method:'POST',...(typeof input==='string'?{}:{body:{approval_token:input.approval_token}})}),invalidatesTags:['MobilePayments','Sales','Products','Batches','Tills','Customers','Debts'],transformResponse:(r:{data:CashSale})=>r.data}),
    paymentCapabilities: b.query<PaymentCapabilities, void>({ query: () => 'payments/capabilities', transformResponse: (r: { data: PaymentCapabilities }) => r.data }),
    returnReceipt:b.query<ReturnReceipt,string>({query:(receipt_number)=>({url:'returns/receipt',params:{receipt_number}}),providesTags:['Returns'],transformResponse:(r:{data:ReturnReceipt})=>r.data}),
    previewReturn:b.mutation<ReturnReview,ReturnInput>({query:(body)=>({url:'returns/preview',method:'POST',body}),transformResponse:(r:{data:ReturnReview})=>r.data}),
    completeReturn:b.mutation<ReturnResult,{key:string;body:ReturnCommit}>({query:({key,body})=>({url:'returns',method:'POST',headers:{'Idempotency-Key':key},body}),invalidatesTags:['Returns','Customers','Debts','Sales','Products','Batches','Movements','Tills'],transformResponse:(r:{data:ReturnResult})=>r.data}),
    customers: b.query<{customers:Customer[];meta:{page:number;per_page:number;total:number}}, {page:number;search?:string}>({query:(params)=>({url:'customers',params}),providesTags:['Customers'],transformResponse:(r:{data:{customers:Customer[]};meta:{page:number;per_page:number;total:number}})=>({...r.data,meta:r.meta})}),
    saveCustomer: b.mutation<Customer,{id?:string;body:CustomerInput}>({query:({id,body})=>({url:id?`customers/${id}`:'customers',method:id?'PUT':'POST',body}),invalidatesTags:['Customers','Debts'],transformResponse:(r:{data:Customer})=>r.data}),
    customerDebt: b.query<CustomerDebt,string>({query:(id)=>`customers/${id}/debt`,providesTags:['Debts'],transformResponse:(r:{data:CustomerDebt})=>r.data}),
customerStatement: b.query<CustomerStatement,{customer:string;page:number}>({query:({customer,page})=>({url:`customers/${customer}/statement`,params:{page}}),providesTags:['Debts'],transformResponse:(r:{data:CustomerStatement})=>r.data}),
    exportCustomerStatement:b.mutation<CustomerStatement,string>({query:customer=>`customers/${customer}/statement/export`,transformResponse:(r:{data:CustomerStatement})=>r.data}),
    previewCollection: b.mutation<CollectionReview,{customer:string;body:CollectionInput}>({query:({customer,body})=>({url:`customers/${customer}/payments/preview`,method:'POST',body}),transformResponse:(r:{data:CollectionReview})=>r.data}),
    collectDebt: b.mutation<CollectionPayment,{customer:string;key:string;body:CollectionInput & {review_hash:string}}>({query:({customer,key,body})=>({url:`customers/${customer}/payments`,method:'POST',headers:{'Idempotency-Key':key},body}),invalidatesTags:['Customers','Debts','Tills'],transformResponse:(r:{data:CollectionPayment})=>r.data}),
    mobileCompletionReview:b.mutation<{operation_id:string;review:SaleReview},string>({query:(id)=>({url:`payments/${id}/review`,method:'POST'}),transformResponse:(r:{data:{operation_id:string;review:SaleReview}})=>r.data}),
    product:b.query<CatalogueProduct,string>({query:id=>`products/${id}`,providesTags:['Products'],transformResponse:(r:{data:CatalogueProduct})=>r.data}),
    customer:b.query<Customer,string>({query:id=>`customers/${id}`,providesTags:['Customers'],transformResponse:(r:{data:Customer})=>r.data}),
    previewSale: b.mutation<SaleReview, SaleInput>({ query: (body) => ({ url: 'sales/preview', method: 'POST', body }), transformResponse: (r: { data: SaleReview }) => r.data }),
    savedSale: b.query<CashSale, string>({ query: (id) => `sales/${id}`, providesTags: ['Sales'], transformResponse: (r: { data: CashSale }) => ({ ...r.data, replayed: false }) }),
    completeSale: b.mutation<CashSale, { key: string; body: CashSaleInput }>({ query: ({ key, body }) => ({ url: 'sales', method: 'POST', headers: { 'Idempotency-Key': key }, body }), invalidatesTags: ['Customers','Debts','Sales', 'Products', 'Batches', 'Movements', 'Tills'], transformResponse: (r: { data: CashSale }) => r.data }),
    reorderAlerts: b.query<{ products: CatalogueProduct[]; meta: { total: number } }, void>({ query: () => ({ url: 'stock/reorder-alerts', params: { page: 1, per_page: 5 } }), providesTags: ['Products'], transformResponse: (r: { data: { products: CatalogueProduct[] }; meta: { total: number } }) => ({ ...r.data, meta: r.meta }) }),
    expiryAlerts: b.query<{ batches: ExpiryAlert[]; meta: { total: number } }, void>({ query: () => ({ url: 'stock/expiry-alerts', params: { page: 1, per_page: 5, days: 7 } }), providesTags: ['Batches', 'Products'], transformResponse: (r: { data: { batches: ExpiryAlert[] }; meta: { total: number } }) => ({ ...r.data, meta: r.meta }) }),
    currentTill: b.query<{ till: Till | null; branch: { id: string; name: string } | null; variance_limit: number; can_open: boolean }, void>({ query: () => 'till-sessions/current', providesTags: ['Tills'], transformResponse: (r: { data: { till: Till | null; branch: { id: string; name: string } | null; variance_limit: number; can_open: boolean } }) => r.data }),
    tills: b.query<{ tills: Till[]; meta: { page: number; total: number; owner_review_count: number } }, { page: number; status: 'open' | 'closed' }>({ query: (params) => ({ url: 'till-sessions', params }), providesTags: ['Tills'], transformResponse: (r: { data: { tills: Till[] }; meta: { page: number; total: number; owner_review_count: number } }) => ({ ...r.data, meta: r.meta }) }),
    openTill: b.mutation<TillResult, { operation_id: string; branch_id: string; opening_float: number }>({ query: (body) => ({ url: 'till-sessions/open', method: 'POST', body }), invalidatesTags: ['Tills'], transformResponse: (r: { data: TillResult }) => r.data }),
    paidOut: b.mutation<TillResult, { operation_id: string; till_id: string; amount: number; reason: string }>({ query: (body) => ({ url: 'till-sessions/current/paid-out', method: 'POST', body }), invalidatesTags: ['Tills'], transformResponse: (r: { data: TillResult }) => r.data }),
    closeTill: b.mutation<TillResult, TillCloseCommit>({ query: (body) => ({ url: 'till-sessions/current/close', method: 'POST', body }), invalidatesTags: ['Tills'], transformResponse: (r: { data: TillResult }) => r.data }),

    previewCorrection: b.mutation<CorrectionPreview, CorrectionInput>({ query: (body) => ({ url: 'stock/adjust/preview', method: 'POST', body }), transformResponse: (r: { data: CorrectionPreview }) => r.data }),
    correctStock: b.mutation<CorrectionResult, CorrectionCommit>({ query: (body) => ({ url: 'stock/adjust', method: 'POST', body }), invalidatesTags: ['Batches', 'Products', 'Movements'], transformResponse: (r: { data: CorrectionResult }) => r.data }),
    movements: b.query<{ movements: StockMovement[]; meta: { page: number; per_page: number; total: number } }, { product: string; page: number; batch_id?: string }>({ query: ({ product, ...params }) => ({ url: `products/${product}/movements`, params }), providesTags: ['Movements'], transformResponse: (r: { data: { movements: StockMovement[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    batches: b.query<{ batches: StockBatch[]; meta: { page: number; per_page: number; total: number } }, { product: string; page: number }>({ query: ({ product, page }) => ({ url: `products/${product}/batches`, params: { page } }), providesTags: ['Batches'], transformResponse: (r: { data: { batches: StockBatch[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    receiveStock: b.mutation<ReceiveResult, { product: string; body: ReceiveInput }>({ query: ({ product, body }) => ({ url: `products/${product}/batches`, method: 'POST', body }), invalidatesTags: ['Batches', 'Products', 'Movements'], transformResponse: (r: { data: ReceiveResult }) => r.data }),
    departments: b.query<{ departments: Department[]; meta: { page: number; per_page: number; total: number } }, { page: number; search?: string }>({ query: (params) => ({ url: 'departments', params }), providesTags: ['Departments'], transformResponse: (r: { data: { departments: Department[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    createDepartment: b.mutation<Department, { name: string }>({ query: (body) => ({ url: 'departments', method: 'POST', body }), invalidatesTags: ['Departments'], transformResponse: (r: { data: Department }) => r.data }),
    updateDepartment: b.mutation<Department, { id: string; name: string }>({ query: ({ id, name }) => ({ url: `departments/${id}`, method: 'PUT', body: { name } }), invalidatesTags: ['Departments', 'Products'], transformResponse: (r: { data: Department }) => r.data }),
    archiveDepartment: b.mutation<unknown, string>({ query: (id) => ({ url: `departments/${id}`, method: 'DELETE' }), invalidatesTags: ['Departments', 'Products'] }),
    packagings: b.query<{ packagings: Packaging[]; meta: { page: number; per_page: number; total: number } }, { product: string; page: number }>({ query: ({ product, page }) => ({ url: `products/${product}/packagings`, params: { page } }), providesTags: ['Packagings'], transformResponse: (r: { data: { packagings: Packaging[] }; meta: { page: number; per_page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    createPackaging: b.mutation<Packaging, { product: string; body: PackagingInput }>({ query: ({ product, body }) => ({ url: `products/${product}/packagings`, method: 'POST', body }), invalidatesTags: ['Products', 'Packagings'], transformResponse: (r: { data: Packaging }) => r.data }),
    updatePackaging: b.mutation<Packaging, { product: string; id: string; body: PackagingInput }>({ query: ({ product, id, body }) => ({ url: `products/${product}/packagings/${id}`, method: 'PUT', body }), invalidatesTags: ['Products', 'Packagings'], transformResponse: (r: { data: Packaging }) => r.data }),
    archivePackaging: b.mutation<unknown, { product: string; id: string }>({ query: ({ product, id }) => ({ url: `products/${product}/packagings/${id}`, method: 'DELETE' }), invalidatesTags: ['Products', 'Packagings'] }),
    products: b.query<{ products: CatalogueProduct[]; meta: { page: number; per_page: number; total: number; low_stock_matches?: number } }, CatalogueFilters>({ query: (params) => ({ url: 'products', params: { ...params, per_page: 50 } }), providesTags: ['Products'], transformResponse: (r: { data: { products: CatalogueProduct[] }; meta: { page: number; per_page: number; total: number; low_stock_matches?: number } }) => ({ ...r.data, meta: r.meta }) }),
    createProduct: b.mutation<CatalogueProduct, ProductInput>({ query: (body) => ({ url: 'products', method: 'POST', body }), invalidatesTags: ['Products', 'Departments'], transformResponse: (r: { data: CatalogueProduct }) => r.data }),
    updateProduct: b.mutation<CatalogueProduct, { id: string; body: ProductInput }>({ query: ({ id, body }) => ({ url: `products/${id}`, method: 'PUT', body }), invalidatesTags: ['Products', 'Departments'], transformResponse: (r: { data: CatalogueProduct }) => r.data }),
    archiveProduct: b.mutation<unknown, string>({ query: (id) => ({ url: `products/${id}`, method: 'DELETE' }), invalidatesTags: ['Products', 'Departments'] }),
    login: b.mutation<AuthResult, Record<string, string>>({ query: (body) => ({ url: 'auth/login', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    setupOwner: b.mutation<AuthResult, Record<string, string>>({ query: (body) => ({ url: 'auth/owner/setup', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    verifyCode: b.mutation<AuthResult, { challenge_token: string; code: string }>({ query: (body) => ({ url: 'auth/totp/verify', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    changePin: b.mutation<AuthResult, { challenge_token: string; new_pin: string }>({ query: (body) => ({ url: 'auth/pin/change', method: 'POST', body }), transformResponse: (r: { data: AuthResult }) => r.data }),
    supervisorCheck: b.mutation<ApprovalGrant, ApprovalRequest>({ query: (body) => ({ url: 'auth/supervisor-check', method: 'POST', body }), transformResponse: (r: { data: ApprovalGrant }) => r.data }),
    logout: b.mutation<unknown, void>({ query: () => ({ url: 'auth/logout', method: 'POST' }) }),
    me: b.query<{ user: ServerUser; business: { name: string };features?:Record<string,boolean> }, void>({ query: () => 'me', transformResponse: (r: { data: { user: ServerUser; business: { name: string };features?:Record<string,boolean> } }) => r.data }),
    users: b.query<{ users: ManagedUser[]; meta: { page: number; total: number } }, number>({ query: (page) => `users?page=${page}&per_page=50`, providesTags: ['Users'], transformResponse: (r: { data: { users: ManagedUser[] }; meta: { page: number; total: number } }) => ({ ...r.data, meta: r.meta }) }),
    createUser: b.mutation<ManagedUser, Record<string, unknown>>({ query: (body) => ({ url: 'users', method: 'POST', body }), invalidatesTags: ['Users'], transformResponse: (r: { data: ManagedUser }) => r.data }),
    updateUser: b.mutation<ManagedUser, { id: string; body: Record<string, unknown> }>({ query: ({ id, body }) => ({ url: `users/${id}`, method: 'PUT', body }), invalidatesTags: ['Users'], transformResponse: (r: { data: ManagedUser }) => r.data }),
    deactivateUser: b.mutation<unknown, string>({ query: (id) => ({ url: `users/${id}/deactivate`, method: 'POST' }), invalidatesTags: ['Users'] }),
    resetPin: b.mutation<unknown, { user_id: string; new_pin: string }>({ query: (body) => ({ url: 'auth/pin/reset', method: 'POST', body }), invalidatesTags: ['Users'] }),
    devices: b.query<Device[], void>({ query: () => 'devices', providesTags: ['Devices'], transformResponse: (r: { data: { devices: Device[] } }) => r.data.devices }),
    deviceStatus: b.mutation<unknown, { id: string; action: 'approve' | 'block' }>({ query: ({ id, action }) => ({ url: `devices/${id}/${action}`, method: 'POST' }), invalidatesTags: ['Devices'] }),
  }),
});
export const { useMobileCompletionReviewMutation,useLazyProductQuery,useLazyCustomerQuery,usePreviewManualRefundMutation,useRecordManualRefundMutation,useMobilePaymentsQuery,useOwnerPaymentsQuery,useMobilePaymentQuery,useBeginMobilePaymentMutation,useCompleteMobilePaymentMutation, useCompleteMobileCollectionMutation,usePaymentCapabilitiesQuery, useLazyReturnReceiptQuery, usePreviewReturnMutation, useCompleteReturnMutation, useCustomersQuery, useSaveCustomerMutation, useExportCustomerStatementMutation, useCustomerStatementQuery,useCustomerDebtQuery, usePreviewCollectionMutation, useCollectDebtMutation, usePreviewSaleMutation, useCompleteSaleMutation,useSavedSaleQuery,useLazySavedSaleQuery, useReorderAlertsQuery, useExpiryAlertsQuery, useCurrentTillQuery, useTillsQuery, useOpenTillMutation, usePaidOutMutation, useCloseTillMutation, useLoginMutation, useSetupOwnerMutation, useVerifyCodeMutation, useChangePinMutation, useSupervisorCheckMutation, useLogoutMutation,
  useMeQuery, useUsersQuery, useCreateUserMutation, useUpdateUserMutation, useDeactivateUserMutation, useResetPinMutation,
  useDevicesQuery, useDeviceStatusMutation, useProductsQuery, useCreateProductMutation, useUpdateProductMutation, useArchiveProductMutation,
  usePackagingsQuery, useCreatePackagingMutation, useUpdatePackagingMutation, useArchivePackagingMutation,
  useDepartmentsQuery, useCreateDepartmentMutation, useUpdateDepartmentMutation, useArchiveDepartmentMutation, useBatchesQuery, useReceiveStockMutation, useMovementsQuery, usePreviewCorrectionMutation, useCorrectStockMutation } = liveApi;
export function errorMessage(error: unknown): string {
  const e = error as { data?: { error?: { code?: string; message?: string; details?: { errors?: Record<string, string[]> } } } };
  if (e.data?.error?.code === 'NOT_FOUND') return translate('Open your shop’s BiziSmart address to sign in.');
  const errors = e.data?.error?.details?.errors;
  if (currentLanguage() === 'sw') {
    const mapped = translatedApiError(e.data?.error?.code);
    if (mapped) return errors ? `${mapped} ${Object.values(errors).flat().map(message => translate(message)).join(' ')}` : mapped;
  }
  return errors ? Object.values(errors).flat().map(message => translate(message)).join(' ') : translate(e.data?.error?.message ?? 'Could not connect. Please try again.');
}
