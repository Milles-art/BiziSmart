import { expect, test } from '@playwright/test';

test.use({ trace: 'off' });
test('Laravel isolates shop authentication and revokes logout tokens', async ({ request }, info) => {
  const mobile = info.project.name.startsWith('mobile');
  const headers = { Host: 'e2e-shop.localhost', Accept: 'application/json' };
  const health = await request.get('/api/v1/health');
  expect(health.status()).toBe(200);
  const login = await request.post('/api/v1/auth/email/login', { headers, data: {
    email: (mobile ? '0711111102' : '0711111101')+'@example.test', password: 'browser-email-password-42',
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
    email: (mobile ? '0711111104' : '0711111103')+'@example.test', password: 'browser-email-password-42', permission: 'give_discount',
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

test('a private credential link opens safely even when a shop session already exists', async ({ page }, info) => {
  const mobile=info.project.name.startsWith('mobile');
  await page.addInitScript(mobile=>localStorage.setItem('bizismart.device.v1',`e2e-${mobile?'mobile':'desktop'}-approved`),mobile);
  await page.goto('http://e2e-shop.localhost:15173/');
  await page.getByLabel('Email address',{exact:true}).fill((mobile?'0711111102':'0711111101')+'@example.test');
  await page.getByLabel('Password',{exact:true}).fill('browser-email-password-42');
  await page.getByRole('button',{name:'Sign In',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Browser Test Supermarket'})).toBeVisible();
  await page.goto('http://e2e-shop.localhost:15173/#reset=invalid');
  await expect(page.getByRole('heading',{name:'Choose a new password'})).toBeVisible();
  await expect(page.getByRole('button',{name:'Save new password'})).toBeDisabled();
  expect(new URL(page.url()).hash).toBe('');
  await expect(page.getByLabel('Authenticator code')).toHaveCount(0);
});
