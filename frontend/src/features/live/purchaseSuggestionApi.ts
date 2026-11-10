import {liveApi} from './api';

export interface PurchaseSuggestion {
 product_id:string;name:string;quantity_type:'weight'|'counted';unit:string;
 stock:string;reorder_level:string;shortage_to_reorder:string;suggested_qty:string|null;packaging_id:null;
 historical_cost:{cost_price:number;invoice_id:string;invoice_date:string};
}
export interface PurchaseSuggestionsResult {
 supplier:{id:string;name:string};lines:PurchaseSuggestion[];basis:string;
 meta:{page:number;per_page:number;total:number;last_page:number};
}
const api=liveApi.injectEndpoints({endpoints:b=>({
 purchaseSuggestions:b.query<PurchaseSuggestionsResult,{supplier:string;page:number}>({
  query:({supplier,page})=>({url:`suppliers/${supplier}/purchase-suggestions`,params:{page,per_page:25}}),
  transformResponse:(r:{data:PurchaseSuggestionsResult})=>r.data,
  providesTags:['Products','Batches'],
 }),
})});
export const {usePurchaseSuggestionsQuery}=api;
