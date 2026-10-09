// @vitest-environment jsdom
import React from 'react';
import { Provider } from 'react-redux';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { reduxStore } from '../src/store/redux';
import { useStore, type Store } from '../src/store/useStore';
import { StaffScreen } from '../src/screens/StaffScreen';

let store: Store;
let initial: Record<string, unknown>;
function Harness() { store = useStore(); return <StaffScreen store={store} />; }
beforeEach(() => {
  if (initial) for (const [key, value] of Object.entries(initial)) reduxStore.dispatch({ type: 'business/setField', payload: { key, value: structuredClone(value) } });
  render(<Provider store={reduxStore}><Harness /></Provider>);
  initial ??= structuredClone(reduxStore.getState().business);
  act(() => { store.login('0711001100', '1234'); });
});
afterEach(cleanup);

it('edits an existing staff card while preserving its history and PIN', () => {
  const target = store.staffList.find((s) => s.role === 'Cashier')!;
  fireEvent.click(within(screen.getByLabelText(`Staff member ${target.name}`)).getByRole('button', { name: 'Edit staff' }));
  fireEvent.change(screen.getByLabelText('Full Name'), { target: { value: 'Updated cashier' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));
  expect(store.staffList.find((s) => s.id === target.id)).toMatchObject({ name: 'Updated cashier', pin: target.pin, salesToday: target.salesToday });
  expect(screen.queryByRole('dialog')).toBeNull();
});

it('confirms deactivation, blocks login and supports reactivation', () => {
  const target = store.staffList.find((s) => s.role === 'Cashier')!;
  fireEvent.click(within(screen.getByLabelText(`Staff member ${target.name}`)).getByRole('button', { name: 'Deactivate' }));
  expect(store.staffList.find((s) => s.id === target.id)!.active).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Confirm change' }));
  act(() => { expect(store.login(target.phone, target.pin)).toBe(false); });
  fireEvent.click(within(screen.getByLabelText(`Staff member ${target.name}`)).getByRole('button', { name: 'Reactivate' }));
  fireEvent.click(screen.getByRole('button', { name: 'Confirm change' }));
  act(() => { expect(store.login(target.phone, target.pin)).toBe(true); });
});

it('rejects duplicate canonical phones and invalid PINs when adding staff', () => {
  act(() => {
    expect(store.addStaff({ name: 'Duplicate', phone: '+255711001100', pin: '1234', role: 'Cashier' }).success).toBe(false);
    expect(store.addStaff({ name: 'Invalid', phone: '0712345678', pin: 'abcd', role: 'Cashier' }).success).toBe(false);
  });
  expect(store.staffList).toHaveLength(initial.staffList instanceof Array ? initial.staffList.length : 0);
});

it('keeps a last active owner and denies management by a manager', () => {
  const owner = store.currentUser!;
  act(() => {
    expect(store.setStaffActive(owner.id, false)).toMatchObject({ success: false });
    expect(store.saveStaffProfile({ name: owner.name, phone: owner.phone, role: 'Cashier' }, owner.id).success).toBe(false);
    store.login('0711002200', '2345');
  });
  expect(screen.queryByRole('button', { name: 'Add Staff' })).toBeNull();
  act(() => { expect(store.setStaffActive(owner.id, false).success).toBe(false); });
});

it('creates a valid demo staff member with a six-digit PIN and permits sign-in', () => {
  act(() => { expect(store.addStaff({ name: 'New demo staff', phone: '0712345678', pin: '654321', role: 'Cashier' }).success).toBe(true); });
  const added = store.staffList.find((s) => s.phone === '0712345678')!;
  expect(added.id).toMatch(/^st-/);
  act(() => { store.logout(); });
  act(() => { expect(store.login('0712345678', '654321')).toBe(true); });
  expect(store.currentUser?.id).toBe(added.id);
});
