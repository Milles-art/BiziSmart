// @vitest-environment jsdom
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { POS } from '../src/features/live/POS';
import { returnReducer } from '../src/features/live/returnState';
import { checkoutReducer } from '../src/features/live/checkoutState';
import { liveApi, sessionReducer, signedIn, signedOut, type CatalogueProduct, type ServerUser } from '../src/features/live/api';

const actor: ServerUser = { id: 'usr_cashier', name: 'Cashier', role: 'cashier', branch_id: 'br_main', permissions: ['manage_till', 'sell','manage_customers'] };
const water: CatalogueProduct = { id: 'prod_water', name: 'Water', barcode: '0612345678901', category: null, quantity_type: 'counted', unit: 'bottle', selling_price: 1000, reorder_level: '0', tracks_expiry: false, is_active: true, stock: '20', stock_available: true, packagings: [] };
const rice: CatalogueProduct = { ...water, id: 'prod_rice', name: 'Rice', barcode: '0612345678902', unit: 'bag' };
function createStore() { return configureStore({ reducer: { returns: returnReducer, session: sessionReducer, checkout: checkoutReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: get => get().concat(liveApi.middleware) }); }
let store: ReturnType<typeof createStore>;
function response(body: unknown) { return new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } }); }
function catalogue(products: CatalogueProduct[]) { return response({ data: { products }, meta: { page: 1, per_page: 50, total: products.length } }); }
function base(request: Request) {
  if (request.url.endsWith('/payments/mine')) return response({ data: { payments: [] } });
  if (request.url.endsWith('/payments/capabilities')) return response({ data: { provider: 'selcom', configured: false, enabled: false, reason: 'merchant_setup_required' } });
  if (request.url.endsWith('/current')) return response({ data: { till: { id: 'till_one', branch_id: 'br_main', branch_name: 'Main' }, branch: { id: 'br_main', name: 'Main' }, can_open: true, variance_limit: 5000 } });
  return catalogue([water, rice]);
}
async function mount() { const view = render(<Provider store={store}><POS actor={actor} shopName="Shop" onNavigate={vi.fn()} /></Provider>); await screen.findByText('Till open · Main'); return view; }
function scan(barcode: string) { for (const key of barcode) fireEvent.keyDown(document.body, { key }); fireEvent.keyDown(document.body, { key: 'Enter' }); }
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  store = createStore(); store.dispatch(signedIn({ token: 'scan-test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: actor }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });

it('routes configured gram labels through the server decoder before adding the exact quantity',async()=>{
 const weighted={...rice,quantity_type:'weight',unit:'kg',barcode:'00123'};let decoded=false;
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.url.endsWith('/scales/profile'))return response({data:{profile:{prefix:'22',product_start:2,product_length:5,weight_start:7,weight_length:5}}});if(request.url.endsWith('/scales/decode')){decoded=true;expect(JSON.parse(await request.text())).toEqual({label:'2200123007500'});return response({data:{product:weighted,qty:'0.75'}});}return base(request);}));
 await mount();await waitFor(()=>expect(store.getState().liveApi.queries['scaleProfile(undefined)']?.status).toBe('fulfilled'));scan('2200123007500');await waitFor(()=>expect(store.getState().checkout.entries).toHaveLength(1));expect(decoded).toBe(true);expect(store.getState().checkout.entries[0].qty).toBe('0.75');
});
it.each(['base','pack'] as const)('prioritises an exact registered %s barcode over a configured scale prefix',async(kind)=>{
 const label='2200123007506';const packaging={id:'pkg_registered',product_id:water.id,name:'Six pack',base_qty:'6',conversion_atoms:'6',selling_price:5000,barcode:label,version:1,is_active:true,archived:false};const registered={...water,barcode:kind==='base'?label:water.barcode,...(kind==='pack'?{selected_packaging:packaging}: {})};let decoderCalls=0;let release:((response:Response)=>void)|undefined;
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.url.endsWith('/scales/profile'))return response({data:{profile:{prefix:'22',product_start:2,product_length:5,weight_start:7,weight_length:5}}});if(request.url.endsWith('/scales/decode')){decoderCalls++;return response({data:{product:rice,qty:'0.75'}});}const barcode=new URL(request.url).searchParams.get('barcode');if(barcode===label)return new Promise<Response>(resolve=>{release=resolve;});return base(request);}));
 await mount();await waitFor(()=>expect(store.getState().liveApi.queries['scaleProfile(undefined)']?.status).toBe('fulfilled'));scan(label);await waitFor(()=>expect(release).toBeDefined());scan(rice.barcode!);release!(catalogue([registered]));await waitFor(()=>expect(store.getState().checkout.entries).toHaveLength(2));const entries=store.getState().checkout.entries;expect(entries.map(entry=>entry.product.id)).toEqual([water.id,rice.id]);expect(entries[0].qty).toBe('1');expect(entries[0].packaging_id).toBe(kind==='pack'?packaging.id:null);expect(decoderCalls).toBe(0);
});
it('hides display and skips scale configuration when the current plan disables them',async()=>{
 await store.dispatch(liveApi.util.upsertQueryData('me',undefined,{user:actor,business:{name:'Shop'},features:{display:false,scales:false,loyalty:false}}));const requests:string[]=[];vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{requests.push(request.url);return base(request);}));await mount();expect(screen.queryByRole('button',{name:'Open customer display'})).toBeNull();expect(requests.some(url=>url.endsWith('/scales/profile'))).toBe(false);
});
it('selects the server resolved loyalty customer and continues scanning products',async()=>{
 const token='BSL:0123456789abcdef0123456789abcdef';
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{expect(request.url).not.toContain('BSL');if(request.url.endsWith('/loyalty-cards/resolve')){expect(JSON.parse(await request.text())).toEqual({token});return response({data:{id:'cust_qr',name:'Asha'}});}return base(request);}));
 await mount();scan(token);await screen.findByRole('button',{name:'Customer: Asha'});scan(water.barcode!);await waitFor(()=>expect(store.getState().checkout.entries).toHaveLength(1));
});

