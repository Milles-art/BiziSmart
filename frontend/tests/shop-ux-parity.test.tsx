// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { denominationTotal, roundUpToNote, tenderNotes } from '../src/features/live/cashCounts';
import { Reports } from '../src/features/live/Reports';
import { CashDrops } from '../src/features/live/CashDrops';
import { Staff } from '../src/features/live/Staff';
import { Customers } from '../src/features/live/Customers';
import { collectionReducer } from '../src/features/live/collectionState';
import { financialReducer } from '../src/features/live/financeState';
import { liveApi, sessionReducer, signedIn, type ServerUser } from '../src/features/live/api';

const owner: ServerUser = { id: 'usr_owner', name: 'Owner', role: 'owner', branch_id: 'br_main', permissions: ['sell', 'manage_settings', 'view_reports', 'manage_till', 'manage_customers', 'manage_users', 'receive_stock'] };
const cashier: ServerUser = { id: 'usr_cash', name: 'Cashier', role: 'cashier', branch_id: 'br_main', permissions: ['sell', 'manage_customers'] };
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
const paginated = (key: string, rows: unknown[], total = rows.length) => response({ data: { [key]: rows }, meta: { page: 1, per_page: 25, total } });
let store: ReturnType<typeof makeStore>;
function makeStore() {
  return configureStore({ reducer: { session: sessionReducer, collection: collectionReducer, financial: financialReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) });
}
function mount(element: React.ReactNode) { return render(<Provider store={store}>{element}</Provider>); }
beforeEach(() => {
  const controller = transferableAbortController();
  vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  store = makeStore();
  store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });

const summary = { period: {}, summary: { net_revenue: 10000, net_cogs: 7000, gross_profit: 3000, net_expenses: 0, commission_accrual: 0, net_profit: 3000, cashflow_in: 10000, cashflow_out: 0, net_cashflow: 10000, net_output_vat: 1500, legacy_cost_allocations: 0 }, stock: { recorded_value: 5000, sellable_value: 5000, held_value: 0, negative_batches: 0 }, outstanding_customer_debt: 0, generated_at: '2026-10-13T12:00:00Z', basis: 'journals', scope: 'business', tax_basis: 'stored VAT' };

it('adds counted notes into one exact whole-TSh total and offers only notes that cover the sale', () => {
  expect(denominationTotal({ '10000': '2', '5000': '1', '1000': '3', '500': '' })).toBe(28000);
  expect(denominationTotal({ '20000': 'abc' })).toBe(0);
  expect(denominationTotal({})).toBe(0);
  expect(tenderNotes(8500)).toEqual([10000, 20000, 50000]);
  expect(tenderNotes(20000)).toEqual([20000, 50000]);
  expect(tenderNotes(0)).toEqual([]);
  expect(roundUpToNote(8500)).toBe(10000);
  expect(roundUpToNote(120000)).toBe(120000);
});

it('keeps reports closed to staff without owner authority and never calls a report endpoint', async () => {
  const requested: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { requested.push(new URL(request.url).pathname); return response({ data: summary }); }));
  mount(<Reports actor={cashier} />);
  expect(screen.getByText('Financial reports are available to the owner.')).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Run report' })).toBeNull();
  expect(requested.filter(path => path.includes('/reports'))).toEqual([]);
});

it('runs a saved date range in one tap and sends that exact window', async () => {
  const windows: string[][] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { const url = new URL(request.url); windows.push([url.searchParams.get('from') ?? '', url.searchParams.get('to') ?? '']); return response({ data: summary }); }));
  mount(<Reports actor={owner} />);
  await waitFor(() => expect(windows.length).toBeGreaterThan(0));
  const eastAfricaNow = new Date(Date.now() + 3 * 3600000);
  const day = (offset: number) => new Date(Date.now() + 3 * 3600000 - offset * 86400000).toISOString().slice(0, 10);
  fireEvent.click(screen.getByRole('button', { name: 'Yesterday' }));
  await waitFor(() => expect(windows).toContainEqual([day(1), day(1)]));
  fireEvent.click(screen.getByRole('button', { name: 'Last 7 days' }));
  await waitFor(() => expect(windows).toContainEqual([day(6), day(0)]));
  expect(eastAfricaNow.toISOString().slice(0, 10)).toBe(day(0));
});

it('requires an explicit confirmation before acknowledging received cash', async () => {
  const writes: string[] = [];
  const drop = { id: 'drop_one', till_id: 'till_one', amount: 40000, reason: 'Bank run', status: 'pending', cashier: 'Cashier', receiver: 'Owner', receiver_id: owner.id, created_at: '2026-10-17T09:00:00Z' };
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const path = new URL(request.url).pathname;
    if (request.method !== 'POST') { if (path.endsWith('/current')) return response({ data: { till: null, branch: null, variance_limit: 5000, can_open: true } }); if (path.endsWith('/receivers')) return response({ data: { receivers: [] } }); return paginated('drops', [drop]); }
    writes.push(path);
    return response({ data: { drop: { ...drop, status: 'acknowledged' }, replayed: false } }, 201);
  }));
  mount(<CashDrops actor={owner} />);
  fireEvent.click(await screen.findByRole('button', { name: 'Acknowledge received cash' }));
  const dialog = within(screen.getByRole('dialog', { name: /Acknowledge received cash/ }));
  expect(dialog.getByText('TSh 40,000')).toBeTruthy();
  expect(writes).toEqual([]);
  fireEvent.click(dialog.getByRole('button', { name: 'Confirm cash received' }));
  await waitFor(() => expect(writes).toEqual(['/api/v1/cash-drops/drop_one/acknowledge']));
});

