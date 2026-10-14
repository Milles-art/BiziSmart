import {useCustomerLoyaltyQuery} from './incentivesApi';
import {useScaleProfileQuery,useDecodeScaleLabelMutation,useResolveLoyaltyCardMutation} from './displayLoyaltyApi';
import {CustomerDisplay} from './CustomerDisplay';
import {usePlanFeature} from './usePlanFeature';
import { translate, useLanguage } from '../../i18n';
import { useEffect, useState,useRef,useCallback, type FormEvent } from 'react';
import { useDispatch, useSelector, useStore } from 'react-redux';
import ReceiptPrint from './ReceiptPrint';
import HeldCarts from './HeldCarts';
import type {HeldCart} from './heldApi';
import type {HeldState} from './heldState';
import {ProductImage} from './ProductImage';
import {usePrivateProductImageLoader} from './productImagesApi';
import {DiscountApproval} from './DiscountApproval';
import {CameraScanner} from './CameraScanner';
import {useBarcodeKeyboard} from './useBarcodeKeyboard';
import {Returns} from './Returns';
import { MobileCheckout } from './MobileCheckout';
import { PaymentRecovery } from './PaymentRecovery';
import { MobilePaymentStatus } from './MobilePaymentStatus';
import {type ReturnState} from './returnState';
import { Dialog } from '@mui/material';
import { Search, ShoppingCart, Trash2, Wallet, RotateCcw, Pause, Percent } from 'lucide-react';
import { checkoutActions, type CheckoutState } from './checkoutState';
import { errorMessage,liveApi,useLazyProductQuery,useLazyCustomerQuery, useProductsQuery, useCurrentTillQuery, usePreviewSaleMutation, useCompleteSaleMutation, type ServerUser, type CatalogueFilters, type SaleReview, type SessionState, useCustomersQuery, usePaymentCapabilitiesQuery } from './api';
import type { Screen } from '../../types';

