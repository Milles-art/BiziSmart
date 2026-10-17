// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { act, cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn, type ApprovalRequest } from '../src/features/live/api';
import { useSessionLifetime } from '../src/features/live/useSessionLifetime';

function store() {
  return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer },
    middleware: (getDefault) => getDefault().concat(liveApi.middleware) });
}
const user = { id: 'usr_test', name: 'Owner', role: 'owner' as const, branch_id: null, permissions: ['manage_users'] };
function signIn(s: ReturnType<typeof store>, expiry = new Date(Date.now() + 86400000).toISOString()) {
  s.dispatch(signedIn({ token: 'synthetic-test-token', expires_at: expiry, user }));
}
beforeEach(() => {
  const controller = transferableAbortController();
  vi.stubGlobal('AbortController', controller.constructor);
  vi.stubGlobal('AbortSignal', controller.signal.constructor);
});
afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals(); localStorage.clear(); });

it('sends the bearer token to Laravel and clears credentials and cached shop data on a protected 401', async () => {
  const s = store(); signIn(s);
  const fetch = vi.fn(async (request: Request) => {
    expect(request.headers.get('Authorization')).toBe('Bearer synthetic-test-token');
    expect(new URL(request.url).pathname).toBe('/api/v1/me');
    return new Response(JSON.stringify({ success: false, error: { code: 'UNAUTHORIZED', message: 'Sign in again.' } }),
      { status: 401, headers: { 'Content-Type': 'application/json' } });
  });
  vi.stubGlobal('fetch', fetch);
  await s.dispatch(liveApi.endpoints.me.initiate());
  expect(fetch).toHaveBeenCalledOnce();
  expect(Object.keys(s.getState().liveApi.queries)).toHaveLength(0);
  expect(s.getState().session.token).toBeNull();
  expect(localStorage.length).toBe(0);
  s.dispatch(liveApi.util.resetApiState());
});

it('keeps failed public authenticator requests as errors without manufacturing a session', async () => {
  const s = store();
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { code: 'INVALID_CODE', message: 'Invalid code.' } }),
    { status: 422, headers: { 'Content-Type': 'application/json' } })));
  const request = s.dispatch(liveApi.endpoints.verifyCode.initiate({ challenge_token: 'test-challenge', code: '000000' }));
  const result = await request;
  expect(result.error).toBeDefined();
  expect(s.getState().session.token).toBeNull();
  request.reset();
  expect(Object.keys(s.getState().liveApi.mutations)).toHaveLength(0);
  s.dispatch(liveApi.util.resetApiState());
});

function Lifetime() { useSessionLifetime(); return null; }
const approval: ApprovalRequest = { phone: '0712345678', pin: '1234', permission: 'refund',
  operation_id: 'a05c7c91-5262-4b3c-9101-1c0c8dd4dd6f', payload_hash: 'a'.repeat(64) };
it.each(['INVALID_CREDENTIALS', 'INVALID_PIN', 'INVALID_TOTP'])('retains the cashier session on supervisor %s without keeping mutation credentials', async (code) => {
  const s = store(); signIn(s);
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ success: false, error: { code, message: 'Try again.' } }),
    { status: 401, headers: { 'Content-Type': 'application/json' } })));
  const request = s.dispatch(liveApi.endpoints.supervisorCheck.initiate(approval));
  expect((await request).error).toBeDefined();
  expect(s.getState().session.token).toBe('synthetic-test-token');
  request.reset();
  expect(Object.keys(s.getState().liveApi.mutations)).toHaveLength(0);
  s.dispatch(liveApi.util.resetApiState());
});

it('clears a revoked cashier session when the approval endpoint returns UNAUTHORIZED', async () => {
  const s = store(); signIn(s);
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ success: false, error: { code: 'UNAUTHORIZED', message: 'Sign in.' } }),
    { status: 401, headers: { 'Content-Type': 'application/json' } })));
  await s.dispatch(liveApi.endpoints.supervisorCheck.initiate(approval));
  expect(s.getState().session.token).toBeNull();
  expect(Object.keys(s.getState().liveApi.mutations)).toHaveLength(0);
  s.dispatch(liveApi.util.resetApiState());
});

it('returns an operation grant without replacing the staff session and removes it from the mutation cache', async () => {
  const s = store(); signIn(s);
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    expect(request.headers.get('Authorization')).toBe('Bearer synthetic-test-token');
    expect(await request.json()).toEqual(approval);
    return new Response(JSON.stringify({ success: true, data: { approved_by: 'usr_manager', approval_token: 'test-operation-grant', expires_at: new Date().toISOString() } }),
      { status: 200, headers: { 'Content-Type': 'application/json' } });
  }));
  const request = s.dispatch(liveApi.endpoints.supervisorCheck.initiate(approval));
  expect((await request.unwrap()).approval_token).toBe('test-operation-grant');
  expect(s.getState().session.token).toBe('synthetic-test-token');
  request.reset();
  expect(Object.keys(s.getState().liveApi.mutations)).toHaveLength(0);
  expect(localStorage.length).toBe(0);
  s.dispatch(liveApi.util.resetApiState());
});

it('locks an in-memory session after 30 inactive minutes without saving credentials', () => {
  vi.useFakeTimers(); const s = store(); signIn(s);
  render(<Provider store={s}><Lifetime /></Provider>);
  act(() => { vi.advanceTimersByTime(1799999); });
  expect(s.getState().session.token).not.toBeNull();
  act(() => { vi.advanceTimersByTime(1); });
  expect(s.getState().session.token).toBeNull();
  expect(localStorage.length).toBe(0);
});

it('checks expiry when a sleeping browser becomes visible again', () => {
  vi.useFakeTimers(); const s = store(); signIn(s, new Date(Date.now() + 60000).toISOString());
  render(<Provider store={s}><Lifetime /></Provider>);
  vi.setSystemTime(Date.now() + 60001);
  act(() => { document.dispatchEvent(new Event('visibilitychange')); });
  expect(s.getState().session.token).toBeNull();
});
