// @vitest-environment jsdom
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import Suppliers from '../src/features/live/Suppliers';
import {supplierReducer} from '../src/features/live/supplierState';
import {liveApi,sessionReducer,signedIn,signedOut,type ServerUser} from '../src/features/live/api';
const owner:ServerUser={id:'usr_owner',name:'Owner',role:'owner',branch_id:'br_one',permissions:['manage_products','manage_till','receive_stock']};
const keeper:ServerUser={...owner,id:'usr_keeper',name:'Keeper',role:'stockkeeper',permissions:['manage_products','receive_stock']};
const supplier={id:'sup_one',name:'Rice supplier',phone:null,address:null,is_active:true,revision:1,payable:5000,unapplied_credit:0};
const invoice={id:'sinv_one',supplier_id:supplier.id,order_id:null,reference:'INV-ONE',invoice_date:'2026-10-13',original_amount:5000,remaining_amount:5000,lines:[]};
const overview={suppliers:[supplier],orders:[],invoices:[invoice],returns:[],credits:[],payments:[]};
const response=(data:unknown,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
function makeStore(){return configureStore({reducer:{session:sessionReducer,supplierOperations:supplierReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:owner}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
function mount(actor=owner){return render(<Provider store={store}><Suppliers actor={actor}/></Provider>);}
it('recovers an uncertain supplier write with the same frozen body and UUID through navigation',async()=>{
 const attempts:unknown[]=[];
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.method==='GET')return response(request.url.endsWith('/current')?{till:null}:overview);if(request.url.endsWith('/preview'))return response({review_hash:'a'.repeat(64)});attempts.push({key:request.headers.get('Idempotency-Key'),body:await request.json()});return attempts.length===1?response({},504):response({supplier,replayed:true});}));
 const view=mount();await screen.findByText('Rice supplier · Active');fireEvent.click(screen.getByRole('button',{name:'Add supplier'}));fireEvent.change(screen.getByLabelText('Supplier name'),{target:{value:'New vendor'}});fireEvent.click(screen.getByRole('button',{name:'Review supplier operation'}));fireEvent.click(await screen.findByRole('button',{name:'Confirm supplier operation'}));await screen.findByRole('button',{name:'Retry same supplier operation'});
 view.unmount();mount();fireEvent.click(screen.getByRole('button',{name:'Retry same supplier operation'}));
 await screen.findByText(/Supplier operation recorded/);expect(attempts).toHaveLength(2);expect(attempts[0]).toEqual(attempts[1]);expect((attempts[0] as {body:Record<string,unknown>}).body).not.toHaveProperty('supplier_id');expect(store.getState().supplierOperations.pending).toBeNull();
});
it('settles explicit invoices using an attested external-payment review and does not send a transfer',async()=>{
 let reviewed:Record<string,unknown>|null=null;let committed:Record<string,unknown>|null=null;
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.method==='GET')return response(request.url.endsWith('/current')?{till:null}:overview);const body=await request.json();if(request.url.endsWith('/preview')){reviewed=body;return response({review_hash:'b'.repeat(64),amount:2000,allocated_amount:2000,external_transfer_executed:false});}committed=body;return response({payment:{id:'spay_one'},external_transfer_executed:false});}));
 mount();fireEvent.click(screen.getByRole('button',{name:'Payables'}));fireEvent.click(screen.getByRole('button',{name:'Record supplier payment'}));
 await screen.findByRole('option',{name:'Rice supplier'});fireEvent.change(screen.getByLabelText('Supplier'),{target:{value:'sup_one'}});
 fireEvent.change(screen.getByLabelText('Payment reference'),{target:{value:'BANK-PAID-1'}});fireEvent.change(await screen.findByLabelText('Allocation for INV-ONE · unpaid TSh 5,000'),{target:{value:'2000'}});fireEvent.click(screen.getByLabelText('I have already completed this external payment; BiziSmart will only record it.'));
 fireEvent.click(screen.getByRole('button',{name:'Review supplier operation'}));fireEvent.click(await screen.findByRole('button',{name:'Confirm supplier operation'}));await screen.findByText(/Supplier operation recorded/);
 expect(reviewed?.allocations).toEqual([{invoice_id:'sinv_one',amount:2000}]);expect(reviewed?.completed).toBe(true);expect(reviewed?.source).toBe('bank');expect(committed?.review_hash).toBe('b'.repeat(64));expect(committed?.amount).toBeUndefined();
});
it('keeps stockkeeper financial queries disabled and clears private pending operations at sign-out',async()=>{
 const paths:string[]=[];vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{paths.push(new URL(request.url).pathname);return response({suppliers:[{id:supplier.id,name:supplier.name}],orders:[]});}));
 store.dispatch(signedIn({token:'keeper-only',expires_at:'2099-01-01',user:keeper}));mount(keeper);await waitFor(()=>expect(paths).toContain('/api/v1/supplier-receiving'));
 expect(paths).not.toContain('/api/v1/suppliers');expect(paths).not.toContain('/api/v1/till-sessions/current');expect(screen.queryByRole('button',{name:'Payables'})).toBeNull();
 store.dispatch(signedOut());expect(store.getState().supplierOperations.pending).toBeNull();
});
