// @vitest-environment jsdom
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import {Banking} from '../src/features/live/Banking';
import {supplierReducer} from '../src/features/live/supplierState';
import {liveApi,sessionReducer,signedIn} from '../src/features/live/api';
const overview={lines:[{id:'bank_one',account:'Shop bank',booked_on:'2026-10-14',direction:'debit',amount:3000,reference:'BANK-1',payment_id:null,payment_type:null}],payments:[{id:'spay_one',type:'supplier',amount:3000,direction:'debit',paid_on:'2026-10-14',reference:'PAY-1',matched:false}],schedules:[]};
const suppliers={suppliers:[],orders:[],invoices:[{id:'sinv_one',reference:'INV-1',remaining_amount:5000}],returns:[],credits:[],payments:[]};
const response=(data:unknown,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
function makeStore(){return configureStore({reducer:{session:sessionReducer,supplierOperations:supplierReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:{id:'usr_one',name:'Owner',role:'owner',branch_id:null,permissions:['manage_products']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
function mount(){return render(<Provider store={store}><Banking/></Provider>);}
function installFetch(post:(request:Request)=>Promise<Response>){vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.method==='POST')return post(request);if(request.url.endsWith('supplier-price-comparisons'))return response({products:[]});return response(request.url.endsWith('/suppliers')?suppliers:overview);}));}
it('recovers a failed overview and loads comparison costs only when that task is opened',async()=>{
 let attempts=0;const reads:string[]=[];
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
  reads.push(request.url);
  if(request.url.endsWith('/banking'))return ++attempts===1?response({},503):response(overview);
  return response(request.url.endsWith('/suppliers')?suppliers:{products:[]});
 }));
 mount();await screen.findByRole('alert');expect(reads.some(url=>url.endsWith('/suppliers'))).toBe(false);
 fireEvent.click(screen.getByRole('button',{name:'Try again'}));await screen.findByRole('option',{name:'INV-1 · TSh 5,000'});
 expect(attempts).toBe(2);expect(reads.some(url=>url.endsWith('supplier-price-comparisons'))).toBe(false);
 fireEvent.click(screen.getByRole('button',{name:'Price comparison'}));await screen.findByText('Receive a supplier invoice to start comparing actual costs.');
 expect(reads.some(url=>url.endsWith('supplier-price-comparisons'))).toBe(true);
});
it('does not crash or claim zero statement records when the overview payload is incomplete',async()=>{
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>response(request.url.endsWith('supplier-price-comparisons')?{products:[]}:suppliers)));
 mount();await screen.findByText('Banking records are unavailable. Reload before relying on these counts.');
 expect(screen.getByText('Statement lines').parentElement?.textContent).toContain('—');
 expect(screen.queryByText('Recorded. No transfer was executed.')).toBeNull();
});
it.each([504,408])('plans selected invoices and recovers HTTP %s uncertainty across navigation using the same UUID',async status=>{
 const attempts:unknown[]=[];installFetch(async request=>{attempts.push(await request.json());return attempts.length===1?response({},status):response({replayed:true,external_transfer_executed:false});});
 const view=mount();await screen.findByRole('option',{name:'INV-1 · TSh 5,000'});fireEvent.change(screen.getByLabelText('Unpaid invoice'),{target:{value:'sinv_one'}});fireEvent.change(screen.getByLabelText('Due date'),{target:{value:'2026-10-20'}});fireEvent.change(screen.getByLabelText('Planned amount (TSh)'),{target:{value:'2000'}});fireEvent.click(screen.getByRole('button',{name:'Save payment plan'}));await screen.findByRole('button',{name:'Retry same operation'});view.unmount();mount();fireEvent.click(screen.getByRole('button',{name:'Retry same operation'}));await screen.findByRole('status');expect(attempts).toHaveLength(2);expect(attempts[0]).toEqual(attempts[1]);expect(attempts[0]).toMatchObject({amount:2000});expect(store.getState().supplierOperations.bankPending).toBeNull();
});
it('sends exact selected statement and payment identities without browser settlement calculations',async()=>{
 let body:unknown;installFetch(async request=>{body=await request.json();return response({external_transfer_executed:false});});mount();fireEvent.click(screen.getByRole('button',{name:'Bank statement'}));await screen.findByRole('option',{name:'Shop bank · BANK-1 · debit · TSh 3,000'});fireEvent.change(screen.getByLabelText('Unmatched statement'),{target:{value:'bank_one'}});fireEvent.change(screen.getByLabelText('Completed bank payment'),{target:{value:'spay_one'}});fireEvent.click(screen.getByRole('button',{name:'Match exact payment'}));await waitFor(()=>expect(body).toMatchObject({line_id:'bank_one',payment_type:'supplier',payment_id:'spay_one'}));expect(body).not.toHaveProperty('amount');
});
