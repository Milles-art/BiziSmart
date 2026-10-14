import { translate, useLanguage } from '../../i18n';
import { useDispatch } from 'react-redux';
import { checkoutActions } from './checkoutState';
import { errorMessage, useMobilePaymentsQuery } from './api';
export function PaymentRecovery({locked,onResume}:{locked:boolean;onResume:()=>void}) {
  useLanguage();
  const pending=useMobilePaymentsQuery(undefined,{refetchOnMountOrArgChange:true});
  if(pending.error)return <div role="alert" className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{errorMessage(pending.error)} <button onClick={()=>void pending.refetch()} className="underline">{translate("Check unfinished payments")}</button></div>;
  if(!pending.currentData?.payments?.length)return null;
  return <div className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"><p>{translate("Unfinished mobile payments")}</p>{pending.currentData.payments.filter(p=>p.purpose!=='debt').map(p=><Recovery key={p.id} id={p.id} amount={p.amount} status={p.status} locked={locked} onResume={onResume}/>)}{pending.currentData.payments.some(p=>p.purpose==='debt')&&<p>{translate("Recover unfinished debt payments in Customers.")}</p>}</div>;
}
function Recovery({id,amount,status,locked,onResume}:{id:string;amount:number;status:string;locked:boolean;onResume:()=>void}) {
  useLanguage();
 const dispatch=useDispatch();return <button disabled={locked} onClick={()=>{dispatch(checkoutActions.mobileSubmitting({key:null,body:null,id}));onResume();}} className="mt-2 block text-left font-semibold underline disabled:opacity-40">{translate("Resume TSh")} {amount.toLocaleString('en-TZ')} · {status}</button>;
}



