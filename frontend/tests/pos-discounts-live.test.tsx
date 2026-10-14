// @vitest-environment jsdom
import {configureStore} from '@reduxjs/toolkit';
import {Provider} from 'react-redux';
import {cleanup,fireEvent,render,screen,within} from '@testing-library/react';
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {transferableAbortController} from 'node:util';
import {POS} from '../src/features/live/POS';
import {Returns} from '../src/features/live/Returns';
import {checkoutReducer} from '../src/features/live/checkoutState';
import {returnReducer} from '../src/features/live/returnState';
import {heldReducer} from '../src/features/live/heldState';
import {liveApi,sessionReducer,signedIn,type CatalogueProduct,type ServerUser} from '../src/features/live/api';
const actor:ServerUser={id:'usr_one',name:'Cashier',role:'cashier',branch_id:'br_one',permissions:['sell','manage_till']};
const product:CatalogueProduct={id:'prod_one',name:'Water',quantity_type:'counted',unit:'bottle',selling_price:8500,reorder_level:'0',tracks_expiry:false,is_active:true,stock:'20',stock_available:true,packagings:[]};
const line={product_id:product.id,name:product.name,quantity_type:'counted',unit:'bottle',qty:'1',base_qty:'1',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1',unit_price:8500,gross_total:8500,line_discount:500,cart_discount:500,discount:1000,line_total:7500};
const review={branch_id:'br_one',till_id:'till_one',lines:[line],subtotal:8000,discount:500,discount_reason:'Customer goodwill',total:7500,vat_rate:18,vat_amount:1144,review_hash:'a'.repeat(64)};
function makeStore(){return configureStore({reducer:{session:sessionReducer,checkout:checkoutReducer,returns:returnReducer,held:heldReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:g=>g().concat(liveApi.middleware)});}
let store:ReturnType<typeof makeStore>;
const response=(data:unknown,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
function queries(request:Request){
  if(request.url.endsWith('/payments/capabilities'))return response({provider:'selcom',configured:false,enabled:false,reason:'merchant_setup_required'});
  if(request.url.endsWith('/payments/mine'))return response({payments:[]});
  if(request.url.endsWith('/current'))return response({till:{id:'till_one',branch_id:'br_one',branch_name:'Main'}});
  return new Response(JSON.stringify({data:{products:[product]},meta:{per_page:50,total:1,page:1}}),{headers:{'Content-Type':'application/json'}});
}
beforeEach(()=>{const c=transferableAbortController();vi.stubGlobal('AbortController',c.constructor);vi.stubGlobal('AbortSignal',c.signal.constructor);store=makeStore();store.dispatch(signedIn({token:'test-only',expires_at:'2099-01-01',user:actor}));});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();});
async function discountedReview(){
  render(<Provider store={store}><POS actor={actor} shopName="Fresh Choice" onNavigate={vi.fn()}/></Provider>);
  fireEvent.click(await screen.findByRole('button',{name:/Water.*TSh/}));fireEvent.click(screen.getByRole('button',{name:'Discount'}));
  fireEvent.change(screen.getByLabelText('Line discount for Water (TSh)'),{target:{value:'500'}});
  fireEvent.change(screen.getByLabelText('Cart discount (TSh)'),{target:{value:'500'}});
  fireEvent.change(screen.getByLabelText('Discount reason'),{target:{value:'Customer goodwill'}});
  fireEvent.click(screen.getByRole('button',{name:'Apply discounts'}));
  fireEvent.click(await screen.findByRole('button',{name:'Review cash sale'}));
  const dialog=within(await screen.findByRole('dialog',{name:'Payment'}));
  fireEvent.change(dialog.getByLabelText('Cash tendered (TSh)'),{target:{value:'7500'}});
  return dialog;
}
it('does not submit a discounted payment through form Enter before approval',async()=>{
  const writes:unknown[]=[];
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.method!=='POST')return queries(request);if(request.url.endsWith('/preview'))return response(review);writes.push(await request.json());return response({},403);}));
  const dialog=await discountedReview();
  expect(dialog.getByRole('button',{name:'Confirm cash payment'}).hasAttribute('disabled')).toBe(true);
  fireEvent.submit(dialog.getByLabelText('Cash tendered (TSh)').closest('form')!);
  await dialog.findByText('Approve this discount before collecting payment.');
  expect(writes).toHaveLength(0);expect(store.getState().checkout.pending).toBeNull();
});
it('commits the approved original line discount and cart discount once without double-applying cart shares',async()=>{
  let approvalBody:Record<string,unknown>|null=null;let savedBody:Record<string,unknown>|null=null;let key:string|null=null;
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
    if(request.method!=='POST')return queries(request);
    if(request.url.endsWith('/preview'))return response(review);
    if(request.url.endsWith('/supervisor-check')){approvalBody=await request.json();return response({approval_token:'b'.repeat(64)});}
    savedBody=await request.json();key=request.headers.get('Idempotency-Key');
    return response({...review,id:'sale_one',receipt_number:'RCP-2026-000001',status:'completed',cash_tendered:7500,change_given:0,created_at:'2026-10-12T10:00:00Z',payments:[{id:'sp_one',method:'cash',amount:7500}]},201);
  }));
  const dialog=await discountedReview();
  fireEvent.change(dialog.getByLabelText('Supervisor phone'),{target:{value:'0710000002'}});
  fireEvent.change(dialog.getByLabelText('Supervisor PIN'),{target:{value:'1234'}});
  fireEvent.click(dialog.getByRole('button',{name:'Approve discount'}));
  await dialog.findByText('Discount approved for this checkout.');
  fireEvent.click(dialog.getByRole('button',{name:'Confirm cash payment'}));
  await screen.findByRole('heading',{name:'Sale completed'});
  expect(savedBody?.items).toEqual([{product_id:'prod_one',qty:'1',packaging_id:null,discount:500}]);
  expect(savedBody?.discount).toBe(500);expect(savedBody?.approval_token).toBe('b'.repeat(64));expect(savedBody?.total).toBeUndefined();
  expect(approvalBody?.operation_id).toBe(key);expect(approvalBody?.payload_hash).toBe(review.review_hash);
  expect(store.getState().checkout.receipt?.total).toBe(7500);
});
it('voids only remaining original receipt quantities through the existing approved refund contract',async()=>{
  let previewBody:Record<string,unknown>|null=null;
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
    if(request.method==='GET')return response({sale_id:'sale_one',receipt_number:'RCP-2026-000001',branch_id:'br_one',total:7500,return_status:'partial',lines:[{...line,line_index:0,qty:'3',base_qty:'3',returned_qty:'1',remaining_qty:'2'}]});
    previewBody=await request.json();return response({review_hash:'c'.repeat(64),refund_total:5000,debt_reduction:0,cash_refund:5000,cash_required:0,cash_paid:5000,additional_cash:0,replacement:null,lines:[{line_index:0,name:'Water',qty:'2',unit:'bottle',amount:5000}],allocations:[]});
  }));
  render(<Provider store={store}><Returns tillId="till_one" entries={[]} onClose={vi.fn()}/></Provider>);
  fireEvent.change(screen.getByLabelText('Receipt number'),{target:{value:'RCP-2026-000001'}});fireEvent.click(screen.getByRole('button',{name:'Find receipt'}));
  fireEvent.click(await screen.findByRole('button',{name:'Void remaining receipt items'}));
  expect(screen.getByLabelText('Return quantity · line 1')).toHaveProperty('value','2');
  fireEvent.change(screen.getByLabelText('Return reason'),{target:{value:'Void mistaken sale'}});fireEvent.click(screen.getByRole('button',{name:'Review return'}));
  await screen.findByText('Review return');expect(previewBody?.items).toEqual([{line_index:0,qty:'2',disposition:'sellable'}]);
  expect(previewBody?.replacement).toBeUndefined();expect(previewBody?.refund_total).toBeUndefined();
  expect(screen.getByRole('button',{name:'Confirm return'}).hasAttribute('disabled')).toBe(true);
});