it('hides legacy PIN reset for email accounts and shows their password-reset guidance', async () => {
  const staff = [{ id: 'usr_email', name: 'Email Cashier', email: 'cashier@shop.test', phone: '255620000001', role: 'cashier', is_active: true, email_login_enabled: true, permissions: ['sell'] }];
  vi.stubGlobal('fetch', vi.fn(async () => paginated('users', staff)));
  mount(<Staff actor={owner} />);
  const card = within(await screen.findByLabelText('Staff member Email Cashier'));
  expect(card.queryByRole('button', { name: 'Reset PIN' })).toBeNull();
  expect(card.getByText('Email sign-in ready. Use Forgot password on the sign-in page to reset it.')).toBeTruthy();
});

it('filters staff on the loaded page and validates a new PIN before calling the reset endpoint', async () => {
  const writes: string[] = [];
  const staff = [{ id: 'usr_grace', name: 'Grace Mwangi', email: null, phone: '255620000001', role: 'cashier', is_active: true, two_factor_enabled: false, must_change_pin: false, email_login_enabled: false, permissions: ['sell'] }, { id: 'usr_joseph', name: 'Joseph Kimaro', email: 'joseph@shop.test', phone: '255620000002', role: 'stockkeeper', is_active: true, two_factor_enabled: false, must_change_pin: false, email_login_enabled: true, permissions: ['receive_stock'] }];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { if (request.method === 'POST') { writes.push(new URL(request.url).pathname); return response({ data: { id: 'usr_grace' } }); } return paginated('users', staff); }));
  mount(<Staff actor={owner} />);
  fireEvent.change(await screen.findByLabelText('Filter staff on this page'), { target: { value: 'grace' } });
  await waitFor(() => expect(screen.queryByLabelText('Staff member Joseph Kimaro')).toBeNull());
  expect(screen.getByLabelText('Staff member Grace Mwangi')).toBeTruthy();
  fireEvent.click(within(screen.getByLabelText('Staff member Grace Mwangi')).getByRole('button', { name: 'Reset PIN' }));
  const dialog = within(screen.getByRole('dialog', { name: /Reset PIN/ }));
  fireEvent.change(dialog.getByLabelText('New PIN'), { target: { value: '123' } });
  fireEvent.change(dialog.getByLabelText('Repeat new PIN'), { target: { value: '123' } });
  fireEvent.click(dialog.getByRole('button', { name: 'Reset PIN' }));
  expect(dialog.getByRole('alert').textContent).toContain('4 to 6 digits');
  fireEvent.change(dialog.getByLabelText('New PIN'), { target: { value: '1234567' } });
  fireEvent.change(dialog.getByLabelText('Repeat new PIN'), { target: { value: '1234567' } });
  fireEvent.click(dialog.getByRole('button', { name: 'Reset PIN' }));
  expect(dialog.getByRole('alert').textContent).toContain('4 to 6 digits');
  expect(writes).toEqual([]);
  fireEvent.change(dialog.getByLabelText('New PIN'), { target: { value: '4821' } });
  fireEvent.change(dialog.getByLabelText('Repeat new PIN'), { target: { value: '4822' } });
  fireEvent.click(dialog.getByRole('button', { name: 'Reset PIN' }));
  expect(dialog.getByRole('alert').textContent).toContain('do not match');
  expect(writes).toEqual([]);
  fireEvent.change(dialog.getByLabelText('Repeat new PIN'), { target: { value: '4821' } });
  fireEvent.click(dialog.getByRole('button', { name: 'Reset PIN' }));
  await waitFor(() => expect(writes).toEqual(['/api/v1/auth/pin/reset']));
});

it('keeps cash collections closed for staff without till authority and never asks for a till session', async () => {
  const requested: string[] = [];
  const customer = { id: 'cust_one', name: 'Asha', phone: '255620000000', credit_limit: 50000, outstanding_balance: 15000, created_at: '2026-10-10T00:00:00Z' };
  const debt = { customer_id: customer.id, outstanding_balance: 15000, credit_limit: 50000, aging: { '0_30': 15000, '31_60': 0, '61_90': 0, '90_plus': 0 }, open_sales: [{ sale_id: 'sale_new', receipt_number: 'RCP-new', remaining_amount: 15000, original_amount: 15000, created_at: '2026-10-10T00:00:00Z' }] };
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const path = new URL(request.url).pathname; requested.push(path);
    if (path.endsWith('/debt')) return response({ data: debt });
    return response({ data: { customers: [customer] }, meta: { page: 1, per_page: 30, total: 1 } });
  }));
  await store.dispatch(liveApi.util.upsertQueryData('me', undefined, { user: cashier, business: { name: 'Shop' }, features: { loyalty: false, messaging: false } }));
  mount(<Customers actor={cashier} />);
  fireEvent.click(await screen.findByRole('button', { name: 'Asha', exact: true }));
  expect(await screen.findByText(/Debt collections are recorded by a staff member with till access/)).toBeTruthy();
  expect(screen.queryByLabelText('Cash collection amount (TSh)')).toBeNull();
  expect(requested.filter(path => path.includes('/till-sessions/current'))).toEqual([]);
});
