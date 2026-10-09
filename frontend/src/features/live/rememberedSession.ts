import type { Middleware } from '@reduxjs/toolkit';
import { sessionRestored, signedIn, signedOut, type SessionState } from './api';

export const SESSION_KEY = 'bizismart.session.v1';
export const IDLE_LIMIT = 30 * 60 * 1000;
interface RememberedSession { token: string; expiresAt: string; lastActiveAt: number; deviceUid: string }

export function clearRememberedSession() {
  try { sessionStorage.removeItem(SESSION_KEY); } catch { /* Storage is optional; memory-only sign-in still works. */ }
}

export function readRememberedSession(): RememberedSession | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(SESSION_KEY) ?? 'null');
    const now = Date.now();
    if (!value || typeof value.token !== 'string' || value.token.length > 512 || !/^[0-9]+\|[A-Za-z0-9]+$/.test(value.token)
      || typeof value.expiresAt !== 'string' || !Number.isFinite(Date.parse(value.expiresAt)) || Date.parse(value.expiresAt) <= now
      || !Number.isSafeInteger(value.lastActiveAt) || value.lastActiveAt > now || now - value.lastActiveAt >= IDLE_LIMIT
      || typeof value.deviceUid !== 'string' || !value.deviceUid || value.deviceUid !== localStorage.getItem('bizismart.device.v1')) {
      clearRememberedSession(); return null;
    }
    // Never restore saved user objects, permissions or other extra fields.
    return { token: value.token, expiresAt: value.expiresAt, lastActiveAt: value.lastActiveAt, deviceUid: value.deviceUid };
  } catch { clearRememberedSession(); return null; }
}

export function rememberActivity(token: string, lastActiveAt: number) {
  try {
    const saved = readRememberedSession();
    if (saved?.token === token && lastActiveAt - saved.lastActiveAt >= 1000) {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify({ ...saved, lastActiveAt }));
    }
  } catch { clearRememberedSession(); }
}

export const rememberSessionMiddleware: Middleware = (store) => (next) => (action) => {
  const result = next(action);
  if (signedOut.match(action)) { clearRememberedSession(); }
  if (signedIn.match(action)) {
    clearRememberedSession();
    try {
      const state = (store.getState() as { session: SessionState }).session;
      const deviceUid = localStorage.getItem('bizismart.device.v1');
      if (deviceUid) {
        sessionStorage.setItem(SESSION_KEY, JSON.stringify({ token: state.token, expiresAt: state.expiresAt, lastActiveAt: state.lastActiveAt, deviceUid }));
      }
    } catch { clearRememberedSession(); }
  }
  return result;
};

export function restoreRememberedSession(dispatch: (action: ReturnType<typeof sessionRestored>) => unknown) {
  const saved = readRememberedSession();
  if (saved) dispatch(sessionRestored({ token: saved.token, expiresAt: saved.expiresAt, lastActiveAt: saved.lastActiveAt }));
}
