// @vitest-environment jsdom
import React from 'react';
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { Login } from '../src/screens/Login';
import { liveApi, sessionReducer } from '../src/features/live/api';
import { setLanguage } from '../src/i18n';
import { DiscountApproval } from '../src/features/live/DiscountApproval';

const password = 'A real test password 42';
const token = 'a'.repeat(64);
const user = { id: 'usr_owner', name: 'Owner', role: 'owner', branch_id: 'br_main', permissions: [] };
function makeStore() { return configureStore({ reducer: { session: sessionReducer, [liveApi.reducerPath]: liveApi.reducer }, middleware: get => get().concat(liveApi.middleware) }); }
let store: ReturnType<typeof makeStore>;
const bodies: { path: string; body: Record<string, unknown> }[] = [];
function response(data: unknown, status = 200) { return new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }); }
function mount(hash = '') { history.replaceState(null, '', '/' + hash); return render(<Provider store={store}><Login /></Provider>); }
function input(label: string, value: string) { fireEvent.change(screen.getByLabelText(label, { exact: true }), { target: { value } }); }
function newPassword() { input('New password', password); input('Confirm password', password); }
let registrationAvailable = false;
beforeEach(() => {
  const controller = transferableAbortController(); vi.stubGlobal('AbortController', controller.constructor); vi.stubGlobal('AbortSignal', controller.signal.constructor);
  localStorage.clear(); sessionStorage.clear(); setLanguage('en'); store = makeStore(); bodies.length = 0; registrationAvailable = false;
  vi.stubGlobal('fetch', vi.fn(async (request: Request) => {
    const path = new URL(request.url).pathname;
    bodies.push({ path, body: request.method === 'GET' ? {} : await request.json() });
    if (path.endsWith('/register/status')) return response({ data: { available: registrationAvailable } });
    if (path.endsWith('/login') || path.endsWith('/setup') || path.endsWith('/register')) return response({ data: { token: 'session-token', expires_at: new Date(Date.now() + 3600000).toISOString(), user } });
    return response({ data: { completed: true, message: 'Private generic result' } });
  }));
});
afterEach(() => { cleanup(); store.dispatch(liveApi.util.resetApiState()); vi.unstubAllGlobals(); localStorage.clear(); sessionStorage.clear(); history.replaceState(null, '', '/'); setLanguage('en'); });

