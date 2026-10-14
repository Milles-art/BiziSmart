// @vitest-environment jsdom
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { useState } from 'react';
import { liveApi, sessionReducer, signedIn, errorMessage } from '../src/features/live/api';
import { useLazyPrivateProductImageQuery, useProductImageCapabilitiesQuery, useUploadProductImageMutation, privateImageBlob } from '../src/features/live/productImagesApi';

let store: ReturnType<typeof createStore>;
const actor = { id: 'usr_image', name: 'Owner', role: 'owner' as const, branch_id: null, permissions: ['manage_products'] };
function createStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: get => get().concat(liveApi.middleware) }); }
function json(body: unknown, status = 200) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }); }
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  store = createStore(); store.dispatch(signedIn({ token: 'image-test-session', expires_at: new Date(Date.now() + 600000).toISOString(), user: actor }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

function Harness() {
  const capabilities = useProductImageCapabilitiesQuery();
  const [read] = useLazyPrivateProductImageQuery(); const [upload] = useUploadProductImageMutation();
  const [result, setResult] = useState(''); const [error, setError] = useState('');
  async function load() { try { const data = await read({ id: 'prod_water', revision: '01JREVISION' }).unwrap(); setResult(data); } catch (failure) { setError(errorMessage(failure)); } }
  async function save() { try { await upload({ id: 'prod_water', image: new File(['image'], 'water.jpg', { type: 'image/jpeg' }), expectedRevision: '01JREVISION' }).unwrap(); setResult('Saved'); } catch (failure) { setError(errorMessage(failure)); } }
  return <><p>{capabilities.data?.enabled ? 'Images enabled' : capabilities.data ? 'Private storage must be connected' : 'Loading'}</p><button onClick={() => void load()}>Read image</button><button onClick={() => void save()}>Upload image</button>{result && <output>{result}</output>}{error && <p role="alert">{error}</p>}</>;
}
function mount() { render(<Provider store={store}><Harness /></Provider>); }

it('authenticates image reads and keeps Redux response data serializable', async () => {
  const warning = vi.spyOn(console, 'error').mockImplementation(() => {});
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    expect(request.headers.get('Authorization')).toBe('Bearer image-test-session');
    return request.url.endsWith('/image-capabilities') ? json({ data: { enabled: true, configured: true, reason: 'ready', max_bytes: 2097152, formats: ['image/png'] } }) : new Response(new Uint8Array([1, 2, 3]), { headers: { 'Content-Type': 'image/png' } });
  }));
  mount(); await screen.findByText('Images enabled'); fireEvent.click(screen.getByText('Read image'));
  await screen.findByText('data:image/png;base64,AQID');
  expect(privateImageBlob('data:image/png;base64,AQID').size).toBe(3);
  expect(warning.mock.calls.flat().join(' ')).not.toContain('non-serializable');
});

it('retains authoritative read and upload errors without claiming success', async () => {
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    if (request.url.endsWith('/image-capabilities')) return json({ data: { enabled: true, configured: true, reason: 'ready', max_bytes: 2097152, formats: ['image/png'] } });
    return json({ success: false, error: { code: 'IMAGE_CHANGED', message: 'Refresh before replacing this image.' } }, 400);
  }));
  mount(); await screen.findByText('Images enabled'); fireEvent.click(screen.getByText('Read image'));
  await screen.findByRole('alert'); expect(screen.getByRole('alert').textContent).toContain('Refresh before replacing');
  fireEvent.click(screen.getByText('Upload image'));
  await waitFor(() => expect(vi.mocked(fetch).mock.calls.filter(([request]) => (request as Request).method === 'POST')).toHaveLength(1));
  expect(screen.queryByText('Saved')).toBeNull();
});

it('shows unconfigured storage as unavailable and expires the session on unauthorized image read', async () => {
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => request.url.endsWith('/image-capabilities')
    ? json({ data: { enabled: false, configured: false, reason: 'storage_setup_required', max_bytes: 2097152, formats: ['image/png'] } })
    : json({ error: { code: 'UNAUTHENTICATED', message: 'Please sign in again.' } }, 401)));
  mount(); await screen.findByText('Private storage must be connected'); fireEvent.click(screen.getByText('Read image'));
  await waitFor(() => expect(store.getState().session.token).toBeNull());
  expect(screen.queryByRole('status')).toBeNull();
});
