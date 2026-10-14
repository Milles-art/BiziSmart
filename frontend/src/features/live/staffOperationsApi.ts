import {liveApi} from './api';
export interface AttendanceRecord {id:string;user_id:string;staff_name:string;is_open:boolean;revision:number;original_started_at:string;original_ended_at:string|null;started_at:string;ended_at:string|null;correction:{id:string;reason:string;recorded_at:string}|null}
export interface NotificationPreferences {in_app:boolean;whatsapp:boolean;sms:boolean;revision:number}
export interface StaffNotice {id:string;type:'stock_low'|'till_variance';payload:{name?:string;stock?:string;unit?:string;till_id?:string;variance?:number};read_at:string|null;created_at:string}
export interface StaffOverview {current:AttendanceRecord|null;attendance:AttendanceRecord[];preferences:NotificationPreferences;channels:Record<'in_app'|'whatsapp'|'sms',{ready:boolean;reason?:string}>;notifications:StaffNotice[]}
export type StaffAction='clock_in'|'clock_out'|'correction'|'preferences'|'read_notification';
export interface StaffPending {action:StaffAction;key:string;body:Record<string,unknown>}
const api=liveApi.enhanceEndpoints({addTagTypes:['StaffOperations']}).injectEndpoints({endpoints:b=>({
 staffOperations:b.query<StaffOverview,void>({query:()=> 'staff-operations',providesTags:['StaffOperations'],transformResponse:(r:{data:StaffOverview})=>r.data}),
 commitStaffOperation:b.mutation<Record<string,unknown>,StaffPending>({query:({action,key,body})=>({url:`staff-operations/${action}`,method:'POST',body,headers:{'Idempotency-Key':key}}),invalidatesTags:['StaffOperations'],transformResponse:(r:{data:Record<string,unknown>})=>r.data}),
})});
export const {useStaffOperationsQuery,useCommitStaffOperationMutation}=api;