it('signs in using only email/password, supports visibility, and retains no credentials in API state or storage', async () => {
  mount(); expect(screen.queryByLabelText('Phone Number')).toBeNull(); expect(screen.queryByLabelText('Authenticator code')).toBeNull();
  input('Email address', 'owner@example.test'); input('Password', password);
  fireEvent.click(screen.getByRole('button', { name: 'Show password' })); expect(screen.getByLabelText('Password')).toHaveProperty('type', 'text');
  fireEvent.click(screen.getByRole('button', { name: 'Hide password' }));
  fireEvent.click(screen.getByRole('button', { name: 'Sign In', exact: true }));
  await waitFor(() => expect(store.getState().session.token).toBe('session-token'));
  const loginRequest = bodies.find(request => request.path === '/api/v1/auth/email/login');
  expect(loginRequest).toMatchObject({ path: '/api/v1/auth/email/login', body: { email: 'owner@example.test', password, device_name: 'Shop browser' } });
  expect(loginRequest?.body.device_uid).toMatch(/^[a-f0-9-]{36}$/);
  await waitFor(() => expect(JSON.stringify(store.getState().liveApi)).not.toContain(password));
  expect(JSON.stringify(localStorage)).not.toContain(password); expect(JSON.stringify(sessionStorage)).not.toContain(password); expect(sessionStorage.length).toBe(0);
});
it('consumes an owner invitation in memory and sends an explicit confirmed password without PIN or authenticator', async () => {
  mount('#setup=' + token); expect(location.hash).toBe(''); input('Owner name', 'Real Owner');  input('Email address', 'owner@example.test'); newPassword();
  expect(screen.getByLabelText('New password')).toHaveProperty('minLength', 12);
  fireEvent.click(screen.getByRole('button', { name: 'Create owner account' }));
  await waitFor(() => expect(store.getState().session.token).toBe('session-token'));
  expect(bodies[0]).toMatchObject({ path: '/api/v1/auth/email/setup', body: { invitation_token: token, name: 'Real Owner', password_confirmation: password } });
  expect(bodies[0].body).not.toHaveProperty('phone');
  expect(bodies[0].body).not.toHaveProperty('pin'); expect(bodies[0].body).not.toHaveProperty('code'); expect(JSON.stringify(localStorage)).not.toContain(token); expect(JSON.stringify(sessionStorage)).not.toContain(token);
});
it('requests reset with a generic account-neutral result and never treats it as a session', async () => {
  mount(); fireEvent.click(screen.getByRole('button', { name: 'Forgot password?' })); input('Email address', 'unknown@example.test'); fireEvent.click(screen.getByRole('button', { name: 'Send reset link' }));
  expect((await screen.findByRole('status')).textContent).toContain('If an eligible account'); expect(store.getState().session.token).toBeNull(); expect(bodies.find(request => request.path === '/api/v1/auth/email/forgot-password')).toEqual({ path: '/api/v1/auth/email/forgot-password', body: { email: 'unknown@example.test' } });
});
it('changes password from a private fragment then requires a fresh sign in', async () => {
  mount('#reset=' + token); expect(location.hash).toBe(''); expect(screen.queryByLabelText('Email address')).toBeNull(); newPassword(); fireEvent.click(screen.getByRole('button', { name: 'Save new password' }));
  await screen.findByText('Password updated. Sign in with your email and new password.'); expect(store.getState().session.token).toBeNull(); expect(bodies[0]).toEqual({ path: '/api/v1/auth/email/reset-password', body: { token, password, password_confirmation: password } }); expect(screen.getByLabelText('Password')).toHaveProperty('value', '');
});
it('activates an existing account from its private link and clears credentials before returning to login', async () => {
  mount('#activate=' + token); input('Email address', 'staff@example.test'); newPassword(); fireEvent.click(screen.getByRole('button', { name: 'Activate account' }));
  await screen.findByText('Account activated. Sign in with your email and password.'); expect(bodies[0]).toMatchObject({ path: '/api/v1/auth/email/activate', body: { email: 'staff@example.test', token, password_confirmation: password } }); expect(store.getState().session.token).toBeNull(); await waitFor(() => expect(JSON.stringify(store.getState().liveApi)).not.toContain(token));
});
it('rejects invalid links locally and mismatched passwords without submitting', async () => {
  const view = mount('#activate=invalid'); expect(location.hash).toBe(''); expect(screen.getByRole('alert').textContent).toContain('private link is invalid'); expect(screen.getByRole('button', { name: 'Activate account' })).toHaveProperty('disabled', true); view.unmount();
  mount('#reset=' + token); input('New password', password); input('Confirm password', 'Different password 42'); fireEvent.click(screen.getByRole('button', { name: 'Save new password' })); expect(screen.getByRole('alert').textContent).toBe('The two passwords must match.'); expect(bodies).toHaveLength(0);
});
it('shows failed credentials as errors without manufacturing a session and clears the password', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => response({ error: { code: 'INVALID_CREDENTIALS', message: 'Incorrect credentials.' } }, 401)));
  mount(); input('Email address', 'owner@example.test'); input('Password', password); fireEvent.click(screen.getByRole('button', { name: 'Sign In', exact: true }));
  expect((await screen.findByRole('alert')).textContent).toBe('The email or password is incorrect.'); expect(store.getState().session.token).toBeNull(); expect(screen.getByLabelText('Password')).toHaveProperty('value', '');
});
it('renders the new login fields and guidance in Kiswahili', () => {
  setLanguage('sw'); mount(); expect(screen.getByLabelText('Barua pepe')).toBeTruthy(); expect(screen.getByLabelText('Nenosiri')).toBeTruthy(); expect(screen.getByRole('button', { name: 'Umesahau nenosiri?' })).toBeTruthy();
});
it('rejects a multibyte password exceeding bcrypt byte capacity without sending it', () => {
  mount('#activate=' + token);input('Email address','owner@example.test');
  input('New password','é'.repeat(37));input('Confirm password','é'.repeat(37));
  fireEvent.click(screen.getByRole('button',{name:'Activate account'}));
  expect(screen.getByRole('alert').textContent).toContain('at most 72 bytes');
  expect(bodies).toHaveLength(0);
});
it('requests bound supervisor approval with email/password and no numeric PIN or authenticator fields', async () => {
  const approved = vi.fn(); vi.stubGlobal('fetch', vi.fn(async (request: Request) => { bodies.push({ path: new URL(request.url).pathname, body: await request.json() }); return response({ data: { approval_token: 'grant', approved_by: 'usr_owner', expires_at: 'later' } }); }));
  render(<Provider store={store}><DiscountApproval operationId="operation-one" reviewHash="review-one" onApproved={approved} /></Provider>);
  input('Supervisor email', 'manager@example.test'); input('Supervisor password', password); expect(screen.queryByLabelText(/authenticator/)).toBeNull(); fireEvent.click(screen.getByRole('button', { name: 'Approve discount' }));
  await waitFor(() => expect(approved).toHaveBeenCalledWith('grant'));
  expect(bodies[0].body).toEqual({ email: 'manager@example.test', password, permission: 'give_discount', operation_id: 'operation-one', payload_hash: 'review-one' });
});

