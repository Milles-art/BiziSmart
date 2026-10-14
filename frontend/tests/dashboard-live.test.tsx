// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Dashboard } from '../src/features/live/Dashboard';
import { Inventory } from '../src/features/live/Inventory';
import { liveApi, sessionReducer, signedIn, type ServerUser } from '../src/features/live/api';

const owner: ServerUser = { id: 'usr_owner', name: 'Owner', role: 'owner', branch_id: 'br_main', permissions: ['receive_stock', 'manage_till', 'manage_products'] };
let store: ReturnType<typeof makeStore>;
function makeStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: g => g().concat(liveApi.middleware) }); }
function response(data: unknown, status = 200) { return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }); }
function mount(actor = owner) { const navigate = vi.fn(); render(<Provider store={store}><Dashboard shopName="Real shop" actor={actor} onNavigate={navigate} /></Provider>); return navigate; }
beforeEach(() => { const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor); store = makeStore(); store.dispatch(signedIn({ token: 'test-only-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner })); });
afterEach(() => { vi.restoreAllMocks();cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });

it('restores stock, expiry and till panels from server data with exact display strings and working links', async () => {
  const requests: URL[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const url = new URL(request.url); requests.push(url);
    if (url.pathname.endsWith('reorder-alerts')) return response({ data: { products: [{ id: 'prod_rice', name: 'Rice', unit: 'kg', stock: '0.750', stock_available: true, reorder_level: '1.000', department_name: 'Grocery' }] }, meta: { total: 8 } });
    if (url.pathname.endsWith('expiry-alerts')) return response({ data: { batches: [{ id: 'bat_milk', product_name: 'Milk', qty_remaining: '12', unit: 'piece', batch_number: 'M-1', expiry_date: '2026-10-13' }] }, meta: { total: 3 } });
    return response({ data: { tills: [{ id: 'till_one', cashier_name: 'Server cashier', branch_name: 'Main', expected_cash: 18000 }] }, meta: { total: 1, owner_review_count: 2 } });
  }));
  const navigate = mount();
  await screen.findByText('0.750 kg'); await screen.findByText('Expires 2026-10-13'); await screen.findByText('TSh 18,000');
  expect(screen.getByText('8 products below reorder level')).toBeTruthy(); expect(screen.getByText('2 closed tills need review')).toBeTruthy();
  expect(requests.find(url => url.pathname.endsWith('expiry-alerts'))?.searchParams.get('days')).toBe('7');
  fireEvent.click(screen.getByRole('button', { name: 'Restock' })); expect(navigate).toHaveBeenLastCalledWith('products', { page: 1, low_stock: 1 });
  fireEvent.click(screen.getByRole('button', { name: 'Review', exact: true })); expect(navigate).toHaveBeenLastCalledWith('products', { page: 1, expiring_in: 7 });
  fireEvent.click(screen.getByRole('button', { name: 'Manage' })); expect(navigate).toHaveBeenLastCalledWith('tills');
  const transactions = screen.getByRole('heading', { name: 'Recent Transactions' }).closest('.rounded-xl')!;
  expect(within(transactions as HTMLElement).queryByText(/TSh/)).toBeNull(); expect(screen.queryByText(/5 min ago|12 min ago/)).toBeNull();
});

it('does not query stock endpoints for a cashier without stock permissions', async () => {
  const fetcher = vi.fn(async () => response({ data: { tills: [] }, meta: { total: 0, owner_review_count: 0 } })); vi.stubGlobal('fetch', fetcher);
  mount({ ...owner, role: 'cashier', permissions: ['manage_till'] });
  await screen.findByText('No open tills.');
  expect(fetcher.mock.calls.length).toBe(1); expect(screen.queryByRole('button', { name: 'Restock' })).toBeNull();
  expect(screen.getByText('Your role does not have stock alert access.')).toBeTruthy();
});

it('shows failed alert queries as errors instead of an all-clear or cached totals, and retries', async () => {
  let failed = true;
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => new URL(request.url).pathname.endsWith('reorder-alerts') && failed ? response({ error: { code: 'SERVER_ERROR', message: 'Stock unavailable' } }, 503) : response({ data: { products: [], batches: [], tills: [] }, meta: { total: 0, owner_review_count: 0 } })));
  mount(); await screen.findByText('Stock unavailable'); expect(screen.queryByText('All products well stocked.')).toBeNull(); expect(screen.queryByText('No stock or till alerts for your access.')).toBeNull();
  failed = false; fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
  await waitFor(() => expect(screen.queryByText('Stock unavailable')).toBeNull()); await screen.findByText('All products well stocked.');
});

