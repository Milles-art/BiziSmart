import {useState,type FormEvent} from 'react';
import {useDispatch,useSelector,useStore} from 'react-redux';
import {errorMessage,type CashSale,type SessionState} from './api';
import {useMessageConsentQuery,useMessageDeliveriesQuery,useMessagingWriteMutation,type MessageConsent,type MessagingWrite} from './messagingApi';
import {messagingActions,type MessagingState} from './messagingState';
import {translate,useLanguage} from '../../i18n';
import {MessagingAutomation} from './MessagingAutomation';
import {usePlanFeature} from './usePlanFeature';
import {StatCard,Badge} from '../../components/ui/Primitives';
import {AlertTriangle,CheckCircle,MessageSquare,XCircle} from 'lucide-react';
const input='mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm';
const button='rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-white disabled:opacity-40';
function useWrite(){const dispatch=useDispatch();const store=useStore<{session:SessionState}>();const pending=useSelector((s:{messaging?:MessagingState})=>s.messaging?.pending??null);const [commit]=useMessagingWriteMutation();const [busy,setBusy]=useState(false);const [error,setError]=useState('');const [notice,setNotice]=useState('');
 async function save(write:MessagingWrite){if(busy)return;const token=store.getState().session.token;if(!token)return;dispatch(messagingActions.submitting(write));setBusy(true);setError('');setNotice('');const request=commit(write);try{const result=await request.unwrap();if(store.getState().session.token!==token)return;dispatch(messagingActions.settled());setNotice(result.delivery?`Message ${result.delivery.status}. Delivery requires provider confirmation.`:'Customer consent recorded.');}catch(e){if(store.getState().session.token!==token)return;const status=(e as {status?:number|string}).status;if(typeof status==='number'&&status>=400&&status<500&&status!==408)dispatch(messagingActions.settled());setError(errorMessage(e));}finally{request.reset();setBusy(false);}}
 return {save,pending,busy,error,notice,locked:busy||!!pending};
}
export function MessagingRecovery(){useLanguage();const w=useWrite();if(!w.pending)return null;return <div className="m-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800"><p>{translate('Messaging request outcome is not confirmed. Retry the same operation before starting another.')}</p><button disabled={w.busy} onClick={()=>void w.save(w.pending!)} className={`${button} mt-2`}>{translate('Retry same messaging request')}</button>{w.error&&<p role="alert">{w.error}</p>}</div>;}
export function CustomerMessaging({customerId}:{customerId:string}){useLanguage();const query=useMessageConsentQuery(customerId);const owner=useSelector((s:{session:SessionState})=>s.session.user?.role==='owner');const w=useWrite();const [draft,setDraft]=useState<MessageConsent|null>(null);const [evidence,setEvidence]=useState('');const [channel,setChannel]=useState<'sms'|'whatsapp'>('whatsapp');const current=draft??query.data;
 function submit(e:FormEvent){e.preventDefault();if(!current||w.locked)return;void w.save({action:'consent',key:crypto.randomUUID(),body:{customer_id:customerId,expected_revision:current.revision,whatsapp:current.whatsapp,sms:current.sms,receipts:current.receipts,debt_reminders:current.debt_reminders,evidence}}).then(()=>setDraft(null));}
 return <section className="mt-4 rounded-xl border border-slate-200 bg-white p-4"><h3 className="font-semibold text-slate-800">{translate('Customer message consent')}</h3><p className="mt-1 text-xs text-slate-500">{translate('Record the customer’s explicit consent. Missing provider setup leaves delivery blocked; no success is simulated.')}</p>{query.error&&<p role="alert">{errorMessage(query.error)}</p>}{current&&<form onSubmit={submit} className="mt-3 space-y-3">{(['whatsapp','sms','receipts','debt_reminders'] as const).map(k=><label key={k} className="mr-4 inline-flex gap-2 text-sm"><input type="checkbox" disabled={w.locked} checked={current[k]} onChange={e=>setDraft({...current,[k]:e.target.checked})}/>{translate(k.replace(/_/g,' '))}</label>)}<label className="block text-xs">{translate('Consent evidence or revocation reason')}<input required minLength={3} maxLength={500} disabled={w.locked} value={evidence} onChange={e=>setEvidence(e.target.value)} className={input}/></label><button disabled={w.locked} className={button}>{translate('Save customer consent')}</button></form>}<label className="mt-4 block text-xs">{translate('Message channel')}<select value={channel} disabled={w.locked} onChange={e=>setChannel(e.target.value as 'sms'|'whatsapp')} className={input}><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></label><button type="button" disabled={!owner||w.locked||!query.data?.[channel]||!query.data.debt_reminders} onClick={()=>void w.save({action:'debt_reminder',key:crypto.randomUUID(),body:{customer_id:customerId,channel}})} className={`${button} mt-3`}>{translate('Queue debt reminder (owner)')}</button>{(w.error||w.notice)&&<p role={w.error?'alert':'status'} className="mt-2 text-sm">{w.error||translate(w.notice)}</p>}</section>;
}
export function ReceiptMessaging({sale}:{sale:CashSale}){useLanguage();const enabled=usePlanFeature('messaging');const w=useWrite();const [channel,setChannel]=useState<'sms'|'whatsapp'>('whatsapp');const consent=useMessageConsentQuery(sale.customer_id??'',{skip:!sale.customer_id||!enabled});const me=useSelector((s:{session:SessionState})=>s.session);if(!sale.customer_id||!me.token||!enabled)return null;
 return <div className="mt-3 space-y-2 text-sm"><label>{translate('Receipt message channel')}<select disabled={w.locked} value={channel} onChange={e=>setChannel(e.target.value as 'sms'|'whatsapp')} className={input}><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></label><button disabled={w.locked||!consent.data?.[channel]||!consent.data.receipts} onClick={()=>void w.save({action:'receipt',key:crypto.randomUUID(),body:{customer_id:sale.customer_id,sale_id:sale.id,channel}})} className={button}>{translate('Queue consented receipt')}</button><p className="text-xs text-slate-500">{translate('Requires customer channel/receipt consent. Queued and accepted do not mean delivered.')}</p>{(w.error||w.notice)&&<p role={w.error?'alert':'status'}>{w.error||translate(w.notice)}</p>}</div>;
}
export function Messaging(){
 useLanguage();const q=useMessageDeliveriesQuery();const w=useWrite();
 const [filter,setFilter]=useState('all');
 const data=q.error?undefined:q.data;
 const deliveries=data?.deliveries;
 const filtered=deliveries?.filter(row=>filter==='all'||row.status===filter);
 return <section className="space-y-5 p-4 lg:p-6"><div className="workflow-heading"><div><h2>{translate("WhatsApp & SMS delivery")}</h2><p>{translate("Review provider setup and track each message through delivery.")}</p></div></div>
  <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
   <StatCard title="Delivery records shown" value={deliveries?String(deliveries.length):'—'} icon={<MessageSquare className="h-5 w-5 text-white"/>} color="bg-blue-500"/>
   <StatCard title="Delivered" value={deliveries?String(deliveries.filter(row=>row.status==='delivered').length):'—'} icon={<CheckCircle className="h-5 w-5 text-white"/>} color="bg-emerald-500"/>
   <StatCard title="Failed" value={deliveries?String(deliveries.filter(row=>row.status==='failed').length):'—'} icon={<XCircle className="h-5 w-5 text-white"/>} color="bg-red-500"/>
   <StatCard title="Uncertain" value={deliveries?String(deliveries.filter(row=>row.status==='uncertain').length):'—'} icon={<AlertTriangle className="h-5 w-5 text-white"/>} color="bg-amber-500"/>
  </div>
  <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
   <h3 className="font-semibold text-slate-800">{translate("Delivery settings")}</h3>
   {q.isLoading&&<p role="status" className="text-sm text-slate-500">{translate('Loading delivery records…')}</p>}
   {q.error&&<p role="alert">{errorMessage(q.error)}<button onClick={()=>void q.refetch()} className="ml-3 underline">{translate('Try again')}</button></p>}
   <p className="text-sm text-slate-500">{translate('External credentials, approved templates/sender and scheduler are required. Provider acceptance is not confirmed delivery.')}</p>
   {data&&<><div className="flex flex-wrap gap-3 text-sm">{(['whatsapp','sms'] as const).map(channel=><div key={channel} className="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2"><span>{channel==='whatsapp'?'WhatsApp':'SMS'}</span><Badge status={data.channels[channel].ready?'Configured':'Provider setup required'} variant={data.channels[channel].ready?'success':'warning'}/></div>)}</div>
   {data.automation&&<MessagingAutomation settings={data.automation} locked={w.locked} save={w.save}/>}
   {(w.error||w.notice)&&<p role={w.error?'alert':'status'}>{w.error||translate(w.notice)}</p>}</>}
  </div>
  <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
   <h3 className="font-semibold text-slate-800">{translate('Delivery history')}</h3>
   <p className="text-xs text-slate-500">{translate('Counts and filters cover the delivery records returned, not all historical messages.')}</p>
   <div className="workflow-tabs" role="group" aria-label={translate("Delivery status")}>{['all','queued','accepted','delivered','failed','uncertain','blocked'].map(value=><button key={value} type="button" aria-pressed={filter===value} onClick={()=>setFilter(value)} className={`rounded-lg px-3 py-1.5 text-sm font-medium ${filter===value?'bg-emerald-500 text-white':'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{translate(value.charAt(0).toUpperCase()+value.slice(1))}</button>)}</div>
   <div className="space-y-2">{filtered?.map(delivery=><div key={delivery.id} className="rounded-lg border border-slate-100 p-3 text-sm"><div className="flex flex-wrap items-center justify-between gap-2"><p className="break-words text-slate-700">{delivery.channel} · {delivery.purpose.replace(/_/g,' ')}</p><Badge status={delivery.status} variant={delivery.status==='delivered'?'success':delivery.status==='failed'?'danger':'neutral'}/></div>{delivery.failure_code&&<p className="mt-1 break-words text-xs text-amber-700">{delivery.failure_code}</p>}<p className="mt-1 text-xs text-slate-400">{new Date(delivery.created_at).toLocaleString('en-TZ',{timeZone:'Africa/Dar_es_Salaam'})}</p></div>)}{filtered?.length===0&&<div className="py-8 text-center"><MessageSquare className="mx-auto mb-2 h-8 w-8 text-slate-300"/><p className="text-sm text-slate-500">{translate(deliveries?.length===0?'No delivery records.':'No delivery records match this filter.')}</p></div>}</div>
  </div>
 </section>;
}
