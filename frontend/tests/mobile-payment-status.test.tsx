// @vitest-environment jsdom
import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { MobilePaymentStatus } from '../src/features/live/MobilePaymentStatus';
import { usePaymentCapabilitiesQuery } from '../src/features/live/api';
vi.mock('../src/features/live/api', () => ({ usePaymentCapabilitiesQuery: vi.fn() }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
it('shows merchant setup only from the server capability result', () => {
  vi.mocked(usePaymentCapabilitiesQuery).mockReturnValue({ data: { provider:'selcom',configured:false,enabled:false,reason:'merchant_setup_required' }, refetch:vi.fn() });
  render(<MobilePaymentStatus/>);
  expect(screen.getByRole('status').textContent).toBe('Selcom mobile payments require merchant setup.');
  expect(screen.queryByRole('button')).toBeNull();
});
it('shows availability from the verified server capability', () => {
  vi.mocked(usePaymentCapabilitiesQuery).mockReturnValue({ data: { provider:'selcom',configured:true,enabled:true,reason:'ready' }, refetch:vi.fn() });
  render(<MobilePaymentStatus/>);
  expect(screen.getByRole('status').textContent).toBe('Selcom mobile payments are available.');
});
it('keeps availability unknown when the API fails', () => {
  vi.mocked(usePaymentCapabilitiesQuery).mockReturnValue({ isError:true,refetch:vi.fn() });
  render(<MobilePaymentStatus/>);
  expect(screen.getByRole('status').textContent).toBe('Mobile payment availability could not be checked.');
});
it('shows an availability check before the server responds', () => {
  vi.mocked(usePaymentCapabilitiesQuery).mockReturnValue({ refetch:vi.fn() });
  render(<MobilePaymentStatus/>);
  expect(screen.getByRole('status').textContent).toBe('Checking mobile payment availability…');
});
