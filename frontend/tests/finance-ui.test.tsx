// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn, type ServerUser } from '../src/features/live/api';
import { financialReducer, financialPending } from '../src/features/live/financeState';
import { Reports } from '../src/features/live/Reports';
import { Expenses } from '../src/features/live/Expenses';
import { CashDrops } from '../src/features/live/CashDrops';
const owner: ServerUser = { id: 'usr_owner', name: 'Owner', role: 'owner', branch_id: 'br_main', permissions: ['manage_settings', 'view_reports', 'manage_till'] };
function makeStore() { return configureStore({ reducer: { session: sessionReducer, financial: financialReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
const list = (key: string) => response({ data: { [key]: [] }, meta: { page: 1, per_page: 25, total: 0 } });
const mount = (element: React.ReactNode) => render(<Provider store={store}>{element}</Provider>);
beforeEach(() => { const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor); store = makeStore(); store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner })); });
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });
it('does not fabricate zero financial totals when the report request fails', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => response({ error: { code: 'FORBIDDEN', message: 'Reports unavailable' } }, 403)));
  mount(<Reports actor={owner} />); await screen.findByRole('alert'); expect(screen.getByText('Reports unavailable')).toBeTruthy(); expect(screen.queryByText('Net sales')).toBeNull(); expect(screen.queryByText('TSh 0')).toBeNull();
});
it('renders exact server profit and commission results and submits the selected report window', async () => {
  const windows: string[][] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { const url = new URL(request.url); windows.push([url.searchParams.get('from') ?? '', url.searchParams.get('to') ?? '']); return response({ data: { period: {}, summary: { net_revenue: 10000, net_cogs: 7000, gross_profit: 3000, net_expenses: 500, commission_accrual: 200, net_profit: 2300, cashflow_in: 14000, cashflow_out: 2000, net_cashflow: 12000, net_output_vat: 1800, legacy_cost_allocations: 0 }, stock: { recorded_value: 9000, sellable_value: 8000, held_value: 1000, negative_batches: 0 }, outstanding_customer_debt: 4000, generated_at: '2026-10-13T12:00:00Z', basis: 'journals', scope: 'business', tax_basis: 'stored VAT' } }); }));
  mount(<Reports actor={owner} />); await screen.findByText('TSh 2,300'); expect(screen.getByText('Commission accrual')).toBeTruthy(); expect(screen.getByText('TSh 200')).toBeTruthy();
  fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } }); fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-10-13' } }); fireEvent.click(screen.getByRole('button', { name: 'Run report' })); await waitFor(() => expect(windows).toContainEqual(['2026-10-01', '2026-10-13']));
});
it('retries an uncertain expense through navigation with the same immutable reviewed UUID and body', async () => {
  const frozen = { operation_id: '8a0bf06e-09be-4d9a-a42e-3a123f7f1f30', category: 'Rent', description: 'Private reviewed expense', amount: 5000, incurred_on: '2026-10-13', review_hash: 'a'.repeat(64) };
  store.dispatch(financialPending({ url: 'expenses', label: 'Expense', body: frozen })); let attempts = 0; const writes: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { if (request.method === 'GET') { const path = new URL(request.url).pathname; if (path.endsWith('/current')) return response({ data: { till: null } }); if (path.endsWith('/receivers')) return response({ data: { receivers: [] } }); return list(path.endsWith('/expenses') ? 'expenses' : 'drops'); } writes.push(await request.clone().text()); return ++attempts === 1 ? response({ error: { code: 'SERVER_ERROR', message: 'Expense response uncertain' } }, 503) : response({ data: { expense: { id: 'exp_saved' }, replayed: true } }); }));
  const first = mount(<Expenses />); fireEvent.click(screen.getByRole('button', { name: 'Retry saved action' })); await screen.findByText('Expense response uncertain'); expect(store.getState().financial.pending?.body).toEqual(frozen); first.unmount();
  mount(<CashDrops actor={owner} />); fireEvent.click(screen.getByRole('button', { name: 'Retry saved action' })); await waitFor(() => expect(store.getState().financial.pending).toBeNull()); expect(writes).toEqual([JSON.stringify(frozen), JSON.stringify(frozen)]); expect(screen.queryByText('TSh 5,000')).toBeNull();
});
it('requires server expense preview and explicit confirmation before recording the reviewed body', async () => {
  const writes: { path: string; body: Record<string, unknown> }[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { const path = new URL(request.url).pathname; if (request.method === 'GET') return path.endsWith('/current') ? response({ data: { till: null } }) : list('expenses'); const body = JSON.parse(await request.clone().text()); writes.push({ path, body }); if (path.endsWith('/preview')) return response({ data: { snapshot: body, review_hash: 'a'.repeat(64), approval_required: false } }); return response({ data: { expense: { id: 'exp_saved' }, replayed: false } }, 201); }));
  mount(<Expenses />); fireEvent.click(screen.getByRole('button', { name: 'Record expense' })); fireEvent.change(screen.getByLabelText('Category'), { target: { value: 'Rent' } }); fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Reviewed shop rent' } }); fireEvent.change(screen.getByLabelText('Amount (TSh)'), { target: { value: '1000' } }); fireEvent.click(screen.getByRole('button', { name: 'Review expense' }));
  await screen.findByRole('button', { name: 'Confirm expense record' }); expect(writes).toHaveLength(1); expect(writes[0].path).toBe('/api/v1/expenses/preview'); fireEvent.click(screen.getByRole('button', { name: 'Confirm expense record' })); await waitFor(() => expect(writes).toHaveLength(2)); expect(writes[1].path).toBe('/api/v1/expenses'); expect(writes[1].body).toEqual({ ...writes[0].body, review_hash: 'a'.repeat(64) });
});
it('reviews a cash handover against the server till and retains its exact uncertain request', async () => {
  const writes: { path: string; body: Record<string, unknown> }[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { const path = new URL(request.url).pathname; if (request.method === 'GET') { if (path.endsWith('/current')) return response({ data: { till: { id: 'till_server', expected_cash: 30000 } } }); if (path.endsWith('/receivers')) return response({ data: { receivers: [{ id: 'usr_manager', name: 'Receiving manager', role: 'manager' }] } }); return list('drops'); } const body = JSON.parse(await request.clone().text()); writes.push({ path, body }); if (path.endsWith('/preview')) return response({ data: { snapshot: { ...body, expected_cash: 30000 }, review_hash: 'b'.repeat(64) } }); return response({ error: { code: 'SERVER_ERROR', message: 'Handover response uncertain' } }, 503); }));
  mount(<CashDrops actor={owner} />); await waitFor(() => expect((screen.getByRole('button', { name: 'Hand over cash' }) as HTMLButtonElement).disabled).toBe(false)); fireEvent.click(screen.getByRole('button', { name: 'Hand over cash' })); fireEvent.change(screen.getByLabelText('Receiving manager'), { target: { value: 'usr_manager' } }); fireEvent.change(screen.getByLabelText('Amount (TSh)'), { target: { value: '10000' } }); fireEvent.change(screen.getByLabelText('Handover reason'), { target: { value: 'Safe handover' } }); fireEvent.click(screen.getByRole('button', { name: 'Review handover' })); await screen.findByText(/Drawer cash after handover/); expect(writes).toHaveLength(1); fireEvent.click(screen.getByRole('button', { name: 'Confirm cash handover' })); await waitFor(() => expect(writes).toHaveLength(2)); await waitFor(() => expect(store.getState().financial.pending).not.toBeNull()); expect(writes[1].body).toEqual({ ...writes[0].body, review_hash: 'b'.repeat(64) }); expect(store.getState().financial.pending?.body).toEqual(writes[1].body);
});