const money = (amount: number) => `TSh ${amount.toLocaleString('en-TZ')}`;
const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
function checkoutError(error: unknown): string {
  const e = error as { data?: { error?: { code?: string; details?: { available_qty?: string } } } };
  if (e.data?.error?.code === 'PACKAGING_VERSION_CHANGED') return 'This pack changed. Refresh the catalogue, remove its old cart line and add the current pack.';
  const available = e.data?.error?.details?.available_qty;
  return errorMessage(error) + (available === undefined ? '' : ` Available quantity: ${available}.`);
}
export function POS({ actor, shopName, onNavigate }: { actor: ServerUser; shopName: string; onNavigate: (screen: Screen) => void }) {
  useLanguage();
  const dispatch = useDispatch();
  const store = useStore<{ session: SessionState }>();
  const cart = useSelector((s: { checkout: CheckoutState }) => s.checkout);
  const returnState = useSelector((s: {returns:ReturnState})=>s.returns);
  const heldPending=useSelector((s:{held?:HeldState})=>s.held?.pending??null);
  const [heldOpen,setHeldOpen]=useState(false);const [discountOpen,setDiscountOpen]=useState(false);
  const [discountDraft,setDiscountDraft]=useState('0');const [reasonDraft,setReasonDraft]=useState('');const [lineDraft,setLineDraft]=useState<Record<string,string>>({});
  const [saleKey,setSaleKey]=useState('');const [discountGrant,setDiscountGrant]=useState<string|null>(null);
  const [loadProduct]=useLazyProductQuery();const [loadCustomer]=useLazyCustomerQuery();const searchRef=useRef<HTMLInputElement>(null);
  const [lookupBarcode] = liveApi.useLazyProductsQuery();
  const scalesEnabled=usePlanFeature('scales');const displayEnabled=usePlanFeature('display');const loyaltyEnabled=usePlanFeature('loyalty');
  const scaleProfile=useScaleProfileQuery(undefined,{skip:!scalesEnabled});const [decodeScale]=useDecodeScaleLabelMutation();const [resolveCard]=useResolveLoyaltyCardMutation();
  const scanQueue = useRef<{ barcode: string; token: string }[]>([]);
  const scanning = useRef(false); const scanMounted = useRef(true); const scanGeneration = useRef(0);
  const scanRequest = useRef<ReturnType<typeof lookupBarcode>|ReturnType<typeof decodeScale>|ReturnType<typeof resolveCard>|null>(null);
  const [scanCount, setScanCount] = useState(0);
  const loadImage=usePrivateProductImageLoader();
  const capabilities=usePaymentCapabilitiesQuery();
  const [mobileOpen,setMobileOpen]=useState(false);const [mobileBase,setMobileBase]=useState<SaleReview|null>(null);
  const [returnOpen,setReturnOpen] = useState(false);
  const [search, setSearch] = useState(''); const [filters, setFilters] = useState<CatalogueFilters>({ page: 1 });
  const [barcodeMode, setBarcodeMode] = useState(false);
  const [loyaltyPoints,setLoyaltyPoints]=useState('0');
  const [customerOpen,setCustomerOpen] = useState(false); const [customerSearch,setCustomerSearch] = useState(''); const [customerQuery,setCustomerQuery] = useState('');
  const [customer,setCustomer] = useState<{id:string;name:string}|null>(null); const [credit,setCredit] = useState('0');
  const loyalty=useCustomerLoyaltyQuery(customer?.id??'',{skip:!customer||!loyaltyEnabled});
  useEffect(()=>{setLoyaltyPoints('0');},[customer?.id]);
  useEffect(()=>{if(!loyaltyEnabled)setLoyaltyPoints('0');},[loyaltyEnabled]);
  useEffect(()=>{if(!cart.entries.length)setLoyaltyPoints('0');},[cart.entries.length]);
  const customers = useCustomersQuery({page:1,search:customerQuery},{skip:!customerOpen});
  const products = useProductsQuery(filters); const current = useCurrentTillQuery();
  const [preview] = usePreviewSaleMutation(); const [complete] = useCompleteSaleMutation();
  const [review, setReview] = useState<SaleReview | null>(null); const [cash, setCash] = useState('');
  const [busy, setBusy] = useState(false); const [failure, setFailure] = useState('');
  const financialLocked = busy || !!cart.pending || !!cart.mobile || !!returnState.pending || !!heldPending;
  const locked = financialLocked || scanCount > 0;
  const scanContext = useRef({ blocked: false, tillId: null as string | null });
  scanContext.current = { blocked: financialLocked || !!review || returnOpen || heldOpen || discountOpen || mobileOpen || !!cart.receipt, tillId: !current.error ? current.data?.till?.id ?? null : null };
  useEffect(() => {
    const generation = scanGeneration.current;
    scanMounted.current = true;
    return () => { scanMounted.current = false; scanGeneration.current = generation + 1; scanQueue.current = []; scanning.current = false; scanRequest.current?.abort();if(scanRequest.current&&'unsubscribe' in scanRequest.current)scanRequest.current.unsubscribe(); scanRequest.current = null; };
  }, []);
  useEffect(() => {
    // Browser reload cannot persist shop data under the approved session contract.
    const guard = (e: BeforeUnloadEvent) => { if (cart.pending || cart.mobile || heldPending) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', guard); return () => window.removeEventListener('beforeunload', guard);
  }, [cart.pending, cart.mobile,heldPending]);
  async function inspect() {
    if (!current.data?.till || locked || cart.entries.length === 0) return;
    setFailure('');
    if (!/^(0|[1-9][0-9]*)$/.test(credit) || !Number.isSafeInteger(Number(credit))) {setFailure('Enter credit as whole TSh.');return;}
    setBusy(true);
    const p = preview({ branch_id: current.data.till.branch_id, till_id: current.data.till.id, ...(customer ? {customer_id:customer.id,credit_amount:Number(credit)} : {}),
      loyalty_points:Number(loyaltyPoints),discount:cart.discount??0,discount_reason:cart.discount_reason||(Number(loyaltyPoints)>0?'Loyalty redemption':undefined),items: cart.entries.map((r) => ({ product_id: r.product.id, qty: r.qty, packaging_id: r.packaging_id,discount:r.discount??0 })) });
    try { setReview(await p.unwrap()); setSaleKey(crypto.randomUUID());setDiscountGrant(null);setCash(''); } catch (e) { setFailure(errorMessage(e)); } finally { p.reset(); setBusy(false); }
  }
  async function pay() {
    if (busy || (!review && !cart.pending)) return;
    if(!cart.pending&&review?.lines.some(l=>l.discount>0)&&!discountGrant){setFailure('Approve this discount before collecting payment.');return;}
    const sessionToken = store.getState().session.token;
    if (!sessionToken) return;
    const tendered = Number(cash);
    if (!cart.pending && (!/^(0|[1-9][0-9]*)$/.test(cash) || !Number.isSafeInteger(tendered))) { setFailure('Enter cash tendered as whole TSh.'); return; }
    const operation = cart.pending ?? { key: saleKey, body: { loyalty_points:review!.loyalty_redemption?.points??0,discount:review!.manual_cart_discount??review!.discount,discount_reason:review!.discount_reason,...(discountGrant?{approval_token:discountGrant}:{}),branch_id: review!.branch_id, till_id: review!.till_id, ...(review!.customer_id ? {customer_id:review!.customer_id,credit_amount:review!.credit_amount??0} : {}),
      items: review!.lines.map((r) => ({ product_id: r.product_id, qty: r.qty, packaging_id: r.packaging_id,discount:r.line_discount??r.discount })), cash_tendered: tendered, review_hash: review!.review_hash } };
    dispatch(checkoutActions.submitting(operation)); setBusy(true); setFailure('');
    const p = complete(operation);
    try { const result = await p.unwrap(); if (store.getState().session.token !== sessionToken) return; dispatch(checkoutActions.confirmed(result)); setReview(null); setCash(''); setCustomer(null); setCredit('0'); }
    catch (e) {
      if (store.getState().session.token !== sessionToken) return;
      const status = (e as { status?: number | string }).status;
      if (typeof status === 'number' && status >= 400 && status < 500 && status !== 408) { dispatch(checkoutActions.rejected()); setReview(null); }
      setFailure(checkoutError(e));
    } finally { p.reset(); setBusy(false); }
  }
  async function resumeHeld(held:HeldCart){const token=store.getState().session.token;const entries=await Promise.all(held.draft.items.map(async l=>({key:crypto.randomUUID(),product:await loadProduct(l.product_id).unwrap(),qty:l.qty,packaging_id:l.packaging_id??null,discount:l.discount??0})));const c=held.draft.customer_id?await loadCustomer(held.draft.customer_id).unwrap():null;if(store.getState().session.token!==token)return;dispatch(checkoutActions.restore({entries,discount:held.draft.discount??0,discount_reason:held.draft.discount_reason??''}));setCustomer(c?{id:c.id,name:c.name}:null);setCredit(String(held.draft.credit_amount??0));setReview(null);setDiscountGrant(null);}
  const drainScans = useCallback(async () => {
    if (scanning.current) return;
    scanning.current = true;
    const generation = scanGeneration.current;
    try {
      while (scanQueue.current.length && scanMounted.current && generation === scanGeneration.current) {
        const queued = scanQueue.current[0];
        const tillId = scanContext.current.tillId;
        if (store.getState().session.token !== queued.token || scanContext.current.blocked || !tillId) {
          scanQueue.current = [];
          if (store.getState().session.token === queued.token) setFailure('Scanning stopped because checkout or your till changed. Scan the remaining items again.');
          break;
        }
        setSearch(queued.barcode); setBarcodeMode(true);
        const isCard=queued.barcode.startsWith('BSL:');if(isCard&&!loyaltyEnabled){setFailure('Loyalty is disabled for this shop.');scanQueue.current.shift();setScanCount(scanQueue.current.length);continue;}const isScale=scalesEnabled&&!isCard&&queued.barcode.length===13&&!!scaleProfile.currentData?.profile&&queued.barcode.startsWith(scaleProfile.currentData.profile.prefix);
        // Card identifiers travel only in the authenticated POST body, never catalogue URLs.
        if(!isCard&&!isScale)setFilters({page:1,barcode:queued.barcode});
        type ScanRequest=ReturnType<typeof lookupBarcode>|ReturnType<typeof decodeScale>|ReturnType<typeof resolveCard>;
        let request:ScanRequest = isCard?resolveCard(queued.barcode):lookupBarcode({ page: 1, barcode: queued.barcode }, false);
        scanRequest.current = request;
        try {
          let result:Awaited<ReturnType<ScanRequest['unwrap']>> = await request.unwrap();
          if (!scanMounted.current || generation !== scanGeneration.current || store.getState().session.token !== queued.token) break;
          if (scanContext.current.blocked || scanContext.current.tillId !== tillId) {
            scanQueue.current = []; setFailure('Scanning stopped because checkout or your till changed. Scan the remaining items again.'); break;
          }
          // An exact registered item or pack takes precedence over a scale prefix.
          if(isScale&&'products' in result&&!result.products.some(product=>product.barcode===queued.barcode||product.selected_packaging?.barcode===queued.barcode)){
            if('unsubscribe' in request)request.unsubscribe();
            request=decodeScale(queued.barcode);scanRequest.current=request;result=await request.unwrap();
            if (!scanMounted.current || generation !== scanGeneration.current || store.getState().session.token !== queued.token) break;
            if(scanContext.current.blocked||scanContext.current.tillId!==tillId){scanQueue.current=[];setFailure('Scanning stopped because checkout or your till changed. Scan the remaining items again.');break;}
          }
          if(isCard&&'name' in result){setCustomer({id:result.id,name:result.name});setCredit('0');setReview(null);setDiscountGrant(null);scanQueue.current.shift();setScanCount(scanQueue.current.length);continue;}
          const product = 'products' in result?result.products.find(product => product.is_active && (product.barcode === queued.barcode || product.selected_packaging?.barcode === queued.barcode)):'product' in result?result.product:null;
          if (!product) throw new Error('This barcode does not identify an available product.');
          dispatch(checkoutActions.add({ key: crypto.randomUUID(), product, qty: 'qty' in result?result.qty:'1', packaging_id: product.selected_packaging?.id ?? null }));
          setReview(null); setDiscountGrant(null);
        } catch (error) {
          if (!scanMounted.current || generation !== scanGeneration.current || store.getState().session.token !== queued.token) break;
          const message = `Barcode ${queued.barcode}: ${error instanceof Error ? error.message : errorMessage(error)}`;
          setFailure(previous => previous ? `${previous} ${message}` : message);
        } finally { if('unsubscribe' in request)request.unsubscribe();else request.reset(); if (scanRequest.current === request) scanRequest.current = null; }
        if (scanQueue.current[0] === queued) scanQueue.current.shift();
        setScanCount(scanQueue.current.length);
      }
    } finally {
      if (scanMounted.current && generation === scanGeneration.current) { scanning.current = false; scanQueue.current = []; setScanCount(0); }
    }
  }, [dispatch, lookupBarcode, store,decodeScale,resolveCard,scaleProfile.currentData,loyaltyEnabled,scalesEnabled]);
  const scan = useCallback((barcode: string) => {
    const token = store.getState().session.token;
    if (!token || scanContext.current.blocked) return;
    if (!scanContext.current.tillId) { setFailure('Open your till before scanning products.'); return; }
    if (!/^[!-~]{1,100}$/.test(barcode)) { setFailure('This barcode is invalid. Use exact barcode search.'); return; }
    if (scanQueue.current.length >= 100) { setFailure('The scan queue is full. Wait for the pending items, then scan this item again.'); return; }
    if (!scanning.current) setFailure('');
    scanQueue.current.push({ barcode, token }); setScanCount(scanQueue.current.length);
    void drainScans();
  }, [drainScans, store]);
  useBarcodeKeyboard(scan, financialLocked || !!review || returnOpen || heldOpen || discountOpen || mobileOpen || !!cart.receipt);
  useEffect(()=>{const key=(e:KeyboardEvent)=>{if(e.key==='F2'&&!locked){e.preventDefault();searchRef.current?.focus();}if(e.key==='F4'&&!locked&&!review&&!returnOpen&&!mobileOpen&&!heldOpen&&!discountOpen){e.preventDefault();void inspect();}};window.addEventListener('keydown',key);return()=>window.removeEventListener('keydown',key);});
  function editDiscount(){setDiscountDraft(String(cart.discount??0));setReasonDraft(cart.discount_reason??'');setLineDraft(Object.fromEntries(cart.entries.map(l=>[l.key,String(l.discount??0)])));setDiscountOpen(true);}
  function applyDiscount(){const all=[discountDraft,...Object.values(lineDraft)];if(all.some(v=>!/^(0|[1-9][0-9]*)$/.test(v)||!Number.isSafeInteger(Number(v)))){setFailure('Enter discounts as whole TSh.');return;}dispatch(checkoutActions.setDiscount({discount:Number(discountDraft),reason:reasonDraft}));for(const l of cart.entries)dispatch(checkoutActions.edit({key:l.key,discount:Number(lineDraft[l.key]??0)}));setReview(null);setDiscountGrant(null);setDiscountOpen(false);}
  function searchProducts(e: FormEvent) { e.preventDefault(); if(!locked&&search&&(search.startsWith('BSL:')||(barcodeMode&&scalesEnabled&&search.length===13&&scaleProfile.currentData?.profile&&search.startsWith(scaleProfile.currentData.profile.prefix)))){scan(search);return;}if (!locked) setFilters({ page: 1, ...(barcodeMode && search ? { barcode: search } : { search }) }); }
  return <div className="flex min-h-full flex-col lg:flex-row">
    <section className="min-w-0 flex-1 border-r border-slate-200">
      <div className="space-y-3 border-b border-slate-200 bg-white p-4">
        <form onSubmit={searchProducts} className="flex gap-2"><div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg bg-slate-100 px-3 py-2.5"><Search className="h-5 w-5 shrink-0 text-slate-400" /><input ref={searchRef} aria-label={translate("Search products or barcode")} disabled={locked} value={search} onChange={(e) => setSearch(e.target.value)} placeholder={translate("Search products or barcode…")} className="min-w-0 flex-1 bg-transparent text-sm outline-none" /></div><button disabled={locked} className="rounded-lg bg-emerald-500 px-4 text-sm font-medium text-white disabled:opacity-40">{translate("Search")}</button></form>
        <div className="flex flex-wrap gap-2 text-sm"><label className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-slate-600"><input type="checkbox" disabled={locked} checked={barcodeMode} onChange={(e) => setBarcodeMode(e.target.checked)} /> {translate("Exact barcode")}</label>{displayEnabled&&<CustomerDisplay review={review} entries={cart.entries} shopName={shopName}/>}<CameraScanner onScan={scan} disabled={financialLocked || !!review || returnOpen || heldOpen || discountOpen || mobileOpen || !!cart.receipt}/><button disabled={busy||!!cart.pending||!!cart.mobile} onClick={()=>setReturnOpen(true)} className="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-amber-700 disabled:opacity-40"><RotateCcw className="h-4 w-4" /> {translate("Refund")}</button><span className="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700">{current.data?.till ? `Till open · ${current.data.till.branch_name}` : translate("Open a till to sell")}</span></div>
      </div>
      <div className="p-4">
        {scanCount > 0 && <p role="status" className="mb-3 text-sm text-emerald-700">{translate("Adding scanned items…")} {scanCount} {translate("pending")}</p>}
        {(products.error || current.error) && <p role="alert" className="mb-3 text-sm text-red-600">{errorMessage(products.error ?? current.error)} <button onClick={() => { void products.refetch(); void current.refetch(); }} className="underline">{translate("Try again")}</button></p>}
        {(products.isFetching || current.isFetching) && <p role="status" className="mb-3 text-sm text-slate-500">{translate("Loading…")}</p>}
        {!current.isFetching && !current.error && !current.data?.till && <button onClick={() => onNavigate('tills')} className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">{translate("Open your till")}</button>}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">{products.currentData?.products.filter((p) => p.is_active).map((p) => <button key={p.id} disabled={locked || !current.data?.till || !!current.error} onClick={() => { dispatch(checkoutActions.add({ key: crypto.randomUUID(), product: p, qty: '1', packaging_id: p.selected_packaging?.id ?? null })); setReview(null); setFailure(''); }} className="rounded-xl border border-slate-200 bg-white p-3 text-left transition-colors hover:border-emerald-400 disabled:opacity-50"><ProductImage productId={p.id} image={p.image} name={p.name} loadImage={loadImage} className="mb-3 h-16 w-full rounded-lg bg-emerald-50"/><p className="break-words text-sm font-semibold text-slate-800">{p.name}</p><p className="mt-1 text-xs text-slate-400">{p.selected_packaging?.name ?? p.category ?? 'Uncategorised'}</p><p className="mt-2 text-sm font-bold text-emerald-600">{money(p.selected_packaging?.selling_price ?? p.selling_price)}</p><p className="mt-1 text-xs text-slate-400">{p.stock ?? '—'} {p.unit} {translate("available")}</p></button>)}</div>
        {!products.isFetching && !products.error && products.currentData?.products.length === 0 && <p className="py-8 text-center text-sm text-slate-400">{translate("No products found.")}</p>}
        {products.currentData && <div className="mt-4 flex items-center justify-between text-sm text-slate-500"><button disabled={locked || filters.page === 1} onClick={() => setFilters({ ...filters, page: filters.page-1 })} className="disabled:opacity-40">{translate("Previous")}</button><span>{translate("Page")} {filters.page}</span><button disabled={locked || filters.page*products.currentData.meta.per_page >= products.currentData.meta.total} onClick={() => setFilters({ ...filters, page: filters.page+1 })} className="disabled:opacity-40">{translate("Next")}</button></div>}
      </div>
    </section>
    <section className="flex w-full shrink-0 flex-col bg-white lg:w-[400px] xl:w-[440px]">
      <div className="flex items-center justify-between border-b border-slate-200 p-4"><h2 className="flex items-center gap-2 font-semibold text-slate-800"><ShoppingCart className="h-5 w-5 text-slate-600" /> {translate("Current sale")} <span className="text-sm text-slate-400">({cart.entries.length})</span></h2><button aria-label={translate("Clear cart")} disabled={locked || !cart.entries.length} onClick={() => { dispatch(checkoutActions.clear()); setReview(null); }} className="text-slate-400 disabled:opacity-40"><Trash2 className="h-4 w-4" /></button></div>
      <div className="flex flex-wrap gap-2 border-b border-slate-100 p-3"><button disabled={locked} onClick={()=>setHeldOpen(true)} className="flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600"><Pause className="h-4 w-4" /> {translate("Hold")}</button><button disabled={locked||!cart.entries.length} onClick={editDiscount} className="flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600"><Percent className="h-4 w-4" /> {translate("Discount")}</button><span className="px-2 py-2 text-xs text-slate-500">{translate("Cashier:")} {actor.name}</span></div>
      {actor.permissions.includes('manage_customers') && <div className="border-b border-slate-100 p-4">
        <button disabled={locked} onClick={()=>setCustomerOpen(!customerOpen)} className="text-sm font-medium text-emerald-600">{customer ? `Customer: ${customer.name}` : translate("Choose customer")}</button>
        {customerOpen && <div className="mt-3 space-y-2"><form onSubmit={(e)=>{e.preventDefault();if(!locked)setCustomerQuery(customerSearch);}} className="flex gap-2"><input aria-label={translate("Find checkout customer")} disabled={locked} value={customerSearch} onChange={(e)=>setCustomerSearch(e.target.value)} placeholder={translate("Customer name or phone")} className={inputClass}/><button disabled={locked} className="text-sm text-emerald-600">{translate("Find")}</button></form>
          {customers.error && <p role="alert" className="text-sm text-red-600">{errorMessage(customers.error)}</p>}
          {customers.isFetching && <p role="status" className="text-xs text-slate-500">{translate("Loading customers…")}</p>}
          <div className="max-h-36 space-y-1 overflow-y-auto">{customers.currentData?.customers.map((c)=><button key={c.id} disabled={locked} onClick={()=>{setCustomer({id:c.id,name:c.name});setCredit('0');setReview(null);setCustomerOpen(false);}} className="block w-full rounded-lg bg-slate-50 px-3 py-2 text-left text-sm text-slate-700">{c.name} · {c.phone??'No phone'}</button>)}</div>
          <button type="button" disabled={locked} onClick={()=>onNavigate('customers')} className="text-xs text-emerald-600">{translate("Manage customers")}</button></div>}
        {customer && <div className="mt-3 flex items-end gap-2"><label className="min-w-0 flex-1 text-xs text-slate-500">{translate("Credit portion (TSh)")}<input disabled={locked} value={credit} inputMode="numeric" onChange={(e)=>{setCredit(e.target.value);setReview(null);}} className={`${inputClass} mt-1`}/></label><button disabled={locked} onClick={()=>{setCustomer(null);setCredit('0');setReview(null);}} className="pb-2 text-xs text-slate-500">{translate("Remove customer")}</button></div>}
      </div>}
      <div className="space-y-3 p-4">{cart.entries.length === 0 && <div className="flex flex-col items-center py-12 text-slate-300"><ShoppingCart className="mb-3 h-12 w-12" /><p className="text-sm text-slate-400">{translate("Cart is empty")}</p></div>}{cart.entries.map((row, index) => <div key={row.key} className="rounded-lg border border-slate-100 p-3"><div className="flex justify-between gap-2"><p className="min-w-0 break-words text-sm font-semibold text-slate-700">{row.product.name}</p><button disabled={locked} aria-label={`Remove line ${index+1}`} onClick={() => { dispatch(checkoutActions.remove(row.key)); setReview(null); }} className="text-slate-400 disabled:opacity-40"><Trash2 className="h-4 w-4" /></button></div><div className="mt-2 flex gap-2"><label className="min-w-0 flex-1 text-xs text-slate-500">{translate("Quantity · line")} {index+1}<input disabled={locked} value={row.qty} inputMode="decimal" onChange={(e) => { dispatch(checkoutActions.edit({ key: row.key, qty: e.target.value })); setReview(null); }} className={`${inputClass} mt-1`} /></label><label className="min-w-0 flex-1 text-xs text-slate-500">{translate("Unit · line")} {index+1}<select disabled={locked} value={row.packaging_id ?? ''} onChange={(e) => { dispatch(checkoutActions.edit({ key: row.key, packaging_id: e.target.value || null, qty: '1' })); setReview(null); }} className={`${inputClass} mt-1`}><option value="">{row.product.unit}</option>{row.product.packagings?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label></div></div>)}</div>
      <div className="mt-auto border-t border-slate-200 p-4">
        <PaymentRecovery locked={locked} onResume={()=>setMobileOpen(true)}/>{cart.mobile && <button onClick={()=>setMobileOpen(true)} className="mb-3 text-sm font-semibold text-amber-700 underline">{translate("Resume current mobile payment")}</button>}
        {failure && <p role="alert" className="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(failure)}</p>}
        {cart.pending && <div className="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-700"><p>{translate("Payment outcome is not confirmed. Retry this same sale before starting another.")}</p><button disabled={busy} onClick={() => void pay()} className="mt-2 font-semibold underline">{busy ? translate("Confirming…") : translate("Retry same sale")}</button></div>}
        <p className="mb-3 text-xs text-slate-400">{translate("Review the current prices and total before collecting cash.")}</p><button disabled={locked || !cart.entries.length || !current.data?.till || !!current.error || current.isFetching} onClick={() => void inspect()} className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-bold text-white disabled:opacity-40"><Wallet className="h-5 w-5" />{busy ? translate("Checking…") : translate("Review cash sale")}</button>
      </div>
    </section>
    {(returnOpen||returnState.pending||returnState.receipt)&&<Returns cartDiscount={cart.discount??0} tillId={current.data?.till?.id} entries={cart.entries} onClose={()=>setReturnOpen(false)}/>}
    <HeldCarts open={heldOpen} branchId={current.data?.till?.branch_id??actor.branch_id} locked={busy||!!cart.pending||!!cart.mobile||!!returnState.pending} draft={cart.entries.length?{items:cart.entries.map(l=>({product_id:l.product.id,qty:l.qty,packaging_id:l.packaging_id,discount:l.discount??0})),customer_id:customer?.id??null,credit_amount:Number(credit)||0,discount:cart.discount??0,discount_reason:cart.discount_reason??''}:null} onClose={()=>setHeldOpen(false)} onSaved={()=>{dispatch(checkoutActions.clear());setCustomer(null);setCredit('0');setReview(null);}} onResume={resumeHeld}/>
    <Dialog open={discountOpen} onClose={()=>setDiscountOpen(false)} fullWidth maxWidth="sm" aria-labelledby="discount-title"><div className="space-y-3 p-6"><h2 id="discount-title" className="text-lg font-bold text-slate-800">{translate("Checkout discounts")}</h2>{cart.entries.map(l=><label key={l.key} className="block text-xs text-slate-600">{translate("Line discount for")} {l.product.name} {translate("(TSh)")}<input value={lineDraft[l.key]??'0'} inputMode="numeric" onChange={e=>setLineDraft({...lineDraft,[l.key]:e.target.value})} className={inputClass}/></label>)}<label className="block text-xs text-slate-600">{translate("Cart discount (TSh)")}<input value={discountDraft} inputMode="numeric" onChange={e=>setDiscountDraft(e.target.value)} className={inputClass}/></label><label className="block text-xs text-slate-600">{translate("Discount reason")}<input value={reasonDraft} onChange={e=>setReasonDraft(e.target.value)} className={inputClass}/></label><p className="text-xs text-slate-500">{translate("Prices and limits are checked at review. Every discount needs supervisor approval.")}</p><button onClick={applyDiscount} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white">{translate("Apply discounts")}</button><button onClick={()=>setDiscountOpen(false)} className="ml-3 text-sm text-slate-500">{translate("Cancel")}</button></div></Dialog>
      {loyaltyEnabled&&customer&&loyalty.currentData?.redemption_enabled&&<div className="mx-4 mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3"><label className="block text-xs text-slate-600">{translate('Redeem loyalty points')}<input disabled={locked} inputMode="numeric" value={loyaltyPoints} onChange={e=>setLoyaltyPoints(e.target.value)} className="mt-1 w-full rounded-lg border bg-white px-3 py-2"/></label><p className="mt-1 text-xs text-slate-500">{translate('Available points')}: {loyalty.currentData.available_points} · {translate('Supervisor approval is required. No cash payout.')}</p></div>}
    <Dialog open={!!review} onClose={() => { if (!locked) setReview(null); }} maxWidth="sm" fullWidth aria-labelledby="payment-title" slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6"><h2 id="payment-title" className="text-lg font-bold text-slate-800">{translate("Payment")}</h2>{review && <><p className="mt-3 text-2xl font-bold text-emerald-600">{money(review.total)}</p><p className="mt-1 text-xs text-slate-500">{translate("Includes VAT")} {money(review.vat_amount)} · {review.vat_rate}%</p><div className="my-4 space-y-2 text-sm text-slate-600">{review.lines.map((l, i) => <div key={i} className="flex justify-between gap-3"><span>{l.name} · {l.qty} {l.packaging_name ?? l.unit}</span><span>{money(l.line_total)}</span></div>)}</div>{review.customer_id && <div className="mb-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600"><p>{review.customer_name}</p><p>{translate("Credit:")} {money(review.credit_amount??0)}</p><p>{translate("Cash required:")} {money(review.cash_allocated??review.total)}</p></div>}<p className="my-2 text-xs text-slate-500">{translate("F2: search · F4: review checkout")}</p>{review.lines.some(l=>l.discount>0)&&!discountGrant&&<DiscountApproval operationId={saleKey} reviewHash={review.review_hash} onApproved={setDiscountGrant} disabled={busy}/>} {discountGrant&&<p className="text-sm text-emerald-700">{translate("Discount approved for this checkout.")}</p>}<form onSubmit={(e) => { e.preventDefault(); void pay(); }}><label className="block text-xs text-slate-500">{translate("Cash tendered (TSh)")}<input autoFocus disabled={locked} value={cash} onChange={(e) => setCash(e.target.value)} inputMode="numeric" required className={`${inputClass} mt-1`} /></label>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}{cart.pending && <p className="mt-3 text-sm text-amber-700">{translate("Outcome not confirmed. Retry the same payment.")}</p>}<button disabled={busy||!!review.lines.find(l=>l.discount>0)&&!discountGrant} className="mt-4 w-full rounded-lg bg-emerald-500 px-4 py-3 text-sm font-bold text-white disabled:opacity-40">{busy ? translate("Confirming…") : cart.pending ? translate("Retry same cash payment") : (review.credit_amount??0)>0 ? translate("Confirm sale") : translate("Confirm cash payment")}</button><button type="button" disabled={locked} onClick={() => setReview(null)} className="mt-4 text-sm text-slate-500 disabled:opacity-40">{translate("Back to cart")}</button></form><MobilePaymentStatus/>{capabilities.currentData?.enabled && <button disabled={locked} onClick={()=>{setMobileBase(review);setReview(null);setMobileOpen(true);}} className="mt-3 w-full rounded-lg border border-emerald-200 px-4 py-2 text-sm font-semibold text-emerald-700">{translate("Pay with Selcom")}</button>}</>}</div></Dialog>
    <Dialog open={!!cart.receipt} onClose={() => dispatch(checkoutActions.clear())} maxWidth="sm" fullWidth aria-labelledby="receipt-title"><div className="p-6">{cart.receipt && <><h2 id="receipt-title" className="text-lg font-bold text-slate-800">{translate("Sale completed")}</h2><p className="mt-3 text-center font-bold text-slate-700">{shopName}</p><p className="mt-1 text-center text-sm text-slate-500">{cart.receipt.receipt_number}</p><div className="my-4 space-y-3 border-y border-dashed border-slate-200 py-4 text-sm">{cart.receipt.lines.map((l, i) => <div key={i} className="flex justify-between gap-3"><span>{l.name}<small className="block text-slate-400">{l.qty} {l.packaging_name ?? l.unit}</small></span><span>{money(l.line_total)}</span></div>)}</div><div className="space-y-2 text-sm text-slate-600"><p className="flex justify-between font-bold"><span>{translate("Total")}</span><span>{money(cart.receipt.total)}</span></p>{cart.receipt.payments?.filter((p)=>p.method==='credit'||p.method==='mpesa').map((p)=><p key={p.id} className="flex justify-between"><span>{p.method==='mpesa'?translate("Selcom mobile payment"):translate("Customer credit")}</span><span>{money(p.amount)}</span></p>)}<p className="flex justify-between"><span>{translate("VAT included")}</span><span>{money(cart.receipt.vat_amount)}</span></p><p className="flex justify-between"><span>{translate("Cash tendered")}</span><span>{money(cart.receipt.cash_tendered)}</span></p><p className="flex justify-between font-bold text-emerald-600"><span>{translate("Change")}</span><span>{money(cart.receipt.change_given)}</span></p></div><ReceiptPrint sale={cart.receipt} shopName={shopName}/><button onClick={() => dispatch(checkoutActions.clear())} className="mt-5 w-full rounded-lg bg-emerald-500 px-4 py-3 text-sm font-bold text-white">{translate("New sale")}</button></>}</div></Dialog>
    <MobileCheckout open={mobileOpen} base={mobileBase} onClose={()=>setMobileOpen(false)}/>
  </div>;
}



