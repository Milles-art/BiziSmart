import {liveApi} from './api';
export interface BankLine {id:string;account:string;booked_on:string;direction:'debit'|'credit';amount:number;reference:string;payment_type:string|null;payment_id:string|null}
export interface BankPayment {id:string;type:'supplier'|'expense';amount:number;direction:'debit'|'credit';paid_on:string;reference:string;matched:boolean}
export interface PaymentSchedule {id:string;invoice_id:string;supplier_name:string;invoice_reference:string;due_on:string;amount:number;remaining_amount:number;status:string;overdue:boolean}
export interface BankingOverview {lines:BankLine[];payments:BankPayment[];schedules:PaymentSchedule[]}
export interface HistoricalPrices {products:{product_id:string;name:string;unit:string;quantity_type:string;receipts:{supplier_name:string;invoice_id:string;invoice_date:string;cost_price:number;base_qty:string}[]}[]}
const api=liveApi.enhanceEndpoints({addTagTypes:['Banking','Suppliers']}).injectEndpoints({endpoints:b=>({
 banking:b.query<BankingOverview,void>({query:()=> 'banking',providesTags:['Banking','Suppliers'],transformResponse:(r:{data:BankingOverview})=>r.data}),
 supplierPrices:b.query<HistoricalPrices,void>({query:()=> 'supplier-price-comparisons',providesTags:['Suppliers'],transformResponse:(r:{data:HistoricalPrices})=>r.data}),
 bankingAction:b.mutation<Record<string,unknown>,{action:string;body:Record<string,unknown>}>({query:({action,body})=>({url:`banking/${action}`,method:'POST',body}),invalidatesTags:['Banking'],transformResponse:(r:{data:Record<string,unknown>})=>r.data}),
})});
export const {useBankingQuery,useSupplierPricesQuery,useBankingActionMutation}=api;
