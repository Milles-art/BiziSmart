import { liveApi, type Customer, type CatalogueProduct } from './api';
import type { CommissionBalance } from './incentivesApi';
export interface LoyaltyCard { id: string; token: string | null; active: boolean; created_at: string }
export interface ScaleProfile { prefix: string; product_start: number; product_length: number; weight_start: number; weight_length: number }
const api = liveApi.injectEndpoints({ endpoints: b => ({
 loyaltyCard: b.query<{card:LoyaltyCard|null},string>({query:id=>`customers/${id}/loyalty-card`,providesTags:['Customers'],transformResponse:(r:{data:{card:LoyaltyCard|null}})=>r.data}),
 issueLoyaltyCard:b.mutation<{card:LoyaltyCard;replayed:boolean},{customer:string;operation_id:string}>({query:({customer,...body})=>({url:`customers/${customer}/loyalty-card`,method:'POST',body}),invalidatesTags:['Customers'],transformResponse:(r:{data:{card:LoyaltyCard;replayed:boolean}})=>r.data}),
 resolveLoyaltyCard:b.mutation<Customer,string>({query:token=>({url:'loyalty-cards/resolve',method:'POST',body:{token}}),transformResponse:(r:{data:Customer})=>r.data}),
 commissionLeaderboard:b.query<{leaderboard:(CommissionBalance&{rank:number})[];basis:string;meta:{page:number;per_page:number;total:number}},number>({query:page=>({url:'incentives/leaderboard',params:{page}}),providesTags:['Sales','Returns','Debts','Users'],transformResponse:(r:{data:{leaderboard:(CommissionBalance&{rank:number})[];basis:string};meta:{page:number;per_page:number;total:number}})=>({...r.data,meta:r.meta})}),
 scaleProfile:b.query<{profile:ScaleProfile|null},void>({query:()=> 'scales/profile',providesTags:['Users'],transformResponse:(r:{data:{profile:ScaleProfile|null}})=>r.data}),
 configureScaleProfile:b.mutation<{profile:ScaleProfile|null},{operation_id:string;profile:ScaleProfile|null}>({query:body=>({url:'scales/profile',method:'POST',body}),invalidatesTags:['Users'],transformResponse:(r:{data:{profile:ScaleProfile|null}})=>r.data}),
 decodeScaleLabel:b.mutation<{product:CatalogueProduct;qty:string},string>({query:label=>({url:'scales/decode',method:'POST',body:{label}}),transformResponse:(r:{data:{product:CatalogueProduct;qty:string}})=>r.data}),
}) });
export const {useLoyaltyCardQuery,useIssueLoyaltyCardMutation,useResolveLoyaltyCardMutation,useCommissionLeaderboardQuery,useScaleProfileQuery,useConfigureScaleProfileMutation,useDecodeScaleLabelMutation}=api;
