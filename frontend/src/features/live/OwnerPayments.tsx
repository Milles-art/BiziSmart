import { translate, useLanguage } from '../../i18n';
import {useState} from 'react';
import {useSelector} from 'react-redux';
import {ManualMobileRefund} from './ManualMobileRefund';
import {type MobileRefundState} from './mobileRefundState';
import { useOwnerPaymentsQuery } from './api';
export function OwnerPayments() {
  useLanguage();
  const pending=useSelector((s:{mobileRefund?:MobileRefundState})=>s.mobileRefund?.pending??null);
  const [selected,setSelected]=useState<string|null>(null);
  const payments=useOwnerPaymentsQuery(undefined,{pollingInterval:60000});
  if(payments.error&&!pending&&!selected)return <p role="alert" className="p-4 text-sm text-red-600">{translate("Unallocated mobile payments could not be checked.")} <button onClick={()=>void payments.refetch()} className="underline">{translate("Check again")}</button></p>;
  if(!payments.currentData?.payments?.length&&!pending&&!selected)return null;
  return <section className="m-4 rounded-xl border border-amber-200 bg-amber-50 p-4"><h2 className="font-semibold text-amber-900">{translate("Unallocated mobile payments")}</h2><p className="mt-1 text-xs text-amber-800">{translate("Confirmed money stays recorded until its original sale or selected invoices are completed. Review awaiting or uncertain orders with Selcom before collecting again.")}</p><div className="mt-3 space-y-2">{(payments.currentData?.payments??[]).map(p=><div key={p.id} className="rounded-lg bg-white p-3 text-sm text-slate-700"><p className="font-semibold">{translate("TSh")} {p.amount.toLocaleString('en-TZ')} · {p.status}</p><p className="mt-1 break-all text-xs">{p.id}{p.reference?` · ${p.reference}`:''}</p>{p.owner_review_required&&<p className="mt-1 text-xs text-red-600">{p.status==='confirmed'?translate("Confirmed more than an hour ago; owner review required."):translate("Payment evidence requires owner review.")}</p>}{p.status==='confirmed'&&<button disabled={!!pending} onClick={()=>setSelected(p.id)} className="mt-2 text-xs font-semibold text-emerald-700 underline disabled:opacity-40">{translate("Record external refund")}</button>}</div>)}</div>{pending&&<button onClick={()=>setSelected(pending.id)} className="mt-3 text-sm font-semibold text-amber-900 underline">{translate("Resume refund record")}</button>}{selected&&<ManualMobileRefund id={selected} onClose={()=>setSelected(null)}/>}</section>;
}



