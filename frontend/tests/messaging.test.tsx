// @vitest-environment jsdom
import React from 'react';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import {liveApi,sessionReducer,signedIn,signedOut} from '../src/features/live/api';
import {messagingActions,messagingReducer} from '../src/features/live/messagingState';
import {CustomerMessaging,Messaging,MessagingRecovery} from '../src/features/live/Messaging';
function makeStore(){return configureStore({reducer:{session:sessionReducer,messaging:messagingReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
const response=(data:unknown,status=200)=>new Response(JSON.stringify({data,...(status>=400?{error:{code:'SERVER_ERROR',message:'Response uncertain'}}:{})}),{status,headers:{'Content-Type':'application/json'}});
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-token',expires_at:new Date(Date.now()+600000).toISOString(),user:{id:'usr_owner',name:'Owner',role:'owner',branch_id:null,permissions:['manage_customers']}}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
function mount(ui:React.ReactNode){return render(<Provider store={store}>{ui}</Provider>);}
it('shows unconfigured and accepted states without claiming actual delivery',async()=>{
 vi.stubGlobal('fetch',vi.fn(async()=>response({channels:{whatsapp:{ready:false,reason:'provider_setup_required'},sms:{ready:false,reason:'provider_setup_required'}},deliveries:[{id:'msg_1',channel:'sms',purpose:'receipts',status:'accepted',failure_code:null,created_at:'2026-10-14T09:00:00Z',accepted_at:'2026-10-14T09:00:01Z',delivered_at:null}]})));
 mount(<Messaging/>);await screen.findByText('sms · receipts');expect(screen.getByText('accepted',{exact:true})).toBeTruthy();expect(screen.getAllByText(/Provider setup required/)).toHaveLength(2);expect(screen.queryByText('delivered',{exact:true})).toBeNull();
 expect(screen.getByText('Delivered',{selector:'p'}).parentElement?.textContent).toContain('0');
 fireEvent.click(screen.getByRole('button',{name:'Delivered',exact:true}));expect(screen.queryByText('sms · receipts')).toBeNull();expect(screen.getByText('No delivery records match this filter.')).toBeTruthy();
 fireEvent.click(screen.getByRole('button',{name:'Accepted',exact:true}));expect(screen.getByText('sms · receipts')).toBeTruthy();
});
it('retains the same request UUID and body through uncertain remount and clears on signout',async()=>{
 const frozen={action:'consent' as const,key:'5c61eaec-a93e-4d36-85a4-68c1a27d08ce',body:{customer_id:'cust_1',expected_revision:0,whatsapp:true,sms:false,receipts:true,debt_reminders:false,evidence:'Customer agreed'}};store.dispatch(messagingActions.submitting(frozen));let attempts=0;const writes:{key:string|null;body:string}[]=[];
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{writes.push({key:request.headers.get('Idempotency-Key'),body:await request.clone().text()});return ++attempts===1?response({},500):response({consent:{revision:1},replayed:true});}));
 const first=mount(<MessagingRecovery/>);fireEvent.click(screen.getByRole('button',{name:'Retry same messaging request'}));await screen.findByText('Response uncertain');expect(store.getState().messaging.pending).toEqual(frozen);first.unmount();mount(<MessagingRecovery/>);fireEvent.click(screen.getByRole('button',{name:'Retry same messaging request'}));await waitFor(()=>expect(store.getState().messaging.pending).toBeNull());expect(writes).toEqual([{key:frozen.key,body:JSON.stringify(frozen.body)},{key:frozen.key,body:JSON.stringify(frozen.body)}]);store.dispatch(messagingActions.submitting(frozen));store.dispatch(signedOut());expect(store.getState().messaging.pending).toBeNull();
});
it('requires explicit consent and evidence before customer message actions become available',async()=>{
 vi.stubGlobal('fetch',vi.fn(async()=>response({whatsapp:false,sms:false,receipts:false,debt_reminders:false,revision:0})));mount(<CustomerMessaging customerId="cust_1"/>);await screen.findByRole('button',{name:'Save customer consent'});expect((screen.getByRole('button',{name:'Queue debt reminder (owner)'})as HTMLButtonElement).disabled).toBe(true);expect((screen.getByLabelText('Consent evidence or revocation reason')as HTMLInputElement).required).toBe(true);
});
