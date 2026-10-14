// @vitest-environment jsdom
import React from 'react';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import {Returns} from '../src/features/live/Returns';
import {returnReducer,returnActions} from '../src/features/live/returnState';
import {checkoutReducer} from '../src/features/live/checkoutState';
import {liveApi,sessionReducer,signedIn,signedOut} from '../src/features/live/api';
const original={sale_id:'sale_one',receipt_number:'RCP-2026-000001',total:20000,branch_id:'br_one',return_status:'none',lines:[{line_index:0,name:'Rice',unit:'bag',quantity_type:'counted',remaining_qty:'2',returned_qty:'0'}]};
const review={review_hash:'a'.repeat(64),refund_total:10000,debt_reduction:6000,cash_refund:4000,cash_required:0,cash_paid:4000,additional_cash:0,replacement:null,lines:[{line_index:0,name:'Rice',qty:'1',unit:'bag',amount:10000}],allocations:[{quarantined:true}]};
const saved={id:'ret_one',sale_id:'sale_one',refund_total:10000,debt_reduction:6000,cash_refund:4000,cash_collected:0,cash_paid:4000,replacement:null,replayed:false};
function makeStore(){return configureStore({reducer:{returns:returnReducer,checkout:checkoutReducer,session:sessionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
function response(data:unknown,status=200){return new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});}
function mount(){return render(<Provider store={store}><Returns tillId="till_one" entries={[]} onClose={vi.fn()}/></Provider>);}
async function inspect(){fireEvent.change(screen.getByLabelText('Receipt number'),{target:{value:original.receipt_number}});fireEvent.click(screen.getByRole('button',{name:'Find receipt'}));fireEvent.change(await screen.findByLabelText('Return quantity · line 1'),{target:{value:'1'}});fireEvent.change(screen.getByLabelText('Return reason'),{target:{value:'Damaged on return'}});fireEvent.click(screen.getByLabelText('Damaged · line 1'));fireEvent.click(screen.getByRole('button',{name:'Review return'}));await screen.findByText('Review return');}
async function approve(){fireEvent.change(screen.getByLabelText('Supervisor phone'),{target:{value:'0710000001'}});fireEvent.change(screen.getByLabelText('Supervisor PIN'),{target:{value:'1234'}});fireEvent.click(screen.getByRole('button',{name:'Request return approval'}));await screen.findByText('Supervisor approval received.');}
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:new Date(Date.now()+600000).toISOString(),user:{id:'usr_one',name:'Cashier',role:'cashier',branch_id:'br_one',permissions:['sell','manage_till']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();localStorage.clear();sessionStorage.clear();});
it('submits exact server-reviewed returns and requires supervisor approval',async()=>{
 let body:Record<string,unknown>|null=null;let key:string|null=null;
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='GET')return response({data:original});if(r.url.endsWith('/preview'))return response({data:review});if(r.url.endsWith('/supervisor-check'))return response({data:{approval_token:'b'.repeat(64),approved_by:'usr_manager'}});body=await r.json();key=r.headers.get('Idempotency-Key');return response({data:saved},201);}));
 mount();await inspect();expect(screen.getByRole('button',{name:'Confirm return'})).toHaveProperty('disabled',true);expect(screen.getByText('Debt reduced: TSh 6,000')).toBeTruthy();expect(screen.getByText(/separate quarantined batches/)).toBeTruthy();await approve();fireEvent.click(screen.getByRole('button',{name:'Confirm return'}));await screen.findByText('Return completed');
 expect(body?.review_hash).toBe(review.review_hash);expect(body?.refund_total).toBeUndefined();expect(key).toBe(body?.operation_id);expect(store.getState().returns.pending).toBeNull();expect(localStorage.length).toBe(0);expect(sessionStorage.length).toBe(0);
});
it('binds the completed void shortcut to all remaining quantities and clears void intent after manual editing',async()=>{
 const previews:Record<string,unknown>[]=[];
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='GET')return response({data:{...original,lines:[...original.lines,{line_index:1,name:'Already returned rice',unit:'kg',quantity_type:'weight',remaining_qty:'0.000',returned_qty:'1.000'}]}});if(r.url.endsWith('/preview')){previews.push(await r.json());return response({data:review});}return response({data:{}});}));
 mount();fireEvent.change(screen.getByLabelText('Receipt number'),{target:{value:original.receipt_number}});fireEvent.click(screen.getByRole('button',{name:'Find receipt'}));fireEvent.click(await screen.findByRole('button',{name:'Void remaining receipt items'}));fireEvent.change(screen.getByLabelText('Return reason'),{target:{value:'Approved completed receipt void'}});fireEvent.click(screen.getByRole('button',{name:'Review return'}));await screen.findByRole('button',{name:'Back to return'});
 expect(previews[0].operation_kind).toBe('void');expect(previews[0].items).toEqual([{line_index:0,qty:'2',disposition:'sellable'}]);
 fireEvent.click(screen.getByRole('button',{name:'Back to return'}));fireEvent.change(screen.getByLabelText('Return quantity · line 1'),{target:{value:'1'}});fireEvent.click(screen.getByRole('button',{name:'Review return'}));await waitFor(()=>expect(previews).toHaveLength(2));expect(previews[1]).not.toHaveProperty('operation_kind');expect(previews[1].items).toEqual([{line_index:0,qty:'1',disposition:'sellable'}]);
});
it('retains identical key and body through uncertain outcomes and screen navigation',async()=>{
 const attempts:unknown[]=[];vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='GET')return response({data:original});if(r.url.endsWith('/preview'))return response({data:review});if(r.url.endsWith('/supervisor-check'))return response({data:{approval_token:'b'.repeat(64)}});attempts.push({key:r.headers.get('Idempotency-Key'),body:await r.json()});return attempts.length===1?response({error:{code:'SERVER_ERROR',message:'Interrupted'}},500):response({data:{...saved,replayed:true}});}));
 const view=mount();await inspect();await approve();fireEvent.click(screen.getByRole('button',{name:'Confirm return'}));await screen.findByText(/Return outcome is not confirmed/);view.unmount();mount();fireEvent.click(screen.getByRole('button',{name:'Retry same return'}));await screen.findByText('Return completed');expect(attempts[0]).toEqual(attempts[1]);
});
it('rejects stale reviews without displaying success or discarding entered quantities',async()=>{
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>r.method==='GET'?response({data:original}):r.url.endsWith('/preview')?response({data:review}):r.url.endsWith('/supervisor-check')?response({data:{approval_token:'b'.repeat(64)}}):response({error:{code:'RETURN_CHANGED',message:'Review this return again.'}},409)));
 mount();await inspect();await approve();fireEvent.click(screen.getByRole('button',{name:'Confirm return'}));await screen.findByText('Review this return again.');expect(screen.queryByText('Return completed')).toBeNull();expect(screen.getByLabelText('Return quantity · line 1')).toHaveProperty('value','1');expect(store.getState().returns.pending).toBeNull();
});
it('clears pending sensitive return state on sign-out and ignores late saved results',async()=>{
 let finish:((r:Response)=>void)|undefined;
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='GET')return response({data:original});if(r.url.endsWith('/preview'))return response({data:review});if(r.url.endsWith('/supervisor-check'))return response({data:{approval_token:'b'.repeat(64)}});return new Promise<Response>(resolve=>{finish=resolve;});}));
 mount();await inspect();await approve();fireEvent.click(screen.getByRole('button',{name:'Confirm return'}));await waitFor(()=>expect(finish).toBeDefined());store.dispatch(signedOut());finish!(response({data:saved}));await waitFor(()=>expect(store.getState().liveApi.mutations).toEqual({}));expect(store.getState().returns.receipt).toBeNull();expect(store.getState().returns.pending).toBeNull();
 store.dispatch(returnActions.clear());
});

