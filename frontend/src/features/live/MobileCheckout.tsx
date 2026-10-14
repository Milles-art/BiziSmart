import { translate, useLanguage } from '../../i18n';
import { useEffect, useState } from 'react';
import {DiscountApproval} from './DiscountApproval';
import { Dialog } from '@mui/material';
import { useDispatch, useSelector, useStore } from 'react-redux';
import { checkoutActions, type CheckoutState } from './checkoutState';
import { errorMessage,useMobileCompletionReviewMutation, useBeginMobilePaymentMutation, useCompleteMobilePaymentMutation, useMobilePaymentQuery, usePreviewSaleMutation, type SaleReview, type SessionState } from './api';
const money=(n:number)=>`TSh ${n.toLocaleString('en-TZ')}`;
const input='mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
export function MobileCheckout({open,base,onClose}:{open:boolean;base:SaleReview|null;onClose:()=>void}) {
  useLanguage();
  const dispatch=useDispatch();const store=useStore<{session:SessionState}>();
  const pending=useSelector((s:{checkout:CheckoutState})=>s.checkout.mobile);
  const [amount,setAmount]=useState('');const [cash,setCash]=useState('0');const [name,setName]=useState('');const [email,setEmail]=useState('');const [phone,setPhone]=useState('');
  const [review,setReview]=useState<SaleReview|null>(null);const [busy,setBusy]=useState(false);const [failure,setFailure]=useState('');
  const [operationId,setOperationId]=useState('');const [grant,setGrant]=useState<string|null>(null);const [completionReview,setCompletionReview]=useState<{operation_id:string;review:SaleReview}|null>(null);
  const [getCompletionReview]=useMobileCompletionReviewMutation();
  const [preview]=usePreviewSaleMutation();const [begin]=useBeginMobilePaymentMutation();const [complete]=useCompleteMobilePaymentMutation();
  const payment=useMobilePaymentQuery(pending?.id??'',{skip:!open||!pending?.id,pollingInterval:3000});
  useEffect(()=>{if(open && base && !pending){setAmount(String(base.total-(base.credit_amount??0)));setCash('0');setReview(null);setFailure('');}},[open,base,pending]);
  useEffect(()=>{const guard=(e:BeforeUnloadEvent)=>{if(pending){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',guard);return()=>window.removeEventListener('beforeunload',guard);},[pending]);
  async function inspect(){
    if(!base||busy)return;setFailure('');
    if(!/^[1-9][0-9]*$/.test(amount)||!Number.isSafeInteger(Number(amount))||!/^(0|[1-9][0-9]*)$/.test(cash)||!Number.isSafeInteger(Number(cash))){setFailure('Enter whole TSh amounts.');return;}
    const token=store.getState().session.token;setBusy(true);
    const p=preview({branch_id:base.branch_id,till_id:base.till_id,customer_id:base.customer_id,credit_amount:base.credit_amount??0,mobile_amount:Number(amount),loyalty_points:base.loyalty_redemption?.points??0,discount:base.manual_cart_discount??base.discount,discount_reason:base.discount_reason,items:base.lines.map(l=>({product_id:l.product_id,qty:l.qty,packaging_id:l.packaging_id,discount:l.line_discount??l.discount}))});
    try{const r=await p.unwrap();if(store.getState().session.token===token){setReview(r);setOperationId(crypto.randomUUID());setGrant(null);}}catch(e){if(store.getState().session.token===token)setFailure(errorMessage(e));}finally{p.reset();setBusy(false);}
  }
  async function request(){
    if(busy||(!review&&!pending?.body))return;const token=store.getState().session.token;if(!token)return;
    const normalized=phone.startsWith('0')?`255${phone.slice(1)}`:phone;
    if(!pending && !/^255[67][0-9]{8}$/.test(normalized)){setFailure('Enter a Tanzania mobile number.');return;}
    if(!pending&&review?.lines.some(l=>l.discount>0)&&!grant){setFailure('Approve this discount before requesting payment.');return;}
    const operation=pending??{key:operationId,id:null,body:{sale:{discount:review!.discount,discount_reason:review!.discount_reason,...(grant?{approval_token:grant}:{}),branch_id:review!.branch_id,till_id:review!.till_id,customer_id:review!.customer_id,credit_amount:review!.credit_amount??0,mobile_amount:review!.mobile_amount,items:review!.lines.map(l=>({product_id:l.product_id,qty:l.qty,packaging_id:l.packaging_id,discount:l.line_discount??l.discount})),review_hash:review!.review_hash,cash_tendered:Number(cash)},buyer_name:name.trim(),buyer_email:email.trim(),phone:normalized}};
    if(!operation.key||!operation.body)return;
    dispatch(checkoutActions.mobileSubmitting(operation));setBusy(true);setFailure('');
    const p=begin({key:operation.key,body:operation.body});
    try{const result=await p.unwrap();if(store.getState().session.token!==token)return;dispatch(checkoutActions.mobileSubmitting({key:null,body:null,id:result.id}));setName('');setEmail('');setPhone('');setGrant(null);setCompletionReview(null);}
    catch(e){if(store.getState().session.token!==token)return;const status=(e as {status?:number|string}).status;if(typeof status==='number'&&status>=400&&status<500&&status!==408)dispatch(checkoutActions.mobileRejected());setFailure(errorMessage(e));}
    finally{p.reset();setBusy(false);}
  }
  async function finish(){
    if(!pending?.id||busy)return;const token=store.getState().session.token;setBusy(true);setFailure('');const p=complete(grant?{id:pending.id,approval_token:grant}:pending.id);
    try{const r=await p.unwrap();if(store.getState().session.token!==token)return;dispatch(checkoutActions.confirmed(r));onClose();}
    catch(e){if(store.getState().session.token===token)setFailure(errorMessage(e)+' The recorded payment remains available for review.');}
    finally{p.reset();setBusy(false);}
  }
  async function reviewCompletion(){if(!pending?.id||busy)return;const token=store.getState().session.token;setBusy(true);setFailure('');const p=getCompletionReview(pending.id);try{const result=await p.unwrap();if(store.getState().session.token===token){setCompletionReview(result);setGrant(null);}}catch(e){if(store.getState().session.token===token)setFailure(errorMessage(e));}finally{p.reset();setBusy(false);}}
  const data=payment.currentData;
  return <Dialog open={open} onClose={()=>{if(!busy)onClose();}} fullWidth maxWidth="sm" aria-labelledby="mobile-title" slotProps={{paper:{sx:{borderRadius:'16px'}}}}><div className="p-6">
    <h2 id="mobile-title" className="text-lg font-bold text-slate-800">{translate("Selcom payment")}</h2>
    {!pending && <form onSubmit={e=>{e.preventDefault();void(review?request():inspect());}} className="mt-4 space-y-3">
      <label className="block text-xs text-slate-500">{translate("Mobile amount (TSh)")}<input disabled={busy||!!review} required inputMode="numeric" value={amount} onChange={e=>setAmount(e.target.value)} className={input}/></label>
      <label className="block text-xs text-slate-500">{translate("Cash tendered (TSh)")}<input disabled={busy||!!review} required inputMode="numeric" value={cash} onChange={e=>setCash(e.target.value)} className={input}/></label>
      <label className="block text-xs text-slate-500">{translate("Payer name")}<input disabled={busy} required value={name} onChange={e=>setName(e.target.value)} className={input}/></label>
      <label className="block text-xs text-slate-500">{translate("Payer email")}<input disabled={busy} type="email" required value={email} onChange={e=>setEmail(e.target.value)} className={input}/></label>
      <label className="block text-xs text-slate-500">{translate("Payer phone")}<input disabled={busy} required inputMode="tel" value={phone} onChange={e=>setPhone(e.target.value)} className={input}/></label>
      {review && <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800"><p>{translate("Total:")} {money(review.total)}</p><p>{translate("Mobile:")} {money(review.mobile_amount??0)}</p><p>{translate("Cash required:")} {money(review.cash_allocated??0)}</p><p>{translate("Credit:")} {money(review.credit_amount??0)}</p></div>}
      {review?.lines.some(l=>l.discount>0)&&!grant&&<DiscountApproval operationId={operationId} reviewHash={review.review_hash} onApproved={setGrant} disabled={busy}/>}
      {grant&&<p className="text-sm text-emerald-700">{translate("Discount approved.")}</p>}
      <button disabled={busy||!!review?.lines.some(l=>l.discount>0)&&!grant} className="w-full rounded-lg bg-emerald-500 px-4 py-3 text-sm font-bold text-white disabled:opacity-40">{busy?translate("Checking…"):review?translate("Send payment request"):translate("Review mobile payment")}</button>
      {review && <button disabled={busy} type="button" onClick={()=>setReview(null)} className="text-sm text-slate-500">{translate("Edit payment")}</button>}
    </form>}
    {pending && <div className="mt-4 space-y-3 text-sm text-slate-600">
      {!pending.id && <><p>{translate("Request outcome is not confirmed. Recover the same payment request.")}</p><button disabled={busy} onClick={()=>void request()} className="font-semibold text-emerald-600">{translate("Recover same request")}</button></>}
      {pending.id && !data && !payment.error && <p role="status">{translate("Checking the recorded payment…")}</p>}
      {data && <><p className="text-2xl font-bold text-emerald-600">{money(data.amount)}</p><p>{translate("Phone ending")} {data.phone_suffix}</p><p role="status">{data.status==='confirmed'?translate("Payment verified. Complete the recorded sale."):data.status==='allocated'?translate("Payment already allocated to its receipt."):data.status==='failed'?translate("The provider cancelled or rejected this payment."):data.status==='manual_refunded'?translate("The owner recorded a completed external refund. This payment cannot fund a sale."):translate("Awaiting provider confirmation. Do not request payment again.")}</p>
        {data.reference && <p>{translate("Reference:")} {data.reference}</p>}
        {data.owner_review_required && <p className="rounded-lg bg-amber-50 p-3 text-amber-800">{translate("Paid money has not been allocated. Ask the owner to review it.")}</p>}
        {data.status==='confirmed'&&data.discount_required&&!grant&&(completionReview?<DiscountApproval operationId={completionReview.operation_id} reviewHash={completionReview.review.review_hash} onApproved={setGrant} disabled={busy}/>:<button disabled={busy} onClick={()=>void reviewCompletion()} className="text-sm font-semibold text-emerald-700 underline">{translate("Review recorded discount for fresh approval")}</button>)}
        {(data.status==='confirmed'||data.status==='allocated') && <button disabled={busy||(data.status==='confirmed'&&data.discount_required&&!grant)} onClick={()=>void finish()} className="w-full rounded-lg bg-emerald-500 px-4 py-3 font-bold text-white">{busy?translate("Completing…"):translate("Complete recorded sale")}</button>}
        {(data.status==='failed'||data.status==='manual_refunded') && <button disabled={busy} onClick={()=>{dispatch(checkoutActions.mobileRejected());onClose();}} className="font-semibold text-slate-600">{translate("Return to cart")}</button>}
      </>}
      {payment.error && <p role="alert" className="text-red-600">{errorMessage(payment.error)} <button onClick={()=>void payment.refetch()} className="underline">{translate("Check again")}</button></p>}
    </div>}
    {failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}
    <button disabled={busy} onClick={onClose} className="mt-4 text-sm text-slate-500">{translate("Close payment")}</button>
  </div></Dialog>;
}



