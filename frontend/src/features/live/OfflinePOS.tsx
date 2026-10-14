import {useEffect,useRef,useState} from 'react';
import {useSelector,useStore} from 'react-redux';
import {Dialog} from '@mui/material';
import {CloudOff,RefreshCw,Search,ShieldCheck,Trash2} from 'lucide-react';
import {translate,useLanguage} from '../../i18n';
import {errorMessage,type ServerUser,type SessionState} from './api';
import {useIssueOfflineLeaseMutation,useSyncOfflineSaleMutation} from './offlineApi';
import {acknowledgeOfflineSale,loadOfflineLease,readOfflineQueue,saveOfflineLease,saveOfflineSale,validateOfflineLease} from './offlineVault';
import {offlineDisplayTotal,parseOfflineCash,type OfflineCartLine} from './offlineDisplay';
import {signOfflineBody} from './offlineProof';
import type {OfflineLease,OfflineQueuedSale} from './offlineTypes';
import ReceiptPrint from './ReceiptPrint';

const input='w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 outline-none focus:border-emerald-400';
const button='rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 disabled:opacity-40';
const money=(value:number)=>`TSh ${value.toLocaleString('en-TZ')}`;
const failure=(error:unknown)=>translate(error instanceof Error?error.message:errorMessage(error));
type Queue=Awaited<ReturnType<typeof readOfflineQueue>>;

