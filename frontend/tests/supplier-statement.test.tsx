// @vitest-environment jsdom
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import SupplierStatement from '../src/features/live/SupplierStatement';
import {liveApi,sessionReducer,signedIn} from '../src/features/live/api';
const data={supplier:{id:'sup_one',name:'Rice supplier',phone:null},period:{from:'2026-10-01',to:'2026-10-18',timezone:'Africa/Dar_es_Salaam',basis:'recorded_at'},opening_balance:1000,charges:0,payments:200,credits:300,closing_balance:500,outstanding_payable:600,unapplied_credit:100,currency:'TSh',entries:[{id:'spay_one',kind:'payment',at:'2026-10-10T21:00:00.000Z',date:'2026-10-11',document_date:'2026-10-10',reference:'BANK-1',charge:0,payment:200,credit:0,balance:500}],meta:{page:1,per_page:50,total:51,last_page:2}};
let store:ReturnType<typeof createStore>;
function createStore(){return configureStore({reducer:{session:sessionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
beforeEach(()=>{
 const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);
 store=createStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:{id:'usr_owner',name:'Owner',role:'owner',branch_id:null,permissions:['manage_products']}}));
});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.restoreAllMocks();vi.unstubAllGlobals();});
function mount(){render(<Provider store={store}><SupplierStatement supplier="sup_one" onClose={()=>undefined}/></Provider>);}
it('displays server balances and pages with dated filters without calculating money',async()=>{
 const urls:string[]=[];
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{urls.push(request.url);return new Response(JSON.stringify({data}),{headers:{'Content-Type':'application/json'}});}));
 mount();
 await screen.findByText('BANK-1');
 expect(screen.getByText('Rice supplier')).toBeTruthy();
 expect(screen.getAllByText('TSh 500').length).toBe(2);
 fireEvent.click(screen.getByText('Next'));
 await waitFor(()=>expect(urls.some(url=>url.includes('page=2'))).toBe(true));
 fireEvent.change(screen.getByLabelText('Statement from'),{target:{value:'2026-10-01'}});
 await waitFor(()=>expect(urls.some(url=>url.includes('from=2026-10-01')&&url.includes('page=1'))).toBe(true));
});
it('exports full-period CSV without page parameters and reports export errors honestly',async()=>{
 const requests:string[]=[];
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
  requests.push(request.url);
  return request.url.includes('.csv')?new Response(JSON.stringify({error:{code:'STATEMENT_EXPORT_TOO_LARGE',message:'Choose a shorter period.'}}),{status:422,headers:{'Content-Type':'application/json'}}):new Response(JSON.stringify({data}),{headers:{'Content-Type':'application/json'}});
 }));
 mount();await screen.findByText('BANK-1');
 fireEvent.click(screen.getByText('Export full statement CSV'));
 await screen.findByRole('alert');
 const exported=requests.find(url=>url.includes('.csv'));
 expect(exported).toBeTruthy();expect(exported).not.toContain('page=');
});
