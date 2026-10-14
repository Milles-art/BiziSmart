// @vitest-environment jsdom
import React from 'react';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import {MobileCheckout} from '../src/features/live/MobileCheckout';
import {checkoutActions,checkoutReducer} from '../src/features/live/checkoutState';
import {liveApi,sessionReducer,signedIn,signedOut,type SaleReview} from '../src/features/live/api';
const base:SaleReview={branch_id:'br_main',till_id:'till_one',lines:[{product_id:'prod_water',name:'Water',quantity_type:'counted',unit:'bottle',qty:'1',base_qty:'1',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1',unit_price:8500,discount:0,line_total:8500}],subtotal:8500,discount:0,total:8500,vat_rate:18,vat_amount:1297,review_hash:'a'.repeat(64)};
const review={...base,mobile_amount:6000,cash_allocated:2500,review_hash:'b'.repeat(64)};
const payment={id:'mpay_one',status:'confirmed',amount:6000,currency:'TZS',phone_suffix:'3714',created_at:'2026-10-10T00:00:00Z',confirmed_at:'2026-10-10T00:00:00Z',reference:'ref_one',last_error:null,owner_review_required:false,sale:null};
const sale={...base,id:'sale_one',receipt_number:'RCP-2026-000001',status:'completed',cash_tendered:3000,change_given:500,created_at:'2026-10-10T00:00:00Z',replayed:false,receipt_url:null};
function makeStore(){return configureStore({reducer:{session:sessionReducer,checkout:checkoutReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
function response(data:unknown,status=200){return new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});}
function mount(){return render(<Provider store={store}><MobileCheckout open base={base} onClose={vi.fn()}/></Provider>);}
beforeEach(()=>{const native=globalThis.Request;vi.stubGlobal('AbortController',function(){return transferableAbortController();});vi.stubGlobal('Request',class extends native{constructor(input:RequestInfo|URL,options?:RequestInit){super(typeof input==='string'&&input.startsWith('/')?`http://shop.localhost${input}`:input,options);}});store=makeStore();store.dispatch(signedIn({token:'token',expires_at:'2099-01-01',user:{id:'usr_one',name:'Cashier',role:'cashier',branch_id:'br_main',permissions:['sell']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
async function fillReview(){fireEvent.change(screen.getByLabelText('Mobile amount (TSh)'),{target:{value:'6000'}});fireEvent.change(screen.getByLabelText('Cash tendered (TSh)'),{target:{value:'3000'}});fireEvent.change(screen.getByLabelText('Payer name'),{target:{value:'Asha'}});fireEvent.change(screen.getByLabelText('Payer email'),{target:{value:'asha@example.com'}});fireEvent.change(screen.getByLabelText('Payer phone'),{target:{value:'0624643714'}});fireEvent.click(screen.getByRole('button',{name:'Review mobile payment'}));await screen.findByText('Mobile: TSh 6,000');}
it('uses the server review and verified payment before completing the saved sale',async()=>{
 let posted:Record<string,unknown>|undefined;let key='';
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.url.endsWith('/sales/preview'))return response(review);if(r.url.endsWith('/payments/selcom')){posted=await r.json();key=r.headers.get('Idempotency-Key')??'';return response({...payment,status:'awaiting'});}if(r.url.endsWith('/complete'))return response(sale);return response(payment);}));
 mount();await fillReview();fireEvent.click(screen.getByRole('button',{name:'Send payment request'}));await screen.findByText('Payment verified. Complete the recorded sale.');expect(posted?.phone).toBe('255624643714');expect(key).toMatch(/^[a-f0-9-]{36}$/);expect(store.getState().checkout.receipt).toBeNull();
 fireEvent.click(screen.getByRole('button',{name:'Complete recorded sale'}));await waitFor(()=>expect(store.getState().checkout.receipt?.id).toBe('sale_one'));expect(store.getState().checkout.mobile).toBeNull();
});
it('recovers a lost initiation response using the identical UUID and body across remount',async()=>{
 const requests:{key:string|null;body:unknown}[]=[];
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.url.endsWith('/preview'))return response(review);if(r.url.endsWith('/selcom')){requests.push({key:r.headers.get('Idempotency-Key'),body:await r.json()});return requests.length===1?response({},504):response({...payment,status:'awaiting'});}return response({...payment,status:'awaiting'});}));
 const view=mount();await fillReview();fireEvent.click(screen.getByRole('button',{name:'Send payment request'}));await screen.findByRole('button',{name:'Recover same request'});view.unmount();mount();fireEvent.click(screen.getByRole('button',{name:'Recover same request'}));await screen.findByText('Awaiting provider confirmation. Do not request payment again.');expect(requests).toHaveLength(2);expect(requests[0]).toEqual(requests[1]);expect(store.getState().checkout.receipt).toBeNull();
});
it('recovers a server-recorded order without issuing another payment request',async()=>{
 const fetcher=vi.fn(async()=>response({...payment,status:'unknown'}));vi.stubGlobal('fetch',fetcher);store.dispatch(checkoutActions.mobileSubmitting({key:null,body:null,id:payment.id}));mount();await screen.findByText('Awaiting provider confirmation. Do not request payment again.');expect(screen.queryByRole('button',{name:'Send payment request'})).toBeNull();expect(fetcher.mock.calls.every(([r])=>(r as Request).method==='GET')).toBe(true);expect(screen.queryByRole('button',{name:'Complete recorded sale'})).toBeNull();
});
it('ignores a late payment response after sign-out and clears pending payer data',async()=>{
 let finish:((r:Response)=>void)|undefined;vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.url.endsWith('/preview'))return response(review);return new Promise<Response>(resolve=>{finish=resolve;});}));mount();await fillReview();fireEvent.click(screen.getByRole('button',{name:'Send payment request'}));await waitFor(()=>expect(finish).toBeDefined());store.dispatch(signedOut());finish!(response(payment));await waitFor(()=>expect(store.getState().checkout.mobile).toBeNull());expect(store.getState().checkout.receipt).toBeNull();
});
it('unlocks the cart only after the provider reports a cancelled or rejected payment',async()=>{
 vi.stubGlobal('fetch',vi.fn(async()=>response({...payment,status:'failed'})));store.dispatch(checkoutActions.mobileSubmitting({key:null,body:null,id:payment.id}));mount();await screen.findByText('The provider cancelled or rejected this payment.');fireEvent.click(screen.getByRole('button',{name:'Return to cart'}));expect(store.getState().checkout.mobile).toBeNull();expect(store.getState().checkout.receipt).toBeNull();
});
