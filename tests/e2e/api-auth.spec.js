import { expect, test } from '@playwright/test';

test.use({ trace: 'off' });
test('Laravel isolates shop authentication and revokes logout tokens', async ({ request }, info) => {
  const mobile = info.project.name.startsWith('mobile');
  const headers = { Host: 'e2e-shop.localhost', Accept: 'application/json' };
  const health = await request.get('/api/v1/health');
  expect(health.status()).toBe(200);
  const login = await request.post('/api/v1/auth/login', { headers, data: {
    phone: mobile ? '0711111102' : '0711111101', pin: '2468',
    device_uid: `e2e-${mobile ? 'mobile' : 'desktop'}-approved`, device_name: 'Test till',
  } });
  expect(login.status()).toBe(200);
  const { data } = await login.json();
  expect(data.user.role).toBe('cashier');
  const authorized = { ...headers, Authorization: `Bearer ${data.token}` };
  const unavailable = await request.get('/api/v1/payments/capabilities', { headers });
  expect(unavailable.status()).toBe(401);
  expect(unavailable.headers()['cache-control']).toContain('no-store');
  const capabilities = await request.get('/api/v1/payments/capabilities', { headers: authorized });
  expect(capabilities.status()).toBe(200);
  expect(capabilities.headers()['cache-control']).toContain('no-store');
  expect((await capabilities.json()).data).toEqual({ provider:'selcom',configured:false,enabled:false,reason:'merchant_setup_required' });

  expect((await request.get('/api/v1/me', { headers: authorized })).status()).toBe(200);
  expect((await request.get('/api/v1/users', { headers: authorized })).status()).toBe(403);
  const approval = await request.post('/api/v1/auth/supervisor-check', { headers: authorized, data: {
    phone: mobile ? '0711111104' : '0711111103', pin: '3579', permission: 'give_discount',
    operation_id: '91399020-6f79-45c7-9250-6d224771a200', payload_hash: 'a'.repeat(64),
  } });
  expect(approval.status()).toBe(200);
  const grant = (await approval.json()).data;
  expect(grant.approved_by).toMatch(/^usr_/);
  expect(grant.approval_token).toMatch(/^[a-f0-9]{64}$/);
  expect(grant.token).toBeUndefined();
  expect((await request.get('/api/v1/me', { headers: { ...headers, Authorization: `Bearer ${grant.approval_token}` } })).status()).toBe(401);
  expect((await request.post('/api/v1/auth/logout', { headers: authorized })).status()).toBe(200);
  expect((await request.get('/api/v1/me', { headers: authorized })).status()).toBe(401);
});
