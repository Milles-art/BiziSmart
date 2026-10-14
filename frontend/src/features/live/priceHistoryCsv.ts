import type {PriceEntry} from './productPricesApi';
function csvCell(value: unknown) { let cell = value == null ? '' : String(value); if (/^[\s]*[=+\-@]/.test(cell)) cell = `'${cell}`; return `"${cell.replace(/"/g, '""')}"`; }
export function priceHistoryCsv(entries: PriceEntry[]): string {
  return [['Recorded (UTC)', 'Product', 'Pack', 'Pack version', 'Contents', 'Unit', 'Old price (TSh)', 'New price (TSh)', 'Actor'], ...entries.map((entry) => [entry.created_at, entry.product_name, entry.packaging_name, entry.packaging_version, entry.base_qty, entry.unit, entry.old_price, entry.new_price, entry.actor_name])].map((row) => row.map(csvCell).join(',')).join('\r\n');
}
