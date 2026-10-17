// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Products } from '../src/features/live/Products';
import { Packagings } from '../src/features/live/Packagings';
import { Departments, DepartmentPicker } from '../src/features/live/Departments';
import { StockReceiving } from '../src/features/live/StockReceiving';
import { StockCorrection } from '../src/features/live/StockCorrection';
import { StockHistory } from '../src/features/live/StockHistory';
import { liveApi, sessionReducer, signedIn, type CatalogueProduct, type ServerUser } from '../src/features/live/api';

const actor: ServerUser = { id: 'usr_test', name: 'Owner', role: 'owner', branch_id: null, permissions: ['manage_products', 'view_buying_price'] };
const product: CatalogueProduct = { id: 'prod_test', name: 'Server rice', barcode: '00123456789', category: 'Food', quantity_type: 'weight', unit: 'kg', selling_price: 4000, buying_price: 3000, reorder_level: '0.75', tracks_expiry: false, is_active: true, stock: null, stock_available: false };
let s: ReturnType<typeof makeStore>;
function makeStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) }); }
function response(data: unknown, status = 200) { return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }); }
function list(products: CatalogueProduct[], total = products.length, page = 1) { return response({ data: { products }, meta: { page, per_page: 50, total } }); }
// Existing detailed catalogue workflows exercise the retained list view. Grid paths have dedicated coverage.
function mount(user = actor) { render(<Provider store={s}><Products actor={user} /></Provider>); fireEvent.click(screen.getByRole('button', { name: 'List view' })); fireEvent.click(screen.getByRole('button', { name: 'Filters', exact: true })); }
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  s = makeStore(); s.dispatch(signedIn({ token: 'test-only-session', expires_at: new Date(Date.now() + 600000).toISOString(), user: actor }));
});
afterEach(() => { cleanup(); s.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); localStorage.clear(); });

function batch(qty = '0.75') { return { id: 'bat_test', product_id: product.id, batch_number: 'RICE-1', expiry_date: '2027-03-31', qty_remaining: qty, cost_price: 3000, received_at: '2026-10-09T00:00:00.000Z', sellable: true, is_quarantined: false, is_recalled: false, receipt: { operation_id: 'test', qty, base_qty: qty, packaging_id: null, packaging_name: null, packaging_version: null, conversion_atoms: '1000' } }; }
function mountReceiving() { render(<Provider store={s}><StockReceiving product={product} showCost onClose={vi.fn()} /></Provider>); }
async function openReceipt() {
  fireEvent.click(await screen.findByRole('button', { name: 'Receive stock', exact: true }));
  const form = within(screen.getByRole('dialog', { name: 'Receive stock · Server rice' }));
  fireEvent.change(form.getByLabelText('Batch number'), { target: { value: 'RICE-1' } });
  fireEvent.change(form.getByLabelText('Quantity (kg)'), { target: { value: '0.750' } });
  fireEvent.change(form.getByLabelText('Cost (TSh / kg)'), { target: { value: '3000' } });
  fireEvent.change(form.getByLabelText('Expiry date'), { target: { value: '2027-03-31' } });
  return form;
}
function receiptQueries(r: Request) {
  return response(new URL(r.url).pathname.endsWith('/packagings') ? { data: { packagings: [] }, meta: { page: 1, per_page: 50, total: 0 } } : { data: { batches: [] }, meta: { page: 1, per_page: 50, total: 0 } });
}

it('receives exact strings through authenticated endpoints and displays confirmed server quantities', async () => {
  let saved = false;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    expect(r.headers.get('Authorization')).toBe('Bearer test-only-session');
    if (r.method === 'POST') {
      expect(new URL(r.url).pathname).toBe('/api/v1/products/prod_test/batches');
      const body = await r.json(); expect(body.qty).toBe('0.750'); expect(body.cost_price).toBe(3000); expect(body.operation_id).toMatch(/^[0-9a-f-]{36}$/);
      saved = true; return response({ data: { batch: batch(), movement: { id: 'mov_test', type: 'receive', qty_change: '0.75', qty_after: '0.75' }, replayed: false } }, 201);
    }
    return saved && new URL(r.url).pathname.endsWith('/batches') ? response({ data: { batches: [batch()] }, meta: { page: 1, per_page: 50, total: 1 } }) : receiptQueries(r);
  })); mountReceiving();
  const form = await openReceipt(); fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' }));
  await screen.findByText('Stock received: 0.75 kg. Batch RICE-1.');
  await screen.findByLabelText('Batch RICE-1'); expect(localStorage.length).toBe(0);
});

