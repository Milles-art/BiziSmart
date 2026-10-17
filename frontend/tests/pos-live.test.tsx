// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { POS } from '../src/features/live/POS';
import {returnReducer} from '../src/features/live/returnState';
import { checkoutReducer } from '../src/features/live/checkoutState';
import { liveApi, sessionReducer, signedIn, signedOut, type ServerUser, type CatalogueProduct } from '../src/features/live/api';

const actor: ServerUser = { id:'usr_cashier',name:'Cashier',role:'cashier',branch_id:'br_main',permissions:['manage_till','sell'] };
const product: CatalogueProduct = { id:'prod_water',name:'Water',barcode:'001',category:'Drinks',quantity_type:'counted',unit:'bottle',selling_price:8500,reorder_level:'0',tracks_expiry:false,is_active:true,stock:'3',stock_available:true,packagings:[] };
const line = { product_id:product.id,name:'Water',quantity_type:'counted',unit:'bottle',qty:'1',base_qty:'1',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1',unit_price:8500,discount:0,line_total:8500 };
const review = { branch_id:'br_main',till_id:'till_one',lines:[line],subtotal:8500,discount:0,total:8500,vat_rate:18,vat_amount:1297,review_hash:'a'.repeat(64) };
const sale = { ...review,id:'sale_one',receipt_number:'RCP-2026-000001',status:'completed',cash_tendered:10000,change_given:1500,created_at:'2026-10-10T00:00:00Z',replayed:false,receipt_url:null };
function makeStore() { return configureStore({ reducer:{ returns:returnReducer, session:sessionReducer, checkout:checkoutReducer, [liveApi.reducerPath]:liveApi.reducer }, middleware:(g)=>g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
function response(data:unknown,status=200) { return new Response(JSON.stringify(data),{ status,headers:{'Content-Type':'application/json'} }); }
function queries(r:Request,open=true) {
  if(r.url.endsWith('/payments/mine'))return response({data:{payments:[]}});
  if(r.url.endsWith('/payments/capabilities'))return response({data:{provider:'selcom',configured:false,enabled:false,reason:'merchant_setup_required'}}); return new URL(r.url).pathname.endsWith('/current') ? response({data:{till:open?{id:'till_one',branch_id:'br_main',branch_name:'Main'}:null,branch:{id:'br_main',name:'Main'},can_open:true,variance_limit:5000}}) : response({data:{products:[product]},meta:{page:1,per_page:50,total:1}}); }
function mount() { return render(<Provider store={store}><POS actor={actor} shopName="Test Shop" onNavigate={vi.fn()}/></Provider>); }

it('phone cart preserves edited quantities when closed and reopened without submitting a payment', async () => {
  vi.stubGlobal('matchMedia', vi.fn(query => ({ matches: query.includes('1023'), media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() })));
  const fetch = vi.fn(async (r: Request) => queries(r));
  vi.stubGlobal('fetch', fetch);
  mount();
  fireEvent.click(await screen.findByRole('button', { name: /Water.*TSh/ }));
  const entry = screen.getByRole('button', { name: /Current sale.*View cart/ });
  fireEvent.click(entry);
  const cart = within(await screen.findByRole('dialog', { name: 'Current sale' }));
  fireEvent.change(cart.getByLabelText('Quantity · line 1'), { target: { value: '2' } });
  fireEvent.click(cart.getByRole('button', { name: 'Close cart' }));
  expect(store.getState().checkout.entries[0].qty).toBe('2');
  fireEvent.click(entry);
  expect(within(screen.getByRole('dialog', { name: 'Current sale' })).getByLabelText('Quantity · line 1')).toHaveProperty('value', '2');
  expect(fetch.mock.calls.some(([request]) => request.method === 'POST')).toBe(false);
});

it('restores category tabs while requesting server filtered products rather than hiding a local catalogue', async () => {
  const requested: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (new URL(r.url).pathname.endsWith('/products')) requested.push(new URL(r.url).searchParams.get('category') ?? 'all');
    return queries(r);
  }));
  mount();
  const category = await screen.findByRole('button', {name:'Drinks', exact:true});
  fireEvent.click(category);
  await waitFor(() => expect(requested).toContain('Drinks'));
  expect(category.getAttribute('aria-pressed')).toBe('true');
  expect(screen.getByRole('button', {name:/Water.*TSh/})).toBeTruthy();
  fireEvent.click(screen.getByRole('button', {name:'All', exact:true}));
  await waitFor(() => expect(category.getAttribute('aria-pressed')).toBe('false'));
});
async function cartReview() { fireEvent.click(await screen.findByRole('button',{name:/Water.*TSh/})); fireEvent.click(screen.getByRole('button',{name:'Review cash sale'})); const dialog=within(await screen.findByRole('dialog',{name:'Payment'})); fireEvent.change(dialog.getByLabelText('Cash tendered (TSh)'),{target:{value:'10000'}}); return dialog; }
beforeEach(()=>{ const c=transferableAbortController(); vi.stubGlobal('AbortController',c.constructor); vi.stubGlobal('AbortSignal',c.signal.constructor); store=makeStore(); store.dispatch(signedIn({token:'test-only-token',expires_at:new Date(Date.now()+600000).toISOString(),user:actor})); });
afterEach(()=>{ cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); localStorage.clear(); sessionStorage.clear(); });
it('renders server margin values in owner review and fresh receipt without sending cost authority back',async()=>{
 const owner:ServerUser={...actor,role:'owner',permissions:[...actor.permissions,'view_buying_price']};store.dispatch(signedIn({token:'test-only-token',expires_at:new Date(Date.now()+600000).toISOString(),user:owner}));
 const warnings=[{code:'MARGIN_BELOW_COST',line_index:0,product_id:product.id,product_name:product.name,batch_id:'batch_cost',batch_number:'ACTUAL-COST',base_qty:'1',net_total:8500,cost_total:13000,shortfall:4500}];let posted:Record<string,unknown>|undefined;
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='POST'){if(r.url.endsWith('/preview'))return response({data:{...review,margin_warnings:warnings}});posted=await r.json();return response({data:{...sale,margin_warnings:warnings}},201);}if(new URL(r.url).pathname.endsWith('/products'))return response({data:{products:[{...product,buying_price:99}]},meta:{page:1,per_page:50,total:1}});return queries(r);}));
 render(<Provider store={store}><POS actor={owner} shopName="Owner shop" onNavigate={vi.fn()}/></Provider>);const d=await cartReview();expect(d.getByRole('note',{name:'Below-cost lines'}).textContent).toContain('Cost: TSh 13,000');expect(d.getByRole('note',{name:'Below-cost lines'}).textContent).toContain('Short by: TSh 4,500');expect(d.getByRole('button',{name:'Confirm cash payment'})).toHaveProperty('disabled',false);fireEvent.click(d.getByRole('button',{name:'Confirm cash payment'}));const receipt=within(await screen.findByRole('dialog',{name:'Sale completed'}));expect(receipt.getByRole('note',{name:'Below-cost lines'}).textContent).toContain('Cost: TSh 13,000');expect(posted?.review_hash).toBe(review.review_hash);expect(posted).not.toHaveProperty('margin_warnings');expect(JSON.stringify(posted)).not.toContain('cost_total');
});

