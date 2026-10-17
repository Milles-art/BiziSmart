import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import { cases, prepare, inspect, collection, environment, preview, endpoint } from './build-totalvfd-kit.mjs';

class Skipped extends Error {}
function harness(overrides = {}) {
  // Synthetic test-only values: never sent anywhere, not provider-approved identities.
  const values = new Map(Object.entries({
    sandbox_token: 'OFFLINE-FAKE-TOKEN', sandbox_business_id: 'OFFLINE-BUSINESS', sandbox_serial: 'OFFLINE-SERIAL',
    sandbox_access_confirmed: 'YES', operator_label: 'Offline tester', sandbox_run_id: 'BIZISMART-TEST-OFFLINE-0001',
    test_customer_name: 'Offline Test Customer', test_customer_mobile: '0700000000', test_tin: '000000000',
    test_dl: 'OFFLINE-DL', test_voter_id: 'OFFLINE-VOTER', test_passport: 'OFFLINE-PASSPORT', test_nid: 'OFFLINE-NID',
    test_nil_value: 'OFFLINE-NIL', approved_test_tax_groups: 'A,C,D,E', nil_contract_confirmed: 'YES',
    two_decimal_weights_confirmed: 'YES', negative_tests_confirmed: 'YES', ...overrides,
  }));
  const headers = new Map(); const local = new Map(); const checks = [];
  const pm = {
    environment: { get: (key) => values.get(key), set: (key, value) => values.set(key, value), unset: (key) => values.delete(key) },
    variables: { set: (key, value) => local.set(key, value), get: (key) => local.get(key) },
    execution: { skipRequest: () => { throw new Skipped(); }, setNextRequest: (next) => assert.equal(next, null) },
    request: { method: 'POST', url: endpoint, headers: { upsert: ({ key, value }) => headers.set(key, value) },
      body: { update: (body) => { pm.body = JSON.parse(body); } } },
    test: (name, callback) => { try { callback(); checks.push({ name, pass: true }); } catch { checks.push({ name, pass: false }); } },
    expect: (actual) => ({ to: { eql: (expected) => assert.deepEqual(actual, expected) } }),
  };
  return { pm, values, headers, checks };
}
function execute(h, spec) {
  // Execute exactly the serialized Postman script, not a separate implementation.
  vm.runInNewContext(`(${prepare.toString()})(${JSON.stringify(spec)});`, { pm: h.pm });
  return h.pm.body;
}
function arm(h, id) { h.values.set('send_case', id); }
function response(h, spec, code, body) {
  h.pm.response = { code, json: () => body };
  vm.runInNewContext(`(${inspect.toString()})(${JSON.stringify(spec)});`, { pm: h.pm });
  return JSON.parse(h.values.get('_results'))[spec.id];
}
function receipt(body, additions = {}) {
  return { referenceNumber: body.referenceNumber, device: { serial: body.serial }, rctvnum: 'OFFLINE-RECEIPT',
    localTime: '12:00:00', verificationLink: 'https://example.invalid/offline-only', status: 'pending',
    totals: { taxInclusive: body.payments[0].amount }, ...additions };
}

test('import files exactly match the reviewed generator and contain only blank environment values', async () => {
  const root = new URL('../../docs/sandbox/', import.meta.url);
  assert.deepEqual(JSON.parse(await readFile(new URL('BiziSmart-TotalVFD.postman_collection.json', root), 'utf8')), collection());
  assert.deepEqual(JSON.parse(await readFile(new URL('BiziSmart-TotalVFD.blank.postman_environment.json', root), 'utf8')), environment());
  assert.deepEqual(JSON.parse(await readFile(new URL('fictional-cases.json', root), 'utf8')), cases);
});

test('blank import has no credentials, twenty fixed sandbox requests and no background scripts', () => {
  assert.ok(environment().values.every((entry) => entry.value === ''));
  assert.equal(collection().item.length, 20);
  assert.equal(cases.filter((entry) => !entry.negative && !entry.duplicate).length, 15);
  for (const request of collection().item) {
    assert.equal(request.request.url, endpoint);
    assert.equal(request.protocolProfileBehavior.followRedirects, false);
    assert.equal(request.request.auth.type, 'noauth');
    assert.ok(!JSON.stringify(request).includes('pm.sendRequest'));
  }
});

for (const spec of cases) {
  test('builds visible fixture, frozen retry and safe headers for ' + spec.id, () => {
    const h = harness();
    if (spec.duplicate) { arm(h, '01'); execute(h, cases[0]); }
    arm(h, spec.id); const first = execute(h, spec);
    assert.equal(h.values.has('send_case'), false);
    assert.equal(h.headers.get('Authorization'), 'Bearer OFFLINE-FAKE-TOKEN');
    assert.equal(h.headers.get('x-active-business'), 'OFFLINE-BUSINESS');
    assert.ok(!h.values.get('_frozen_requests').includes('OFFLINE-FAKE-TOKEN'));
    assert.ok(!h.values.get('_results').includes('OFFLINE-FAKE-TOKEN'));
    const expected = JSON.parse(JSON.stringify(preview(spec)).replace(/\{\{([^}]+)\}\}/g, (_, key) => h.values.get(key)));
    assert.deepEqual(JSON.parse(JSON.stringify(first)), expected);
    arm(h, spec.id); assert.deepEqual(JSON.parse(JSON.stringify(execute(h, spec))), expected);
    assert.throws(() => execute(h, spec), Skipped);
  });
}

