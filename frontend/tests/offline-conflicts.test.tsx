// @vitest-environment jsdom
import {beforeEach,afterEach,it,expect,vi} from 'vitest';
import {render,screen,fireEvent,cleanup,waitFor} from '@testing-library/react';
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {transferableAbortController} from 'node:util';
import {OfflineQuarantine} from '../src/features/live/OfflineQuarantine';
import {liveApi,sessionReducer,signedIn,type ServerUser} from '../src/features/live/api';
import {offlineReconciliationReducer} from '../src/features/live/offlineReconciliationState';
import {setLanguage} from '../src/i18n';
const owner:ServerUser={id:'usr_owner',name:'Owner',role:'owner',branch_id:'br_one',permissions:['sell','view_reports','receive_stock']};
function makeStore(){return configureStore({reducer:{session:sessionReducer,offlineReconciliation:offlineReconciliationReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
const response=(data:unknown)=>new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);setLanguage('en');store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:owner}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());setLanguage('en');vi.unstubAllGlobals();});
it('shows already-posted conflicts in Kiswahili without offering another cash posting',async()=>{
 const requests:Request[]=[];
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{requests.push(request);const path=new URL(request.url).pathname;if(path.endsWith('/conflicts'))return response({data:{sales:[{id:'off_one',sale_id:'sale_one',receipt_number:'RCP-real',total:30000,captured_at:'2026-10-14T00:00:00Z',cashier_name:'Asha',conflicts:['NEGATIVE_STOCK','PRICE_CHANGED'],owner_reconciled:false}]},meta:{page:1,per_page:25,total:1}});if(path.endsWith('/quarantine'))return response({data:[]});return response({data:{till:null}});}));
 setLanguage('sw');const inventory=vi.fn();render(<Provider store={store}><OfflineQuarantine actor={owner} onInventory={inventory}/></Provider>);
 await screen.findByText('RCP-real · TSh 30,000');expect(screen.getByText(/Stoo ilishuka chini ya sifuri/)).toBeTruthy();
 expect(screen.queryByRole('button',{name:'Post reviewed cash'})).toBeNull();expect(requests.every(request=>request.method==='GET')).toBe(true);
 fireEvent.click(screen.getByRole('button',{name:'Kagua stoo'}));expect(inventory).toHaveBeenCalledOnce();
});
it('hides the empty owner panel only after both actual feeds confirm no records',async()=>{
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>new URL(request.url).pathname.endsWith('/conflicts')?response({data:{sales:[]},meta:{page:1,per_page:25,total:0}}):new URL(request.url).pathname.endsWith('/quarantine')?response({data:[]}):response({data:{till:null}})));
 render(<Provider store={store}><OfflineQuarantine actor={owner}/></Provider>);
 await waitFor(()=>expect(screen.queryByText('Offline owner review')).toBeNull());
});
it('never queries owner conflict data for a manager',()=>{
 const fetcher=vi.fn();vi.stubGlobal('fetch',fetcher);render(<Provider store={store}><OfflineQuarantine actor={{...owner,role:'manager'}}/></Provider>);expect(fetcher).not.toHaveBeenCalled();
});