it('shows an actionable missing-receipt error without enabling a return',async()=>{
 vi.stubGlobal('fetch',vi.fn(async()=>response({error:{code:'NOT_FOUND',message:'Not found'}},404)));
 mount();fireEvent.change(screen.getByLabelText('Receipt number'),{target:{value:'RCP-2026-999999'}});fireEvent.click(screen.getByRole('button',{name:'Find receipt'}));await screen.findByText('No matching receipt was found in this shop.');expect(screen.queryByRole('button',{name:'Review return'})).toBeNull();expect(screen.queryByText('Return completed')).toBeNull();
});

it('requires recorded external mobile refund details before requesting bound approval',async()=>{
 let posted:Record<string,unknown>|null=null;
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='GET')return response({data:{...original,has_mobile_payment:true}});if(r.url.endsWith('/preview'))return response({data:{...review,cash_refund:2500,cash_paid:2500,mobile_refund:6000,refund_total:8500}});if(r.url.endsWith('/supervisor-check'))return response({data:{approval_token:'b'.repeat(64)}});posted=await r.json();return response({data:{...saved,mobile_refund:6000,mobile_refund_reference:'EXTERNAL-1',external_transfer_executed:false}});}));
 mount();await inspect();expect(screen.queryByRole('button',{name:'Request return approval'})).toBeNull();fireEvent.click(screen.getByRole('button',{name:'Back to return'}));fireEvent.change(screen.getByLabelText('External mobile refund reference'),{target:{value:'EXTERNAL-1'}});fireEvent.click(screen.getByLabelText('The mobile refund has already been paid outside BiziSmart.'));fireEvent.click(screen.getByRole('button',{name:'Review return'}));await screen.findByText('Review return');await approve();fireEvent.click(screen.getByRole('button',{name:'Confirm return'}));await screen.findByText(/External mobile refund recorded: TSh 6,000/);expect(posted?.mobile_refund_reference).toBe('EXTERNAL-1');expect(posted?.mobile_refund_completed).toBe(true);expect(posted?.mobile_refund).toBeUndefined();
});