it('uses the server review and saved receipt then clears the cart and refreshes stock and till',async()=>{
  let body:Record<string,unknown>|null=null; let key:string|null=null;
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{ if(r.method==='POST') { if(r.url.endsWith('/preview')) return response({data:review}); body=await r.json(); key=r.headers.get('Idempotency-Key'); return response({data:sale},201); } return queries(r); }));
  mount(); const d=await cartReview(); fireEvent.click(d.getByRole('button',{name:'Confirm cash payment'})); await screen.findByRole('heading',{name:'Sale completed'});
  expect(body?.cash_tendered).toBe(10000); expect(body?.review_hash).toBe(review.review_hash); expect(body?.total).toBeUndefined(); expect(key).toMatch(/^[0-9a-f-]{36}$/);
  expect(screen.getByText('TSh 1,500')).toBeTruthy(); expect(store.getState().checkout.entries).toHaveLength(0); expect(store.getState().checkout.pending).toBeNull(); expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0);
});
it('preserves the same exact operation after an uncertain response across screen navigation',async()=>{
  const operations:{key:string|null;body:unknown}[]=[];
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{ if(r.method==='POST') { if(r.url.endsWith('/preview')) return response({data:review}); operations.push({key:r.headers.get('Idempotency-Key'),body:await r.json()}); return operations.length===1 ? response({error:{code:'SERVER_ERROR',message:'Connection interrupted'}},500) : response({data:{...sale,replayed:true}}); } return queries(r); }));
  const view=mount(); const d=await cartReview(); fireEvent.click(d.getByRole('button',{name:'Confirm cash payment'})); await d.findByText('Outcome not confirmed. Retry the same payment.');
  expect(store.getState().checkout.entries).toHaveLength(1); view.unmount(); mount(); fireEvent.click(await screen.findByRole('button',{name:'Retry same sale'})); await screen.findByRole('heading',{name:'Sale completed'}); expect(operations[0]).toEqual(operations[1]);
});
it('keeps the cart after stale prices or stock failure and never shows a successful receipt',async()=>{
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>r.method==='POST'?(r.url.endsWith('/preview')?response({data:review}):response({error:{code:'INSUFFICIENT_STOCK',message:'Only 0 sellable stock remains.'}},400)):queries(r)));
  mount(); const d=await cartReview(); fireEvent.click(d.getByRole('button',{name:'Confirm cash payment'})); await screen.findByText('Only 0 sellable stock remains.'); expect(screen.queryByRole('heading',{name:'Sale completed'})).toBeNull(); expect(store.getState().checkout.entries).toHaveLength(1); expect(store.getState().checkout.pending).toBeNull(); expect(await screen.findByRole('button',{name:'Review cash sale'})).toBeTruthy();
  store.dispatch(signedOut()); expect(store.getState().checkout.entries).toHaveLength(0);
});
it('requires an open authoritative till and does not calculate or submit a sale locally',async()=>{
  const mock=vi.fn(async(r:Request)=>queries(r,false)); vi.stubGlobal('fetch',mock); mount(); await screen.findByRole('button',{name:'Open your till'});
  await waitFor(()=>expect(screen.getByRole('button',{name:/Water.*TSh/})).toHaveProperty('disabled',true)); expect(screen.getByRole('button',{name:'Review cash sale'})).toHaveProperty('disabled',true); expect(mock.mock.calls.some(([r])=>r.method==='POST')).toBe(false);
});
it('does not restore private sale data from a response arriving after logout',async()=>{
  let finish: ((r: Response) => void) | undefined;
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{ if(r.method==='POST') { if(r.url.endsWith('/preview')) return response({data:review}); return new Promise<Response>((resolve)=>{finish=resolve;}); } return queries(r); }));
  mount(); const d=await cartReview(); fireEvent.click(d.getByRole('button',{name:'Confirm cash payment'})); await waitFor(()=>expect(finish).toBeDefined());
  store.dispatch(signedOut()); finish!(response({data:sale},201)); await waitFor(()=>expect(store.getState().liveApi.mutations).toEqual({}));
  expect(store.getState().checkout.pending).toBeNull(); expect(store.getState().checkout.receipt).toBeNull(); expect(store.getState().checkout.entries).toHaveLength(0);
});
it('submits the reviewed customer credit portion and displays saved credit without trusting a local total',async()=>{
  const customer={id:'cust_one',name:'Asha',phone:'255620000000',credit_limit:50000,outstanding_balance:0,created_at:'2026-10-10T00:00:00Z'};
  const creditReview={...review,customer_id:customer.id,customer_name:customer.name,credit_amount:3500,cash_allocated:5000};
  let posted:Record<string,unknown>|undefined;
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{
    if(r.url.includes('/customers'))return response({data:{customers:[customer]},meta:{page:1,per_page:30,total:1}});
    if(r.method==='POST'){if(r.url.endsWith('/preview'))return response({data:creditReview});posted=await r.json();return response({data:{...sale,customer_id:customer.id,cash_tendered:5000,change_given:0,payments:[{id:'pay_credit',method:'credit',amount:3500}]}},201);}return queries(r);
  }));
  render(<Provider store={store}><POS actor={{...actor,permissions:[...actor.permissions,'manage_customers']}} shopName="Shop" onNavigate={vi.fn()}/></Provider>);
  fireEvent.click(await screen.findByRole('button',{name:/Water.*TSh/}));fireEvent.click(screen.getByRole('button',{name:'Choose customer'}));fireEvent.click(await screen.findByRole('button',{name:/Asha ·/}));fireEvent.change(screen.getByLabelText('Credit portion (TSh)'),{target:{value:'3500'}});
  fireEvent.click(screen.getByRole('button',{name:'Review cash sale'}));const dialog=within(await screen.findByRole('dialog',{name:'Payment'}));expect(dialog.getByText('Cash required: TSh 5,000')).toBeTruthy();fireEvent.change(dialog.getByLabelText('Cash tendered (TSh)'),{target:{value:'5000'}});fireEvent.click(dialog.getByRole('button',{name:'Confirm sale'}));await screen.findByRole('heading',{name:'Sale completed'});expect(posted?.customer_id).toBe(customer.id);expect(posted?.credit_amount).toBe(3500);expect(screen.getByText('Customer credit')).toBeTruthy();
});
