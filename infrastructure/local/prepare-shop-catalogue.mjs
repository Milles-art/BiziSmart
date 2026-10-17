// Offline source-data preparation only. Does not boot Laravel or connect to any database/provider.
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
const permitted = new Set(['--source', '--expected-sha256', '--prices-approved-on']);
const options = new Map();
for (let index = 0; index < args.length; index += 2) {
  if (!permitted.has(args[index]) || !args[index + 1] || options.has(args[index])) {
    throw new Error('Use --source <catalogue.json> --expected-sha256 <source hash> --prices-approved-on <YYYY-MM-DD>.');
  }
  options.set(args[index], args[index + 1]);
}
const source = options.get('--source');
const expectedHash = options.get('--expected-sha256');
const priceApproval = options.get('--prices-approved-on');
if (!source || !/^[a-f0-9]{64}$/i.test(expectedHash || '') || !/^\d{4}-\d{2}-\d{2}$/.test(priceApproval || '')) {
  throw new Error('Explicit source, expected SHA256 and owner-approved price date are required.');
}
const parsedDate = new Date(priceApproval + 'T00:00:00Z');
if (!Number.isFinite(parsedDate.valueOf()) || parsedDate.toISOString().slice(0, 10) !== priceApproval) {
  throw new Error('Price approval date is invalid.');
}
const bytes = await readFile(path.resolve(source));
const hash = createHash('sha256').update(bytes).digest('hex');
if (hash !== expectedHash.toLowerCase()) {
  throw new Error('Source changed since price approval. Review the source and its new prices before preparing another snapshot.');
}
const catalogue = JSON.parse(bytes.toString('utf8').replace(/^\uFEFF/, ''));
if (!Array.isArray(catalogue) || !catalogue.length) throw new Error('Source must contain a nonempty product array.');
const seen = new Set();
const products = catalogue.map((product, index) => {
  if (typeof product.id !== 'string' || !product.id.trim() || seen.has(product.id)) {
    throw new Error('Source product ID missing or duplicated at row ' + (index + 1));
  }
  seen.add(product.id);
  if (typeof product.name !== 'string' || !product.name.trim() || typeof product.unit !== 'string' || !product.unit.trim()) {
    throw new Error('Source product name/unit missing at row ' + (index + 1));
  }
  if (!Number.isSafeInteger(product.price) || product.price <= 0) {
    throw new Error('A positive exact integer TSh price is required at row ' + (index + 1));
  }
  return {
    source_id: product.id,
    name: product.name,
    category: product.categoryId ?? null,
    source_selling_unit: product.unit,
    source_price_unit: product.priceUnit ?? null,
    selling_price: product.price,
    prices_approved_on: priceApproval,
    source_price_basis: product.priceBasis ?? product.priceType ?? null,
    source_note: product.priceNote ?? '',
    // Website availability, image filenames and FC IDs are not stock counts or packaging barcodes.
    registration: { quantity_type: null, unit: null, barcode: null, buying_price: null, tracks_expiry: null },
    opening_stock: null,
    ready_for_product_creation: false,
    review: [
      'Choose counted packs/items or weighed kilograms and confirm the price unit.',
      'Supply the recorded buying cost; unknown is not zero.',
      'Confirm expiry tracking. Supply physical barcode if scanning is needed; manual products may have no barcode.',
      ...(product.name.length > 160 ? ['Shorten the product name to the API limit of 160 characters.'] : []),
      ...(product.priceUnit && product.priceUnit !== product.unit ? ['Source selling and price units differ; reconcile before registration.'] : []),
    ],
  };
});
const destination = path.join(root, 'tmp/fresh-choice-catalogue');
await mkdir(destination, { recursive: true });
const report = {
  purpose: 'Owner-approved source catalogue preparation, not a BiziSmart import request.',
  source: path.resolve(source), source_sha256: hash, prices_approved_on: priceApproval,
  product_count: products.length,
  operational_writes: false, external_requests: false,
  requires_selected_business_and_authorized_operator: true,
  registration_note: 'Use ProductService through authorized product creation once missing fields are confirmed. F13 imports opening stock only for existing registered barcodes.',
  products,
};
await writeFile(path.join(destination, 'preparation.json'), JSON.stringify(report, null, 2) + '\n');
console.log(`Prepared ${products.length} source products at approved prices. No products or stock imported. Review: ${path.join(destination, 'preparation.json')}`);
