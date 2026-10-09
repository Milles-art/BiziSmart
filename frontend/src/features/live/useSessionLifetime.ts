import { useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { liveApi, signedOut, type SessionState } from './api';
import { IDLE_LIMIT, readRememberedSession, rememberActivity } from './rememberedSession';

export function useSessionLifetime() {
  const session = useSelector((s: { session: SessionState }) => s.session);
  const dispatch = useDispatch();
  useEffect(() => {
    if (!session.token) return;
    const saved = readRememberedSession();
    let active = saved?.token === session.token ? saved.lastActiveAt : session.lastActiveAt ?? Date.now();
    let timer: ReturnType<typeof setTimeout>;
    const check = () => {
      if (Date.now() >= Math.min(active + IDLE_LIMIT, Date.parse(session.expiresAt!))) {
        dispatch(signedOut()); dispatch(liveApi.util.resetApiState()); return false;
      }
      return true;
    };
    const schedule = () => { clearTimeout(timer); timer = setTimeout(check, Math.max(0, Math.min(active + IDLE_LIMIT, Date.parse(session.expiresAt!)) - Date.now())); };
    const touch = () => { if (check()) { active = Date.now(); rememberActivity(session.token!, active); schedule(); } };
    const events = ['pointerdown', 'keydown', 'touchstart', 'scroll'];
    events.forEach((name) => window.addEventListener(name, touch, { passive: true }));
    document.addEventListener('visibilitychange', check);
    schedule();
    return () => { clearTimeout(timer); events.forEach((name) => window.removeEventListener(name, touch)); document.removeEventListener('visibilitychange', check); };
  }, [session.token, session.expiresAt, session.lastActiveAt, dispatch]);
}