it('retains an uncertain receipt unchanged for idempotent retry after a transport failure', async () => {
  const bodies: unknown[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (r.method === 'POST') {
      bodies.push(await r.json()); if (bodies.length === 1) throw new Error('connection lost');
      return response({ data: { batch: batch(), movement: { id: 'mov_test', type: 'receive', qty_change: '0.75', qty_after: '0.75' }, replayed: true } });
    } return receiptQueries(r);
  })); mountReceiving();
  const form = await openReceipt(); fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' }));
  await screen.findByText(/The outcome is not confirmed/);
  expect((form.getByLabelText('Batch number') as HTMLInputElement).disabled || form.getByLabelText('Batch number').closest('fieldset')?.disabled).toBe(true);
  expect(screen.queryByText(/Stock received:/)).toBeNull();
  fireEvent.click(form.getByRole('button', { name: 'Retry receipt' }));
  await screen.findByText(/Existing receipt confirmed; no stock added twice/); expect(bodies[1]).toEqual(bodies[0]);
});

it('retains rejected delivery input and permits correction without simulated success', async () => {
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => r.method === 'POST' ? response({ error: { code: 'EXPIRED_STOCK', message: 'Return expired goods to the supplier.' } }, 400) : receiptQueries(r)));
  mountReceiving(); const form = await openReceipt(); fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' }));
  await screen.findByText('Return expired goods to the supplier.'); expect(screen.queryByText(/Stock received:/)).toBeNull();
  expect((form.getByLabelText('Quantity (kg)') as HTMLInputElement).value).toBe('0.750');
  expect(form.getByLabelText('Batch number').closest('fieldset')?.disabled).toBe(false);
});

it('refreshes changed pack versions and requires quantity entry when selecting a replacement', async () => {
  let reads = 0, posts = 0;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (new URL(r.url).pathname.endsWith('/packagings')) {
      reads++; return response({ data: { packagings: [{ id: reads === 1 ? 'pkg_old' : 'pkg_new', product_id: product.id, name: '500 g bag', base_qty: '0.5', conversion_atoms: '500', selling_price: 2100, barcode: null, version: reads === 1 ? 1 : 2, is_active: true, archived: false }] }, meta: { page: 1, per_page: 50, total: 1 } });
    }
    if (r.method === 'POST') {
      posts++; const body = await r.json();
      if (posts === 1) return response({ error: { code: 'PACKAGING_VERSION_CHANGED', message: 'Select the current pack.' } }, 409);
      expect(body.packaging_id).toBe('pkg_new'); expect(body.qty).toBe('10');
      return response({ data: { batch: batch('5'), movement: { id: 'mov_test', type: 'receive', qty_change: '5', qty_after: '5' }, replayed: false } }, 201);
    } return receiptQueries(r);
  })); mountReceiving(); const form = await openReceipt();
  await screen.findByRole('option', { name: '500 g bag (0.5 kg)' });
  fireEvent.change(form.getByRole('combobox', { name: 'Receive unit' }), { target: { value: 'pkg_old' } });
  fireEvent.change(form.getByLabelText('Quantity (packs)'), { target: { value: '10' } });
  fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' })); await screen.findByText('Select the current pack.');
  fireEvent.click(form.getByRole('button', { name: 'Refresh pack choices' }));
  await screen.findByRole('option', { name: '500 g bag (selected version 1)' });
  fireEvent.change(form.getByRole('combobox', { name: 'Receive unit' }), { target: { value: 'pkg_new' } });
  expect((form.getByLabelText('Quantity (packs)') as HTMLInputElement).value).toBe('');
  fireEvent.change(form.getByLabelText('Quantity (packs)'), { target: { value: '10' } });
  fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' })); await screen.findByText('Stock received: 5 kg. Batch RICE-1.');
});