it('connects Inventory movement selection to the real product history adapter', async () => {
  const paths: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const path = new URL(request.url).pathname; paths.push(path);
    if (path.endsWith('/products')) return response({data:{products:[{id:'prod_rice',name:'Rice',unit:'kg',quantity_type:'weight',stock:'0.750',stock_available:true,reorder_level:'1',buying_price:3000,selling_price:4000,is_active:true,tracks_expiry:false}]},meta:{page:1,per_page:50,total:1}});
    return response({data:{products:[],batches:[],movements:[]},meta:{page:1,per_page:50,total:0}});
  }));
  render(<Provider store={store}><Inventory actor={owner} onNavigate={vi.fn()} /></Provider>);
  expect((screen.getByRole('tab', {name:'Stock Take'}) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.click(screen.getByRole('tab', {name:'Stock Movements'}));
  fireEvent.click(await screen.findByRole('button', {name:'View movements for Rice'}));
  await waitFor(() => expect(paths.some(path => path.endsWith('/products/prod_rice/movements'))).toBe(true));
  expect((screen.getByRole('tab', {name:'Movement history'})).getAttribute('aria-selected')).toBe('true');
  expect(paths.some(path => path.endsWith('/products/prod_rice/batches'))).toBe(false);
});

it('does not query inventory data when stock access is absent', async () => {
  const fetcher = vi.fn(); vi.stubGlobal('fetch', fetcher);
  render(<Provider store={store}><Inventory actor={{...owner,role:'manager',permissions:['manage_till']}} onNavigate={vi.fn()} /></Provider>);
  expect(screen.getByRole('alert').textContent).toContain('does not have inventory access');
  expect(fetcher).not.toHaveBeenCalled();
});

it('renders owner dashboard figures and recent receipts only from authoritative report responses',async()=>{
  vi.stubGlobal('ResizeObserver',class {observe(){}unobserve(){}disconnect(){}});
  vi.spyOn(HTMLElement.prototype,'getBoundingClientRect').mockReturnValue({width:500,height:230,left:0,right:500,top:0,bottom:230,x:0,y:0,toJSON:()=>({})});
  const paths:string[]=[];
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{
    const url=new URL(request.url);paths.push(url.pathname);
    if(url.pathname.endsWith('/reports/summary'))return response({data:{summary:{net_sales_gross:13500,gross_profit:4200,paid_sales:9000},daily:[{date:'2026-10-13',net_sales_gross:5000},{date:'2026-10-14',net_sales_gross:13500}]}});
    if(url.pathname.endsWith('/reports/sales'))return response({data:{sales:[{id:'sale_real',receipt_number:'RCP-real',cashier:'Server cashier',total:13500}]},meta:{page:1,per_page:5,total:1}});
    return response({data:{products:[],batches:[],tills:[]},meta:{total:0,owner_review_count:0}});
  }));
  const navigate=mount({...owner,permissions:[...owner.permissions,'view_reports']});
  await screen.findByText('TSh 4,200');await screen.findByText('TSh 9,000');await screen.findByText('RCP-real');
  expect(screen.getByRole('img',{name:'Weekly net sales from saved sales and returns'}).querySelector('path.recharts-area-curve')?.getAttribute('d')).toBeTruthy();
  expect(paths.filter(path=>path.endsWith('/reports/summary'))).toHaveLength(2);
  fireEvent.click(screen.getByRole('button',{name:'View reports'}));expect(navigate).toHaveBeenLastCalledWith('reports');
});
it('keeps report failures visible and never substitutes dashboard zeroes or a synthetic chart',async()=>{
  vi.stubGlobal('fetch',vi.fn(async(request:Request)=>new URL(request.url).pathname.includes('/reports/')?response({error:{code:'SERVER_ERROR',message:'Report unavailable'}},503):response({data:{products:[],batches:[],tills:[]},meta:{total:0,owner_review_count:0}})));
  mount({...owner,permissions:[...owner.permissions,'view_reports']});
  await waitFor(()=>expect(screen.getAllByText('Report unavailable').length).toBeGreaterThan(0));
  expect(screen.queryByText('TSh 0')).toBeNull();expect(screen.queryByRole('img',{name:'Weekly net sales from saved sales and returns'})).toBeNull();
});
