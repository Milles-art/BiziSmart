// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { act, cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn, signedOut } from '../src/features/live/api';
import { SESSION_KEY, IDLE_LIMIT, readRememberedSession, rememberActivity, rememberSessionMiddleware, restoreRememberedSession } from '../src/features/live/rememberedSession';
import { useSessionLifetime } from '../src/features/live/useSessionLifetime';

function store() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: (g) => g().concat(liveApi.middleware, rememberSessionMiddleware) }); }
const user = { id: 'usr_owner', name: 'Private owner', role: 'owner' as const, branch_id: null, permissions: ['manage_users'] };
function login(s: ReturnType<typeof store>) { s.dispatch(signedIn({ token: '12|TestOnlyToken', expires_at: new Date(Date.now() + 3600000).toISOString(), user })); }
function Lifetime() { useSessionLifetime(); return null; }
beforeEach(() => { localStorage.clear(); sessionStorage.clear(); localStorage.setItem('bizismart.device.v1', 'approved-test-device'); });
afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals(); localStorage.clear(); sessionStorage.clear(); });

it('survives a fresh store/reload without persisting identity, permissions or shop data', () => {
  const original = store(); login(original);
  const saved = JSON.parse(sessionStorage.getItem(SESSION_KEY)!);
  expect(Object.keys(saved).sort()).toEqual(['deviceUid', 'expiresAt', 'lastActiveAt', 'token']);
  expect(JSON.stringify(saved)).not.toContain('Private owner', 'manage_users');
  const reloaded = store(); restoreRememberedSession(reloaded.dispatch);
  expect(reloaded.getState().session.token).toBe('12|TestOnlyToken');
  expect(reloaded.getState().session.user).toBeNull();
  expect(localStorage.getItem(SESSION_KEY)).toBeNull();
});

it('does not extend the inactivity window just because the page was refreshed', () => {
  vi.useFakeTimers(); const original = store(); login(original);
  vi.advanceTimersByTime(IDLE_LIMIT - 1000);
  const reloaded = store(); restoreRememberedSession(reloaded.dispatch);
  render(<Provider store={reloaded}><Lifetime /></Provider>);
  act(() => { vi.advanceTimersByTime(1000); });
  expect(reloaded.getState().session.token).toBeNull();
  expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
});

it('keeps recent activity across reload while preserving the original token expiry', () => {
  vi.useFakeTimers(); const original = store(); login(original);
  const expiry = original.getState().session.expiresAt;
  vi.advanceTimersByTime(20 * 60000); rememberActivity('12|TestOnlyToken', Date.now());
  vi.advanceTimersByTime(15 * 60000);
  const reloaded = store(); restoreRememberedSession(reloaded.dispatch);
  expect(reloaded.getState().session.token).toBe('12|TestOnlyToken');
  expect(reloaded.getState().session.expiresAt).toBe(expiry);
});

it.each(['expired', 'idle', 'device', 'malformed', 'future'])('rejects a %s remembered session', (reason) => {
  const s = store(); login(s);
  const saved = JSON.parse(sessionStorage.getItem(SESSION_KEY)!);
  if (reason === 'expired') saved.expiresAt = new Date(Date.now() - 1).toISOString();
  if (reason === 'idle') saved.lastActiveAt = Date.now() - IDLE_LIMIT;
  if (reason === 'device') saved.deviceUid = 'different-device';
  if (reason === 'future') saved.lastActiveAt = Date.now() + 10000;
  sessionStorage.setItem(SESSION_KEY, reason === 'malformed' ? '{broken' : JSON.stringify(saved));
  expect(readRememberedSession()).toBeNull();
  expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
});

it('clears remembered credentials on logout and server revocation without restoring them', async () => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  const s = store(); login(s); s.dispatch(signedOut());
  expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
  login(s);
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { code: 'UNAUTHORIZED' } }), { status: 401, headers: { 'Content-Type': 'application/json' } })));
  await s.dispatch(liveApi.endpoints.me.initiate());
  expect(s.getState().session.token).toBeNull();
  expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
  const reloaded = store(); restoreRememberedSession(reloaded.dispatch);
  expect(reloaded.getState().session.token).toBeNull();
  s.dispatch(liveApi.util.resetApiState());
});

it('falls back to ordinary memory sign-in when session storage is blocked', () => {
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Blocked'); });
  const s = store(); expect(() => login(s)).not.toThrow();
  expect(s.getState().session.token).toBe('12|TestOnlyToken');
  expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
  vi.restoreAllMocks();
});
