import { chromium, devices } from '@playwright/test';
import assert from 'node:assert/strict';

// Fresh public visitor contexts only: never borrow a person's browser/session or submit credentials.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
// This optional diagnostic allowance is for the measured slow external download link,
// not a change to checkout/API deadlines or the 50-case local acceptance suite.
const firstLoadTimeout = process.argv.includes('--slow-link') ? 120000 : 30000;
try {
  for (const [name, settings] of [['desktop', { viewport: { width: 1440, height: 900 } }], ['mobile', devices['Pixel 7']]]) {
    const context = await browser.newContext(settings);
    try {
      const page = await context.newPage();
      const errors = [];
      const started = Date.now();
      const network = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('requestfailed', request => network.push({url:request.url(),failure:request.failure()?.errorText}));
      page.on('response', response => network.push({url:response.url(),status:response.status()}));
      // Verify the rendered application rather than waiting for unrelated/background load events.
      const response = await page.goto('https://freshchoice.bizismart.com/', { waitUntil: 'commit', timeout:firstLoadTimeout }).catch(error=>{console.log(JSON.stringify({viewport:name,errors,network}));throw error;});
      assert.equal(response.status(), 200);
      await page.getByRole('heading', { name: 'Sign In', exact: true }).waitFor({timeout:firstLoadTimeout}).catch(error=>{console.log(JSON.stringify({viewport:name,url:page.url(),errors,network}));throw error;});
      assert.equal(await page.getByLabel('Email address', { exact: true }).isVisible(), true);
      assert.equal(await page.getByLabel('Password', { exact: true }).isVisible(), true);
      assert.equal(await page.getByLabel('Authenticator code').count(), 0);
      assert.equal(await page.getByRole('button', { name: 'Sign In', exact: true }).isEnabled(), true);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      assert.deepEqual(errors, []);
      const initialLoadMs = Date.now()-started;
      await page.screenshot({ path: `tmp/d105-hosted-login-${name}.png`, fullPage: true, animations: 'disabled', timeout:firstLoadTimeout });
      results.push({ viewport: name, https: true, login_visible: true, overflow: false, script_errors: errors.length, initial_load_ms:initialLoadMs, diagnostic_timeout_ms:firstLoadTimeout });
    } finally { await context.close(); }
  }
} finally { await browser.close(); }
console.log(JSON.stringify({ results, note: 'No sign-in, sale, product change, provider call or hardware operation performed.' }, null, 2));
