// @vitest-environment jsdom
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {Provider} from 'react-redux';
import {configureStore} from '@reduxjs/toolkit';
import {webcrypto} from 'node:crypto';
import {transferableAbortController} from 'node:util';
import {OfflinePOS} from '../src/features/live/OfflinePOS';
import {liveApi,sessionReducer,signedIn,type ServerUser} from '../src/features/live/api';
import {setLanguage} from '../src/i18n';
import type {OfflineLease,OfflineQueuedSale} from '../src/features/live/offlineTypes';
const vault=vi.hoisted(()=>({load:vi.fn(),read:vi.fn(),saveLease:vi.fn(),saveSale:vi.fn(),ack:vi.fn(),validate:vi.fn()}));
vi.mock('../src/features/live/offlineVault',()=>({loadOfflineLease:vault.load,readOfflineQueue:vault.read,saveOfflineLease:vault.saveLease,saveOfflineSale:vault.saveSale,acknowledgeOfflineSale:vault.ack,validateOfflineLease:vault.validate}));
const actor:ServerUser={id:'usr_TEST',name:'Cashier',role:'cashier',branch_id:'br_TEST',permissions:['sell','manage_till']};
const lease:OfflineLease={lease_id:'ol_TEST',device_uid:'device_test',cashier_id:actor.id,issued_at:new Date(Date.now()-1000).toISOString(),expires_at:new Date(Date.now()+86399000).toISOString(),secret:'0'.repeat(64),shop_name:'Private shop',cashier_name:'Cashier',branch_name:'Main',vat_rate:18,truncated:false,automatic_offers_enabled:false,catalogue:[{snapshot_id:'22222222-2222-4222-8222-222222222222',product_id:'prod_TEST',name:'Rice',barcode:'123',quantity_type:'weight',unit:'kg',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1000',unit_price:10000,stock:'5',proof:'a'.repeat(64)}]};
let store:ReturnType<typeof makeStore>;
function makeStore(){return configureStore({reducer:{session:sessionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:defaults=>defaults().concat(liveApi.middleware)});}
beforeEach(()=>{
  vi.clearAllMocks();const controller=transferableAbortController();vi.stubGlobal('AbortController',controller.constructor);vi.stubGlobal('AbortSignal',controller.signal.constructor);vi.stubGlobal('crypto',webcrypto);
  localStorage.setItem('bizismart.device.v1','device_test');setLanguage('en');store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:actor}));
  vault.load.mockResolvedValue(lease);vault.read.mockResolvedValue([]);vault.saveSale.mockResolvedValue(undefined);vault.saveLease.mockResolvedValue(undefined);vault.ack.mockResolvedValue(undefined);
});
afterEach(()=>{cleanup();setLanguage('en');store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
function mount(){return render(<Provider store={store}><OfflinePOS actor={actor} onClose={vi.fn()}/></Provider>);}
async function fill(){fireEvent.click(await screen.findByRole('button',{name:/Rice/}));fireEvent.change(screen.getByLabelText('Quantity (kg)'),{target:{value:'0.750'}});fireEvent.change(screen.getByLabelText('Cash tendered (TSh)'),{target:{value:'10000'}});}
it('persists an exact frozen cash operation before clearing the draft and never shows a server completion',async()=>{
  mount();await fill();fireEvent.click(screen.getByRole('button',{name:'Save cash sale locally'}));
  await waitFor(()=>expect(vault.saveSale).toHaveBeenCalledOnce());const queued=vault.saveSale.mock.calls[0][1] as OfflineQueuedSale;
  expect(queued.body.items).toEqual([{snapshot_id:lease.catalogue[0].snapshot_id,proof:lease.catalogue[0].proof,qty:'0.750'}]);expect(queued.body.cash_tendered).toBe(10000);expect(queued.estimated_total).toBe(7500);
  await screen.findByText('Cash sale saved in the encrypted queue. Server confirmation is pending.');expect(screen.queryByText('Sale completed')).toBeNull();expect(screen.queryByRole('button',{name:'Print or download receipt'})).toBeNull();
});
it('preserves the identical UUID/body after a local storage failure and locks editing until retry succeeds',async()=>{
  vault.saveSale.mockRejectedValueOnce(new Error('Storage unavailable')).mockResolvedValueOnce(undefined);mount();await fill();fireEvent.click(screen.getByRole('button',{name:'Save cash sale locally'}));
  await screen.findByText('Storage unavailable');expect((screen.getByLabelText('Quantity (kg)')as HTMLInputElement).disabled).toBe(true);
  expect((screen.getByRole('button',{name:'Return to connected app'})as HTMLButtonElement).disabled).toBe(true);
  fireEvent.click(screen.getByRole('button',{name:'Retry identical local capture'}));await waitFor(()=>expect(vault.saveSale).toHaveBeenCalledTimes(2));
  expect(vault.saveSale.mock.calls[0][1]).toEqual(vault.saveSale.mock.calls[1][1]);
});
it('signs recovery without an unrelated Bearer token and retains the queue on unknown-lease authentication failure',async()=>{
  const queued:OfflineQueuedSale={body:{lease_id:lease.lease_id,device_uid:lease.device_uid,operation_id:'11111111-1111-4111-8111-111111111111',captured_at:new Date().toISOString(),cash_tendered:10000,items:[{snapshot_id:lease.catalogue[0].snapshot_id,proof:lease.catalogue[0].proof,qty:'0.750'}]},secret:lease.secret,cashier_id:actor.id,shop_name:lease.shop_name,display_lines:[{name:'Rice',qty:'0.750',unit:'kg'}],estimated_total:7500};
  vault.read.mockResolvedValue([{id:queued.body.operation_id,state:'pending',sale:queued}]);
  const requests:Request[]=[];vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{requests.push(request);return new Response(JSON.stringify({error:{code:'UNAUTHENTICATED',message:'Unknown offline lease.'}}),{status:401,headers:{'Content-Type':'application/json'}});}));
  mount();const syncButton=await screen.findByRole('button',{name:'Synchronise saved sales'});await waitFor(()=>expect((syncButton as HTMLButtonElement).disabled).toBe(false));fireEvent.click(syncButton);await screen.findByText('Unknown offline lease.');
  expect(requests[0].headers.get('Authorization')).toBeNull();expect(requests[0].headers.get('X-Offline-Signature')).toMatch(/^[a-f0-9]{64}$/);expect(store.getState().session.token).toBe('test-only');expect(vault.ack).not.toHaveBeenCalled();expect(await requests[0].json()).toEqual(queued.body);
});
it('switches cash cart language without changing exact quantities or the signed price snapshots',async()=>{
  mount();await fill();setLanguage('sw');await screen.findByRole('heading',{name:'Mauzo ya taslimu bila mtandao'});
  expect((screen.getByLabelText('Kiasi (kg)')as HTMLInputElement).value).toBe('0.750');expect((screen.getByLabelText('Fedha iliyopokelewa (TSh)')as HTMLInputElement).value).toBe('10000');expect(screen.getByText('Jumla ya muda')).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:'Hifadhi mauzo ya taslimu kwenye kifaa'}));await waitFor(()=>expect(vault.saveSale).toHaveBeenCalledOnce());expect((vault.saveSale.mock.calls[0][1]as OfflineQueuedSale).estimated_total).toBe(7500);
});
