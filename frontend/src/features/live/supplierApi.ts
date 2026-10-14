import {liveApi} from './api';
export interface SupplierRecord {id:string;name:string;phone:string|null;address:string|null;is_active:boolean;revision:number;payable:number;unapplied_credit:number}
export interface SupplierLine {product_id:string;name:string;quantity_type:'weight'|'counted';unit:string;qty:string;base_qty:string;cost_price:number;amount:number;packaging_id:string|null;packaging_name:string|null;batch_id?:string;batch_number?:string;expiry_date?:string|null}
export interface SupplierOrderRecord {id:string;supplier_id:string;status:'draft'|'issued';revision:number;lines:SupplierLine[];total:number;issued_at:string|null}
export interface SupplierInvoiceRecord {id:string;supplier_id:string;order_id:string|null;reference:string;invoice_date:string;original_amount:number;remaining_amount:number;lines:SupplierLine[]}
export interface SupplierReturnRecord {id:string;supplier_id:string;invoice_id:string;expected_credit:number;reason:string;credit_recorded:boolean;lines:{line_index:number;qty:string;amount:number}[]}
export interface SupplierCreditRecord {id:string;supplier_id:string;return_id:string;amount:number;remaining_amount:number;reference:string}
export interface SupplierPaymentRecord {id:string;supplier_id:string;source:'till'|'bank'|'mobile';amount:number;reference:string;paid_on:string}
export interface SupplierOverview {suppliers:SupplierRecord[];orders:SupplierOrderRecord[];invoices:SupplierInvoiceRecord[];returns:SupplierReturnRecord[];credits:SupplierCreditRecord[];payments:SupplierPaymentRecord[]}
export type SupplierAction='supplier'|'order'|'receive'|'payment'|'return'|'credit'|'apply_credit';
export type SupplierInput=Record<string,unknown>;
export interface SupplierReview {review_hash:string;total?:number;amount?:number;allocated_amount?:number;expected_credit?:number;lines?:SupplierLine[];external_transfer_executed?:false}
export interface SupplierPending {key:string;action:SupplierAction;body:SupplierInput}
const api=liveApi.enhanceEndpoints({addTagTypes:['Suppliers']}).injectEndpoints({endpoints:b=>({
 suppliers:b.query<SupplierOverview,void>({query:()=> 'suppliers',providesTags:['Suppliers'],transformResponse:(r:{data:SupplierOverview})=>r.data}),
 supplierReceiving:b.query<{suppliers:{id:string;name:string}[];orders:SupplierOrderRecord[]},void>({query:()=> 'supplier-receiving',providesTags:['Suppliers'],transformResponse:(r:{data:{suppliers:{id:string;name:string}[];orders:SupplierOrderRecord[]}})=>r.data}),
 previewSupplier:b.mutation<SupplierReview,{action:SupplierAction;body:SupplierInput}>({query:({action,body})=>({url:`suppliers/actions/${action}/preview`,method:'POST',body}),transformResponse:(r:{data:SupplierReview})=>r.data}),
 commitSupplier:b.mutation<Record<string,unknown>,SupplierPending>({query:({action,body,key})=>({url:`suppliers/actions/${action}`,method:'POST',body,headers:{'Idempotency-Key':key}}),invalidatesTags:['Suppliers','Products','Batches','Movements','Tills'],transformResponse:(r:{data:Record<string,unknown>})=>r.data}),
})});
export const {useSuppliersQuery,useSupplierReceivingQuery,usePreviewSupplierMutation,useCommitSupplierMutation}=api;
