// Generates credential-free, manually operated Postman requests. Never calls the network.
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const endpoint = 'https://testapi.totalvfd.co.tz/sales';
const item = (id, price, qty = 1, vatGroup = 'A', discount = 0) => ({
  id: `TEST-${id}`, name: `BiziSmart Test ${id}`, price, qty, vatGroup, discount,
});
export const cases = [
  { id: '01', name: 'Single item - TIN', identity: 1, items: [item('Single', 2500)] },
  { id: '02', name: 'Two counted units - driving licence', identity: 2, items: [item('Two Units', 5000, 2)] },
  { id: '03', name: 'Multiple lines - voter ID', identity: 3, items: [item('First', 2500), item('Second', 1000)] },
  { id: '04', name: 'Single line discount - passport', identity: 4, items: [item('Discount', 2500, 1, 'A', 200)] },
  { id: '05', name: 'Multiple discounted lines - NID', identity: 5, items: [item('Discount One', 2500, 1, 'A', 200), item('Discount Two', 1500, 1, 'A', 100)] },
  { id: '06', name: 'Walk-in NIL identity - provider confirmation required', identity: 6, items: [item('Walk In', 1000)] },
  { id: '07', name: 'Weight 1.25 - two decimal quantity probe', items: [item('Weight 125', 5000, 1.25)] },
  { id: '08', name: 'Weight 0.01 - two decimal quantity probe', items: [item('Weight 001', 100, 0.01)] },
  { id: '09', name: 'Odd integer shilling total', items: [item('Odd Total', 1101)] },
  { id: '10', name: 'Tax group C fixture', items: [item('Group C', 1000, 1, 'C')] },
  { id: '11', name: 'Tax group D fixture', items: [item('Group D', 1000, 1, 'D')] },
  { id: '12', name: 'Exempt group E fixture', items: [item('Group E', 1000, 1, 'E')] },
  { id: '13', name: 'Mixed A C E fixture', items: [item('Mixed A', 2500), item('Mixed C', 1000, 1, 'C'), item('Mixed E', 500, 1, 'E')] },
  { id: '14', name: 'Three units with a line total', items: [item('Three Units', 7500, 3)] },
  { id: '15', name: 'Weighted and counted lines', items: [item('Weight Half', 2000, 0.5), item('Counted', 1500)] },
  { id: 'DUP', name: 'Replay frozen case 01 - expect 409', duplicate: true },
  { id: 'NEG-TOTAL', name: 'Payment mismatch - expect rejection', negative: 'total', items: [item('Bad Total', 2500)] },
  { id: 'NEG-QTY', name: 'Zero quantity - expect rejection', negative: 'quantity', items: [item('Bad Quantity', 2500, 0)] },
  { id: 'NEG-TAX', name: 'Invalid tax group - expect rejection', negative: 'tax', items: [item('Bad Tax', 2500, 1, 'INVALID')] },
  { id: 'NEG-SERIAL', name: 'Missing serial - expect rejection', negative: 'serial', items: [item('Missing Serial', 2500)] },
];