it('creates and renames departments through authenticated endpoints without local persistence', async () => {
  let name = '';
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    expect(r.headers.get('Authorization')).toBe('Bearer test-only-session');
    if (r.method === 'POST' || r.method === 'PUT') {
      name = (await r.json()).name;
      expect(new URL(r.url).pathname).toBe(r.method === 'POST' ? '/api/v1/departments' : '/api/v1/departments/dep_food');
      return response({ data: { id: 'dep_food', name } }, r.method === 'POST' ? 201 : 200);
    }
    return response({ data: { departments: name ? [{ id: 'dep_food', name, assigned_products_count: 0 }] : [] }, meta: { page: 1, per_page: 50, total: name ? 1 : 0 } });
  }));
  render(<Provider store={s}><Departments onClose={vi.fn()} /></Provider>);
  await screen.findByText('No departments on this page.');
  fireEvent.click(screen.getByRole('button', { name: 'Add department' }));
  fireEvent.change(screen.getByLabelText('Department name'), { target: { value: 'Food' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save department' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Rename department Food' }));
  fireEvent.change(screen.getByLabelText('Department name'), { target: { value: 'Grocery' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save department' }));
  await screen.findByRole('button', { name: 'Rename department Grocery' });
  expect(localStorage.length).toBe(0);
});

it('keeps assigned departments on archive failure and retains input on duplicate name rejection', async () => {
  let deletes = 0;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (r.method === 'DELETE') { deletes++; return response({ error: { code: 'DEPARTMENT_IN_USE', message: 'Reassign products first.' } }, 409); }
    if (r.method === 'PUT') return response({ error: { code: 'DUPLICATE_DEPARTMENT', message: 'Department name already exists.' } }, 409);
    return response({ data: { departments: [{ id: 'dep_food', name: 'Food', assigned_products_count: 1 }] }, meta: { page: 1, per_page: 50, total: 1 } });
  }));
  render(<Provider store={s}><Departments onClose={vi.fn()} /></Provider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Archive department Food' }));
  expect(deletes).toBe(0);
  const dialog = within(screen.getByRole('dialog', { name: 'Archive Food?' }));
  fireEvent.click(dialog.getByRole('button', { name: 'Archive department', exact: true }));
  await screen.findByText('Reassign products first.'); expect(screen.queryByText('Department archived.')).toBeNull();
  fireEvent.click(dialog.getByRole('button', { name: 'Cancel' }));
  await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Archive Food?' })).toBeNull());
  fireEvent.click(await screen.findByRole('button', { name: 'Rename department Food' }));
  fireEvent.change(screen.getByLabelText('Department name'), { target: { value: 'Duplicate' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save department' }));
  await screen.findByText('Department name already exists.');
  expect((screen.getByLabelText('Department name') as HTMLInputElement).value).toBe('Duplicate');
});

it('pages department options without discarding the selected assignment', async () => {
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    const page = Number(new URL(r.url).searchParams.get('page'));
    return response({ data: { departments: [{ id: page === 1 ? 'dep_one' : 'dep_two', name: page === 1 ? 'First' : 'Second' }] }, meta: { page, per_page: 1, total: 2 } });
  }));
  const change = vi.fn();
  render(<Provider store={s}><DepartmentPicker label="Department" value="dep_one" currentName="First" onChange={change} /></Provider>);
  await screen.findByRole('option', { name: 'First' });
  fireEvent.click(await screen.findByRole('button', { name: 'Department: next departments' }));
  await screen.findByRole('option', { name: 'Second' });
  expect((screen.getByLabelText('Department') as HTMLSelectElement).value).toBe('dep_one');
  fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'dep_two' } });
  expect(change).toHaveBeenCalledWith('dep_two');
});

it('uses server department filters and clears product assignments with a nullable public ID', async () => {
  let assigned = true;
  const fetch = vi.fn(async (r: Request) => {
    const url = new URL(r.url);
    if (url.pathname.endsWith('/departments')) return response({ data: { departments: [{ id: 'dep_food', name: 'Food' }] }, meta: { page: 1, per_page: 50, total: 1 } });
    if (r.method === 'PUT') {
      expect((await r.json()).department_id).toBeNull(); assigned = false;
      return response({ data: { ...product, department_id: null, department_name: null } });
    }
    const rows = url.searchParams.has('department_id') && !assigned ? [] : [{ ...product, department_id: assigned ? 'dep_food' : null, department_name: assigned ? 'Food' : null }];
    return list(rows);
  }); vi.stubGlobal('fetch', fetch); mount();
  await screen.findByRole('option', { name: 'Food' });
  fireEvent.change(screen.getByLabelText('Department filter'), { target: { value: 'dep_food' } });
  fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
  await waitFor(() => expect(fetch.mock.calls.some(([r]) => new URL(r.url).searchParams.get('department_id') === 'dep_food')).toBe(true));
  fireEvent.click(await screen.findByRole('button', { name: 'Edit Server rice' }));
  const form = within(screen.getByRole('dialog', { name: 'Edit Product' }));
  expect((form.getByLabelText('Department') as HTMLSelectElement).value).toBe('dep_food');
  fireEvent.change(form.getByLabelText('Department'), { target: { value: '' } });
  fireEvent.click(form.getByRole('button', { name: 'Save product' }));
  await screen.findByText(/No products match/);
});

it('creates gram packs through the nested endpoint with exact strings and displays server-canonical versions', async () => {
  let saved = false;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    expect(new URL(r.url).pathname).toBe('/api/v1/products/prod_test/packagings');
    if (r.method === 'POST') {
      expect(await r.json()).toEqual({ name: '500 g bag', base_qty: '0.500', selling_price: 2100, barcode: '000PACK', is_active: true });
      saved = true; return response({ data: { id: 'pkg_new' } }, 201);
    }
    return response({ data: { packagings: saved ? [{ id: 'pkg_new', name: '500 g bag', base_qty: '0.5', selling_price: 2100, barcode: '000PACK', version: 1, is_active: true }] : [] }, meta: { page: 1, per_page: 50, total: saved ? 1 : 0 } });
  }));
  render(<Provider store={s}><Packagings product={product} onClose={vi.fn()} /></Provider>);
  await screen.findByText('No packs on this page.');
  fireEvent.click(screen.getByRole('button', { name: 'Add pack' }));
  const form = within(screen.getByRole('dialog', { name: 'Add pack' }));
  fireEvent.change(form.getByLabelText('Pack name'), { target: { value: '500 g bag' } });
  fireEvent.change(form.getByLabelText('Contents (kg)'), { target: { value: '0.500' } });
  fireEvent.change(form.getByLabelText('Pack selling price (TSh)'), { target: { value: '2100' } });
  fireEvent.change(form.getByLabelText('Pack barcode'), { target: { value: '000PACK' } });
  fireEvent.click(form.getByRole('button', { name: 'Save pack' }));
  await screen.findByText('Pack saved.');
  expect(await screen.findByText('0.5 kg · TSh 2,100 / pack')).toBeTruthy();
  expect(localStorage.length).toBe(0);
});