for (const [key, value] of [
  ['sandbox_token', ''], ['sandbox_token', 'Bearer token'], ['sandbox_token', 'token\r\nInjected: secret'],
  ['sandbox_access_confirmed', 'NO'], ['sandbox_business_id', ''], ['sandbox_serial', ''],
  ['operator_label', ''], ['sandbox_run_id', 'production'], ['test_customer_mobile', ''],
  ['test_tin', '123'], ['approved_test_tax_groups', 'E'],
]) {
  test('blocks missing/unsafe private setup ' + key + ' ' + JSON.stringify(value), () => {
    const h = harness({ [key]: value }); arm(h, '01');
    assert.throws(() => execute(h, cases[0]), Skipped);
    assert.equal(h.pm.body, undefined); assert.equal(h.headers.size, 0);
    assert.equal(h.values.has('send_case'), false);
  });
}
test('blocks missing or wrong arm without a body or secret headers', () => {
  const h = harness(); assert.throws(() => execute(h, cases[0]), Skipped);
  arm(h, '02'); assert.throws(() => execute(h, cases[0]), Skipped);
  assert.equal(h.pm.body, undefined); assert.equal(h.headers.size, 0);
});
test('blocks production URLs, redirects masquerading as sandbox, and altered methods', () => {
  for (const url of ['https://api.totalvfd.co.tz/sales', endpoint + '?redirect=production', 'http://testapi.totalvfd.co.tz/sales', endpoint + '/']) {
    const h = harness(); arm(h, '01'); h.pm.request.url = url;
    assert.throws(() => execute(h, cases[0]), Skipped); assert.equal(h.headers.size, 0);
  }
  const h = harness(); arm(h, '01'); h.pm.request.method = 'GET'; assert.throws(() => execute(h, cases[0]), Skipped);
});
test('blocks changes to business, serial, run or frozen payload after uncertainty', () => {
  for (const key of ['sandbox_business_id', 'sandbox_serial', 'sandbox_run_id', 'test_customer_name', 'test_tin']) {
    const h = harness(); arm(h, '01'); execute(h, cases[0]);
    h.values.set(key, key === 'sandbox_run_id' ? 'BIZISMART-TEST-NEW-RUN' : 'CHANGED'); arm(h, '01');
    assert.throws(() => execute(h, cases[0]), Skipped);
    assert.equal(JSON.parse(h.values.get('_results'))['01'].attempt, 1);
  }
});
test('duplicate uses original bytes and cannot run before case 01', () => {
  const h = harness(); arm(h, 'DUP'); assert.throws(() => execute(h, cases[15]), Skipped);
  arm(h, '01'); const original = execute(h, cases[0]);
  h.values.set('test_customer_name', 'Changed later'); arm(h, 'DUP');
  assert.deepEqual(JSON.parse(JSON.stringify(execute(h, cases[15]))), JSON.parse(JSON.stringify(original)));
});
test('NIL, weighted and intentional-invalid probes need separate confirmed contracts', () => {
  for (const [index, key] of [[5, 'nil_contract_confirmed'], [6, 'two_decimal_weights_confirmed'], [16, 'negative_tests_confirmed']]) {
    const h = harness({ [key]: '' }); arm(h, cases[index].id);
    assert.throws(() => execute(h, cases[index]), Skipped); assert.equal(h.headers.size, 0);
  }
});
test('201 with pending preserves provider status and does not claim production verification', () => {
  const h = harness(); arm(h, '01'); const body = execute(h, cases[0]);
  const result = response(h, cases[0], 201, receipt(body));
  assert.equal(result.providerStatus, 'pending'); assert.equal(h.checks.at(-1).pass, true);
  assert.equal(result.outcome, 'sandbox-receipt-returned-review-provider-status');
});
test('409 unwraps existing receipt but requires a duplicate message and matching identity', () => {
  const h = harness(); arm(h, '01'); const body = execute(h, cases[0]);
  response(h, cases[0], 201, receipt(body));
  arm(h, 'DUP'); execute(h, cases[15]);
  const result = response(h, cases[15], 409, { msg: 'Reference already used', data: receipt(body) });
  assert.equal(result.http, 409); assert.equal(h.checks.at(-1).pass, true);
  response(h, cases[15], 409, { msg: 'Some other conflict', data: receipt(body) });
  assert.equal(h.checks.at(-1).pass, false);
  response(h, cases[15], 409, { msg: 'Reference already used', data: receipt(body, { rctvnum: 'DIFFERENT-RECEIPT' }) });
  assert.equal(h.checks.at(-1).pass, false);
});
test('receipt mismatch, missing fields, auth failures, server errors and malformed JSON fail review', () => {
  for (const [code, patch] of [[201, { referenceNumber: 'FOREIGN' }], [201, { device: { serial: 'FOREIGN' } }],
    [201, { rctvnum: '' }], [201, { totals: { taxInclusive: 1 } }], [201, { verificationLink: 'javascript:bad' }],
    [401, {}], [403, {}], [500, {}]]) {
    const h = harness(); arm(h, '01'); const body = execute(h, cases[0]);
    const result = response(h, cases[0], code, receipt(body, patch));
    assert.equal(h.checks.at(-1).pass, false); assert.equal(result.outcome, 'unexpected-response-needs-review');
  }
  const h = harness(); arm(h, '01'); execute(h, cases[0]); response(h, cases[0], 201, null);
  assert.equal(h.checks.at(-1).pass, false);
});
test('invalid probes only accept validation rejection; 201 or authorization failure is not success', () => {
  for (const code of [400, 422, 201, 401, 403, 500]) {
    const h = harness(); const spec = cases[16]; arm(h, spec.id); const body = execute(h, spec);
    response(h, spec, code, receipt(body)); assert.equal(h.checks.at(-1).pass, code === 400 || code === 422);
  }
});
