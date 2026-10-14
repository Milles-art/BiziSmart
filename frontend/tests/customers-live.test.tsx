// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Customers } from '../src/features/live/Customers';
import { collectionReducer } from '../src/features/live/collectionState';
import { liveApi, sessionReducer, signedIn, signedOut } from '../src/features/live/api';
const customer={id:'cust_one',name:'Asha',phone:'255620000000',credit_limit:50000,outstanding_balance:15000,created_at:'2026-10-10T00:00:00Z'};
const debt={customer_id:customer.id,outstanding_balance:15000,credit_limit:50000,aging:{'0_30':10000,'31_60':5000,'61_90':0,'90_plus':0},open_sales:[
  {sale_id:'sale_old',receipt_number:'RCP-old',remaining_amount:5000,original_amount:5000,created_at:'2026-08-20T00:00:00Z'},
  {sale_id:'sale_new',receipt_number:'RCP-new',remaining_amount:10000,original_amount:10000,created_at:'2026-10-10T00:00:00Z'}]};
const review={customer_id:customer.id,till_id:'till_one',method:'cash',amount:5000,outstanding_balance:15000,balance_after:10000,allocations:[{sale_id:'sale_new',amount:5000,expected_balance:10000}],review_hash:'a'.repeat(64)};
const payment={id:'cpay_one',customer_id:customer.id,method:'cash',amount:5000,created_at:'2026-10-10T00:00:00Z',replayed:false};
function makeStore(){return configureStore({reducer:{session:sessionReducer,collection:collectionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:(g)=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
const response=(data:unknown,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
function query(r:Request){if(r.url.endsWith('/loyalty'))return response({data:{customer_id:customer.id,points:0,available_points:0,refund_carry:0,point_value:null,redemption_enabled:false,configuration_enabled:false}});if(r.url.includes('/till-sessions/current'))return response({data:{till:{id:'till_one'}}});if(r.url.includes('/debt'))return response({data:debt});return response({data:{customers:[customer]},meta:{page:1,per_page:30,total:1}});}
const mount=()=>render(<Provider store={store}><Customers/></Provider>);
async function allocations(){fireEvent.click(await screen.findByRole('button',{name:'Asha',exact:true}));fireEvent.click(await screen.findByRole('checkbox',{name:'RCP-new'}));fireEvent.change(screen.getByLabelText('Allocation for RCP-new'),{target:{value:'5000'}});fireEvent.change(screen.getByLabelText('Cash collection amount (TSh)'),{target:{value:'5000'}});fireEvent.click(screen.getByRole('button',{name:'Review cash collection'}));return within(await screen.findByRole('dialog',{name:'Review debt collection'}));}
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-token',expires_at:new Date(Date.now()+600000).toISOString(),user:{id:'usr_one',name:'Cashier',role:'cashier',branch_id:'br_one',permissions:['manage_customers','manage_till']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();localStorage.clear();sessionStorage.clear();});
it('collects only the selected newer invoice using the server review and saved result',async()=>{
  let body:Record<string,unknown>|undefined;let key:string|null=null;
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='POST'){if(r.url.endsWith('/preview'))return response({data:review});body=await r.json();key=r.headers.get('Idempotency-Key');return response({data:payment},201);}return query(r);}));
  mount();const dialog=await allocations();expect(dialog.getByText('Remaining customer debt: TSh 10,000')).toBeTruthy();fireEvent.click(dialog.getByRole('button',{name:'Confirm cash collection'}));await screen.findByText(/Cash collection recorded: TSh 5,000/);
  expect(body?.allocations).toEqual([{sale_id:'sale_new',amount:5000}]);expect(body?.review_hash).toBe(review.review_hash);expect(key).toMatch(/^[0-9a-f-]{36}$/);expect(store.getState().collection.pending).toBeNull();expect(localStorage.length).toBe(0);expect(sessionStorage.length).toBe(0);
});
it('retries the identical uncertain collection after navigating away without showing false success',async()=>{
  const attempts:unknown[]=[];vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='POST'){if(r.url.endsWith('/preview'))return response({data:review});attempts.push({key:r.headers.get('Idempotency-Key'),body:await r.json()});return attempts.length===1?response({error:{code:'SERVER_ERROR',message:'Connection interrupted'}},500):response({data:{...payment,replayed:true}});}return query(r);}));
  const view=mount();const dialog=await allocations();fireEvent.click(dialog.getByRole('button',{name:'Confirm cash collection'}));await screen.findByText(/Collection outcome is not confirmed/);expect(screen.queryByText(/Cash collection recorded/)).toBeNull();view.unmount();mount();fireEvent.click(await screen.findByRole('button',{name:'Retry same collection'}));await screen.findByText(/Cash collection recorded/);expect(attempts[0]).toEqual(attempts[1]);
});
it('retains profile fields when the real API rejects a duplicate phone',async()=>{
  vi.stubGlobal('fetch',vi.fn(async(r:Request)=>r.method==='POST'?response({error:{code:'DUPLICATE_CUSTOMER_PHONE',message:'Phone already registered.'}},409):query(r)));
  mount();fireEvent.click(screen.getByRole('button',{name:'Add Customer'}));const dialog=within(await screen.findByRole('dialog',{name:'Add customer'}));fireEvent.change(dialog.getByLabelText('Customer name'),{target:{value:'Neema'}});fireEvent.change(dialog.getByLabelText('Customer phone (optional)'),{target:{value:'0620000000'}});fireEvent.click(dialog.getByRole('button',{name:'Save customer'}));await dialog.findByText('Phone already registered.');expect(dialog.getByLabelText('Customer name')).toHaveProperty('value','Neema');expect(dialog.getByLabelText('Customer phone (optional)')).toHaveProperty('value','0620000000');
});
it('clears financial state on logout and ignores a late collection response',async()=>{
  let finish:((r:Response)=>void)|undefined;vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='POST'){if(r.url.endsWith('/preview'))return response({data:review});return new Promise<Response>((resolve)=>{finish=resolve;});}return query(r);}));
  mount();const dialog=await allocations();fireEvent.click(dialog.getByRole('button',{name:'Confirm cash collection'}));await waitFor(()=>expect(finish).toBeDefined());store.dispatch(signedOut());finish!(response({data:payment},201));await waitFor(()=>expect(store.getState().liveApi.mutations).toEqual({}));expect(store.getState().collection.pending).toBeNull();expect(store.getState().collection.receipt).toBeNull();
});
