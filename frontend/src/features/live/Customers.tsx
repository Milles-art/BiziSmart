import { CustomerLoyalty } from './Incentives';
import {LoyaltyCardPanel} from './LoyaltyCard';
import {CustomerMessaging} from './Messaging';
import {usePlanFeature} from './usePlanFeature';
import { translate, useLanguage } from '../../i18n';
import { useEffect, useRef, useState } from 'react';
import { useDispatch, useSelector, useStore } from 'react-redux';
import { Dialog } from '@mui/material';
import { Users, Wallet, Plus, Phone, Search, ChevronRight } from 'lucide-react';
import { SectionCard, SummaryCard } from '../../components/ui/Primitives';
import {downloadReportCsv} from './reportCsv';
import {MobileDebtPayment} from './MobileDebtPayment';
import { collectionActions, type CollectionState } from './collectionState';
import { errorMessage, useCustomersQuery, useSaveCustomerMutation, useCustomerDebtQuery, useCustomerStatementQuery, useExportCustomerStatementMutation, useCurrentTillQuery,
  usePaymentCapabilitiesQuery,useMobilePaymentsQuery, usePreviewCollectionMutation, useCollectDebtMutation, type Customer, type CollectionReview, type ServerUser, type SessionState } from './api';

const money = (value:number) => `TSh ${value.toLocaleString('en-TZ')}`;
const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
function amount(value:string):number {
  if (!/^(0|[1-9][0-9]*)$/.test(value) || !Number.isSafeInteger(Number(value))) throw new Error('Enter amounts as whole TSh.');
  return Number(value);
}
export function Customers({ actor }: { actor: ServerUser }) {
  useLanguage();
  const loyaltyEnabled=usePlanFeature('loyalty');const messagingEnabled=usePlanFeature('messaging');
  const tillAccess=actor.permissions.includes('manage_till');
  const dispatch = useDispatch(); const store = useStore<{session:SessionState}>();
  const operation = useSelector((s:{collection:CollectionState}) => s.collection);
  const [search,setSearch] = useState(''); const [filters,setFilters] = useState({page:1,search:''});
  const customers = useCustomersQuery(filters); const till = useCurrentTillQuery(undefined,{skip:!tillAccess});
  const profileRef=useRef<HTMLDivElement>(null);
  const [selected,setSelected] = useState<Customer|null>(null);
  useEffect(()=>{if(selected&&typeof window.matchMedia==='function'&&window.matchMedia('(max-width: 1279px)').matches)profileRef.current?.scrollIntoView?.({block:'start'});},[selected]);
  const customer = customers.currentData?.customers.find((c)=>c.id===selected?.id) ?? selected;
  const debt = useCustomerDebtQuery(customer?.id ?? '',{skip:!customer});
  const [statementOpen,setStatementOpen] = useState(false);
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
    <header className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="text-xl font-bold text-slate-800">{translate('Customers')}</h1><p className="mt-1 text-sm text-slate-500">{translate('Customer records, credit and collections.')}</p></div><button disabled={locked} onClick={()=>{setFailure('');setEditor({name:'',phone:'',limit:'0'});}} className="flex min-h-11 items-center gap-1.5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><Plus className="h-4 w-4"/> {translate("Add Customer")}</button></header>
    <div className="grid grid-cols-2 gap-3">
      <SummaryCard title={translate("Customers matched")} value={customers.currentData&&!customers.error ? String(customers.currentData.meta.total) : '—'} icon={<Users className="h-5 w-5 text-white"/>} color="bg-blue-500"/>
      <SummaryCard title={translate("Selected customer debt")} value={debt.currentData&&!debt.error ? money(debt.currentData.outstanding_balance) : '—'} icon={<Wallet className="h-5 w-5 text-white"/>} color="bg-amber-500"/>
    </div>
    {operation.receipt && <div role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{operation.receipt.method==='mpesa'?translate("Selcom"):translate("Cash")} {translate("collection recorded:")} {money(operation.receipt.amount)}{translate(". Reference")} {operation.receipt.id}</div>}
    {operation.mobile&&<button onClick={()=>setMobileOpen(true)} className="rounded-lg bg-amber-50 p-3 text-sm font-semibold text-amber-800 underline">{translate("Resume unfinished Selcom collection")}</button>}
    {!operation.mobile&&mobilePayments.currentData?.payments?.filter(p=>p.purpose==='debt').map(p=><button key={p.id} disabled={locked} onClick={()=>{dispatch(collectionActions.mobileSubmitting({key:null,body:null,id:p.id}));setMobileOpen(true);}} className="block rounded-lg bg-amber-50 p-3 text-sm text-amber-800 underline">{translate("Recover Selcom collection ·")} {money(p.amount)}</button>)}
    {failure && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(failure)}</p>}
    {operation.pending && <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-700"><p>{translate("Collection outcome is not confirmed. Retry the same payment before collecting again.")}</p><button disabled={busy} onClick={()=>void pay()} className="mt-2 font-semibold underline">{translate("Retry same collection")}</button></div>}
    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
      <div className="rounded-xl border border-slate-200 bg-white xl:col-span-2">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
          <form onSubmit={(e)=>{e.preventDefault();if(!locked)setFilters({page:1,search});}} className="flex w-full min-w-0 gap-2"><div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"><Search className="h-4 w-4 text-slate-400"/><input aria-label={translate("Search customers")} disabled={locked} value={search} onChange={(e)=>setSearch(e.target.value)} placeholder={translate("Search name or phone…")} className="min-w-0 w-full bg-transparent text-sm outline-none"/></div><button disabled={locked} className="min-h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-emerald-700">{translate("Search")}</button></form>
        </div>
        {customers.isFetching && <p role="status" className="p-4 text-sm text-slate-500">{translate("Loading customers…")}</p>}
        {customers.error && <p role="alert" className="p-4 text-sm text-red-600">{errorMessage(customers.error)} <button onClick={()=>void customers.refetch()} className="underline">{translate("Try again")}</button></p>}
        <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="px-4 py-3">{translate("Customer")}</th><th className="px-4 py-3 text-right">{translate("Credit limit")}</th><th className="px-4 py-3 text-right">{translate("Debt")}</th></tr></thead><tbody className="divide-y divide-slate-50">{customers.currentData?.customers.map((c)=><tr key={c.id} aria-selected={customer?.id===c.id} className={customer?.id===c.id?'bg-emerald-50':'hover:bg-slate-50'}><td className="px-4 py-3"><button disabled={locked} onClick={()=>{setSelected(c);setAllocations({});setCash('');setReview(null);setFailure('');dispatch(collectionActions.clear());}} aria-pressed={customer?.id===c.id} className="flex min-h-11 items-center gap-2 text-left font-semibold text-slate-800"><span>{c.name}</span><ChevronRight className="h-4 w-4 shrink-0 text-slate-400"/></button><p className="mt-0.5 text-xs text-slate-500">{c.phone??translate('No phone recorded')}</p></td><td className="px-4 py-3 text-right tabular-nums text-slate-700">{money(c.credit_limit)}</td><td className={`px-4 py-3 text-right font-semibold tabular-nums ${c.outstanding_balance>0?'text-amber-700':'text-slate-500'}`}>{money(c.outstanding_balance)}</td></tr>)}</tbody></table></div>
        {!customers.isFetching && !customers.error && customers.currentData?.meta.total===0 && <p className="p-6 text-center text-sm text-slate-400">{translate("No customers found. Add a customer to offer credit.")}</p>}
        {customers.currentData && <div className="flex items-center justify-between p-4 text-sm text-slate-500"><button disabled={locked||filters.page===1} onClick={()=>setFilters({...filters,page:filters.page-1})}>{translate("Previous")}</button><span>{translate("Page")} {filters.page}</span><button disabled={locked||filters.page*customers.currentData.meta.per_page>=customers.currentData.meta.total} onClick={()=>setFilters({...filters,page:filters.page+1})}>{translate("Next")}</button></div>}
      </div>
      <div ref={profileRef} className={customer?"order-first min-w-0 xl:order-last":"order-last min-w-0"}><SectionCard title={translate("Customer Profile")}>{customer ? <div className="space-y-4">
        <div className="flex items-start justify-between gap-3"><div className="flex min-w-0 items-center gap-3"><div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-bold text-emerald-700">{customer.name.split(' ').map(part=>part[0]).join('').slice(0,2)}</div><div className="min-w-0"><h3 className="break-words font-bold text-slate-800">{customer.name}</h3><p className="mt-1 flex items-center gap-1.5 text-sm text-slate-500"><Phone className="h-3.5 w-3.5 shrink-0"/>{customer.phone??translate('No phone recorded')}</p></div></div><button disabled={locked} onClick={()=>{setFailure('');setEditor({id:customer.id,name:customer.name,phone:customer.phone??'',limit:String(customer.credit_limit)});}} className="min-h-11 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-emerald-700">{translate("Edit")}</button></div>
        {debt.isFetching && <p role="status" className="text-sm text-slate-500">{translate("Loading debt…")}</p>}
        {debt.error && <p role="alert" className="text-sm text-red-600">{errorMessage(debt.error)} <button onClick={()=>void debt.refetch()} className="underline">{translate("Try again")}</button></p>}
        {debt.currentData && <><div className="grid grid-cols-2 gap-3"><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Credit limit")}</p><p className="font-bold tabular-nums text-slate-700">{money(debt.currentData.credit_limit)}</p></div><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Current debt")}</p><p className="font-bold tabular-nums text-amber-700">{money(debt.currentData.outstanding_balance)}</p></div></div>
          <div className="rounded-lg border border-amber-200 bg-amber-50 p-3"><h4 className="text-sm font-semibold text-amber-700">{translate("Debt aging")}</h4><div className="mt-2 space-y-1 text-xs text-amber-700">{Object.entries(debt.currentData.aging).map(([key,value])=><p key={key} className="flex justify-between"><span>{({ '0_30':'0–30 days','31_60':'31–60 days','61_90':'61–90 days','90_plus':'Over 90 days' } as Record<string,string>)[key]}</span><span>{money(value)}</span></p>)}</div></div>
          <div><h4 className="mb-2 text-sm font-semibold text-slate-700">{translate("Choose invoices to settle")}</h4><div className="space-y-3">{debt.currentData.open_sales.map((sale)=><div key={sale.sale_id} className="rounded-lg border border-slate-200 p-3"><label className="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" disabled={locked} checked={sale.sale_id in allocations} onChange={(e)=>{setReview(null);setAllocations((old)=>{const next={...old};if(e.target.checked)next[sale.sale_id]='';else delete next[sale.sale_id];return next;});}}/>{sale.receipt_number}</label><p className="mt-1 text-xs text-slate-500">{translate("Unpaid:")} {money(sale.remaining_amount)}</p>{sale.sale_id in allocations && <label className="mt-2 block text-xs text-slate-500">{translate("Allocation for")} {sale.receipt_number}<input disabled={locked} value={allocations[sale.sale_id]} inputMode="numeric" onChange={(e)=>{setReview(null);setAllocations({...allocations,[sale.sale_id]:e.target.value});}} className={inputClass}/></label>}</div>)}</div>
          {debt.currentData.open_sales.length>0 && <>{tillAccess ? <><label className="mt-4 block text-xs text-slate-500">{translate("Cash collection amount (TSh)")}<input disabled={locked} value={cash} onChange={(e)=>{setReview(null);setCash(e.target.value);}} inputMode="numeric" className={inputClass}/></label><button disabled={locked||!till.data?.till||!!till.error||till.isFetching} onClick={()=>void inspect()} className="mt-3 w-full rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-white disabled:opacity-40">{translate("Review cash collection")}</button>{capability.currentData?.enabled&&<button disabled={locked||!till.data?.till||!!till.error||till.isFetching} onClick={()=>void inspect('mpesa')} className="mt-2 w-full rounded-lg border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-700 disabled:opacity-40">{translate("Pay selected invoices with Selcom")}</button>}{!till.data?.till && !till.isFetching && <p className="mt-2 text-xs text-amber-700">{translate("Open your own till to collect cash.")}</p>}</> : <p className="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">{translate("Debt collections are recorded by a staff member with till access. Ask the owner or a branch manager to collect this payment.")}</p>}</>}
          {debt.currentData.open_sales.length===0 && <p className="text-sm text-emerald-600">{translate("No unpaid sales.")}</p>}</div>
        </>}
        <div className="mt-4 border-t border-slate-100 pt-4"><button disabled={locked} onClick={()=>setStatementOpen(true)} className="min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-emerald-700">{translate("Customer statement")}</button></div>
        <Dialog open={statementOpen} onClose={()=>!locked&&setStatementOpen(false)} maxWidth="md" fullWidth aria-labelledby="statement-title">{customer?.id && <CustomerStatementDialog key={customer.id} customerId={customer.id} onClose={()=>setStatementOpen(false)} busy={locked}/>}</Dialog>
        {loyaltyEnabled&&<div className="space-y-4 border-t border-slate-100 pt-4"><CustomerLoyalty customerId={customer.id}/><LoyaltyCardPanel key={customer.id} customerId={customer.id}/></div>}{messagingEnabled&&<CustomerMessaging customerId={customer.id}/>}
      </div> : <div className="flex flex-col items-center py-10 text-slate-300"><Users className="mb-3 h-10 w-10"/><p className="text-sm text-slate-400">{translate("Select a customer to view their profile")}</p></div>}</SectionCard></div>
    </div>
    <Dialog open={!!editor} onClose={()=>{if(!locked)setEditor(null);}} fullWidth maxWidth="sm" aria-labelledby="customer-title"><div className="p-6"><h2 id="customer-title" className="text-lg font-bold text-slate-800">{editor?.id?translate("Edit customer"):translate("Add customer")}</h2>{editor && <form onSubmit={(e)=>{e.preventDefault();void saveProfile();}} className="mt-4 space-y-4"><label className="block text-xs text-slate-500">{translate("Customer name")}<input disabled={locked} required value={editor.name} onChange={(e)=>setEditor({...editor,name:e.target.value})} className={inputClass}/></label><label className="block text-xs text-slate-500">{translate("Customer phone (optional)")}<input disabled={locked} value={editor.phone} onChange={(e)=>setEditor({...editor,phone:e.target.value})} inputMode="tel" className={inputClass}/></label><label className="block text-xs text-slate-500">{translate("Credit limit (TSh)")}<input disabled={locked} value={editor.limit} onChange={(e)=>setEditor({...editor,limit:e.target.value})} inputMode="numeric" required className={inputClass}/></label>{failure && <p role="alert" className="text-sm text-red-600">{translate(failure)}</p>}<div className="flex gap-3"><button disabled={locked} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white">{translate("Save customer")}</button><button type="button" disabled={locked} onClick={()=>setEditor(null)} className="text-sm text-slate-500">{translate("Cancel")}</button></div></form>}</div></Dialog>
    <Dialog open={!!review} onClose={()=>{if(!locked)setReview(null);}} fullWidth maxWidth="sm" aria-labelledby="collection-title"><div className="p-6"><h2 id="collection-title" className="text-lg font-bold text-slate-800">{translate("Review debt collection")}</h2>{review && <><p className="mt-4 text-2xl font-bold text-emerald-600">{money(review.amount)}</p><p className="mt-2 text-sm text-slate-500">{translate("Remaining customer debt:")} {money(review.balance_after)}</p><div className="my-4 space-y-2 text-sm text-slate-600">{review.allocations.map((a)=><p key={a.sale_id} className="flex justify-between gap-2"><span>{debt.currentData?.open_sales.find((s)=>s.sale_id===a.sale_id)?.receipt_number??a.sale_id}</span><span>{money(a.amount)}</span></p>)}</div>{failure && <p role="alert" className="text-sm text-red-600">{translate(failure)}</p>}<button disabled={busy} onClick={()=>void pay()} className="mt-4 w-full rounded-lg bg-emerald-500 px-4 py-3 text-sm font-semibold text-white">{busy?translate("Confirming…"):operation.pending?translate("Retry same cash collection"):translate("Confirm cash collection")}</button><button disabled={locked} onClick={()=>setReview(null)} className="mt-3 text-sm text-slate-500">{translate("Back to allocations")}</button></>}</div></Dialog>
    <MobileDebtPayment open={mobileOpen} review={mobileReview} onClose={()=>{setMobileOpen(false);setMobileReview(null);}}/>
  </div>;
}