it('retains pack edits on stale version failure and requires confirmation before archive', async () => {
  let deletions = 0;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (r.method === 'PUT') {
      expect(new URL(r.url).pathname).toBe('/api/v1/products/prod_test/packagings/pkg_old');
      expect((await r.json()).selling_price).toBe(2200);
      return response({ error: { code: 'PACKAGING_VERSION_CHANGED', message: 'Refresh the pack list before editing.' } }, 409);
    }
    if (r.method === 'DELETE') { deletions++; return response({ error: { message: 'Connection unavailable.' } }, 503); }
    return response({ data: { packagings: [{ id: 'pkg_old', name: 'Bag', base_qty: '0.5', selling_price: 2000, barcode: null, version: 1, is_active: true }] }, meta: { page: 1, per_page: 50, total: 1 } });
  }));
  render(<Provider store={s}><Packagings product={product} onClose={vi.fn()} /></Provider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Edit pack Bag' }));
  const form = within(screen.getByRole('dialog', { name: 'Edit pack' }));
  fireEvent.change(form.getByLabelText('Pack selling price (TSh)'), { target: { value: '2200' } });
  fireEvent.click(form.getByRole('button', { name: 'Save pack' }));
  await screen.findByText('Refresh the pack list before editing.');
  expect((form.getByLabelText('Pack selling price (TSh)') as HTMLInputElement).value).toBe('2200');
  fireEvent.click(form.getByRole('button', { name: 'Cancel' }));
  await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Edit pack' })).toBeNull());
  fireEvent.click(screen.getByRole('button', { name: 'Archive pack Bag' }));
  expect(deletions).toBe(0);
  fireEvent.click(screen.getByRole('button', { name: 'Archive pack', exact: true }));
  await screen.findByText('Connection unavailable.');
  expect(deletions).toBe(1); expect(screen.queryByText('Pack archived.')).toBeNull();
});

it('creates through Laravel with string kg quantities, integer TSh and server-authoritative canonical data', async () => {
  let saved = false;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    expect(r.headers.get('Authorization')).toBe('Bearer test-only-session');
    if (r.method === 'POST') {
      expect(await r.json()).toMatchObject({ name: 'Server rice', barcode: '00123456789', quantity_type: 'weight', unit: 'kg', reorder_level: '0.750', selling_price: 4000, buying_price: 3000 });
      saved = true; return response({ data: product }, 201);
    }
    return list(saved ? [product] : []);
  }));
  mount(); await screen.findByText(/No products match/);
  fireEvent.click(screen.getByRole('button', { name: 'Add Product' }));
  const dialog = within(screen.getByRole('dialog', { name: 'Add Product' }));
  fireEvent.change(dialog.getByLabelText('Product name'), { target: { value: 'Server rice' } });
  fireEvent.change(dialog.getByLabelText('Barcode'), { target: { value: '00123456789' } });
  fireEvent.change(dialog.getByLabelText('Quantity type'), { target: { value: 'weight' } });
  fireEvent.change(dialog.getByLabelText('Buying price (TSh / kg)'), { target: { value: '3000' } });
  fireEvent.change(dialog.getByLabelText('Selling price (TSh / kg)'), { target: { value: '4000' } });
  fireEvent.change(dialog.getByLabelText('Reorder level (kg)'), { target: { value: '0.750' } });
  fireEvent.click(dialog.getByRole('button', { name: 'Save product' }));
  const row = await screen.findByRole('row', { name: 'Product Server rice' });
  expect(within(row).getByText('0.75 kg')).toBeTruthy();
  expect(within(row).getByText(/TSh 4,000/)).toBeTruthy();
  expect(localStorage.length).toBe(0);
  expect(Object.keys(s.getState().liveApi.mutations)).toHaveLength(0);
});

it('keeps edits and server validation errors visible without claiming a successful save', async () => {
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (r.method === 'PUT') {
      expect(new URL(r.url).pathname).toBe('/api/v1/products/prod_test');
      expect((await r.json()).selling_price).toBe(4500);
      return response({ error: { code: 'PLAN_LIMIT_REACHED', message: 'Product limit reached.' } }, 403);
    }
    return list([product]);
  }));
  mount(); await screen.findByRole('row', { name: 'Product Server rice' });
  fireEvent.click(screen.getByRole('button', { name: 'Edit Server rice' }));
  const dialog = within(screen.getByRole('dialog', { name: 'Edit Product' }));
  expect((dialog.getByLabelText('Quantity type') as HTMLSelectElement).disabled).toBe(true);
  fireEvent.change(dialog.getByLabelText('Selling price (TSh / kg)'), { target: { value: '4500' } });
  fireEvent.click(dialog.getByRole('button', { name: 'Save product' }));
  await screen.findByText('Product limit reached.');
  expect((dialog.getByLabelText('Selling price (TSh / kg)') as HTMLInputElement).value).toBe('4500');
  expect(screen.queryByText('Product saved.')).toBeNull();
});

