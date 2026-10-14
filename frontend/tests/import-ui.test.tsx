// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn, signedOut } from '../src/features/live/api';
import { catalogueImportReducer, importPending } from '../src/features/live/catalogueImportState';
import { ProductImportExport } from '../src/features/live/ProductImportExport';
import type { ImportCommit } from '../src/features/live/catalogueImportApi';
const frozen: ImportCommit = { operation_id: 'ff4af24e-3b69-4d7e-8586-605984c22b67', rows: [{ barcode: '00123', batch_number: 'B1', qty: '0.750', cost_price: 2000, expiry_date: null }], review_hash: 'a'.repeat(64) };
function makeStore() { return configureStore({ reducer: { session: sessionReducer, catalogueImport: catalogueImportReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  store = makeStore(); store.dispatch(signedIn({ token: 'test-token', expires_at: new Date(Date.now() + 600000).toISOString(), user: { id: 'usr_x', name: 'Owner', role: 'owner', branch_id: null, permissions: ['manage_products', 'receive_stock', 'view_buying_price'] } }));
  store.dispatch(importPending(frozen));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); });
const mount = () => render(<Provider store={store}><ProductImportExport onClose={() => undefined} /></Provider>);
it('retries the frozen import through remount and clears pending only after authoritative success', async () => {
  const bodies: string[] = []; let attempt = 0;
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => { bodies.push(await request.clone().text()); attempt++; return new Response(JSON.stringify(attempt === 1 ? { error: { code: 'SERVER_ERROR', message: 'Response uncertain' } } : { data: { import_id: 'imp_saved', row_count: 1, replayed: true, batches: [{ row: 1, batch_id: 'bat_saved', movement_id: 'mov_saved' }] } }), { status: attempt === 1 ? 500 : 200, headers: { 'Content-Type': 'application/json' } }); }));
  const first = mount(); fireEvent.click(screen.getByRole('button', { name: 'Retry saved import' }));
  await screen.findByRole('alert'); expect(store.getState().catalogueImport.pending).toEqual(frozen); first.unmount();
  mount(); fireEvent.click(screen.getByRole('button', { name: 'Retry saved import' }));
  await screen.findByText(/1 stock rows received/); expect(bodies).toEqual([JSON.stringify(frozen), JSON.stringify(frozen)]); expect(store.getState().catalogueImport.pending).toBeNull();
});
it('clears import details on sign-out and ignores a late completed response', async () => {
  let finish!: (value: Response) => void;
  vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { finish = resolve; })));
  mount(); fireEvent.click(screen.getByRole('button', { name: 'Retry saved import' })); await waitFor(() => expect(finish).toBeTypeOf('function'));
  store.dispatch(signedOut()); finish(new Response(JSON.stringify({ data: { import_id: 'imp_late', row_count: 1, replayed: false, batches: [] } }), { status: 201, headers: { 'Content-Type': 'application/json' } }));
  await waitFor(() => expect(store.getState().catalogueImport.pending).toBeNull()); expect(screen.queryByText(/stock rows received/)).toBeNull();
});