// Self-contained functions are embedded in Postman. Keep code compatible with its sandbox.
export function prepare(spec) {
  const env = pm.environment;
  const fail = (message) => { throw new Error(message); };
  const required = (key) => {
    const value = env.get(key);
    if (typeof value !== 'string' || !value.trim() || /[\r\n]/.test(value) || value.includes('{{')) fail('Set a private, provider-approved value for ' + key);
    return value.trim();
  };
  try {
    pm.execution.setNextRequest(null);
    const armed = env.get('send_case');
    env.unset('send_case'); // One click authorizes exactly one case, never a batch.
    if (armed !== spec.id) fail('Review this request, then set send_case to ' + spec.id + ' before Send.');
    if (pm.request.method !== 'POST' || pm.request.url.toString() !== 'https://testapi.totalvfd.co.tz/sales') fail('Only the fixed HTTPS sandbox sales endpoint is allowed.');
    if (required('sandbox_access_confirmed') !== 'YES') fail('Confirm permission to use this sandbox business.');
    const operator = required('operator_label');
    const run = required('sandbox_run_id');
    if (!/^BIZISMART-TEST-[A-Z0-9-]{4,40}$/.test(run)) fail('Use BIZISMART-TEST- followed by a unique run label (4-40 uppercase letters, digits or hyphens).');
    const scope = { business: required('sandbox_business_id'), serial: required('sandbox_serial'), run };
    const token = required('sandbox_token');
    if (/\s/.test(token)) fail('Paste only the bearer token, without the Bearer prefix.');
    const binding = JSON.stringify(scope);
    const oldBinding = env.get('_run_binding');
    if (oldBinding && oldBinding !== binding) fail('This environment is bound to its original business, serial and run. Resolve pending cases before starting a fresh blank environment.');
    const frozen = JSON.parse(env.get('_frozen_requests') || '{}');
    let payload;
    let key = spec.duplicate ? '01' : spec.id;
    if (spec.duplicate) {
      if (!frozen['01']) fail('Send case 01 first. This check reuses its exact saved body.');
      payload = JSON.parse(frozen['01']);
    } else {
      const identity = spec.identity || 1;
      const identityKeys = { 1: 'test_tin', 2: 'test_dl', 3: 'test_voter_id', 4: 'test_passport', 5: 'test_nid', 6: 'test_nil_value' };
      if (identity === 6 && required('nil_contract_confirmed') !== 'YES') fail('Ask TotalVFD to confirm anonymous customer name/mobile/NIL values first.');
      if ([7, 8, 15].includes(Number(spec.id)) && required('two_decimal_weights_confirmed') !== 'YES') fail('Confirm these quantity probes with TotalVFD first; this does not settle three-decimal kg mapping.');
      const groups = required('approved_test_tax_groups').split(',').map((s) => s.trim());
      for (const line of spec.items) {
        if (line.vatGroup !== 'INVALID' && !groups.includes(line.vatGroup)) fail('Provider-approved fictional tax fixture required for group ' + line.vatGroup);
      }
      const mobile = required('test_customer_mobile');
      if (!/^0[0-9]{9}$/.test(mobile)) fail('Use a provider-approved 10-digit local test mobile number.');
      const value = required(identityKeys[identity]);
      if (identity === 1 && !/^[0-9]{9}$/.test(value)) fail('Use a provider-approved nine-digit test TIN.');
      payload = {
        referenceNumber: run + '-' + spec.id,
        serial: scope.serial,
        items: spec.items,
        customer: { name: required('test_customer_name'), mobile, idType: identity, idValue: value },
        payments: [{ type: 'cash', amount: spec.items.reduce((sum, line) => sum + line.price - line.discount, 0) }],
      };
      if (spec.negative === 'total') payload.payments[0].amount += 1;
      if (spec.negative === 'serial') delete payload.serial;
      if (spec.negative && required('negative_tests_confirmed') !== 'YES') fail('Confirm intentional invalid sandbox submissions with the provider first.');
    }
    const serialized = JSON.stringify(payload);
    if (frozen[key] && frozen[key] !== serialized) fail('Frozen request changed. Restore the original inputs; never alter an uncertain sale or generate a new reference to retry it.');
    frozen[key] = serialized;
    env.set('_run_binding', binding);
    env.set('_frozen_requests', JSON.stringify(frozen)); // Save BEFORE network access, including uncertain sends.
    const results = JSON.parse(env.get('_results') || '{}');
    const previous = results[spec.id] || {};
    results[spec.id] = { ...previous, reference: payload.referenceNumber, operatorLabel: operator,
      attempt: (previous.attempt || 0) + 1, attemptedAt: new Date().toISOString(), outcome: 'awaiting-response-or-uncertain' };
    env.set('_results', JSON.stringify(results));
    pm.variables.set('_active_reference', payload.referenceNumber);
    pm.request.headers.upsert({ key: 'Authorization', value: 'Bearer ' + token });
    pm.request.headers.upsert({ key: 'x-active-business', value: scope.business });
    pm.request.headers.upsert({ key: 'Content-Type', value: 'application/json' });
    pm.request.body.update(serialized);
  } catch (error) {
    // Supported Postman clients stop the request here; no credentials or payload in the error.
    pm.test('Sandbox setup and manual authorization must be valid before sending', () => pm.expect(false).to.eql(true));
    pm.execution.skipRequest();
    throw error;
  }
}

export function inspect(spec) {
  pm.execution.setNextRequest(null);
  const results = JSON.parse(pm.environment.get('_results') || '{}');
  const entry = results[spec.id] || {};
  const code = pm.response.code;
  let data;
  try { data = pm.response.json(); } catch { data = null; }
  const receipt = code === 409 ? data && data.data : data;
  const expectedReference = pm.variables.get('_active_reference');
  const frozen = JSON.parse(pm.environment.get('_frozen_requests') || '{}');
  const request = JSON.parse(frozen[spec.duplicate ? '01' : spec.id]);
  const present = (value) => typeof value === 'string' && value.trim().length > 0;
  const fields = receipt && receipt.referenceNumber === expectedReference && receipt.device && receipt.device.serial === request.serial
    && present(receipt.rctvnum) && present(receipt.localTime) && present(receipt.verificationLink)
    && /^https:\/\//.test(receipt.verificationLink) && present(receipt.status)
    && receipt.totals && receipt.totals.taxInclusive === request.payments[0].amount;
  const duplicateMessage = code !== 409 || (data && typeof data.msg === 'string' && /already|duplicate|used/i.test(data.msg));
  const accepted = (code === 201 || code === 409) && fields && duplicateMessage;
  const firstReceipt = results['01'];
  const sameExistingReceipt = !spec.duplicate || !firstReceipt || !firstReceipt.receiptCode
    || (receipt && receipt.rctvnum === firstReceipt.receiptCode && receipt.verificationLink === firstReceipt.verificationLink);
  let good;
  if (spec.negative) {
    good = code === 400 || code === 422;
    entry.outcome = good ? 'provider-rejected-invalid-test' : 'unexpected-response-needs-review';
  } else {
    good = Boolean(accepted && (!spec.duplicate || code === 409) && sameExistingReceipt);
    entry.outcome = good ? 'sandbox-receipt-returned-review-provider-status' : 'unexpected-response-needs-review';
  }
  entry.http = code;
  if (accepted) {
    entry.providerStatus = receipt.status; // pending is preserved, never relabelled as verified.
    entry.receiptCode = receipt.rctvnum;
    entry.verificationLink = receipt.verificationLink;
    entry.localTime = receipt.localTime;
    entry.taxInclusive = receipt.totals.taxInclusive;
  }
  results[spec.id] = entry;
  pm.environment.set('_results', JSON.stringify(results));
  pm.test(spec.negative ? 'Provider rejects this intentional invalid request (400/422)' : 'Sandbox receipt matches frozen reference, device, total and required fields', () => {
    pm.expect(good).to.eql(true);
  });
}

