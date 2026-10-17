import { translate, useLanguage } from '../../i18n';
import {useState} from 'react';
import {useStore} from 'react-redux';
import {errorMessage,useSupervisorCheckMutation,type SessionState} from './api';
export function DiscountApproval({operationId,reviewHash,onApproved,disabled=false}:{operationId:string;reviewHash:string;onApproved:(token:string)=>void;disabled?:boolean}){
  useLanguage();
 const store=useStore<{session:SessionState}>();const [approve]=useSupervisorCheckMutation();
 const [phone,setPhone]=useState('');const [pin,setPin]=useState('');const [error,setError]=useState('');const [busy,setBusy]=useState(false);
 async function submit(){if(disabled||busy)return;const token=store.getState().session.token;setBusy(true);setError('');
  const request=approve({email:phone,password:pin,permission:'give_discount',operation_id:operationId,payload_hash:reviewHash});setPin('');
  try{const grant=await request.unwrap();if(store.getState().session.token===token)onApproved(grant.approval_token);}catch(e){if(store.getState().session.token===token)setError(errorMessage(e));}finally{request.reset();setBusy(false);}
 }
 const style='mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm';
 return <fieldset disabled={disabled||busy} className="my-4 space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3"><legend className="px-1 text-sm font-semibold text-amber-900">{translate("Discount approval")}</legend><p className="text-xs text-amber-800">{translate("Every discount requires approval for this exact checkout. Managers approve their own branch.")}</p><label className="block text-xs text-slate-600">{translate("Supervisor email")}<input type="email" value={phone} onChange={e=>setPhone(e.target.value)} className={style}/></label><label className="block text-xs text-slate-600">{translate("Supervisor password")}<input type="password" autoComplete="off" value={pin} onChange={e=>setPin(e.target.value)} className={style}/></label>{error&&<p role="alert" className="text-sm text-red-600">{translate(error)}</p>}<button type="button" onClick={()=>void submit()} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white">{busy?translate("Checking…"):translate("Approve discount")}</button></fieldset>;
}



