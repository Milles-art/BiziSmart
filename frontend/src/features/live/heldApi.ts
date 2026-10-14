import { liveApi } from './api';
export interface HeldDraft { items: {product_id:string;packaging_id:string|null;qty:string;discount?:number}[]; customer_id?:string|null;credit_amount?:number;discount?:number;discount_reason?:string }
export interface HeldCart { id:string;branch_id:string;status:'held'|'resumed'|'deleted';draft:HeldDraft;created_at:string;expires_at:string;replayed?:boolean }
export type HoldOperation = {action:'save';key:string;body:HeldDraft & {branch_id:string}} | {action:'resume'|'delete';key:string;id:string};
const heldApi = liveApi.injectEndpoints({ endpoints: (b) => ({
  heldCarts: b.query<HeldCart[],string>({query:(branch_id)=>({url:'held-carts',params:{branch_id}}),providesTags:['Sales'],transformResponse:(r:{data:HeldCart[]})=>r.data}),
  operateHold: b.mutation<HeldCart,HoldOperation>({query:(operation)=>operation.action==='save'
    ? {url:'held-carts',method:'POST',body:operation.body,headers:{'Idempotency-Key':operation.key}}
    : {url:`held-carts/${operation.id}${operation.action==='resume'?'/resume':''}`,method:operation.action==='resume'?'POST':'DELETE',headers:{'Idempotency-Key':operation.key}},
    invalidatesTags:['Sales'],transformResponse:(r:{data:HeldCart})=>r.data}),
}) });
export const { useHeldCartsQuery,useOperateHoldMutation } = heldApi;
