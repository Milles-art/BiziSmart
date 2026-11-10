// @vitest-environment jsdom
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen} from '@testing-library/react';
import {afterEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import PurchaseSuggestions from '../src/features/live/PurchaseSuggestions';
import {liveApi,sessionReducer,signedIn} from '../src/features/live/api';

afterEach(()=>{cleanup();vi.unstubAllGlobals();});
const suggestion={product_id:'prod_rice',name:'Rice',quantity_type:'weight',unit:'kg',stock:'0.75',reorder_level:'2.501',shortage_to_reorder:'1.751',suggested_qty:'1.751',packaging_id:null,historical_cost:{cost_price:2345,invoice_id:'sinv_old',invoice_date:'2026-02-01'}};
function mount(rows:unknown[]){
 const controller=transferableAbortController();vi.stubGlobal('AbortController',controller.constructor);vi.stubGlobal('AbortSignal',controller.signal.constructor);
 const fetch=vi.fn(async()=>new Response(JSON.stringify({data:{supplier:{id:'sup_rice',name:'Rice supplier'},lines:rows,basis:'received_supplier_history_and_reorder_threshold',meta:{page:1,per_page:25,total:rows.length,last_page:1}}}),{headers:{'Content-Type':'application/json'}}));
 vi.stubGlobal('fetch',fetch);
 const store=configureStore({reducer:{session:sessionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});
 store.dispatch(signedIn({token:'test',expires_at:'2099-01-01',user:{id:'usr_owner',name:'Owner',role:'owner',branch_id:null,permissions:['manage_products']}}));
 const onDraft=vi.fn();render(<Provider store={store}><PurchaseSuggestions suppliers={[{id:'sup_rice',name:'Rice supplier'}]} onDraft={onDraft}/></Provider>);
 fireEvent.change(screen.getByLabelText('Supplier for low-stock order'),{target:{value:'sup_rice'}});
 return {onDraft,fetch,store};
}
it('passes exact server base-unit lines to the existing draft editor without posting anything',async()=>{
 const {onDraft,fetch,store}=mount([suggestion]);
 fireEvent.click(await screen.findByLabelText('Rice'));
 expect((screen.getByLabelText('Order quantity for Rice (kg)') as HTMLInputElement).value).toBe('1.751');
 fireEvent.change(screen.getByLabelText('Order quantity for Rice (kg)'),{target:{value:'3.001'}});
 fireEvent.click(screen.getByRole('button',{name:'Review selected order lines (1)'}));
 expect(onDraft).toHaveBeenCalledWith('sup_rice',[{product_id:'prod_rice',name:'Rice',packaging_id:null,qty:'3.001',cost_price:'2345',batch_number:'',expiry_date:''}]);
 expect(fetch).toHaveBeenCalledTimes(1);expect(fetch.mock.calls[0]).toBeDefined();store.dispatch(liveApi.util.resetApiState());
});
it('requires an explicit quantity at the reorder equality boundary instead of inventing one',async()=>{
 const {onDraft,store}=mount([{...suggestion,stock:'2.501',shortage_to_reorder:'0',suggested_qty:null}]);
 fireEvent.click(await screen.findByLabelText('Rice'));
 expect((screen.getByLabelText('Order quantity for Rice (kg)') as HTMLInputElement).value).toBe('');
 expect((screen.getByRole('button',{name:'Review selected order lines (1)'}) as HTMLButtonElement).disabled).toBe(true);
 expect(onDraft).not.toHaveBeenCalled();store.dispatch(liveApi.util.resetApiState());
});
