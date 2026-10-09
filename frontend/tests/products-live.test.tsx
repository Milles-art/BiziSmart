// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Products } from '../src/features/live/Products';
import { Packagings } from '../src/features/live/Packagings';
import { liveApi, sessionReducer, signedIn, type CatalogueProduct, type ServerUser } from '../src/features/live/api';

const actor: ServerUser = { id: 'usr_test', name: 'Owner', role: 'owner', branch_id: null, permissions: ['manage_products', 'view_buying_price'] };
const product: CatalogueProduct = { id: 'prod_test', name: 'Server rice', barcode: '00123456789', category: 'Food', quantity_type: 'weight', unit: 'kg', selling_price: 4000, buying_price: 3000, reorder_level: '0.75', tracks_expiry: false, is_active: true, stock: null, stock_available: false };
let s: ReturnType<typeof makeStore>;
function makeStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) }); }
function response(data: unknown, status = 200) { return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }); }
function list(products: CatalogueProduct[], total = products.length, page = 1) { return response({ data: { products }, meta: { page, per_page: 50, total } }); }
function mount(user = actor) { render(<Provider store={s}><Products actor={user} /></Provider>); }
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  s = makeStore(); s.dispatch(signedIn({ token: 'test-only-session', expires_at: new Date(Date.now() + 600000).toISOString(), user: actor }));
});
afterEach(() => { cleanup(); s.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); localStorage.clear(); });

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
  expect(screen.queryByRole('columnheader', { name: 'Cost' })).toBeNull();
  expect(screen.queryByText('TSh 3,000')).toBeNull();
});