function CustomerStatementDialog({ customerId, onClose, busy }: { customerId: string; onClose: () => void; busy: boolean }) {
  const store = useStore<{session:SessionState}>();
  const [page,setPage] = useState(1);
  const statement = useCustomerStatementQuery({customer:customerId,page});
  const [exportStatement,{isLoading:exporting}] = useExportCustomerStatementMutation();
  const [exportError,setExportError] = useState('');
  const exportCsv = async () => {
    if(exporting)return;
    const token=store.getState().session.token;
    if(!token)return;
    setExportError('');
    const request=exportStatement(customerId);
    try {
    const data = await request.unwrap();
    if(store.getState().session.token!==token)return;
    const rows = [
      ['Date', 'Entry', 'Reference', 'Charge (TSh)', 'Payment (TSh)', 'Debt reduced (TSh)', 'Balance (TSh)'],
      ...data.entries.map(entry => [entry.date, entry.kind === 'credit_sale' ? 'Credit sale' : entry.kind === 'return' ? 'Return' : 'Collection', entry.label, String(entry.charge), String(entry.payment), String(entry.debt_reduction), String(entry.balance)]),
      [],
      ['Opening balance', '', '', '', '', '', String(data.opening_balance)],
      ['Charges', '', '', '', '', '', String(data.charges)],
      ['Payments', '', '', '', '', '', String(data.payments)],
      ['Closing balance', '', '', '', '', '', String(data.closing_balance)],
    ];
    downloadReportCsv(`customer-statement-${customerId}.csv`,rows);
    } catch(e) { if(store.getState().session.token===token)setExportError(errorMessage(e)); }
    finally { request.reset(); }
  };
  return <div className="p-6">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 id="statement-title" className="text-lg font-bold text-slate-800">{translate("Customer statement")}</h2><p className="mt-1 text-xs text-slate-500">{statement.currentData ? `${statement.currentData.currency} · ${statement.currentData.period.timezone}` : ''}</p></div><button disabled={busy || exporting || statement.isFetching || !!statement.error || !statement.currentData} onClick={()=>void exportCsv()} className="inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-emerald-700">{translate("Export statement (CSV)")}</button></div>
    {statement.isFetching && !statement.currentData && <p role="status" className="text-sm text-slate-500">{translate("Loading statement…")}</p>}
    {statement.error && <p role="alert" className="text-sm text-red-600">{errorMessage(statement.error)}</p>}
    {exportError && <p role="alert" className="text-sm text-red-600">{exportError}</p>}
    {statement.currentData && !statement.error && <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Opening balance")}</p><p className="font-bold tabular-nums text-slate-800">{money(statement.currentData.opening_balance)}</p></div>
        <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Charges")}</p><p className="font-bold tabular-nums text-slate-800">{money(statement.currentData.charges)}</p></div>
        <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Payments")}</p><p className="font-bold tabular-nums text-slate-800">{money(statement.currentData.payments)}</p></div>
        <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Closing balance")}</p><p className="font-bold tabular-nums text-emerald-700">{money(statement.currentData.closing_balance)}</p></div>
      </div>
      <div className="max-h-96 overflow-y-auto"><table className="w-full text-sm"><thead className="sticky top-0 bg-white"><tr>{['Date', 'Entry', 'Charge', 'Payment', 'Debt reduced', 'Balance'].map(label => <th key={label} className="p-2 text-left text-xs text-slate-400">{translate(label)}</th>)}</tr></thead><tbody>{statement.currentData.entries.map((entry, index) => <tr key={index} className="border-t border-slate-100"><td className="p-2 text-slate-500">{entry.date}</td><td className="p-2 text-slate-700">{entry.kind === 'credit_sale' ? translate("Credit sale") : entry.kind === 'return' ? translate("Return") : translate("Collection")}<p className="text-xs text-slate-400">{entry.label}</p></td><td className="p-2 tabular-nums text-slate-700">{entry.charge ? money(entry.charge) : '—'}</td><td className="p-2 tabular-nums text-slate-700">{entry.payment ? money(entry.payment) : '—'}</td><td className="p-2 tabular-nums text-amber-700">{entry.debt_reduction ? money(entry.debt_reduction) : '—'}</td><td className="p-2 font-semibold tabular-nums text-slate-800">{money(entry.balance)}</td></tr>)}</tbody></table>{!statement.currentData.entries.length && <p className="py-6 text-center text-sm text-slate-400">{translate("No credit movements in this period.")}</p>}</div>
      <nav aria-label={translate("Statement pages")} className="flex items-center justify-between gap-3 text-sm"><button disabled={busy||statement.isFetching||page===1} onClick={()=>setPage(page-1)}>{translate("Previous")}</button><span>{translate("Page")} {page}</span><button disabled={busy||statement.isFetching||page*statement.currentData.meta.per_page>=statement.currentData.meta.total} onClick={()=>setPage(page+1)}>{translate("Next")}</button></nav>
      <p className="text-xs text-slate-400">{translate("The running balance is recomputed from the credit, return and collection journals.")}</p>
    </div>}
    <button disabled={busy} onClick={onClose} className="mt-4 text-sm text-slate-500">{translate("Close")}</button>
  </div>;
}
