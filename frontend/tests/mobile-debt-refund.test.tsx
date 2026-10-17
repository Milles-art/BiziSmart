// @vitest-environment jsdom
import React from 'react';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import {MobileDebtPayment} from '../src/features/live/MobileDebtPayment';
import {ManualMobileRefund} from '../src/features/live/ManualMobileRefund';
import {collectionReducer} from '../src/features/live/collectionState';
import {mobileRefundReducer} from '../src/features/live/mobileRefundState';
import {liveApi,sessionReducer,signedIn,type CollectionReview} from '../src/features/live/api';
const review:CollectionReview={customer_id:'cust_one',till_id:'till_one',amount:6000,method:'mpesa',allocations:[{sale_id:'sale_new',amount:6000}],outstanding_balance:10000,balance_after:4000,review_hash:'a'.repeat(64)};
const payment={id:'mpay_one',purpose:'debt',status:'confirmed',amount:6000,phone_suffix:'3714',reference:'ref_one'};
const saved={id:'cpay_one',customer_id:'cust_one',method:'mpesa',amount:6000,created_at:'2026-10-10',replayed:false};
function makeStore(){return configureStore({reducer:{session:sessionReducer,collection:collectionReducer,mobileRefund:mobileRefundReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
function response(data:unknown,status=200){return new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});}
function mountDebt(){return render(<Provider store={store}><MobileDebtPayment open review={review} onClose={vi.fn()}/></Provider>);}
beforeEach(()=>{const controller=transferableAbortController();vi.stubGlobal('AbortController',controller.constructor);vi.stubGlobal('AbortSignal',controller.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:{id:'usr_one',name:'Owner',role:'owner',branch_id:'br_one',permissions:['sell','manage_customers','refund']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
function fillPayer(){fireEvent.change(screen.getByLabelText('Payer name'),{target:{value:'Asha'}});fireEvent.change(screen.getByLabelText('Payer email'),{target:{value:'asha@example.com'}});fireEvent.change(screen.getByLabelText('Payer phone'),{target:{value:'0624643714'}});fireEvent.click(screen.getByRole('button',{name:'Send payment request'}));}
it('settles the chosen invoice only after verification and clears the pending payer body',async()=>{
 let body:Record<string,unknown>|null=null;vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.url.endsWith('/selcom')){body=await r.json();return response({...payment,status:'awaiting'});}if(r.url.endsWith('/complete'))return response(saved);return response(payment);}));
 mountDebt();fillPayer();await screen.findByText('Payment verified. Settle the selected invoices.');expect(body?.collection).toEqual({customer_id:'cust_one',till_id:'till_one',method:'mpesa',amount:6000,allocations:[{sale_id:'sale_new',amount:6000}],review_hash:review.review_hash});expect(store.getState().collection.mobile?.body).toBeNull();expect(store.getState().collection.receipt).toBeNull();fireEvent.click(screen.getByRole('button',{name:'Complete recorded collection'}));await waitFor(()=>expect(store.getState().collection.receipt?.id).toBe('cpay_one'));expect(store.getState().collection.mobile).toBeNull();
});
it('recovers an uncertain debt request with the identical body after navigation',async()=>{
 const requests:unknown[]=[];vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.url.endsWith('/selcom')){requests.push({key:r.headers.get('Idempotency-Key'),body:await r.json()});return requests.length===1?response({},504):response({...payment,status:'awaiting'});}return response({...payment,status:'unknown'});}));
 const view=mountDebt();fillPayer();await screen.findByRole('button',{name:'Recover same request'});view.unmount();mountDebt();fireEvent.click(screen.getByRole('button',{name:'Recover same request'}));await screen.findByText('Awaiting provider confirmation. Do not collect again.');expect(requests).toHaveLength(2);expect(requests[0]).toEqual(requests[1]);expect(store.getState().collection.receipt).toBeNull();
});
it('records an approved external refund with a frozen retry and clears owner credentials',async()=>{
 const records:unknown[]=[];vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.url.endsWith('/preview'))return response({amount:6000,review_hash:'c'.repeat(64),external_transfer_executed:false});if(r.url.endsWith('/supervisor-check'))return response({approval_token:'d'.repeat(64)});records.push(await r.json());return records.length===1?response({},504):response({id:'mref_one',amount:6000,external_reference:'REF-1',external_transfer_executed:false,replayed:true});}));
 const mount=()=>render(<Provider store={store}><ManualMobileRefund id="mpay_one" onClose={vi.fn()}/></Provider>);const view=mount();fireEvent.change(screen.getByLabelText('External refund reference'),{target:{value:'REF-1'}});fireEvent.change(screen.getByLabelText('Refund reason'),{target:{value:'Unavailable goods'}});fireEvent.click(screen.getByLabelText('I have already returned this payment to the payer.'));fireEvent.click(screen.getByRole('button',{name:'Review refund record'}));fireEvent.change(await screen.findByLabelText('Owner email'),{target:{value:'owner@example.test'}});fireEvent.change(screen.getByLabelText('Owner password'),{target:{value:'supervisor-password-42'}});fireEvent.click(screen.getByRole('button',{name:'Approve refund record'}));fireEvent.click(await screen.findByRole('button',{name:'Confirm recorded refund'}));await screen.findByRole('button',{name:'Retry same refund record'});expect(JSON.stringify(store.getState().mobileRefund)).not.toContain('654321');expect(JSON.stringify(store.getState().mobileRefund)).not.toContain('supervisor-password-42');view.unmount();mount();fireEvent.click(screen.getByRole('button',{name:'Retry same refund record'}));await screen.findByText('Refund recorded: mref_one');expect(records[0]).toEqual(records[1]);expect(store.getState().mobileRefund.pending).toBeNull();
});
