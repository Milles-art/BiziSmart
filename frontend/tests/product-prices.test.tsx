import { describe, expect, it } from 'vitest';
import { code128Bars } from '../src/features/live/code128';
import { priceHistoryCsv } from '../src/features/live/priceHistoryCsv';

describe('shelf barcode encoding and safe historical export', () => {
  it('encodes Code128 B with weighted checksum and quiet zones', () => {
    const result = code128Bars('ABC');
    expect(result.checksum).toBe(1); // (104 + 33 + 34*2 + 35*3) modulo 103.
    expect(result.width).toBe(88); // 6 symbols of 11 modules, 13-module stop, 20 quiet modules.
    expect(result.bars[0]).toEqual({ x: 10, width: 2 });
    expect(result.bars.at(-1)!.x + result.bars.at(-1)!.width).toBe(result.width - 10);
  });
  it('rejects unsupported and control characters and leaves exact registered values intact', () => {
    expect(() => code128Bars('')).toThrow(); expect(() => code128Bars('a\nb')).toThrow(); expect(() => code128Bars('é')).toThrow();
    expect(code128Bars('00123-PACK').bars.length).toBeGreaterThan(10);
  });
  it('escapes CSV formulas and embedded quotation marks without changing exact integer prices', () => {
    const csv = priceHistoryCsv([{ id: 'price_x', product_name: '=CMD()', packaging_id: null, packaging_name: '"pack"', packaging_version: null, base_qty: '0.500', unit: 'kg', old_price: 2300, new_price: 2500, actor_id: 'usr_x', actor_name: '@name', created_at: '2026-10-12T10:00:00Z' }]);
    expect(csv).toContain('"\'=CMD()"'); expect(csv).toContain('"""pack"""'); expect(csv).toContain('"2300","2500"'); expect(csv).toContain('"\'@name"');
  });
});