it('requires archive confirmation and retains the product on a connection failure before a successful retry', async () => {
  let attempts = 0;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (r.method === 'DELETE') {
      attempts++; if (attempts === 1) throw new Error('Offline');
      return response({ data: { completed: true } });
    }
    return list(attempts > 1 ? [] : [product]);
  }));
  mount(); await screen.findByRole('row', { name: 'Product Server rice' });
  fireEvent.click(screen.getByRole('button', { name: 'Archive Server rice' }));
  fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }));
  expect(attempts).toBe(0);
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  fireEvent.click(screen.getByRole('button', { name: 'Archive Server rice' }));
  fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive product' }));
  await screen.findByRole('alert');
  expect(screen.queryByText('Product archived.')).toBeNull();
  fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive product' }));
  await screen.findByText('Product archived.');
  await waitFor(() => expect(screen.queryByRole('row', { name: 'Product Server rice' })).toBeNull());
});

it('sends pagination and exact barcode/category filters to the server instead of filtering one local page', async () => {
  const fetch = vi.fn(async (r: Request) => {
    const params = new URL(r.url).searchParams;
    return list(params.has('barcode') ? [] : [product], params.has('barcode') ? 0 : 51, Number(params.get('page')));
  }); vi.stubGlobal('fetch', fetch); mount();
  await screen.findByRole('row', { name: 'Product Server rice' });
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  await screen.findByText('Page 2');
  fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '000123' } });
  fireEvent.change(screen.getByLabelText('Category'), { target: { value: 'Rice & grains' } });
  fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
  await screen.findByText(/No products match/);
  const url = new URL((fetch.mock.calls.at(-1)![0] as Request).url);
  expect(url.searchParams.get('barcode')).toBe('000123');
  expect(url.searchParams.get('category')).toBe('Rice & grains');
  expect(url.searchParams.get('page')).toBe('1');
});

it('hides management and buying-price controls for a read-only role even if extra cost data arrives', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => list([product])));
  mount({ ...actor, role: 'cashier', permissions: ['sell'] });
  await screen.findByRole('row', { name: 'Product Server rice' });
  expect(screen.queryByRole('button', { name: 'Add Product' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Edit Server rice' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Stock for Server rice' })).toBeNull();
  expect(screen.queryByRole('columnheader', { name: 'Cost' })).toBeNull();
  expect(screen.queryByText('TSh 3,000')).toBeNull();
});

it('displays exact server stock and combines stock filters without browser calculations', async () => {
  const urls: URL[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    const u = new URL(r.url); urls.push(u);
    if (u.pathname.endsWith('/departments')) return response({ data: { departments: [] }, meta: { page: 1, per_page: 50, total: 0 } });
    return response({ data: { products: [{ ...product, stock: '9007199254740.991', stock_available: true, stock_total: '9007199254741.001', nearest_expiry: '2026-10-11' }] }, meta: { page: 1, per_page: 50, total: 1, low_stock_matches: 7 } });
  })); mount({ ...actor, permissions: [...actor.permissions, 'receive_stock'] });
  await screen.findByText('9007199254740.991 kg'); expect(screen.getByText('Held: 9007199254741.001 kg')).toBeTruthy(); expect(screen.getByText('Expiry: 2026-10-11')).toBeTruthy(); expect(screen.getByText('7')).toBeTruthy();
  fireEvent.click(screen.getByLabelText('Low stock only')); fireEvent.change(screen.getByLabelText('Expiring within'), { target: { value: '0' } }); fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
  await waitFor(() => expect(urls.some(u => u.searchParams.get('low_stock') === '1' && u.searchParams.get('expiring_in') === '0')).toBe(true));
  fireEvent.click(screen.getByRole('button', { name: 'Clear filters' })); expect((screen.getByLabelText('Low stock only') as HTMLInputElement).checked).toBe(false); expect((screen.getByLabelText('Expiring within') as HTMLSelectElement).value).toBe(''); expect(localStorage.length).toBe(0);
});

it('hides held totals and stock-operation filters for sellers even if a response contains restricted fields', async () => {
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => new URL(r.url).pathname.endsWith('/departments') ? response({ data: { departments: [] }, meta: { page: 1, per_page: 50, total: 0 } }) : list([{ ...product, stock: '0', stock_available: true, stock_total: '12' }])));
  mount({ ...actor, role: 'cashier', permissions: ['sell'] }); await screen.findByText('0 kg');
  expect(screen.queryByText(/Held:/)).toBeNull(); expect(screen.queryByLabelText('Low stock only')).toBeNull(); expect(screen.queryByLabelText('Expiring within')).toBeNull(); expect(screen.queryByText('Low-stock matches')).toBeNull();
});

