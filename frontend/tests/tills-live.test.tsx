// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Tills } from '../src/features/live/Tills';
import { liveApi, sessionReducer, signedIn, type ServerUser, type Till } from '../src/features/live/api';
const actor: ServerUser = { id:'usr_cashier',name:'Cashier',role:'cashier',branch_id:'br_main',permissions:['manage_till'] };
const till: Till = { id:'till_one',branch_id:'br_main',branch_name:'Main',cashier_id:actor.id,cashier_name:actor.name,status:'open',opening_float:20000,expected_cash:null,closing_cash:null,variance:null,revision:1,opened_at:'2026-10-10T00:00:00Z',closed_at:null,approved_by:null,owner_review_required:false };
let store: ReturnType<typeof makeStore>;
function makeStore(){return configureStore({reducer:{session:sessionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:(g)=>g().concat(liveApi.middleware)});}
function response(data:unknown,status=200){return new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});}
function queries(r:Request,active:Till|null){return new URL(r.url).pathname.endsWith('/current')?response({data:{till:active,branch:{id:'br_main',name:'Main'},variance_limit:5000,can_open:true}}):response({data:{tills:active?[active]:[]},meta:{page:1,total:active?1:0,owner_review_count:0}});}
function mount(){render(<Provider store={store}><Tills actor={actor}/></Provider>);}
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only-token',expires_at:new Date(Date.now()+600000).toISOString(),user:actor}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();localStorage.clear();});
it('freezes uncertain openings and retries the identical operation without fake success',async()=>{
 const bodies:unknown[]=[];let active:Till|null=null;vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{if(r.method==='POST'){bodies.push(await r.json());if(bodies.length===1)throw new Error('Lost response');active=till;return response({data:{till,replayed:true}});}return queries(r,active);}));mount();fireEvent.click(await screen.findByRole('button',{name:'Open till'}));const dialog=within(screen.getByRole('dialog'));fireEvent.change(dialog.getByLabelText('Opening float (TSh)'),{target:{value:'20000'}});fireEvent.click(dialog.getByRole('button',{name:'Confirm opening'}));await screen.findByText(/result is not confirmed/);expect(screen.queryByText('Till opened.')).toBeNull();expect(dialog.getByLabelText('Opening float (TSh)').closest('fieldset')?.disabled).toBe(true);fireEvent.click(dialog.getByRole('button',{name:'Retry operation'}));await screen.findByText('Till opened.');expect(bodies[1]).toEqual(bodies[0]);expect((bodies[0] as {opening_float:number}).opening_float).toBe(20000);
});
it('closes blind, reveals the variance only after the approver authenticates, and keeps the exact operation',async()=>{
 let active:Till|null=till;let firstBody:Record<string,unknown>|undefined;let approvalBody:Record<string,unknown>|undefined;let commitBody:Record<string,unknown>|undefined;let closeAttempts=0;
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>{const path=new URL(r.url).pathname;if(r.method==='POST'){const body=await r.json();
  if(path.endsWith('supervisor-check')){approvalBody=body;expect(body.permission).toBe('approve_variance');expect(body.till_id).toBe('till_one');expect(body.counted_cash).toBe(0);return response({data:{approval_token:'b'.repeat(64),approved_by:'usr_manager',expires_at:new Date(Date.now()+300000).toISOString(),review:{expected_cash:18000,counted_cash:0,variance:-18000,variance_limit:5000}}});}
  if(path.endsWith('/close')){closeAttempts++;if(closeAttempts===1){firstBody=body;return response({error:{code:'SUPERVISOR_REQUIRED',message:'An owner or your branch manager must approve this variance.',field:'approval_token',details:{permission:'approve_variance',payload_hash:'a'.repeat(64)}}},403);}commitBody=body;active=null;return response({data:{till:{...till,status:'closed',expected_cash:18000,closing_cash:0,variance:-18000},replayed:false}},201);}
  return response({error:{code:'INTERNAL_ERROR',message:'Unexpected request.'}},500);}return queries(r,active);}));
 mount();fireEvent.click(await screen.findByRole('button',{name:'Close till'}));const dialog=within(screen.getByRole('dialog'));fireEvent.change(dialog.getByLabelText('Counted cash (TSh)'),{target:{value:'0'}});fireEvent.click(dialog.getByRole('button',{name:'Close till'}));
 await screen.findByText('An owner or your branch manager must approve this variance.');
 expect(screen.queryByText(/Variance:/)).toBeNull();
 expect(firstBody).toEqual({operation_id:expect.any(String),till_id:'till_one',counted_cash:0});
 fireEvent.change(dialog.getByLabelText('Supervisor email'),{target:{value:'supervisor@example.test'}});
 fireEvent.change(dialog.getByLabelText('Supervisor password'),{target:{value:'supervisor-password-42'}});
 fireEvent.click(dialog.getByRole('button',{name:'Request variance approval'}));
 await screen.findByText('Supervisor approval received.');screen.getByText(/Variance: TSh -18,000/);
 expect(approvalBody?.payload_hash).toBe('a'.repeat(64));expect(approvalBody?.operation_id).toBe(firstBody!.operation_id);
 await waitFor(()=>expect(JSON.stringify(store.getState().liveApi.mutations)).not.toContain('b'.repeat(64)));
 fireEvent.click(dialog.getByRole('button',{name:'Confirm close'}));
 await screen.findByText('Till closed. Variance: TSh -18,000');
 expect(commitBody).toEqual({operation_id:firstBody!.operation_id,till_id:'till_one',counted_cash:0,approval_token:'b'.repeat(64)});
 expect(commitBody).not.toHaveProperty('expected_cash');expect(commitBody).not.toHaveProperty('revision');expect(commitBody).not.toHaveProperty('variance_limit');expect(commitBody).not.toHaveProperty('payload_hash');expect(commitBody).not.toHaveProperty('branch_id');
 expect(closeAttempts).toBe(2);
});
it('keeps failed paid outs editable and shows no success or changed balance',async()=>{
 vi.stubGlobal('fetch',vi.fn(async(r:Request)=>r.method==='POST'?response({error:{code:'VALIDATION_ERROR',message:'Enter a positive amount.'}},422):queries(r,till)));mount();fireEvent.click(await screen.findByRole('button',{name:'Record paid-out'}));const dialog=within(screen.getByRole('dialog'));fireEvent.change(dialog.getByLabelText('Paid-out amount (TSh)'),{target:{value:'0'}});fireEvent.change(dialog.getByLabelText('Paid-out reason'),{target:{value:'Transport'}});fireEvent.click(dialog.getByRole('button',{name:'Confirm paid-out'}));await screen.findByText('Enter a positive amount.');expect(screen.queryByText('Cash paid-out recorded.')).toBeNull();expect(dialog.getByLabelText('Paid-out amount (TSh)').closest('fieldset')?.disabled).toBe(false);
});
