import {liveApi} from './api';
export type MessageConsent={whatsapp:boolean;sms:boolean;receipts:boolean;debt_reminders:boolean;revision:number};
export type Delivery={id:string;channel:'sms'|'whatsapp';purpose:string;status:string;failure_code:string|null;accepted_at:string|null;delivered_at:string|null;created_at:string};
export type MessagingAutomation={daily_summary:boolean;debt_reminders:boolean;at_time:string;renewal_days:number|null;revision:number};
export type MessagingWrite={action:'consent'|'receipt'|'debt_reminder'|'automation';key:string;body:Record<string,unknown>};
export const messagingApi=liveApi.enhanceEndpoints({addTagTypes:['Messaging']}).injectEndpoints({endpoints:b=>({
 messageConsent:b.query<MessageConsent,string>({query:id=>`customers/${id}/message-consent`,providesTags:['Messaging'],transformResponse:(r:{data:MessageConsent})=>r.data}),
 messageDeliveries:b.query<{automation?:MessagingAutomation;channels:Record<'sms'|'whatsapp',{ready:boolean;reason:string|null}>;deliveries:Delivery[]},void>({query:()=>`messaging`,providesTags:['Messaging'],transformResponse:(r:{data:{automation?:MessagingAutomation;channels:Record<'sms'|'whatsapp',{ready:boolean;reason:string|null}>;deliveries:Delivery[]}})=>r.data}),
 messagingWrite:b.mutation<{delivery?:Delivery;consent?:MessageConsent},MessagingWrite>({query:p=>({url:`messaging/${p.action}`,method:'POST',body:p.body,headers:{'Idempotency-Key':p.key}}),invalidatesTags:['Messaging'],transformResponse:(r:{data:{delivery?:Delivery;consent?:MessageConsent}})=>r.data}),
})});
export const {useMessageConsentQuery,useMessageDeliveriesQuery,useMessagingWriteMutation}=messagingApi;
