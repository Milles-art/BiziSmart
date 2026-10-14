// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn, signedOut, type ServerUser } from '../src/features/live/api';
import { Pricing } from '../src/features/live/Pricing';
import { pricingReducer, pricingPending } from '../src/features/live/pricingState';
import type { PricingInput } from '../src/features/live/pricingApi';
const owner: ServerUser = { id: 'usr_x', name: 'Owner', role: 'owner', branch_id: null, permissions: ['manage_products'] };
const body: PricingInput & { review_hash: string } = { operation_id: '36e21230-fc08-4da7-ab1c-263650944a65', name: 'Weekend', kind: 'percentage', priority: 100, reason: 'Owner offer', starts_at: '2026-10-13T10:00', ends_at: '2026-10-13T20:00', basis_points: 5000, targets: [{ product_id: 'prod_x', packaging_id: null }], review_hash: 'a'.repeat(64) };
function makeStore() { return configureStore({ reducer: { session: sessionReducer, pricing: pricingReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
beforeEach(() => { const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor); store = makeStore(); store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: owner })); });
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });
it('keeps uncertain owner pricing writes frozen through remount and clears them after server replay', async () => {
  store.dispatch(pricingPending({ action: 'create', body })); let attempts = 0; const writes: string[] = [];
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { if (request.method === 'GET') return response({ data: { rules: [] }, meta: { page: 1, per_page: 50, total: 0 } }); writes.push(await request.clone().text()); attempts++; return attempts === 1 ? response({ error: { code: 'SERVER_ERROR', message: 'Response uncertain' } }, 500) : response({ data: { rule: { id: 'price_rule_saved' }, replayed: true } }); }));
  const first = render(<Provider store={store}><Pricing actor={owner} /></Provider>); fireEvent.click(screen.getByRole('button', { name: 'Retry saved pricing request' })); await screen.findByText('Response uncertain'); expect(store.getState().pricing.pending?.body).toEqual(body); first.unmount();
  render(<Provider store={store}><Pricing actor={owner} /></Provider>); fireEvent.click(screen.getByRole('button', { name: 'Retry saved pricing request' })); await waitFor(() => expect(store.getState().pricing.pending).toBeNull()); expect(writes).toEqual([JSON.stringify(body), JSON.stringify(body)]);
});
it('clears pending rule inputs on sign-out', () => { store.dispatch(pricingPending({ action: 'create', body })); store.dispatch(signedOut()); expect(store.getState().pricing.pending).toBeNull(); });
it('keeps configuration unavailable to managers without loading owner rules', () => { const fetcher = vi.fn(); vi.stubGlobal('fetch', fetcher); render(<Provider store={store}><Pricing actor={{ ...owner, role: 'manager', permissions: ['sell'] }} /></Provider>); expect(screen.getByText('Only the owner configures prices and offers.')).toBeTruthy(); expect(fetcher).not.toHaveBeenCalled(); });
