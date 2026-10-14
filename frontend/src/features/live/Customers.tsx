import { CustomerLoyalty } from './Incentives';
import {LoyaltyCardPanel} from './LoyaltyCard';
import {CustomerMessaging} from './Messaging';
import {usePlanFeature} from './usePlanFeature';
import { translate, useLanguage } from '../../i18n';
import { useEffect, useState } from 'react';
import { useDispatch, useSelector, useStore } from 'react-redux';
import { Dialog } from '@mui/material';
import { Users, Wallet, Plus, Phone, Search, Star, TrendingUp } from 'lucide-react';
import { SectionCard, StatCard } from '../../components/ui/Primitives';
import {MobileDebtPayment} from './MobileDebtPayment';
import { collectionActions, type CollectionState } from './collectionState';
import { errorMessage, useCustomersQuery, useSaveCustomerMutation, useCustomerDebtQuery, useCurrentTillQuery,
  usePaymentCapabilitiesQuery,useMobilePaymentsQuery, usePreviewCollectionMutation, useCollectDebtMutation, type Customer, type CollectionReview, type SessionState } from './api';

const money = (value:number) => `TSh ${value.toLocaleString('en-TZ')}`;
const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
function amount(value:string):number {
  if (!/^(0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) throw new Error('Enter amounts as whole TSh.');
  return Number(value);
}
export function Customers() {
  useLanguage();
  const loyaltyEnabled=usePlanFeature('loyalty');const messagingEnabled=usePlanFeature('messaging');
  const dispatch = useDispatch(); const store = useStore<{session:SessionState}>();
  const operation = useSelector((s:{collection:CollectionState}) => s.collection);
  const [search,setSearch] = useState(''); const [filters,setFilters] = useState({page:1,search:''});
  const customers = useCustomersQuery(filters); const till = useCurrentTillQuery();
  const [selected,setSelected] = useState<Customer|null>(null);
  const customer = customers.currentData?.customers.find((c)=>c.id===selected?.id) ?? selected;
  const debt = useCustomerDebtQuery(customer?.id ?? '',{skip:!customer});
  const [editor,setEditor] = useState<{id?:string;name:string;phone:string;limit:string}|null>(null);
  const [save] = useSaveCustomerMutation(); const [preview] = usePreviewCollectionMutation(); const [collect] = useCollectDebtMutation();
  const [cash,setCash] = useState(''); const [allocations,setAllocations] = useState<Record<string,string>>({});
  const [review,setReview] = useState<CollectionReview|null>(null); const [failure,setFailure] = useState(''); const [busy,setBusy] = useState(false);
  const [mobileOpen,setMobileOpen]=useState(false);const [mobileReview,setMobileReview]=useState<CollectionReview|null>(null);
  const capability=usePaymentCapabilitiesQuery();const mobilePayments=useMobilePaymentsQuery(undefined,{refetchOnMountOrArgChange:true});
  const locked = busy || !!operation.pending || !!operation.mobile;
  useEffect(()=>{
    const guard = (e:BeforeUnloadEvent)=>{if(operation.pending||operation.mobile){e.preventDefault();e.returnValue='';}};
    window.addEventListener('beforeunload',guard); return ()=>window.removeEventListener('beforeunload',guard);
  },[operation.pending,operation.mobile]);

  async function saveProfile() {
    if (!editor || locked) return; setFailure('');
    let limit:number; try { limit=amount(editor.limit); } catch(e) {setFailure((e as Error).message);return;}
    setBusy(true); const request=save({id:editor.id,body:{name:editor.name,phone:editor.phone || null,credit_limit:limit}});
    try {const result=await request.unwrap();setSelected(result);setEditor(null);setAllocations({});setCash('');}
    catch(e){setFailure(errorMessage(e));} finally {request.reset();setBusy(false);}
  }
  async function inspect(method:'cash'|'mpesa'='cash') {
    if (!customer || !till.data?.till || locked) return; setFailure('');
    let value:number; let entries:{sale_id:string;amount:number}[];
    try {value=amount(cash);entries=Object.entries(allocations).map(([sale_id,v])=>({sale_id,amount:amount(v)}));}
    catch(e){setFailure((e as Error).message);return;}
    setBusy(true);const request=preview({customer:customer.id,body:{till_id:till.data.till.id,method,amount:value,allocations:entries}});
    try {const result=await request.unwrap();if(method==='mpesa'){setMobileReview(result);setMobileOpen(true);}else setReview(result);} catch(e){setFailure(errorMessage(e));} finally {request.reset();setBusy(false);}
  }
  async function pay() {
    if (busy || (!review && !operation.pending)) return;
    const token=store.getState().session.token;if(!token)return;
    const pending=operation.pending ?? {customer:review!.customer_id,key:crypto.randomUUID(),body:{till_id:review!.till_id,method:'cash' as const,
      amount:review!.amount,allocations:review!.allocations.map((a)=>({sale_id:a.sale_id,amount:a.amount})),review_hash:review!.review_hash}};
    dispatch(collectionActions.submitting(pending));setBusy(true);setFailure('');const request=collect(pending);
    try {
      const result=await request.unwrap();if(store.getState().session.token!==token)return;
      dispatch(collectionActions.confirmed(result));setReview(null);setCash('');setAllocations({});
    } catch(e) {
      if(store.getState().session.token!==token)return;
      const status=(e as {status?:number|string}).status;
      if(typeof status==='number' && status>=400 && status<500 && status!==408){dispatch(collectionActions.rejected());setReview(null);}
      setFailure(errorMessage(e));
    } finally {request.reset();setBusy(false);}
  }
  return <div className="space-y-5 p-4 lg:p-6">
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard title={translate("Customers matched")} value={customers.currentData ? String(customers.currentData.meta.total) : '—'} icon={<Users className="h-5 w-5 text-white"/>} color="bg-blue-500"/>
      <StatCard title={translate("Selected customer debt")} value={debt.currentData ? money(debt.currentData.outstanding_balance) : '—'} icon={<Wallet className="h-5 w-5 text-white"/>} color="bg-red-500"/>
      <StatCard title={translate("Loyalty points")} value="—" icon={<Star className="h-5 w-5 text-white"/>} color="bg-amber-500"/>
      <StatCard title={translate("Spending reports")} value="—" icon={<TrendingUp className="h-5 w-5 text-white"/>} color="bg-emerald-500"/>
    </div>
    {operation.receipt && <div role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{operation.receipt.method==='mpesa'?translate("Selcom"):translate("Cash")} {translate("collection recorded:")} {money(operation.receipt.amount)}{translate(". Reference")} {operation.receipt.id}</div>}
    {operation.mobile&&<button onClick={()=>setMobileOpen(true)} className="rounded-lg bg-amber-50 p-3 text-sm font-semibold text-amber-800 underline">{translate("Resume unfinished Selcom collection")}</button>}
    {!operation.mobile&&mobilePayments.currentData?.payments?.filter(p=>p.purpose==='debt').map(p=><button key={p.id} disabled={locked} onClick={()=>{dispatch(collectionActions.mobileSubmitting({key:null,body:null,id:p.id}));setMobileOpen(true);}} className="block rounded-lg bg-amber-50 p-3 text-sm text-amber-800 underline">{translate("Recover Selcom collection ·")} {money(p.amount)}</button>)}
    {failure && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(failure)}</p>}
    {operation.pending && <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-700"><p>{translate("Collection outcome is not confirmed. Retry the same payment before collecting again.")}</p><button disabled={busy} onClick={()=>void pay()} className="mt-2 font-semibold underline">{translate("Retry same collection")}</button></div>}
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div className="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
          <form onSubmit={(e)=>{e.preventDefault();if(!locked)setFilters({page:1,search});}} className="flex min-w-0 gap-2"><div className="flex min-w-0 items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 text-slate-400"/><input aria-label={translate("Search customers")} disabled={locked} value={search} onChange={(e)=>setSearch(e.target.value)} placeholder={translate("Search name or phone…")} className="min-w-0 w-32 bg-transparent text-sm outline-none sm:w-48"/></div><button disabled={locked} className="text-sm text-emerald-600">{translate("Search")}</button></form>
          <button disabled={locked} onClick={()=>{setFailure('');setEditor({name:'',phone:'',limit:'0'});}} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white disabled:opacity-40"><Plus className="h-4 w-4"/> {translate("Add Customer")}</button>
        </div>
        {customers.isFetching && <p role="status" className="p-4 text-sm text-slate-500">{translate("Loading customers…")}</p>}
        {customers.error && <p role="alert" className="p-4 text-sm text-red-600">{errorMessage(customers.error)} <button onClick={()=>void customers.refetch()} className="underline">{translate("Try again")}</button></p>}
        <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="px-4 py-3">{translate("Customer")}</th><th className="px-4 py-3">{translate("Phone")}</th><th className="px-4 py-3 text-right">{translate("Credit limit")}</th><th className="px-4 py-3 text-right">{translate("Debt")}</th></tr></thead><tbody className="divide-y divide-slate-50">{customers.currentData?.customers.map((c)=><tr key={c.id} className={customer?.id===c.id?'bg-emerald-50':'hover:bg-slate-50'}><td className="px-4 py-3"><button disabled={locked} onClick={()=>{setSelected(c);setAllocations({});setCash('');setReview(null);setFailure('');dispatch(collectionActions.clear());}} className="text-left font-medium text-slate-700">{c.name}</button></td><td className="px-4 py-3 text-slate-600">{c.phone ?? '—'}</td><td className="px-4 py-3 text-right text-slate-700">{money(c.credit_limit)}</td><td className="px-4 py-3 text-right font-semibold text-red-600">{money(c.outstanding_balance)}</td></tr>)}</tbody></table></div>
        {!customers.isFetching && !customers.error && customers.currentData?.meta.total===0 && <p className="p-6 text-center text-sm text-slate-400">{translate("No customers found. Add a customer to offer credit.")}</p>}
        {customers.currentData && <div className="flex items-center justify-between p-4 text-sm text-slate-500"><button disabled={locked||filters.page===1} onClick={()=>setFilters({...filters,page:filters.page-1})}>{translate("Previous")}</button><span>{translate("Page")} {filters.page}</span><button disabled={locked||filters.page*customers.currentData.meta.per_page>=customers.currentData.meta.total} onClick={()=>setFilters({...filters,page:filters.page+1})}>{translate("Next")}</button></div>}
      </div>
      <SectionCard title={translate("Customer Profile")}>{customer ? <div className="space-y-4">
        <div className="flex items-center justify-between gap-2"><h3 className="break-words font-bold text-slate-800">{customer.name}</h3><button disabled={locked} onClick={()=>{setFailure('');setEditor({id:customer.id,name:customer.name,phone:customer.phone??'',limit:String(customer.credit_limit)});}} className="text-sm font-medium text-emerald-600">{translate("Edit")}</button></div>{loyaltyEnabled&&<><CustomerLoyalty customerId={customer.id}/><LoyaltyCardPanel key={customer.id} customerId={customer.id}/></>}{messagingEnabled&&<CustomerMessaging customerId={customer.id}/>}
        <p className="flex items-center gap-2 text-sm text-slate-600"><Phone className="h-4 w-4 text-slate-400"/>{customer.phone??'No phone recorded'}</p>
        {debt.isFetching && <p role="status" className="text-sm text-slate-500">{translate("Loading debt…")}</p>}
        {debt.error && <p role="alert" className="text-sm text-red-600">{errorMessage(debt.error)} <button onClick={()=>void debt.refetch()} className="underline">{translate("Try again")}</button></p>}
        {debt.currentData && <><div className="grid grid-cols-2 gap-3"><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Credit limit")}</p><p className="font-bold text-slate-700">{money(debt.currentData.credit_limit)}</p></div><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Current debt")}</p><p className="font-bold text-red-600">{money(debt.currentData.outstanding_balance)}</p></div></div>
          <div className="rounded-lg border border-amber-200 bg-amber-50 p-3"><h4 className="text-sm font-semibold text-amber-700">{translate("Debt aging")}</h4><div className="mt-2 space-y-1 text-xs text-amber-700">{Object.entries(debt.currentData.aging).map(([key,value])=><p key={key} className="flex justify-between"><span>{({ '0_30':'0–30 days','31_60':'31–60 days','61_90':'61–90 days','90_plus':'Over 90 days' } as Record<string,string>)[key]}</span><span>{money(value)}</span></p>)}</div></div>
          <div><h4 className="mb-2 text-sm font-semibold text-slate-700">{translate("Choose invoices to settle")}</h4><div className="space-y-3">{debt.currentData.open_sales.map((sale)=><div key={sale.sale_id} className="rounded-lg border border-slate-200 p-3"><label className="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" disabled={locked} checked={sale.sale_id in allocations} onChange={(e)=>{setReview(null);setAllocations((old)=>{const next={...old};if(e.target.checked)next[sale.sale_id]='';else delete next[sale.sale_id];return next;});}}/>{sale.receipt_number}</label><p className="mt-1 text-xs text-slate-500">{translate("Unpaid:")} {money(sale.remaining_amount)}</p>{sale.sale_id in allocations && <label className="mt-2 block text-xs text-slate-500">{translate("Allocation for")} {sale.receipt_number}<input disabled={locked} value={allocations[sale.sale_id]} inputMode="numeric" onChange={(e)=>{setReview(null);setAllocations({...allocations,[sale.sale_id]:e.target.value});}} className={inputClass}/></label>}</div>)}</div>
          {debt.currentData.open_sales.length>0 && <><label className="mt-4 block text-xs text-slate-500">{translate("Cash collection amount (TSh)")}<input disabled={locked} value={cash} onChange={(e)=>{setReview(null);setCash(e.target.value);}} inputMode="numeric" className={inputClass}/></label><button disabled={locked||!till.data?.till||!!till.error||till.isFetching} onClick={()=>void inspect()} className="mt-3 w-full rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-white disabled:opacity-40">{translate("Review cash collection")}</button>{capability.currentData?.enabled&&<button disabled={locked||!till.data?.till||!!till.error||till.isFetching} onClick={()=>void inspect('mpesa')} className="mt-2 w-full rounded-lg border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-700 disabled:opacity-40">{translate("Pay selected invoices with Selcom")}</button>}{!till.data?.till && <p className="mt-2 text-xs text-amber-700">{translate("Open your own till to collect cash.")}</p>}</>}
          {debt.currentData.open_sales.length===0 && <p className="text-sm text-emerald-600">{translate("No unpaid sales.")}</p>}</div>
        </>}
      </div> : <div className="flex flex-col items-center py-10 text-slate-300"><Users className="mb-3 h-10 w-10"/><p className="text-sm text-slate-400">{translate("Select a customer to view their profile")}</p></div>}</SectionCard>
    </div>
    <Dialog open={!!editor} onClose={()=>{if(!locked)setEditor(null);}} fullWidth maxWidth="sm" aria-labelledby="customer-title"><div className="p-6"><h2 id="customer-title" className="text-lg font-bold text-slate-800">{editor?.id?translate("Edit customer"):translate("Add customer")}</h2>{editor && <form onSubmit={(e)=>{e.preventDefault();void saveProfile();}} className="mt-4 space-y-4"><label className="block text-xs text-slate-500">{translate("Customer name")}<input disabled={locked} required value={editor.name} onChange={(e)=>setEditor({...editor,name:e.target.value})} className={inputClass}/></label><label className="block text-xs text-slate-500">{translate("Customer phone (optional)")}<input disabled={locked} value={editor.phone} onChange={(e)=>setEditor({...editor,phone:e.target.value})} inputMode="tel" className={inputClass}/></label><label className="block text-xs text-slate-500">{translate("Credit limit (TSh)")}<input disabled={locked} value={editor.limit} onChange={(e)=>setEditor({...editor,limit:e.target.value})} inputMode="numeric" required className={inputClass}/></label>{failure && <p role="alert" className="text-sm text-red-600">{translate(failure)}</p>}<div className="flex gap-3"><button disabled={locked} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white">{translate("Save customer")}</button><button type="button" disabled={locked} onClick={()=>setEditor(null)} className="text-sm text-slate-500">{translate("Cancel")}</button></div></form>}</div></Dialog>
    <Dialog open={!!review} onClose={()=>{if(!locked)setReview(null);}} fullWidth maxWidth="sm" aria-labelledby="collection-title"><div className="p-6"><h2 id="collection-title" className="text-lg font-bold text-slate-800">{translate("Review debt collection")}</h2>{review && <><p className="mt-4 text-2xl font-bold text-emerald-600">{money(review.amount)}</p><p className="mt-2 text-sm text-slate-500">{translate("Remaining customer debt:")} {money(review.balance_after)}</p><div className="my-4 space-y-2 text-sm text-slate-600">{review.allocations.map((a)=><p key={a.sale_id} className="flex justify-between gap-2"><span>{debt.currentData?.open_sales.find((s)=>s.sale_id===a.sale_id)?.receipt_number??a.sale_id}</span><span>{money(a.amount)}</span></p>)}</div>{failure && <p role="alert" className="text-sm text-red-600">{translate(failure)}</p>}<button disabled={busy} onClick={()=>void pay()} className="mt-4 w-full rounded-lg bg-emerald-500 px-4 py-3 text-sm font-semibold text-white">{busy?translate("Confirming…"):operation.pending?translate("Retry same cash collection"):translate("Confirm cash collection")}</button><button disabled={locked} onClick={()=>setReview(null)} className="mt-3 text-sm text-slate-500">{translate("Back to allocations")}</button></>}</div></Dialog>
    <MobileDebtPayment open={mobileOpen} review={mobileReview} onClose={()=>{setMobileOpen(false);setMobileReview(null);}}/>
  </div>;
}