it('refreshes catalogue balances after a confirmed real receipt', async () => {
  let saved = false, catalogueReads = 0;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    const path = new URL(r.url).pathname;
    if (path.endsWith('/departments')) return response({ data: { departments: [] }, meta: { page: 1, per_page: 50, total: 0 } });
    if (r.method === 'POST') { saved = true; return response({ data: { batch: batch(), movement: { id: 'mov_test', type: 'receive', qty_change: '0.75', qty_after: '0.75' }, replayed: false } }, 201); }
    if (path.endsWith('/products')) { catalogueReads++; return list([{ ...product, stock: saved ? '0.75' : '0', stock_available: true }]); }
    return receiptQueries(r);
  })); mount({ ...actor, permissions: [...actor.permissions, 'receive_stock'] });
  fireEvent.click(await screen.findByRole('button', { name: 'Stock for Server rice' })); const form = await openReceipt(); fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' })); await screen.findByText('Stock received: 0.75 kg. Batch RICE-1.');
  await waitFor(() => expect(within(screen.getByLabelText('Available stock for Server rice')).getByText('0.75 kg')).toBeTruthy()); expect(catalogueReads).toBeGreaterThan(1);
});

it('shows a stock query failure without retaining a successful balance or low-stock count', async () => {
  let failed = false;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    if (new URL(r.url).pathname.endsWith('/departments')) return response({ data: { departments: [] }, meta: { page: 1, per_page: 50, total: 0 } });
    if (failed) return response({ error: { code: 'STOCK_QUANTITY_OVERFLOW', message: 'Recorded stock exceeds the supported quantity range.' } }, 409);
    return response({ data: { products: [{ ...product, stock: '0', stock_available: true }] }, meta: { page: 1, per_page: 50, total: 1, low_stock_matches: 1 } });
  })); mount({ ...actor, permissions: [...actor.permissions, 'receive_stock'] }); await screen.findByText('0 kg');
  failed = true; fireEvent.click(screen.getByLabelText('Low stock only')); fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true })); await screen.findByRole('alert');
  expect(screen.queryByLabelText('Available stock for Server rice')).toBeNull(); expect(screen.queryByText('Product saved.')).toBeNull();
});

function historyMovement(qty = '0.75') { return { id: 'mov_history', product_id: product.id, batch_id: 'bat_test', batch_number: 'RICE-1', type: 'receive', qty_change: qty, qty_after: qty, created_at: '2026-10-10T00:00:00.000Z', user_id: 'usr_test', device_id: 'dev_test', receipt: { ...batch().receipt, packaging_name: '500 g bag', packaging_version: 1 } }; }

it('shows exact historical quantities and preserved receipt metadata through authenticated history queries', async () => {
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    expect(r.headers.get('Authorization')).toBe('Bearer test-only-session'); expect(new URL(r.url).pathname).toBe('/api/v1/products/prod_test/movements');
    return response({ data: { movements: [historyMovement('9007199254740.991')] }, meta: { page: 1, per_page: 50, total: 1 } });
  })); render(<Provider store={s}><StockHistory product={product} batch={null} onAll={vi.fn()} /></Provider>);
  await screen.findByText('Change: 9007199254740.991 kg'); expect(screen.getByText('Batch balance after movement: 9007199254740.991 kg')).toBeTruthy(); expect(screen.getByText(/version 1/)).toBeTruthy(); expect(screen.queryByText(/Cost:/)).toBeNull(); expect(localStorage.length).toBe(0);
});

it('opens an owned batch history and clears its server filter through All movements', async () => {
  const urls: URL[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    const u = new URL(r.url); urls.push(u);
    return u.pathname.endsWith('/movements') ? response({ data: { movements: [historyMovement()] }, meta: { page: 1, per_page: 50, total: 1 } }) : response({ data: { batches: [batch()] }, meta: { page: 1, per_page: 50, total: 1 } });
  })); mountReceiving(); fireEvent.click(await screen.findByRole('button', { name: 'History for RICE-1' }));
  await screen.findByText('Change: 0.75 kg'); expect(urls.at(-1)?.searchParams.get('batch_id')).toBe('bat_test');
  fireEvent.click(screen.getByRole('button', { name: 'All movements' })); await screen.findByText('All movements for this product. Balances below are recorded after each movement, per batch.');
  await waitFor(() => expect(urls.at(-1)?.searchParams.has('batch_id')).toBe(false)); fireEvent.click(screen.getByRole('tab', { name: 'Batches', exact: true })); await screen.findByLabelText('Batch RICE-1');
});