export function OfflinePOS({actor,onClose}:{actor?:ServerUser;onClose:()=>void}){
  useLanguage();
  const actorId=actor?.id;
  const store=useStore<{session:SessionState}>();
  const token=useSelector((state:{session:SessionState})=>state.session.token);
  const [lease,setLease]=useState<OfflineLease|null>(null),[queue,setQueue]=useState<Queue>([]);
  const [cart,setCart]=useState<OfflineCartLine[]>([]),[search,setSearch]=useState(''),[cash,setCash]=useState('');
  const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false),[loading,setLoading]=useState(true);
  const [pending,setPending]=useState<OfflineQueuedSale|null>(null);
  const [issue]=useIssueOfflineLeaseMutation(),[sync]=useSyncOfflineSaleMutation();
  const alive=useRef(true);
  const [clock,setClock]=useState(()=>Date.now());
  useEffect(()=>{const timer=setInterval(()=>setClock(Date.now()),15000);return()=>clearInterval(timer);},[]);
  useEffect(()=>{
    alive.current=true;setLoading(true);
    void(async()=>{try{
      const cached=await loadOfflineLease(actorId);
      if(!alive.current)return;setLease(cached);
      const rows=actorId||cached?await readOfflineQueue(actorId??cached!.cashier_id):[];
      if(alive.current)setQueue(rows);
    }catch(e){if(alive.current){setError(failure(e));if(actorId){try{const rows=await readOfflineQueue(actorId);if(alive.current)setQueue(rows);}catch{/* Preserve encrypted records; the visible error remains. */}}}}
    finally{if(alive.current)setLoading(false);}})();
    return()=>{alive.current=false;};
  },[actorId]);
  const valid=!!lease && Date.parse(lease.expires_at)>clock;
  const scopeUser=actor?.id??lease?.cashier_id;
  const refresh=async()=>{if(scopeUser){const rows=await readOfflineQueue(scopeUser);if(alive.current)setQueue(rows);}};
  const display=(()=>{try{return {total:offlineDisplayTotal(cart),error:''};}catch(e){return {total:0,error:failure(e)};}})();
  async function prepare(){
    if(!actor||!token||busy)return;
    const originalToken=store.getState().session.token;setBusy(true);setError('');setNotice('');
    const request=issue();
    try{
      const result=await request.unwrap();if(!alive.current||store.getState().session.token!==originalToken)return;
      await saveOfflineLease(result,actor.id);
      if(!alive.current||store.getState().session.token!==originalToken)return;
      setLease(result);setCart([]);setCash('');setNotice('Offline catalogue prepared for 24 hours.');await refresh();
    }catch(e){if(alive.current&&store.getState().session.token===originalToken)setError(failure(e));}
    finally{request.reset();if(alive.current)setBusy(false);}
  }
  async function capture(){
    if(!lease||busy||(!pending&&!cart.length))return;
    setError('');setNotice('');
    let operation=pending;
    try{
      if(!operation){
        validateOfflineLease(lease,scopeUser);if(display.error)throw new Error(display.error);
        const tendered=parseOfflineCash(cash);if(tendered<display.total)throw new Error('Cash tendered does not cover this offline sale.');
        operation={body:{lease_id:lease.lease_id,device_uid:lease.device_uid,operation_id:crypto.randomUUID(),captured_at:new Date().toISOString(),cash_tendered:tendered,
          items:cart.map(line=>({snapshot_id:line.snapshot.snapshot_id,proof:line.snapshot.proof,qty:line.qty}))},secret:lease.secret,cashier_id:lease.cashier_id,shop_name:lease.shop_name,
          display_lines:cart.map(line=>({name:line.snapshot.name,qty:line.qty,unit:line.snapshot.packaging_name??line.snapshot.unit})),estimated_total:display.total};
        setPending(operation);
      }
      setBusy(true);await saveOfflineSale(lease,operation);
      if(!alive.current)return;
      setPending(null);setCart([]);setCash('');setNotice('Cash sale saved in the encrypted queue. Server confirmation is pending.');await refresh();
    }catch(e){if(alive.current)setError(failure(e));}
    finally{if(alive.current)setBusy(false);}
  }
  async function synchronise(){
    if(busy||!scopeUser)return;setBusy(true);setError('');setNotice('');
    try{
      const rows=await readOfflineQueue(scopeUser);
      for(const row of rows){
        if(row.state==='committed')continue;
        const signature=await signOfflineBody(row.sale.body,row.sale.secret);
        const request=sync({body:row.sale.body,signature});
        try{const result=await request.unwrap();await acknowledgeOfflineSale(row.id,result);}
        finally{request.reset();}
      }
      if(alive.current){setNotice('Saved sales checked with the server. Quarantined sales require owner review.');await refresh();}
    }catch(e){if(alive.current){setError(failure(e));await refresh().catch(()=>undefined);}}
    finally{if(alive.current)setBusy(false);}
  }
  const candidates=(lease?.catalogue??[]).filter(item=>!search||item.name.toLowerCase().includes(search.toLowerCase())||item.barcode===search).slice(0,60);
  const locked=busy||!!pending;
  return <div className="min-h-screen bg-slate-100 p-4 lg:p-6"><div className="mx-auto max-w-7xl space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="flex items-center gap-2 text-xl font-bold text-slate-800"><CloudOff className="h-6 w-6 text-emerald-600"/>{translate('Offline cash checkout')}</h1><p className="mt-1 text-sm text-slate-500">{lease?.shop_name??translate('Encrypted device recovery')}</p></div><button type="button" className={button} disabled={locked} onClick={onClose}>{translate('Return to connected app')}</button></div>
    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="font-semibold">{translate('Cash only. Saved locally until the server confirms it.')}</p><p className="mt-1">{translate('Credit, mobile payments, returns, manual discounts and automatic offers require a connection. Cached regular or scheduled prices are used.')}</p></div>
    {loading&&<p role="status" className="text-sm text-slate-500">{translate('Opening encrypted offline records…')}</p>}
    {error&&<p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>}{notice&&<p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
    <div className="flex flex-wrap items-center gap-3">{actor&&<button type="button" disabled={locked||!token||cart.length>0} onClick={()=>void prepare()} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white disabled:opacity-40">{translate('Prepare offline catalogue')}</button>}<button type="button" disabled={locked||!queue.some(row=>row.state!=='committed')} onClick={()=>void synchronise()} className={button}><RefreshCw className="mr-2 inline h-4 w-4"/>{translate('Synchronise saved sales')}</button>{lease&&<p className="text-xs text-slate-500">{lease.cashier_name} · {lease.branch_name} · {translate('Authorised until')} {new Date(lease.expires_at).toLocaleString('en-TZ',{timeZone:'Africa/Dar_es_Salaam'})} {translate('(Tanzania)')}</p>}</div>
    {!lease&&!loading&&<p className="rounded-xl bg-white p-4 text-sm text-slate-500">{translate(actor?'Prepare this device while connected and with your own till open. Preserved sales can still be synchronised.':'No valid capture catalogue is available. Sign in online to recover preserved sales.')}</p>}
    {lease&&!valid&&<p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate('The offline authorisation expired. New capture is blocked; saved sales are preserved.')}</p>}
    {(valid||pending)&&lease&&<div className="grid gap-5 lg:grid-cols-[1fr_380px]"><section className="rounded-xl border border-slate-200 bg-white p-4"><div className="mb-4 flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 text-slate-400"/><input className="min-w-0 flex-1 bg-transparent text-sm outline-none" aria-label={translate('Search cached products')} placeholder={translate('Search name or exact barcode')} value={search} disabled={locked} onChange={event=>setSearch(event.target.value)}/></div><p className="mb-3 text-xs text-slate-500">{lease.catalogue.length} {translate('signed product/pack choices cached')}{lease.truncated&&` · ${translate('Only the first 500 active products were cached.')}`}</p><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{candidates.map(item=><button key={item.snapshot_id} type="button" disabled={locked} onClick={()=>setCart(lines=>[...lines,{snapshot:item,qty:'1'}])} className="rounded-xl border border-slate-200 p-3 text-left hover:border-emerald-400 disabled:opacity-40"><p className="break-words font-semibold text-slate-700">{item.name}</p><p className="mt-1 text-xs text-slate-400">{item.packaging_name??item.unit} · {item.barcode??'—'}</p><p className="mt-2 text-sm font-bold text-emerald-600">{money(item.unit_price)}</p><p className="mt-1 text-xs text-slate-400">{translate('Cached stock:')} {item.stock} {item.unit}</p></button>)}</div>{!candidates.length&&<p className="py-5 text-sm text-slate-500">{translate('No cached products match.')}</p>}</section><section className="rounded-xl border border-slate-200 bg-white p-4"><h2 className="mb-4 font-bold text-slate-700">{translate('Cash cart')}</h2><div className="space-y-3">{cart.map((line,index)=><div key={`${line.snapshot.snapshot_id}:${index}`} className="rounded-lg border border-slate-100 p-3"><div className="flex items-start justify-between gap-2"><p className="break-words text-sm font-semibold text-slate-700">{line.snapshot.name}</p><button type="button" disabled={locked} aria-label={`${translate('Remove')} ${line.snapshot.name}`} onClick={()=>setCart(lines=>lines.filter((_,position)=>position!==index))} className="text-red-500"><Trash2 className="h-4 w-4"/></button></div><label className="mt-2 block text-xs text-slate-500">{translate('Quantity')} ({line.snapshot.packaging_name??line.snapshot.unit})<input className={input} inputMode={line.snapshot.quantity_type==='weight'&&!line.snapshot.packaging_id?'decimal':'numeric'} value={line.qty} disabled={locked} onChange={event=>setCart(lines=>lines.map((entry,position)=>position===index?{...entry,qty:event.target.value}:entry))}/></label></div>)}</div>{display.error&&<p role="alert" className="mt-3 text-sm text-red-600">{display.error}</p>}<p className="mt-4 flex justify-between font-bold text-slate-800"><span>{translate('Provisional total')}</span><span>{money(display.total)}</span></p><label className="mt-4 block text-xs text-slate-500">{translate('Cash tendered (TSh)')}<input className={input} inputMode="numeric" value={cash} disabled={locked} onChange={event=>setCash(event.target.value)}/></label><button type="button" disabled={busy||(!pending&&(!cart.length||!!display.error))} onClick={()=>void capture()} className="mt-4 w-full rounded-lg bg-emerald-500 py-3 text-sm font-bold text-white disabled:opacity-40">{translate(pending?'Retry identical local capture':'Save cash sale locally')}</button><p className="mt-2 text-xs text-slate-400">{translate('This is not a confirmed server receipt. Keep the device data until synchronisation succeeds.')}</p></section></div>}
    <section className="rounded-xl border border-slate-200 bg-white p-4"><h2 className="mb-3 flex items-center gap-2 font-bold text-slate-700"><ShieldCheck className="h-4 w-4 text-emerald-500"/>{translate('Durable saved sales')}</h2>{!queue.length&&<p className="text-sm text-slate-400">{translate('No saved offline sales for this cashier.')}</p>}<div className="space-y-3">{queue.map(row=><div key={row.id} className="rounded-lg border border-slate-100 p-3"><div className="flex flex-wrap justify-between gap-2"><span className="break-all text-xs text-slate-500">{row.id}</span><span className="text-sm font-semibold text-slate-700">{translate(row.state==='pending'?'Awaiting server confirmation':row.state==='quarantined'?'Quarantined — owner review':'Server confirmed')} · {money(row.sale.result?.sale?.total??row.sale.estimated_total)}</span></div><p className="mt-1 text-xs text-slate-500">{row.sale.display_lines.map(line=>`${line.name}: ${line.qty} ${line.unit}`).join(' · ')}</p>{row.sale.result?.conflicts.length? <p className="mt-2 text-xs text-amber-700">{row.sale.result.conflicts.join(' · ')}</p>:null}{row.sale.result?.sale&&<ReceiptPrint sale={row.sale.result.sale} shopName={row.sale.shop_name}/>}</div>)}</div></section>
  </div></div>;
}

/** Works independently of /me; cached leases authorize only the offline cash pane, never the normal workspace. */
export function OfflineRecoveryButton(){
  useLanguage();const [open,setOpen]=useState(false);
  return <><button type="button" className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600" onClick={()=>setOpen(true)}>{translate('Recover offline cash sales')}</button><Dialog open={open} fullScreen><OfflinePOS onClose={()=>setOpen(false)}/></Dialog></>;
}


