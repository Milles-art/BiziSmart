import { translate, useLanguage } from '../../i18n';
import { useState } from 'react';
import { useDispatch,useSelector,useStore } from 'react-redux';
import { Dialog } from '@mui/material';
import { errorMessage,type SessionState } from './api';
import { useHeldCartsQuery,useOperateHoldMutation,type HeldDraft,type HeldCart,type HoldOperation } from './heldApi';
import { heldActions,type HeldState } from './heldState';

export default function HeldCarts({open,branchId,draft,locked,onClose,onSaved,onResume}:{open:boolean;branchId:string|null;draft:HeldDraft|null;locked:boolean;onClose:()=>void;onSaved:()=>void;onResume:(cart:HeldCart)=>Promise<void>}) {
  useLanguage();
  const dispatch=useDispatch(); const store=useStore<{session:SessionState}>();
  const pending=useSelector((s:{held?:HeldState})=>s.held?.pending??null);
  const carts=useHeldCartsQuery(branchId??'',{skip:!open || !branchId});
  const [operate]=useOperateHoldMutation(); const [busy,setBusy]=useState(false); const [error,setError]=useState('');
  const execute=async(operation:HoldOperation)=>{
    if(busy || locked)return;
    const token=store.getState().session.token;if(!token)return;
    dispatch(heldActions.submitting(operation));setBusy(true);setError('');
    const request=operate(operation);
    let recorded=false;
    try {
      const result=await request.unwrap();if(store.getState().session.token!==token)return;
      recorded=true;
      if(operation.action==='resume')await onResume(result);
      else if(operation.action==='save')onSaved();
      if(store.getState().session.token!==token)return;
      dispatch(heldActions.settled());
      if(operation.action!=='delete')onClose();
    } catch(e) {
      if(store.getState().session.token!==token)return;
      const status=(e as {status?:number|string}).status;
      // A successful single-use handoff must remain recoverable when subsequent catalogue hydration fails.
      if(!recorded && typeof status==='number' && status>=400 && status<500 && status!==408)dispatch(heldActions.settled());
      setError(errorMessage(e));
    } finally {request.reset();setBusy(false);}
  };
  return <Dialog open={open || !!pending} onClose={()=>{if(!pending && !busy)onClose();}} maxWidth="sm" fullWidth aria-labelledby="held-carts-title"><div className="p-6">
    <h2 id="held-carts-title" className="text-lg font-bold text-slate-800">{translate("Held carts")}</h2>
    <p className="mt-2 text-sm text-slate-500">{translate("Carts expire after 24 hours. Stock is not reserved; prices, stock and approvals are checked again at checkout.")}</p>
    {error && <p role="alert" className="mt-3 text-sm text-red-600">{translate(error)}</p>}
    {pending ? <div className="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-800"><p>{translate("This hold operation is not confirmed. Retry it before editing the cart.")}</p><button disabled={busy || locked} onClick={()=>void execute(pending)} className="mt-3 rounded-lg bg-emerald-500 px-4 py-2 font-semibold text-white disabled:opacity-40">{busy?translate("Confirming…"):translate("Retry same hold operation")}</button></div> : <>
      <button disabled={busy || locked || !branchId || !draft?.items.length} onClick={()=>{if(branchId && draft)void execute({action:'save',key:crypto.randomUUID(),body:{...draft,branch_id:branchId}});}} className="mt-4 w-full rounded-lg bg-emerald-500 px-4 py-3 text-sm font-bold text-white disabled:opacity-40">{translate("Hold current cart")}</button>
      {carts.isLoading && <p className="mt-4 text-sm text-slate-500">{translate("Loading held carts…")}</p>}
      {carts.isError && <div role="alert" className="mt-4 text-sm text-red-600">{errorMessage(carts.error)}<button onClick={()=>void carts.refetch()} className="ml-3 underline">{translate("Try again")}</button></div>}
      {carts.data?.length===0 && <p className="mt-4 text-sm text-slate-500">{translate("No held carts in this branch.")}</p>}
      <div className="mt-4 space-y-3">{carts.data?.map(cart=><div key={cart.id} data-testid={`held-${cart.id}`} className="rounded-lg border border-slate-200 p-3"><p className="text-sm font-semibold text-slate-700">{cart.draft.items.length} {translate("product lines")}</p><p className="mt-1 text-xs text-slate-500">{translate("Held")} {new Date(cart.created_at).toLocaleString()} {translate("· expires")} {new Date(cart.expires_at).toLocaleString()}</p><div className="mt-3 flex gap-3"><button disabled={busy || locked || !!draft?.items.length} onClick={()=>void execute({action:'resume',id:cart.id,key:crypto.randomUUID()})} className="rounded-lg border border-emerald-200 px-3 py-2 text-sm font-semibold text-emerald-700 disabled:opacity-40">{translate("Resume cart")}</button><button disabled={busy || locked} onClick={()=>{if(window.confirm('Delete this held cart?'))void execute({action:'delete',id:cart.id,key:crypto.randomUUID()});}} className="rounded-lg px-3 py-2 text-sm text-red-600 disabled:opacity-40">{translate("Delete")}</button></div></div>)}</div>
      {!!draft?.items.length && <p className="mt-3 text-xs text-slate-500">{translate("Hold or clear the current cart before resuming another.")}</p>}
      <button disabled={busy} onClick={onClose} className="mt-5 text-sm text-slate-500">{translate("Close")}</button>
    </>}
  </div></Dialog>;
}



