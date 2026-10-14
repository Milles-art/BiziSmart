import {useEffect,useState} from 'react';
import {useDispatch,useSelector,useStore} from 'react-redux';
import {Dialog} from '@mui/material';
import {AlertTriangle} from 'lucide-react';
import {SectionCard} from '../../components/ui/Primitives';
import {translate,useLanguage} from '../../i18n';
import {errorMessage,useCurrentTillQuery,useSupervisorCheckMutation,type ServerUser,type SessionState} from './api';
import {useOfflineQuarantineQuery,useOfflineCommittedConflictsQuery,usePreviewOfflineReconciliationMutation,useReconcileOfflineSaleMutation} from './offlineApi';
import {offlineReconciliationActions,type OfflineReconciliationState} from './offlineReconciliationState';
import type {OfflineQuarantine,OfflineReconcileInput,OfflineReconcileReview} from './offlineTypes';

const conflictLabels:Record<string,string>={NEGATIVE_STOCK:'Stock went below zero',EXPIRED_BATCH:'Expired batch used',RECALLED_BATCH:'Recalled batch used',QUARANTINED_BATCH:'Held batch used',PRICE_CHANGED:'Cached price changed',PRODUCT_INACTIVE:'Product is now inactive',PACKAGING_VERSION_CHANGED:'Packaging changed',DEVICE_REVOKED:'Device authorisation changed',LEASE_EXPIRED:'Offline authorisation expired',STAFF_REVOKED:'Staff authorisation changed',BRANCH_CHANGED:'Staff branch changed',TILL_CLOSED:'Original till is closed',CAPTURE_TIME_INVALID:'Capture time needs review',SNAPSHOT_INVALID:'Cached product proof needs review',ARITHMETIC_LIMIT:'Exact amount limit needs review'};
const input='w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
const button='rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 disabled:opacity-40';
export function OfflineQuarantine({actor,onInventory}:{actor:ServerUser;onInventory?:()=>void}){
  useLanguage();
  const dispatch=useDispatch(),store=useStore<{session:SessionState}>();
  const pending=useSelector((state:{offlineReconciliation?:OfflineReconciliationState})=>state.offlineReconciliation?.pending??null);
  const quarantine=useOfflineQuarantineQuery(undefined,{skip:actor.role!=='owner'});
  const [page,setPage]=useState(1);
  const flagged=useOfflineCommittedConflictsQuery(page,{skip:actor.role!=='owner',refetchOnMountOrArgChange:true});
  const current=useCurrentTillQuery(undefined,{skip:actor.role!=='owner'});
  const [selected,setSelected]=useState<OfflineQuarantine|null>(null),[reason,setReason]=useState(''),[operationId,setOperationId]=useState('');
  const [review,setReview]=useState<OfflineReconcileReview|null>(null),[reviewBody,setReviewBody]=useState<OfflineReconcileInput|null>(null);
  const [phone,setPhone]=useState(''),[pin,setPin]=useState(''),[code,setCode]=useState(''),[grant,setGrant]=useState('');
  const [busy,setBusy]=useState(false),[error,setError]=useState(''),[notice,setNotice]=useState('');
  const [preview]=usePreviewOfflineReconciliationMutation(),[approve]=useSupervisorCheckMutation(),[reconcile]=useReconcileOfflineSaleMutation();
  useEffect(()=>{if(pending){setOperationId(pending.body.operation_id);setReason(pending.body.reason);}},[pending]);
  if(actor.role!=='owner')return null;
  const till=!current.error?current.currentData?.till:null;
  const open=!!selected||!!pending;
  function close(){if(busy||pending)return;setSelected(null);setReview(null);setReviewBody(null);setGrant('');setPin('');setCode('');setError('');}
  async function inspect(){
    if(!selected||!till||busy)return;setBusy(true);setError('');
    const token=store.getState().session.token;
    const body={operation_id:operationId,till_id:till.id,reason};const request=preview({id:selected.id,body});
    try{const result=await request.unwrap();if(store.getState().session.token===token){setReview(result);setReviewBody(body);setGrant('');}}
    catch(e){if(store.getState().session.token===token)setError(errorMessage(e));}
    finally{request.reset();setBusy(false);}
  }
  async function authorise(){
    if(!review||busy)return;setBusy(true);setError('');const token=store.getState().session.token;
    const request=approve({phone,pin,code,permission:'manage_settings',operation_id:review.operation_id,payload_hash:review.review_hash});setPin('');setCode('');
    try{const result=await request.unwrap();if(store.getState().session.token===token)setGrant(result.approval_token);}
    catch(e){if(store.getState().session.token===token)setError(errorMessage(e));}
    finally{request.reset();setBusy(false);}
  }
  async function commit(){
    const operation=pending??(selected&&review&&reviewBody&&grant?{id:selected.id,body:{...reviewBody,review_hash:review.review_hash,approval_token:grant}}:null);
    if(!operation||busy)return;dispatch(offlineReconciliationActions.submitting(operation));setBusy(true);setError('');
    const token=store.getState().session.token,request=reconcile(operation);
    try{
      const result=await request.unwrap();if(store.getState().session.token!==token)return;
      dispatch(offlineReconciliationActions.settled());setSelected(null);setReview(null);setGrant('');
      setNotice(`${translate('Offline sale reconciled:')} ${result.sale?.receipt_number??result.operation_id}`);void quarantine.refetch();
    }catch(e){
      if(store.getState().session.token!==token)return;
      const status=(e as {status?:number|string}).status;
      if(typeof status==='number'&&status>=400&&status<500&&status!==408){dispatch(offlineReconciliationActions.settled());setReview(null);setGrant('');}
      setError(errorMessage(e));
    }finally{request.reset();setBusy(false);}
  }
  if(!pending&&!selected&&!notice&&!error&&!quarantine.error&&!flagged.error&&quarantine.currentData?.length===0&&flagged.currentData?.sales?.length===0)return null;
  return <SectionCard title={translate('Offline owner review')}><p className="mb-3 text-xs text-slate-500">{translate('Quarantined sales need cash reconciliation. Posted conflicts need stock or receipt review.')}</p>
    {notice&&<p role="status" className="mb-3 text-sm text-emerald-700">{notice}</p>}
    {quarantine.isFetching&&<p role="status" className="text-sm text-slate-500">{translate('Loading quarantined sales…')}</p>}
    {quarantine.error&&<p role="alert" className="text-sm text-red-600">{errorMessage(quarantine.error)} <button className="underline" onClick={()=>void quarantine.refetch()}>{translate('Try again')}</button></p>}
    {!quarantine.error&&!quarantine.isFetching&&!quarantine.currentData?.length&&<p className="text-sm text-slate-400">{translate('No quarantined sales require cash posting.')}</p>}
    <div className="space-y-3">{quarantine.currentData?.map(row=><div key={row.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-100 bg-amber-50 p-3"><div><p className="flex items-center gap-2 break-all text-sm font-semibold text-slate-700"><AlertTriangle className="h-4 w-4 text-amber-500"/>{row.cashier_name??translate('Cashier')} · {row.operation_id}</p><p className="mt-1 text-xs text-amber-700">{row.conflicts.map(conflict=>translate(conflictLabels[conflict]??conflict)).join(' · ')}</p></div><button className={button} disabled={busy||!!pending} onClick={()=>{setSelected(row);setReason('');setOperationId(crypto.randomUUID());setReview(null);setReviewBody(null);setGrant('');setError('');}}>{translate('Review reconciliation')}</button></div>)}</div>
    {pending&&!selected&&<button className={`${button} mt-3`} disabled={busy} onClick={()=>void commit()}>{translate('Retry same reconciliation')}</button>}
    {flagged.error&&<p role="alert" className="my-3 text-sm text-red-600">{errorMessage(flagged.error)} <button className="underline" onClick={()=>void flagged.refetch()}>{translate('Try again')}</button></p>}
    {!flagged.error&&flagged.currentData&&flagged.currentData.sales.length>0&&<section className="mt-5 space-y-3"><h3 className="font-semibold text-slate-700">{translate('Posted offline sales with conflicts')}</h3><p className="text-xs text-slate-500">{translate('These sales are already posted. Review stock and original receipts; do not post their cash again.')}</p>{flagged.currentData.sales.map(sale=><article key={sale.id} className="rounded-xl border border-amber-200 bg-amber-50 p-3"><p className="text-sm font-semibold">{sale.receipt_number} · TSh {sale.total.toLocaleString('en-TZ')}</p><p className="mt-1 text-xs text-slate-500">{sale.cashier_name} · {new Date(sale.captured_at).toLocaleString('en-TZ',{timeZone:'Africa/Dar_es_Salaam'})}</p><p className="mt-2 text-xs text-amber-800">{sale.conflicts.map(conflict=>translate(conflictLabels[conflict]??conflict)).join(' · ')}</p>{sale.owner_reconciled&&<p className="mt-1 text-xs text-slate-500">{translate('Cash posting was reviewed by the owner.')}</p>}</article>)}<div className="flex flex-wrap items-center gap-3">{onInventory&&<button className={button} onClick={onInventory}>{translate('Review inventory')}</button>}<button className={button} disabled={page<=1} onClick={()=>setPage(page-1)}>{translate('Previous')}</button><span className="text-sm">{translate('Page')} {page}</span><button className={button} disabled={page*flagged.currentData.meta.per_page>=flagged.currentData.meta.total} onClick={()=>setPage(page+1)}>{translate('Next')}</button></div></section>}
    <Dialog open={open} onClose={close} fullWidth maxWidth="sm" aria-labelledby="offline-reconcile-title"><div className="p-5"><h2 id="offline-reconcile-title" className="text-lg font-bold text-slate-800">{translate('Reconcile offline cash sale')}</h2>
      {pending?<><p className="mt-3 text-sm text-amber-700">{translate('The reconciliation outcome is uncertain. Retry the identical request before changing any details.')}</p><p className="mt-2 break-all text-xs text-slate-500">{pending.id} · {pending.body.reason}</p></>:review?<><p className="mt-3 text-sm font-bold text-slate-700">{translate('Cash to reconciliation till:')} TSh {review.total.toLocaleString('en-TZ')}</p><p className="mt-2 text-xs text-slate-500">{review.conflicts.map(conflict=>translate(conflictLabels[conflict]??conflict)).join(' · ')} · {new Date(review.captured_at).toLocaleString('en-TZ',{timeZone:'Africa/Dar_es_Salaam'})}</p><p className="mt-2 break-words text-sm text-slate-500">{review.reason}</p>{!grant?<fieldset disabled={busy} className="mt-4 space-y-3"><label className="block text-xs text-slate-500">{translate('Owner phone')}<input type="tel" className={input} value={phone} onChange={event=>setPhone(event.target.value)} autoComplete="off"/></label><label className="block text-xs text-slate-500">{translate('Owner PIN')}<input type="password" inputMode="numeric" className={input} value={pin} onChange={event=>setPin(event.target.value)} maxLength={6} autoComplete="off"/></label><label className="block text-xs text-slate-500">{translate('Owner authenticator code')}<input inputMode="numeric" className={input} value={code} onChange={event=>setCode(event.target.value)} maxLength={6} autoComplete="off"/></label><button className={button} onClick={()=>void authorise()}>{translate('Approve reconciliation')}</button></fieldset>:<p className="mt-3 text-sm text-emerald-700">{translate('Owner reconciliation approval received.')}</p>}</>:<><label className="mt-4 block text-xs text-slate-500">{translate('Reconciliation till')}<select className={input} disabled={busy||!till} value={till?.id??''} onChange={()=>undefined}><option value={till?.id??''}>{till?`${till.branch_name} · ${till.cashier_name}`:translate('Open your own reconciliation till first.')}</option></select></label><label className="mt-3 block text-xs text-slate-500">{translate('Review reason')}<textarea className={input} maxLength={1000} value={reason} disabled={busy} onChange={event=>setReason(event.target.value)}/></label><p className="mt-2 text-xs text-slate-500">{translate('Confirm where the physical cash is held before posting it. This records a reconciliation and sends no bank or mobile transfer.')}</p></>}
      {error&&<p role="alert" className="mt-3 text-sm text-red-600">{error}</p>}<div className="mt-5 flex flex-wrap gap-3"><button className={button} disabled={busy||!!pending} onClick={close}>{translate('Cancel')}</button>{pending?<button className={button} disabled={busy} onClick={()=>void commit()}>{translate('Retry same reconciliation')}</button>:review?<><button className={button} disabled={busy} onClick={()=>{setReview(null);setGrant('');}}>{translate('Change details')}</button><button className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white disabled:opacity-40" disabled={busy||!grant} onClick={()=>void commit()}>{translate('Post reviewed cash')}</button></>:<button className={button} disabled={busy||!till||!reason.trim()} onClick={()=>void inspect()}>{translate('Review reconciliation')}</button>}</div>
    </div></Dialog>
  </SectionCard>;
}

