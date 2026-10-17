// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MarginWarnings } from '../src/features/live/MarginWarnings';
import type { MarginWarning } from '../src/features/live/api';

afterEach(() => { cleanup(); });
const ownerWarning: MarginWarning = { code: 'MARGIN_BELOW_COST', line_index: 0, product_id: 'prod_a', product_name: 'Fresh milk', batch_id: 'batch_a', batch_number: 'LOSS', base_qty: '1', net_total: 800, cost_total: 1000, shortfall: 200 };
const managerWarning: MarginWarning = { code: 'MARGIN_BELOW_COST', line_index: 0, product_id: 'prod_a', product_name: 'Fresh milk', batch_id: 'batch_a', batch_number: 'LOSS', base_qty: '1', net_total: 800 };
describe('POS margin warnings', () => {
  it('renders server cost detail only when Laravel sent it (owners)', () => {
    render(<MarginWarnings warnings={[ownerWarning]} />);
    expect(screen.getByRole('note', { name: 'Below-cost lines' })).toBeDefined();
    expect(screen.getByText('Fresh milk · 1')).toBeDefined();
    expect(document.body.textContent).toContain('TSh 800');
    expect(document.body.textContent).toContain('TSh 1,000');
    expect(document.body.textContent).toContain('TSh 200');
    expect(document.body.textContent).toContain('does not change prices or block the sale');
  });
  it('renders redacted entries without any cost figures (managers)', () => {
    render(<MarginWarnings warnings={[managerWarning]} />);
    expect(screen.getByRole('note', { name: 'Below-cost lines' })).toBeDefined();
    expect(document.body.textContent).toContain('TSh 800');
    expect(document.body.textContent).not.toContain('TSh 1,000');
    expect(document.body.textContent).not.toContain('Cost:');
  });
  it('renders nothing without server warnings (cashiers and profitable sales)', () => {
    const { container: empty } = render(<MarginWarnings warnings={[]} />);
    expect(empty.innerHTML).toBe('');
    const { container: missing } = render(<MarginWarnings warnings={undefined} />);
    expect(missing.innerHTML).toBe('');
  });
});