it('adds each repeated scan of the same exact barcode after its previous lookup completes', async () => {
  const barcodes: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    expect(request.headers.get('Authorization')).toBe('Bearer scan-test-token');
    const barcode = new URL(request.url).searchParams.get('barcode');
    if (barcode) { barcodes.push(barcode); return catalogue([water]); }
    return base(request);
  }));
  await mount(); scan(water.barcode!);
  await waitFor(() => expect(store.getState().checkout.entries).toHaveLength(1));
  scan(water.barcode!);
  await waitFor(() => expect(store.getState().checkout.entries).toHaveLength(2));
  expect(barcodes).toEqual([water.barcode, water.barcode]);
  expect(store.getState().checkout.entries.map(entry => entry.product.id)).toEqual([water.id, water.id]);
});

it('queues a second barcode during an outstanding lookup instead of dropping the first item', async () => {
  let resolveWater: ((value: Response) => void) | undefined;
  const lookups: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const barcode = new URL(request.url).searchParams.get('barcode');
    if (!barcode) return base(request);
    lookups.push(barcode);
    if (barcode === water.barcode) return new Promise<Response>(resolve => { resolveWater = resolve; });
    return catalogue([rice]);
  }));
  await mount(); scan(water.barcode!); await waitFor(() => expect(resolveWater).toBeDefined());
  scan(rice.barcode!);
  expect(lookups).toEqual([water.barcode]); expect(screen.getByRole('button', { name: 'Review cash sale' })).toHaveProperty('disabled', true);
  expect(screen.getByText('Adding scanned items… 2 pending')).toBeTruthy();
  resolveWater!(catalogue([water]));
  await waitFor(() => expect(store.getState().checkout.entries).toHaveLength(2));
  expect(lookups).toEqual([water.barcode, rice.barcode]);
  expect(store.getState().checkout.entries.map(entry => entry.product.id)).toEqual([water.id, rice.id]);
});

it('does not restore scanned products or process queued scans after logout', async () => {
  let resolveWater: ((value: Response) => void) | undefined;
  const lookups: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const barcode = new URL(request.url).searchParams.get('barcode');
    if (!barcode) return base(request);
    lookups.push(barcode); return new Promise<Response>(resolve => { resolveWater = resolve; });
  }));
  await mount(); scan(water.barcode!); await waitFor(() => expect(resolveWater).toBeDefined()); scan(rice.barcode!);
  store.dispatch(signedOut()); resolveWater!(catalogue([water]));
  await waitFor(() => expect(screen.queryByText(/Adding scanned items/)).toBeNull());
  expect(store.getState().checkout.entries).toEqual([]); expect(lookups).toEqual([water.barcode]);
});

it('keeps a failed barcode visible while adding the next queued valid product', async () => {
  let resolveMissing: ((value: Response) => void) | undefined;
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const barcode = new URL(request.url).searchParams.get('barcode');
    if (!barcode) return base(request);
    if (barcode === '9999999999999') return new Promise<Response>(resolve => { resolveMissing = resolve; });
    return catalogue([rice]);
  }));
  await mount(); scan('9999999999999'); await waitFor(() => expect(resolveMissing).toBeDefined()); scan(rice.barcode!);
  resolveMissing!(catalogue([]));
  await waitFor(() => expect(store.getState().checkout.entries).toHaveLength(1));
  expect(store.getState().checkout.entries[0].product.id).toBe(rice.id);
  expect(screen.getByRole('alert').textContent).toContain('Barcode 9999999999999: This barcode does not identify an available product.');
});