it('retains history pagination and batch filters on failures and retries without showing stale rows', async () => {
  const urls: URL[] = []; let fail = true;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    const u = new URL(r.url); urls.push(u); const page = Number(u.searchParams.get('page'));
    if (page === 2 && fail) return response({ error: { message: 'History could not load.' } }, 503);
    return response({ data: { movements: page === 1 ? [historyMovement()] : [] }, meta: { page, per_page: 1, total: 2 } });
  })); render(<Provider store={s}><StockHistory product={product} batch={{ id: 'bat_test', name: 'RICE-1' }} onAll={vi.fn()} /></Provider>);
  await screen.findByText('Change: 0.75 kg'); fireEvent.click(screen.getByRole('button', { name: 'Next history' })); await screen.findByText('History could not load.'); expect(screen.queryByLabelText('Movement mov_history')).toBeNull();
  fail = false; fireEvent.click(screen.getByRole('button', { name: 'Retry history' })); await screen.findByText('No stock movements match.'); expect(urls.at(-1)?.searchParams.get('page')).toBe('2'); expect(urls.at(-1)?.searchParams.get('batch_id')).toBe('bat_test');
  fireEvent.click(screen.getByRole('button', { name: 'Previous history' })); await screen.findByText('Change: 0.75 kg');
});

it('invalidates an active history query after a confirmed receipt', async () => {
  let saved = false, reads = 0;
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => {
    const u = new URL(r.url);
    if (u.pathname.endsWith('/movements')) { reads++; return response({ data: { movements: saved ? [historyMovement()] : [] }, meta: { page: 1, per_page: 50, total: saved ? 1 : 0 } }); }
    if (r.method === 'POST') { saved = true; return response({ data: { batch: batch(), movement: { id: 'mov_history', type: 'receive', qty_change: '0.75', qty_after: '0.75' }, replayed: false } }, 201); }
    return receiptQueries(r);
  })); render(<Provider store={s}><StockHistory product={product} batch={null} onAll={vi.fn()} /><StockReceiving product={product} showCost onClose={vi.fn()} /></Provider>);
  await screen.findByText('No stock movements match.'); const form = await openReceipt(); fireEvent.click(form.getByRole('button', { name: 'Confirm receipt' })); await screen.findByText('Change: 0.75 kg'); expect(reads).toBeGreaterThan(1);
});

it('shows an unavailable history as a catalogue error without presenting a login or success state', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => response({ error: { code: 'NOT_FOUND', message: 'Not found.' } }, 404)));
  render(<Provider store={s}><StockHistory product={product} batch={null} onAll={vi.fn()} /></Provider>);
  await screen.findByText('This product or batch is no longer available. Close stock and refresh the catalogue.');
  expect(screen.queryByText('Open your shop’s BiziSmart address to sign in.')).toBeNull(); expect(screen.queryByText('No stock movements match.')).toBeNull();
});

