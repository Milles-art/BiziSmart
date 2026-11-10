// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Customers } from '../src/features/live/Customers';
import { collectionReducer } from '../src/features/live/collectionState';
import { liveApi, sessionReducer, signedIn } from '../src/features/live/api';
import { downloadReportCsv } from '../src/features/live/reportCsv';

vi.mock('../src/features/live/reportCsv', () => ({ downloadReportCsv: vi.fn() }));
const customer = { id: 'cust_one', name: 'Asha', phone: null, credit_limit: 10000, outstanding_balance: 5100, created_at: '2026-10-10T00:00:00Z' };
const entries = Array.from({ length: 51 }, (_, i) => ({ date: '2026-10-19', at: `2026-10-19T09:00:${String(i).padStart(2,'0')}Z`, kind: 'credit_sale', label: `RCP-${i+1}`, charge: 100, payment: 0, debt_reduction: 0, balance: (i+1)*100 }));
const full = { customer, period: { from: null, to: null, timezone: 'Africa/Dar_es_Salaam' }, opening_balance: 0, charges: 5100, payments: 0, closing_balance: 5100, outstanding_balance: 5100, currency: 'TSh', generated_at: '2026-10-19T12:00:00Z', entries, meta: { page: 1, per_page: 50, total: 51, count: 51 } };
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
function makeStore() { return configureStore({ reducer: { session: sessionReducer, collection: collectionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: g => g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
let failExport: boolean;
const requests: string[] = [];
beforeEach(() => {
  const c = transferableAbortController(); vi.stubGlobal('AbortController', c.constructor); vi.stubGlobal('AbortSignal', c.signal.constructor);
  vi.mocked(downloadReportCsv).mockClear(); failExport = false; requests.length = 0;
  store = makeStore();
  store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now()+600000).toISOString(), user: { id: 'usr_one', name: 'Cashier', role: 'cashier', branch_id: 'br_one', permissions: ['manage_customers'] } }));
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const url = new URL(request.url); requests.push(url.pathname+url.search);
    if (url.pathname.endsWith('/statement/export')) return failExport ? response({ error: { message: 'Choose a narrower statement period.' } }, 422) : response({ data: full });
    if (url.pathname.endsWith('/statement')) { const page = Number(url.searchParams.get('page') ?? '1'); return response({ data: { ...full, entries: entries.slice((page-1)*50,page*50), meta: { ...full.meta, page } } }); }
    if (url.pathname.endsWith('/debt')) return response({ data: { customer_id: customer.id, outstanding_balance: 5100, credit_limit: 10000, aging: {}, open_sales: [] } });
    if (url.pathname.endsWith('/me')) return response({ data: { user: store.getState().session.user, business: { name: 'Shop' }, features: { loyalty: false, messaging: false } } });
    return response({ data: { customers: [customer] }, meta: { page: 1, per_page: 30, total: 1 } });
  }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });
async function openStatement() {
  await store.dispatch(liveApi.util.upsertQueryData('me', undefined, { user: store.getState().session.user!, business: { name: 'Shop' }, features: { loyalty: false, messaging: false } }));
  render(<Provider store={store}><Customers actor={store.getState().session.user} /></Provider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Asha', exact: true }));
  fireEvent.click(await screen.findByRole('button', { name: 'Customer statement', exact: true }));
  return within(await screen.findByRole('dialog', { name: 'Customer statement' }));
}
it('pages beyond the first fifty movements and exports the complete server ledger', async () => {
  const dialog = await openStatement();
  await dialog.findByText('RCP-50'); expect(dialog.queryByText('RCP-51')).toBeNull();
  fireEvent.click(dialog.getByRole('button', { name: 'Next', exact: true }));
  await dialog.findByText('RCP-51'); expect(dialog.queryByText('RCP-50')).toBeNull();
  expect(dialog.getByRole('button', { name: 'Next', exact: true })).toHaveProperty('disabled', true);
  fireEvent.click(dialog.getByRole('button', { name: 'Export statement (CSV)' }));
  await waitFor(() => expect(downloadReportCsv).toHaveBeenCalledOnce());
  const rows = vi.mocked(downloadReportCsv).mock.calls[0][1];
  expect(rows.filter(row => String(row[2]).startsWith('RCP-'))).toHaveLength(51);
  expect(rows.find(row => row[2] === 'RCP-51')?.[6]).toBe('5100');
  expect(requests).toContain('/api/v1/customers/cust_one/statement/export');
  expect(requests).toContain('/api/v1/customers/cust_one/statement?page=2');
});
it('shows an export failure and never downloads the partial visible page as a full statement', async () => {
  failExport = true;
  const dialog = await openStatement(); await dialog.findByText('RCP-50');
  fireEvent.click(dialog.getByRole('button', { name: 'Export statement (CSV)' }));
  expect(await dialog.findByText('Choose a narrower statement period.')).toBeTruthy();
  expect(downloadReportCsv).not.toHaveBeenCalled(); expect(dialog.getByText('RCP-50')).toBeTruthy();
});
