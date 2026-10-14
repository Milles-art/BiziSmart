// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import ReceiptPrint from '../src/features/live/ReceiptPrint';
import { downloadReceiptPdf, receiptHtml, receiptRows } from '../src/features/live/receiptOutput';
import type { CashSale } from '../src/features/live/api';

const pdf = vi.hoisted(() => ({ setFontSize: vi.fn(), splitTextToSize: vi.fn((row: string) => [row]), text: vi.fn(), addPage: vi.fn(), save: vi.fn() }));
vi.mock('jspdf', () => ({ jsPDF: class { constructor() { return pdf; } } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
const sale: CashSale = {
  id: 'sale_saved', receipt_number: 'RCP-2026-000123', status: 'completed', branch_id: 'branch_a', till_id: 'till_a',
  subtotal: 9000, discount: 500, total: 8500, vat_rate: 18, vat_amount: 1297, cash_tendered: 3000, change_given: 500,
  created_at: '2026-10-12T10:00:00Z', replayed: false, receipt_url: null,
  lines: [{ product_id: 'prod_a', name: '<script>alert("x")</script>', quantity_type: 'weight', unit: 'kg', qty: '0.750', base_qty: '0.750', packaging_id: null, packaging_name: null, packaging_version: null, conversion_atoms: '1', unit_price: 12000, discount: 500, line_total: 8500 }],
  payments: [{ id: 'sp_cash', method: 'cash', amount: 2500 }, { id: 'sp_mobile', method: 'mpesa', amount: 6000 }],
};
describe('saved receipt output', () => {
  it('preserves authoritative discounted lines and split settlement without recalculating totals', () => {
    const rows = receiptRows(sale, 'Fresh Choice');
    expect(rows).toContain('0.750 kg @ TSh 12,000');
    expect(rows).toContain('Total: TSh 8,500');
    expect(rows).toContain('Line discount: TSh 500');
    expect(rows).toContain('Cash: TSh 2,500');
    expect(rows).toContain('Selcom mobile payment: TSh 6,000');
    expect(rows).toContain('Change: TSh 500');
  });
  it('escapes saved product and shop text instead of executing receipt markup', () => {
    const document = new DOMParser().parseFromString(receiptHtml(sale, '<img src=x onerror=alert(1)>'), 'text/html');
    expect(document.querySelector('script')).toBeNull();
    expect(document.querySelector('img')).toBeNull();
    expect(document.body.textContent).toContain(sale.lines[0].name);
    expect(document.body.textContent).toContain('<img src=x onerror=alert(1)>');
  });
  it('distinguishes the manual line discount from the saved allocated cart share', () => {
    const rows = receiptRows({ ...sale, lines: [{ ...sale.lines[0], line_discount: 500, cart_discount: 250, discount: 750 }] }, 'Fresh Choice');
    expect(rows).toContain('Line discount: TSh 500');
    expect(rows).toContain('Cart discount share: TSh 250');
    expect(rows).not.toContain('Line discount: TSh 750');
  });
  it('paginates long PDF receipts and sanitizes the downloaded filename', async () => {
    await downloadReceiptPdf({ ...sale, receipt_number: '../receipt', lines: Array.from({ length: 60 }, () => sale.lines[0]) }, 'Fresh Choice');
    expect(pdf.addPage).toHaveBeenCalled();
    expect(pdf.text).toHaveBeenCalledWith('Total: TSh 8,500', 16, expect.any(Number));
    expect(pdf.save).toHaveBeenCalledWith('___receipt.pdf');
  });
  it('opens a keyboard-accessible isolated preview and waits for the printable document', () => {
    render(<ReceiptPrint sale={sale} shopName="Fresh Choice" />);
    fireEvent.click(screen.getByRole('button', { name: 'Print or download receipt' }));
    const frame = screen.getByTitle('Saved receipt print preview');
    expect(frame.getAttribute('sandbox')).toBe('allow-same-origin allow-modals');
    expect(screen.getByRole('button', { name: 'Print receipt' }).hasAttribute('disabled')).toBe(true);
    fireEvent.load(frame);
    expect(screen.getByRole('button', { name: 'Print receipt' }).hasAttribute('disabled')).toBe(false);
  });
});