export function collection() {
  return {
    info: { name: 'BiziSmart - manually operated TotalVFD sandbox', schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
      description: 'Sandbox only. Read docs/15-totalvfd-sandbox-testing.md. Manually sign in, review each request, arm its case ID and click Send. Do not use Collection Runner, monitors or scheduled runs. AI-assisted preparation is not concealed. No production/POS integration or certification claim.' },
    item: cases.map((spec) => ({
      name: spec.id + ' - ' + spec.name,
      protocolProfileBehavior: { followRedirects: false },
      event: [
        { listen: 'prerequest', script: { type: 'text/javascript', exec: [`(${prepare.toString()})(${JSON.stringify(spec)});`] } },
        { listen: 'test', script: { type: 'text/javascript', exec: [`(${inspect.toString()})(${JSON.stringify(spec)});`] } },
      ],
      request: { method: 'POST', auth: { type: 'noauth' },
        header: [{ key: 'Content-Type', value: 'application/json' }],
        body: { mode: 'raw', raw: JSON.stringify(preview(spec), null, 2), options: { raw: { language: 'json' } } },
        url: endpoint, description: 'Use the private environment. Set send_case to ' + spec.id + ' after reviewing the matching fixture. Pre-request script builds and freezes the body before sending. Preview it offline with the generated fixture listing. Never edit a frozen request or clear uncertainty by changing references.' },
      response: [],
    })),
  };
}

export function preview(spec) {
  if (spec.duplicate) return preview(cases[0]);
  const keys = { 1: 'test_tin', 2: 'test_dl', 3: 'test_voter_id', 4: 'test_passport', 5: 'test_nid', 6: 'test_nil_value' };
  const identity = spec.identity || 1;
  const body = {
    referenceNumber: '{{sandbox_run_id}}-' + spec.id,
    serial: '{{sandbox_serial}}', items: spec.items,
    customer: { name: '{{test_customer_name}}', mobile: '{{test_customer_mobile}}', idType: identity, idValue: '{{' + keys[identity] + '}}' },
    payments: [{ type: 'cash', amount: spec.items.reduce((sum, line) => sum + line.price - line.discount, 0) + (spec.negative === 'total' ? 1 : 0) }],
  };
  if (spec.negative === 'serial') delete body.serial;
  return body;
}

export function environment() {
  return { name: 'BiziSmart TotalVFD sandbox - BLANK PRIVATE TEMPLATE', _postman_variable_scope: 'environment',
    values: [
      'sandbox_token', 'sandbox_business_id', 'sandbox_serial', 'sandbox_access_confirmed',
      'operator_label', 'sandbox_run_id', 'send_case', 'test_customer_name', 'test_customer_mobile',
      'test_tin', 'test_dl', 'test_voter_id', 'test_passport', 'test_nid', 'test_nil_value',
      'approved_test_tax_groups', 'nil_contract_confirmed', 'two_decimal_weights_confirmed',
      'negative_tests_confirmed', '_run_binding', '_frozen_requests', '_results',
    ].map((key) => ({ key, value: '', enabled: true, type: key === 'sandbox_token' ? 'secret' : 'default' })) };
}

export async function build(output = new URL('../../docs/sandbox/', import.meta.url)) {
  await mkdir(output, { recursive: true });
  await writeFile(new URL('BiziSmart-TotalVFD.postman_collection.json', output), JSON.stringify(collection(), null, 2) + '\n');
  await writeFile(new URL('BiziSmart-TotalVFD.blank.postman_environment.json', output), JSON.stringify(environment(), null, 2) + '\n');
  await writeFile(new URL('fictional-cases.json', output), JSON.stringify(cases, null, 2) + '\n');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await build();
  console.log('Prepared 15 fictional sale cases, 1 duplicate check, 4 invalid-request probes and blank private environment. No network calls.');
}