it('offers self-service shop registration on an unclaimed shop address and signs the owner straight in', async () => {
  registrationAvailable = true; mount();
  fireEvent.click(await screen.findByRole('button', { name: 'Create your shop account' }));
  expect(screen.getByLabelText('Shop name')).toBeTruthy();
  input('Shop name', 'Fresh Choice Supermarket'); input('Owner name', 'Neema Mwakalinga');
  input('Phone number', '0714000001'); input('Email address', 'owner@freshchoice.test'); newPassword();
  fireEvent.click(screen.getByRole('button', { name: 'Create shop account' }));
  await waitFor(() => expect(store.getState().session.token).toBe('session-token'));
  expect(bodies.some(request => request.path === '/api/v1/auth/email/register/status')).toBe(true);
  const registration = bodies.find(request => request.path === '/api/v1/auth/email/register');
  expect(registration).toMatchObject({ body: { shop_name: 'Fresh Choice Supermarket', name: 'Neema Mwakalinga', phone: '0714000001', email: 'owner@freshchoice.test', password_confirmation: password } });
  expect(registration?.body).not.toHaveProperty('subdomain');
  expect(registration?.body).not.toHaveProperty('business_id');
  expect(registration?.body).not.toHaveProperty('invitation_token');
  expect(registration?.body).not.toHaveProperty('pin');
  expect(JSON.stringify(localStorage)).not.toContain(password); expect(JSON.stringify(sessionStorage)).not.toContain(password);
});

it('keeps the sign-in screen unchanged when the shop address is already taken', async () => {
  registrationAvailable = false; mount();
  await waitFor(() => expect(bodies.some(request => request.path === '/api/v1/auth/email/register/status')).toBe(true));
  expect(screen.queryByRole('button', { name: 'Create your shop account' })).toBeNull();
  expect(screen.getByText(/First time here\?/)).toBeTruthy();
  input('Email address', 'owner@example.test'); input('Password', password);
  fireEvent.click(screen.getByRole('button', { name: 'Sign In', exact: true }));
  await waitFor(() => expect(store.getState().session.token).toBe('session-token'));
});