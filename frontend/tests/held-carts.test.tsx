// @vitest-environment jsdom
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup,fireEvent,render,screen,waitFor } from '@testing-library/react';
import { afterEach,beforeEach,expect,it,vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import HeldCarts from '../src/features/live/HeldCarts';
import { heldActions,heldReducer } from '../src/features/live/heldState';
import { liveApi,sessionReducer,signedIn,signedOut } from '../src/features/live/api';
const draft={items:[{product_id:'prod_one',packaging_id:null,qty:'0.750',discount:100}],discount:50,discount_reason:'Approved discount',credit_amount:0};
const cart={id:'hold_one',branch_id:'br_one',status:'held',draft,created_at:'2026-10-12T10:00:00Z',expires_at:'2026-10-13T10:00:00Z'};
function makeStore(){return configureStore({reducer:{session:sessionReducer,held:heldReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
const response=(data:unknown,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
beforeEach(()=>{const controller=transferableAbortController();vi.stubGlobal('AbortController',controller.constructor);vi.stubGlobal('AbortSignal',controller.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:{id:'usr_one',name:'Cashier',role:'cashier',branch_id:'br_one',permissions:['sell']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
it('retains the identical hold UUID and draft through uncertainty and component navigation',async()=>{
  const requests:unknown[]=[];
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
    if(request.method==='GET')return response([]);
    requests.push({key:request.headers.get('Idempotency-Key'),body:await request.json()});
    return requests.length===1?response({},504):response(cart);
  }));
  const onSaved=vi.fn();
  const mount=()=>render(<Provider store={store}><HeldCarts open branchId="br_one" draft={draft} locked={false} onClose={vi.fn()} onSaved={onSaved} onResume={vi.fn()}/></Provider>);
  const view=mount();fireEvent.click(screen.getByRole('button',{name:'Hold current cart'}));
  await screen.findByRole('button',{name:'Retry same hold operation'});view.unmount();mount();
  fireEvent.click(screen.getByRole('button',{name:'Retry same hold operation'}));
  await waitFor(()=>expect(onSaved).toHaveBeenCalledOnce());
  expect(requests[0]).toEqual(requests[1]);expect(store.getState().held.pending).toBeNull();
});
it('prevents merging a held cart into a nonempty draft and clears pending drafts at sign-out',async()=>{
  vi.stubGlobal('fetch',vi.fn(async()=>response([cart])));
  render(<Provider store={store}><HeldCarts open branchId="br_one" draft={draft} locked={false} onClose={vi.fn()} onSaved={vi.fn()} onResume={vi.fn()}/></Provider>);
  expect((await screen.findByRole('button',{name:'Resume cart'})).hasAttribute('disabled')).toBe(true);
  store.dispatch(heldActions.submitting({action:'save',key:'same-key',body:{...draft,branch_id:'br_one'}}));
  store.dispatch(signedOut());expect(store.getState().held.pending).toBeNull();
});
it('retains a claimed handoff key while fresh catalogue restoration is unavailable',async()=>{
  const keys:string[]=[];
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
    if(request.method==='GET')return response([cart]);
    keys.push(request.headers.get('Idempotency-Key')!);return response({...cart,status:'resumed'});
  }));
  const onResume=vi.fn().mockRejectedValueOnce(new Error('Catalogue unavailable')).mockResolvedValueOnce(undefined);
  render(<Provider store={store}><HeldCarts open branchId="br_one" draft={null} locked={false} onClose={vi.fn()} onSaved={vi.fn()} onResume={onResume}/></Provider>);
  fireEvent.click(await screen.findByRole('button',{name:'Resume cart'}));
  const retry=await screen.findByRole('button',{name:'Retry same hold operation'});
  await waitFor(()=>expect(retry.hasAttribute('disabled')).toBe(false));
  expect(store.getState().held.pending?.action).toBe('resume');
  fireEvent.click(retry);await waitFor(()=>expect(store.getState().held.pending).toBeNull());
  expect(keys).toHaveLength(2);expect(keys[0]).toBe(keys[1]);
});
it('preserves a recorded handoff through a catalogue 404 and recovers with the original resume UUID',async()=>{
  const keys:string[]=[];
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
    if(request.method==='GET')return response([cart]);
    keys.push(request.headers.get('Idempotency-Key')!);return response({...cart,status:'resumed',replayed:keys.length>1});
  }));
  const onResume=vi.fn().mockRejectedValueOnce({status:404,data:{error:{code:'NOT_FOUND',message:'Product unavailable.'}}}).mockResolvedValueOnce(undefined);
  const mount=()=>render(<Provider store={store}><HeldCarts open branchId="br_one" draft={null} locked={false} onClose={vi.fn()} onSaved={vi.fn()} onResume={onResume}/></Provider>);
  const view=mount();fireEvent.click(await screen.findByRole('button',{name:'Resume cart'}));
  await screen.findByRole('alert');
  expect(store.getState().held.pending?.action).toBe('resume');
  view.unmount();mount();
  fireEvent.click(screen.getByRole('button',{name:'Retry same hold operation'}));
  await waitFor(()=>expect(store.getState().held.pending).toBeNull());
  expect(keys).toHaveLength(2);expect(keys[0]).toBe(keys[1]);expect(onResume).toHaveBeenCalledTimes(2);
});
it('does not clear a new session operation when old session hydration finishes late',async()=>{
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>response(request.method==='GET'?[cart]:{...cart,status:'resumed'})));
  let finish:(()=>void)|undefined;
  const onResume=vi.fn(()=>new Promise<void>(resolve=>{finish=resolve;}));
  render(<Provider store={store}><HeldCarts open branchId="br_one" draft={null} locked={false} onClose={vi.fn()} onSaved={vi.fn()} onResume={onResume}/></Provider>);
  fireEvent.click(await screen.findByRole('button',{name:'Resume cart'}));await waitFor(()=>expect(finish).toBeDefined());
  store.dispatch(signedOut());
  store.dispatch(signedIn({token:'next-session',expires_at:'2099-01-01',user:{id:'usr_next',name:'Other cashier',role:'cashier',branch_id:'br_one',permissions:['sell']}}));
  store.dispatch(heldActions.submitting({action:'delete',id:'hold_other',key:'next-session-operation'}));
  finish!();await waitFor(()=>expect(screen.getByRole('button',{name:'Retry same hold operation'}).hasAttribute('disabled')).toBe(false));
  expect(store.getState().held.pending?.key).toBe('next-session-operation');
});
