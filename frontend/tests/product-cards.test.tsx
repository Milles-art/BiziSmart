// @vitest-environment jsdom
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Products } from '../src/features/live/Products';
import { liveApi, sessionReducer, signedIn, type CatalogueProduct, type ServerUser } from '../src/features/live/api';

const owner: ServerUser = { id: 'usr_cards', name: 'Owner', role: 'owner', branch_id: null, permissions: ['manage_products', 'view_buying_price', 'receive_stock'] };
const rice: CatalogueProduct = { id: 'prod_rice', name: 'Square rice', barcode: '000123', category: 'Grains', unit: 'kg', quantity_type: 'weight', selling_price: 4500, buying_price: 3000, stock: '0.001', stock_available: true, stock_total: '9.001', reorder_level: '0.75', tracks_expiry: true, nearest_expiry: '2027-03-01', is_active: true };
let store: ReturnType<typeof makeStore>;
function makeStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: get => get().concat(liveApi.middleware) }); }
function json(body: unknown) { return new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } }); }
function stubProducts(products = [rice]) {
  const requests: Request[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    requests.push(request);
    const path = new URL(request.url).pathname;
    if (path.endsWith('/departments')) return json({ data: { departments: [] }, meta: { page: 1, total: 0, per_page: 50 } });
    if (path.endsWith('/image-capabilities')) return json({ data: { configured: false, enabled: false, reason: 'storage_setup_required' } });
    return json({ data: { products }, meta: { page: 1, per_page: 50, total: products.length } });
  }));
  return requests;
}
function mount(actor = owner) { render(<Provider store={store}><Products actor={actor} /></Provider>); }
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  store = makeStore(); store.dispatch(signedIn({ token: 'unit-test-session', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); localStorage.clear(); });

it('defaults to product cards with exact server price and stock, a photo placeholder and detailed owner information', async () => {
  stubProducts(); mount();
  const card = await screen.findByRole('article', { name: 'Product Square rice' });
  expect(screen.getByRole('button', { name: 'Grid view' }).getAttribute('aria-pressed')).toBe('true');
  expect(within(card).getByText('TSh 4,500')).toBeTruthy();
  expect(within(card).getByText('0.001 kg')).toBeTruthy();
  expect(within(card).getByText('No photo yet')).toBeTruthy();
  expect(screen.queryByRole('table')).toBeNull();
  fireEvent.click(within(card).getByRole('button', { name: 'Square rice', exact: true }));
  const details = within(screen.getByRole('dialog', { name: 'Square rice' }));
  expect(details.getByText('TSh 3,000')).toBeTruthy();
  expect(details.getByText('Held: 9.001 kg')).toBeTruthy();
  expect(details.getByText('000123')).toBeTruthy();
  fireEvent.click(details.getByRole('button', { name: 'Close product details' }));
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  fireEvent.click(screen.getByRole('button', { name: 'Edit Square rice' }));
  await screen.findByRole('dialog', { name: 'Edit Product' });
  expect(screen.getByLabelText('Selling price (TSh / kg)').getAttribute('value')).toBe('4500');
});

it('keeps restricted costs, held stock and management actions hidden in grid and details for a seller', async () => {
  stubProducts(); mount({ ...owner, role: 'cashier', permissions: ['sell'] });
  const card = await screen.findByRole('article', { name: 'Product Square rice' });
  expect(within(card).queryByRole('button', { name: 'Edit Square rice' })).toBeNull();
  fireEvent.click(within(card).getByRole('button', { name: 'Actions for Square rice' }));
  expect(screen.queryByRole('menuitem', { name: 'Stock for Square rice' })).toBeNull();
  expect(screen.queryByRole('menuitem', { name: 'Archive Square rice' })).toBeNull();
  fireEvent.click(screen.getByRole('menuitem', { name: 'Product details', exact: true }));
  const details = within(await screen.findByRole('dialog', { name: 'Square rice' }));
  expect(details.queryByText('TSh 3,000')).toBeNull();
  expect(details.queryByText(/Held:/)).toBeNull();
  expect(screen.queryByRole('button', { name: 'Add Product' })).toBeNull();
});

it('routes the card archive action through confirmation without mutating when cancelled', async () => {
  const requests = stubProducts(); mount();
  fireEvent.click(await screen.findByRole('button', { name: 'Actions for Square rice' }));
  await screen.findByRole('menuitem', { name: 'Stock for Square rice' });
  fireEvent.click(screen.getByRole('menuitem', { name: 'Archive Square rice' }));
  const confirmation = within(await screen.findByRole('dialog', { name: 'Archive Square rice?' }));
  fireEvent.click(confirmation.getByRole('button', { name: 'Cancel' }));
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  expect(requests.every(request => request.method === 'GET')).toBe(true);
  expect(screen.getByRole('article', { name: 'Product Square rice' })).toBeTruthy();
});

it('preserves server filters and exact values when switching between grid and detailed list', async () => {
  const requests = stubProducts(); mount();
  await screen.findByRole('article', { name: 'Product Square rice' });
  fireEvent.click(screen.getByRole('button', { name: 'Filters', exact: true }));
  fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '000123' } });
  fireEvent.click(screen.getByLabelText('Low stock only'));
  fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
  await waitFor(() => expect(requests.some(request => new URL(request.url).searchParams.get('barcode') === '000123')).toBe(true));
  await screen.findByText('Low stock', { exact: true });
  const reads = requests.length;
  fireEvent.click(screen.getByRole('button', { name: 'List view' }));
  const row = await screen.findByRole('row', { name: 'Product Square rice' });
  expect(within(row).getByText('Held: 9.001 kg')).toBeTruthy();
  expect(screen.getByLabelText('Barcode').getAttribute('value')).toBe('000123');
  fireEvent.click(screen.getByRole('button', { name: 'Grid view' }));
  await screen.findByRole('article', { name: 'Product Square rice' });
  expect(screen.getByLabelText('Low stock only').getAttribute('type')).toBe('checkbox');
  expect(requests).toHaveLength(reads);
  expect(localStorage.length).toBe(0);
});

it('distinguishes missing stock data, zero sellable stock and inactive products without guessing reorder thresholds', async () => {
  stubProducts([
    { ...rice, id: 'prod_unknown', name: 'Unknown stock', stock: null, stock_available: false },
    { ...rice, id: 'prod_empty', name: 'Empty shelf', stock: '0' },
    { ...rice, id: 'prod_inactive', name: 'Inactive rice', is_active: false },
  ]); mount();
  expect(within(await screen.findByRole('article', { name: 'Product Unknown stock' })).getByText('Stock unavailable')).toBeTruthy();
  expect(within(screen.getByRole('article', { name: 'Product Empty shelf' })).getByText('Out of stock')).toBeTruthy();
  expect(within(screen.getByRole('article', { name: 'Product Inactive rice' })).getByText('Inactive', { exact: true })).toBeTruthy();
  expect(screen.queryByText('Low stock', { exact: true })).toBeNull();
});