function correctionReview(body: { operation_id: string; batch_id: string; qty_change: string; reason: string }, needsApproval = false) { return { snapshot: { ...body, product_id: product.id, qty_before: '0.75' }, payload_hash: 'a'.repeat(64), qty_after: '0.749', approval_required: needsApproval }; }
function mountCorrection(done = vi.fn(), close = vi.fn()) { render(<Provider store={s}><StockCorrection product={product} batch={batch()} onClose={close} onCorrected={done} /></Provider>); return { done, close }; }
async function reviewCorrection() { fireEvent.change(screen.getByLabelText('Stock change (kg)'), { target: { value: '-0.001' } }); fireEvent.change(screen.getByLabelText('Correction reason'), { target: { value: 'Damaged goods' } }); fireEvent.click(screen.getByRole('button', { name: 'Review correction' })); await screen.findByText('After: 0.749 kg'); }
it('reviews server balances and submits exact owner correction without client calculations or persistence', async () => {
  const bodies: Record<string, unknown>[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => { const body = await r.json(); bodies.push(body); return response({ data: r.url.endsWith('/preview') ? correctionReview(body) : { movement: { id: 'mov_adjust', qty_change: '-0.001', qty_after: '0.749' }, replayed: false } }, r.url.endsWith('/preview') ? 200 : 201); }));
  const { done } = mountCorrection(); await reviewCorrection(); expect(screen.queryByLabelText('Owner password')).toBeNull(); fireEvent.click(screen.getByRole('button', { name: 'Confirm correction' })); await waitFor(() => expect(done).toHaveBeenCalled());
  expect(bodies[1]).toEqual({ operation_id: bodies[0].operation_id, batch_id: 'bat_test', qty_change: '-0.001', reason: 'Damaged goods', expected_qty: '0.75' }); expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0);
});
it('freezes uncertain corrections for identical retry and blocks editing or cancellation', async () => {
  const writes: unknown[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => { const body = await r.json(); if (r.url.endsWith('/preview')) return response({ data: correctionReview(body) }); writes.push(body); if (writes.length === 1) throw new Error('Connection lost'); return response({ data: { movement: { qty_change: '-0.001', qty_after: '0.749' }, replayed: true } }); }));
  const { done, close } = mountCorrection(); await reviewCorrection(); fireEvent.click(screen.getByRole('button', { name: 'Confirm correction' })); await screen.findByText(/The outcome is not confirmed/); expect((screen.getByRole('button', { name: 'Edit correction' }) as HTMLButtonElement).disabled).toBe(true); expect((screen.getByRole('button', { name: 'Cancel correction' }) as HTMLButtonElement).disabled).toBe(true); expect(close).not.toHaveBeenCalled(); fireEvent.click(screen.getByRole('button', { name: 'Retry correction' })); await waitFor(() => expect(done).toHaveBeenCalled()); expect(writes[1]).toEqual(writes[0]);
});
it('requires owner approval and clears credentials and mutation arguments after failed and successful attempts', async () => {
  let attempts = 0; const writes: Record<string, unknown>[] = [];
  vi.stubGlobal('fetch', vi.fn(async (r: Request) => { const body = await r.json(); if (r.url.endsWith('/preview')) return response({ data: correctionReview(body, true) }); if (r.url.endsWith('/supervisor-check')) { expect(body.permission).toBe('adjust_stock'); expect(body.payload_hash).toBe('a'.repeat(64)); attempts++; return attempts === 1 ? response({ error: { code: 'INVALID_PIN', message: 'Owner credentials incorrect' } }, 401) : response({ data: { approved_by: 'usr_owner', approval_token: 'b'.repeat(64), expires_at: '2026-10-10T00:00:00Z' } }); } writes.push(body); return response({ data: { movement: { qty_change: '-0.001', qty_after: '0.749', approved_by: 'usr_owner' }, replayed: false } }, 201); }));
  const { done } = mountCorrection(); await reviewCorrection(); expect((screen.getByRole('button', { name: 'Confirm correction' }) as HTMLButtonElement).disabled).toBe(true);
  for (let i = 0; i < 2; i++) { fireEvent.change(screen.getByLabelText('Owner email'), { target: { value: 'supervisor@example.test' } }); fireEvent.change(screen.getByLabelText('Owner password'), { target: { value: 'supervisor-password-42' } });  fireEvent.click(screen.getByRole('button', { name: 'Request owner approval' })); if (i === 0) { await screen.findByText('Owner credentials incorrect'); await waitFor(() => expect((screen.getByLabelText('Owner password') as HTMLInputElement).value).toBe('')); expect(screen.queryByLabelText('Owner authenticator code')).toBeNull(); expect(s.getState().session.token).toBe('test-only-session'); } else await screen.findByText('Owner approval received for this correction.'); }
  expect(JSON.stringify(s.getState())).not.toContain('supervisor-password-42'); expect(JSON.stringify(s.getState())).not.toContain('135790'); fireEvent.click(screen.getByRole('button', { name: 'Confirm correction' })); await waitFor(() => expect(done).toHaveBeenCalled()); expect(writes[0].approval_token).toBe('b'.repeat(64)); expect(localStorage.length).toBe(0);
});
it('retains input on preview rejection and requires fresh review after a stale balance conflict', async () => {
  let previews = 0; vi.stubGlobal('fetch', vi.fn(async (r: Request) => { const body = await r.json(); if (r.url.endsWith('/preview')) { previews++; return previews === 1 ? response({ error: { code: 'VALIDATION_ERROR', message: 'Review rejected' } }, 422) : response({ data: correctionReview(body) }); } return response({ error: { code: 'STOCK_CHANGED', message: 'Stock changed after review' } }, 409); }));
  mountCorrection(); fireEvent.change(screen.getByLabelText('Stock change (kg)'), { target: { value: '-0.001' } }); fireEvent.change(screen.getByLabelText('Correction reason'), { target: { value: 'Damaged goods' } }); fireEvent.click(screen.getByRole('button', { name: 'Review correction' })); await screen.findByText('Review rejected'); expect((screen.getByLabelText('Stock change (kg)') as HTMLInputElement).value).toBe('-0.001'); fireEvent.click(screen.getByRole('button', { name: 'Review correction' })); await screen.findByText('After: 0.749 kg'); fireEvent.click(screen.getByRole('button', { name: 'Confirm correction' })); await screen.findByText('Stock changed after review'); expect((screen.getByRole('button', { name: 'Review correction' }) as HTMLButtonElement).disabled).toBe(false); expect(screen.queryByRole('button', { name: 'Retry correction' })).toBeNull();
});

it('explains unavailable correction targets without showing a login error or simulated success', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => response({ error: { code: 'NOT_FOUND', message: 'Not found' } }, 404)));
  const { done } = mountCorrection(); fireEvent.change(screen.getByLabelText('Stock change (kg)'), { target: { value: '-0.001' } }); fireEvent.change(screen.getByLabelText('Correction reason'), { target: { value: 'Damaged goods' } }); fireEvent.click(screen.getByRole('button', { name: 'Review correction' })); await screen.findByText('This product or batch is no longer available. Close stock and refresh the catalogue.'); expect(done).not.toHaveBeenCalled();
});
