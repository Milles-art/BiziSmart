import { translate, useLanguage } from '../../i18n';
import {useEffect,useState} from 'react';
import {Dialog} from '@mui/material';
import {useDispatch,useSelector,useStore} from 'react-redux';
import {collectionActions,type CollectionState} from './collectionState';
import {errorMessage,useBeginMobilePaymentMutation,useCompleteMobileCollectionMutation,useMobilePaymentQuery,type CollectionReview,type SessionState} from './api';
const field='mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
const button='rounded-lg bg-emerald-500 px-4 py-3 text-sm font-semibold text-white disabled:opacity-40';
export function MobileDebtPayment({open,review,onClose}:{open:boolean;review:CollectionReview|null;onClose:()=>void}){
  useLanguage();
 const dispatch=useDispatch();const store=useStore<{session:SessionState}>();const pending=useSelector((s:{collection:CollectionState})=>s.collection.mobile);
 const [name,setName]=useState('');const [email,setEmail]=useState('');const [phone,setPhone]=useState('');const [busy,setBusy]=useState(false);const [failure,setFailure]=useState('');
 const [begin]=useBeginMobilePaymentMutation();const [complete]=useCompleteMobileCollectionMutation();
 const payment=useMobilePaymentQuery(pending?.id??'',{skip:!open||!pending?.id,pollingInterval:3000});
 useEffect(()=>{const guard=(e:BeforeUnloadEvent)=>{if(pending){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',guard);return()=>window.removeEventListener('beforeunload',guard);},[pending]);
 async function request(){if(busy||(!review&&!pending?.body))return;const token=store.getState().session.token;if(!token)return;
  const normalized=phone.startsWith('0')?`255${phone.slice(1)}`:phone;
  if(!pending&&!/^255[67][0-9]{8}$/.test(normalized)){setFailure('Enter a Tanzania mobile number.');return;}
  const operation=pending??{key:crypto.randomUUID(),id:null,body:{buyer_name:name.trim(),buyer_email:email.trim(),phone:normalized,collection:{customer_id:review!.customer_id,till_id:review!.till_id,method:'mpesa' as const,amount:review!.amount,allocations:review!.allocations.map(a=>({sale_id:a.sale_id,amount:a.amount})),review_hash:review!.review_hash}}};
  if(!operation.key||!operation.body)return;
  dispatch(collectionActions.mobileSubmitting(operation));setBusy(true);setFailure('');const r=begin({key:operation.key,body:operation.body});
  try{const p=await r.unwrap();if(store.getState().session.token!==token)return;dispatch(collectionActions.mobileSubmitting({key:null,body:null,id:p.id}));setName('');setEmail('');setPhone('');}
  catch(e){if(store.getState().session.token!==token)return;const status=(e as {status?:number|string}).status;if(typeof status==='number'&&status>=400&&status<500&&status!==408)dispatch(collectionActions.mobileRejected());setFailure(errorMessage(e));}
  finally{r.reset();setBusy(false);}
 }
 async function finish(){if(!pending?.id||busy)return;const token=store.getState().session.token;setBusy(true);setFailure('');const r=complete(pending.id);
  try{const result=await r.unwrap();if(store.getState().session.token!==token)return;dispatch(collectionActions.confirmed(result));onClose();}
  catch(e){if(store.getState().session.token===token)setFailure(errorMessage(e)+' The recorded payment remains available for review.');}
  finally{r.reset();setBusy(false);}
 }
 const p=payment.currentData;
 return <Dialog open={open} onClose={()=>{if(!busy)onClose();}} fullWidth maxWidth="sm" aria-labelledby="mobile-debt-title"><div className="space-y-4 p-6"><h2 id="mobile-debt-title" className="text-lg font-bold text-slate-800">{translate("Selcom debt collection")}</h2>
  {!pending&&review&&<form onSubmit={e=>{e.preventDefault();void request();}} className="space-y-3"><p className="text-2xl font-bold text-emerald-600">{translate("TSh")} {review.amount.toLocaleString('en-TZ')}</p><p className="text-sm text-slate-500">{translate("Only the selected invoices will be settled after payment verification.")}</p>{review.allocations.map(a=><p key={a.sale_id} className="break-all text-xs text-slate-500">{a.sale_id} {translate("· TSh")} {a.amount.toLocaleString('en-TZ')}</p>)}<label className="block text-xs text-slate-500">{translate("Payer name")}<input required disabled={busy} value={name} onChange={e=>setName(e.target.value)} className={field}/></label><label className="block text-xs text-slate-500">{translate("Payer email")}<input required type="email" disabled={busy} value={email} onChange={e=>setEmail(e.target.value)} className={field}/></label><label className="block text-xs text-slate-500">{translate("Payer phone")}<input required inputMode="tel" disabled={busy} value={phone} onChange={e=>setPhone(e.target.value)} className={field}/></label><button disabled={busy} className={button}>{translate("Send payment request")}</button></form>}
  {pending&&!pending.id&&<><p className="text-sm text-amber-800">{translate("Request outcome is not confirmed. Recover this same request before collecting again.")}</p><button disabled={busy} onClick={()=>void request()} className={button}>{translate("Recover same request")}</button></>}
  {pending?.id&&!p&&!payment.error&&<p role="status">{translate("Checking the recorded payment…")}</p>}
  {p&&<><p className="text-2xl font-bold text-emerald-600">{translate("TSh")} {p.amount.toLocaleString('en-TZ')}</p><p role="status" className="text-sm text-slate-600">{p.status==='confirmed'?translate("Payment verified. Settle the selected invoices."):p.status==='allocated'?translate("Payment already allocated to its collection."):['failed','manual_refunded'].includes(p.status)?translate("This payment cannot settle invoices."):translate("Awaiting provider confirmation. Do not collect again.")}</p>{p.reference&&<p className="text-sm text-slate-500">{translate("Reference:")} {p.reference}</p>}{['confirmed','allocated'].includes(p.status)&&<button disabled={busy} onClick={()=>void finish()} className={button}>{translate("Complete recorded collection")}</button>}{['failed','manual_refunded'].includes(p.status)&&<button disabled={busy} onClick={()=>{dispatch(collectionActions.mobileRejected());onClose();}} className="text-sm text-slate-500">{translate("Return to invoices")}</button>}</>}
  {payment.error&&<p role="alert" className="text-sm text-red-600">{errorMessage(payment.error)} <button onClick={()=>void payment.refetch()} className="underline">{translate("Check again")}</button></p>}{failure&&<p role="alert" className="text-sm text-red-600">{translate(failure)}</p>}<button disabled={busy} onClick={onClose} className="text-sm text-slate-500">{translate("Close payment")}</button>
 </div></Dialog>;
}



