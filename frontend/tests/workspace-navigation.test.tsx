// @vitest-environment jsdom
import React, { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { ScreenTabs } from '../src/components/ui/Primitives';

afterEach(cleanup);
it('keyboard navigation skips unavailable modules and keeps one tab stop', () => {
  function Workspace() {
    const [value, setValue] = useState('stock');
    return <ScreenTabs value={value} onChange={setValue} tabs={[
      { id: 'stock', label: 'Overview' },
      { id: 'blocked', label: 'Adjustments', unavailable: 'Your role cannot correct stock.' },
      { id: 'receiving', label: 'Receiving' },
      { id: 'history', label: 'Stock Movements' },
    ]} />;
  }
  render(<Workspace />);
  const overview = screen.getByRole('tab', { name: 'Overview' });
  overview.focus();
  fireEvent.keyDown(overview, { key: 'ArrowRight' });
  const receiving = screen.getByRole('tab', { name: 'Receiving' });
  expect(document.activeElement).toBe(receiving);
  expect(receiving.getAttribute('aria-selected')).toBe('true');
  expect(screen.getAllByRole('tab').filter(tab => tab.tabIndex === 0)).toEqual([receiving]);
  fireEvent.keyDown(receiving, { key: 'End' });
  const history = screen.getByRole('tab', { name: 'Stock Movements' });
  expect(document.activeElement).toBe(history);
  fireEvent.keyDown(history, { key: 'ArrowRight' });
  expect(document.activeElement).toBe(overview);
  expect(screen.getByRole('tab', { name: 'Adjustments' })).toHaveProperty('disabled', true);
});
