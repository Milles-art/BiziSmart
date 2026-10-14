// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn, signedOut, type ServerUser } from '../src/features/live/api';
import { Incentives } from '../src/features/live/Incentives';
import { incentivesReducer, incentivePending } from '../src/features/live/incentivesState';
import type { IncentiveConfigInput } from '../src/features/live/incentivesApi';
const owner: ServerUser = { id: 'usr_owner', name: 'Owner', role: 'owner', branch_id: null, permissions: ['view_reports'] };
const body: IncentiveConfigInput & { review_hash: string } = { operation_id: '5c61eaec-a93e-4d36-85a4-68c1a27d08ce', loyalty_tsh_per_point: 100, loyalty_point_value: 100, commission_basis_points: 1000, reason: 'Owner rates', review_hash: 'a'.repeat(64) };
function makeStore() { return configureStore({ reducer: { session: sessionReducer, incentives: incentivesReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
beforeEach(() => { const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor); store = makeStore(); store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner })); });
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });
it('allows commission rate review without enabling plan-disabled loyalty',async()=>{
 await store.dispatch(liveApi.util.upsertQueryData('me',undefined,{user:owner,business:{name:'Shop'},features:{loyalty:false,commissions:true,scales:false}}));let reviewed:unknown;
 vi.stubGlobal('fetch',vi.fn(async(request:Request)=>{if(request.method==='GET')return response({data:{configuration:null,redemption_enabled:false}});reviewed=JSON.parse(await request.text());return response({data:{snapshot:reviewed,review_hash:'b'.repeat(64)}});}));
 render(<Provider store={store}><Incentives actor={owner}/></Provider>);expect(screen.getByLabelText('Whole TSh paid net value per point')).toHaveProperty('disabled',true);expect(screen.getByLabelText('Whole TSh value per redeemed point')).toHaveProperty('disabled',true);const bps=screen.getByLabelText('Commission basis points (100 = 1%)');expect(bps).toHaveProperty('disabled',false);fireEvent.change(bps,{target:{value:'250'}});fireEvent.change(screen.getByLabelText('Reason'),{target:{value:'Owner commission rates'}});fireEvent.click(screen.getByRole('button',{name:'Review rates'}));await waitFor(()=>expect(reviewed).toMatchObject({loyalty_tsh_per_point:null,loyalty_point_value:null,commission_basis_points:250}));
});
it('keeps uncertain incentive rates frozen through remount and clears after exact replay', async () => {
  store.dispatch(incentivePending({ action: 'configure', body })); let attempt = 0; const writes: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { if (request.method === 'GET') return response({ data: { configuration: null, redemption_enabled: false } }); writes.push(await request.clone().text()); return ++attempt === 1 ? response({ error: { code: 'SERVER_ERROR', message: 'Response uncertain' } }, 500) : response({ data: { configuration: { id: 'icfg_saved', ...body }, replayed: true } }); }));
  const first = render(<Provider store={store}><Incentives actor={owner} /></Provider>); fireEvent.click(screen.getByRole('button', { name: 'Retry saved incentive request' })); await screen.findByText('Response uncertain'); expect(store.getState().incentives.pending?.body).toEqual(body); first.unmount();
  render(<Provider store={store}><Incentives actor={owner} /></Provider>); fireEvent.click(screen.getByRole('button', { name: 'Retry saved incentive request' })); await waitFor(() => expect(store.getState().incentives.pending).toBeNull()); expect(writes).toEqual([JSON.stringify(body), JSON.stringify(body)]);
});
it('clears retained financial inputs on sign-out and does not load owner reports for managers', () => {
  store.dispatch(incentivePending({ action: 'configure', body })); store.dispatch(signedOut()); expect(store.getState().incentives.pending).toBeNull(); const fetcher = vi.fn(); vi.stubGlobal('fetch', fetcher); render(<Provider store={store}><Incentives actor={{ ...owner, role: 'manager', permissions: ['sell'] }} /></Provider>); expect(screen.getByText('Only the owner configures incentives and views commission payments or forecasts.')).toBeTruthy(); expect(fetcher).not.toHaveBeenCalled();
});
it('requires external payment attestation and server review before recording a commission payout', async () => {
  const reviews: Record<string, unknown>[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const path = new URL(request.url).pathname;
    if (path.endsWith('/configuration')) return response({ data: { configuration: null, redemption_enabled: false } });
    if (path.endsWith('/commissions')) return response({ data: { commissions: [{ cashier_id: 'usr_staff', cashier_name: 'Cashier', earned: 500, paid: 0, balance: 500, payable: 500, refund_carry: 0 }] }, meta: { page: 1, per_page: 50, total: 1 } });
    if (path.endsWith('/preview')) { reviews.push(JSON.parse(await request.text())); return response({ data: { snapshot: {}, review_hash: 'a'.repeat(64) } }); }
    return response({ data: { payout: { id: 'comm_pay_saved', amount: 500 }, replayed: false, external_transfer_executed: false } }, 201);
  }));
  render(<Provider store={store}><Incentives actor={owner} /></Provider>); fireEvent.click(screen.getByRole('button', { name: 'Staff commissions' })); fireEvent.click(await screen.findByRole('button', { name: 'Record external payout' }));
  expect(screen.getByText('Record money already paid externally. BiziSmart will not transfer money or remove drawer cash.')).toBeTruthy(); const reviewButton = screen.getByRole('button', { name: 'Review payout' }) as HTMLButtonElement; expect(reviewButton.disabled).toBe(true);
  fireEvent.change(screen.getByLabelText('External payment reference'), { target: { value: 'BANK-EXTERNAL-1' } }); fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Completed staff payout' } }); fireEvent.click(screen.getByLabelText('Payment was completed externally')); fireEvent.click(reviewButton);
  await screen.findByRole('button', { name: 'Record reviewed payout' }); expect(reviews).toHaveLength(1); expect(reviews[0]).toMatchObject({ cashier_id: 'usr_staff', amount: 500, external_reference: 'BANK-EXTERNAL-1', external_payment_completed: true }); expect(store.getState().incentives.pending).toBeNull();
});
