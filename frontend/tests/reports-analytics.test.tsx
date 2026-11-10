// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Reports } from '../src/features/live/Reports';
import { liveApi, sessionReducer, signedIn, type ServerUser } from '../src/features/live/api';

const owner: ServerUser = { id: 'usr_owner', name: 'Owner', role: 'owner', branch_id: 'br_main', permissions: ['view_reports'] };
const analytics = {
  period: { from: '2026-10-13', to: '2026-10-13', timezone: 'Africa/Dar_es_Salaam' },
  generated_at: '2026-10-13T12:00:00Z',
  basis: 'event_date_accrual',
  payment_methods: [
    { method: 'cash', label: 'Cash', sales: 34000, refunds: 8500, net: 25500 },
    { method: 'mpesa', label: 'Mobile money', sales: 5000, refunds: 0, net: 5000 },
    { method: 'credit', label: 'Customer credit', sales: 8500, refunds: 0, net: 8500 },
  ],
  hourly: [{ hour: 9, label: '09:00', sales: 42500, refunds: 8500, net: 34000 }],
  top_sellers: [{ product_id: 'prod_one', name: 'Fresh Choice Rice', unit: 'kg', qty: '2.500', sales_count: 2, revenue: 34000, cost: 20000, profit: 14000 }],
  slow_movers: [{ product_id: 'prod_two', name: 'Canned Beans', unit: 'tin', stock_qty: '12', stock_value: 18000, expiry_date: '2026-12-01' }],
};
function makeStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: g => g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
const requested: string[] = [];
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  requested.length = 0; store = makeStore();
  store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner }));
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const url = new URL(request.url); requested.push(url.pathname + url.search);
    return response({ data: analytics });
  }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });

it('shows exact payment split, hourly trading, top sellers and slow-moving stock on request', async () => {
  render(<Provider store={store}><Reports actor={owner} /></Provider>);
  fireEvent.click(screen.getByRole('button', { name: 'Sales analytics' }));

  expect(await screen.findByText('Payment methods')).toBeTruthy();
  const cardOf = (title: string) => screen.getByText(title).closest('.rounded-xl') as HTMLElement;
  const methods = cardOf('Payment methods');
expect(within(methods).getAllByText('Cash').length).toBeGreaterThan(0);
  expect(within(methods).getAllByText('Mobile money').length).toBeGreaterThan(0);
  expect(within(methods).getByText('TSh 25,500')).toBeTruthy();
  expect(within(methods).getByText(/Refunds TSh 8,500/)).toBeTruthy();
  expect(screen.getByText('Sales by hour')).toBeTruthy();
  expect(screen.getByText('09:00')).toBeTruthy();

  const sellers = cardOf('Top sellers');
  expect(within(sellers).getByText('Fresh Choice Rice')).toBeTruthy();
  expect(within(sellers).getByText('2.500 kg')).toBeTruthy();
  expect(within(sellers).getByText('TSh 14,000')).toBeTruthy();

  const slow = cardOf('Slow-moving stock');
  expect(within(slow).getByText('Canned Beans')).toBeTruthy();
  expect(within(slow).getByText('12 tin')).toBeTruthy();
  expect(within(slow).getByText('2026-12-01')).toBeTruthy();

  await waitFor(() => expect(requested.some(url => url.startsWith('/api/v1/reports/sales-analytics'))).toBe(true));
});

it('never requests analytics for a screen the owner cannot open', async () => {
  render(<Provider store={store}><Reports actor={{ ...owner, role: 'cashier', permissions: ['sell'] } as ServerUser} /></Provider>);
  expect(await screen.findByText('Financial reports are available to the owner.')).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Sales analytics' })).toBeNull();
  await waitFor(() => expect(requested.filter(url => url.includes('reports'))).toEqual([]));
});

it('reports a server failure honestly instead of showing empty analytics', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => response({ error: { code: 'REPORT_TOO_LARGE', message: 'Choose a narrower report period.' } }, 422)));
  render(<Provider store={store}><Reports actor={owner} /></Provider>);
  fireEvent.click(screen.getByRole('button', { name: 'Sales analytics' }));
  expect(await screen.findByText('Choose a narrower report period.')).toBeTruthy();
  expect(screen.queryByText('Top sellers')).toBeNull();
});

it('reprints the owner ledger through the protected reports receipt endpoint', async () => {
  const sale = { id: 'sale_saved', receipt_number: 'RCP-123', status: 'completed', branch_id: 'br_main', till_id: 'till_cashier',
    subtotal: 8500, discount: 0, total: 8500, vat_rate: 18, vat_amount: 1297, cash_tendered: 10000, change_given: 1500,
    created_at: '2026-10-13T09:00:00Z', receipt_url: null, lines: [], payments: [{ id: 'pay_one', method: 'cash', amount: 8500 }] };
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const path = new URL(request.url).pathname; requested.push(path);
    if (path.endsWith('/settings/business')) return response({ data: { name: 'Shop', paper_width: '80', header: '', footer: '' } });
    if (path.endsWith('/reports/sales/sale_saved/receipt')) return response({ data: sale });
    if (path.endsWith('/reports/sales')) return response({ data: { sales: [{ ...sale, branch: 'Main', cashier: 'Cashier', net_revenue: 7203 }] }, meta: { page: 1, per_page: 50, total: 1 } });
    return response({ data: { period: {}, summary: {}, stock: {} } });
  }));
  render(<Provider store={store}><Reports actor={owner} /></Provider>);
  fireEvent.click(screen.getByRole('button', { name: 'Sales ledger' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Reprint' }));
  await waitFor(() => expect(requested).toContain('/api/v1/reports/sales/sale_saved/receipt'));
  expect(requested).not.toContain('/api/v1/sales/sale_saved');
  fireEvent.click(await screen.findByRole('button', { name: 'Reprint' }));
  expect(await screen.findByRole('dialog', { name: 'Receipt preview' })).toBeTruthy();
});
