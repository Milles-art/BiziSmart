import { fetchBaseQuery } from '@reduxjs/toolkit/query/react';
import { liveApi } from './api';
import type { OfflineLease, OfflineResult, OfflineCommittedConflict, OfflineQuarantine, OfflineReconcileInput, OfflineReconcileReview, OfflineReconcileCommit, OfflineSaleBody } from './offlineTypes';

// A lease-authenticated sync must not reuse an unrelated current user's Bearer token or sign-out handling.
const signedSyncQuery=fetchBaseQuery({baseUrl:typeof window==='undefined'?'http://localhost/api/v1/':new URL('/api/v1/',window.location.origin).href,timeout:15000});
export const offlineApi=liveApi.injectEndpoints({endpoints:builder=>({
  issueOfflineLease:builder.mutation<OfflineLease,void>({query:()=>({url:'offline/leases',method:'POST'}),transformResponse:(response:{data:OfflineLease})=>response.data}),
  syncOfflineSale:builder.mutation<OfflineResult,{body:OfflineSaleBody;signature:string}>({
    async queryFn({body,signature},api,extra){
      const response=await signedSyncQuery({url:'offline/sync',method:'POST',body,headers:{Accept:'application/json','X-Offline-Signature':signature}},api,extra);
      if(response.error)return {error:response.error};
      const envelope=response.data as {data:OfflineResult};return {data:envelope.data};
    },invalidatesTags:['Products','Batches','Movements','Tills','Sales'],
  }),
  offlineCommittedConflicts:builder.query<{sales:OfflineCommittedConflict[];meta:{page:number;per_page:number;total:number}},number>({query:page=>({url:'offline/conflicts',params:{page}}),providesTags:['Sales','Returns','Batches'],transformResponse:(response:{data:{sales:OfflineCommittedConflict[]};meta:{page:number;per_page:number;total:number}})=>({...response.data,meta:response.meta})}),
  offlineQuarantine:builder.query<OfflineQuarantine[],void>({query:()=>({url:'offline/quarantine'}),transformResponse:(response:{data:OfflineQuarantine[]})=>response.data}),
  previewOfflineReconciliation:builder.mutation<OfflineReconcileReview,{id:string;body:OfflineReconcileInput}>({query:({id,body})=>({url:`offline/quarantine/${id}/preview`,method:'POST',body}),transformResponse:(response:{data:OfflineReconcileReview})=>response.data}),
  reconcileOfflineSale:builder.mutation<OfflineResult,{id:string;body:OfflineReconcileCommit}>({query:({id,body})=>({url:`offline/quarantine/${id}/reconcile`,method:'POST',body}),invalidatesTags:['Products','Batches','Movements','Tills','Sales'],transformResponse:(response:{data:OfflineResult})=>response.data}),
})});
export const {useIssueOfflineLeaseMutation,useSyncOfflineSaleMutation,useOfflineQuarantineQuery,useOfflineCommittedConflictsQuery,usePreviewOfflineReconciliationMutation,useReconcileOfflineSaleMutation}=offlineApi;
