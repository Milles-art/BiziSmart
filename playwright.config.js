import { defineConfig, devices } from '@playwright/test';

const sharedHosting = process.env.BIZISMART_E2E_SHARED === '1';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/setup.js',
  fullyParallel: true,
  // The local PHP HTTP server handles one request at a time, including PIN hashing.
  workers: 1,
  // Owner workflow checkpoints and cashier journeys retain the same per-case deadline.
  timeout: 120000,
  // Match the live API's 15-second request deadline rather than failing while it is still checking.
  expect: { timeout: 15000 },
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:15173',
    trace: 'retain-on-failure',
    channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
  },
  projects: [
    { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-chromium', use: { ...devices['Pixel 7'] } },
  ],
  webServer: [
    {
      command: 'npm run dev:api',
      env: { APP_ENV: 'testing', TENANT_DOMAIN: 'localhost', ADMIN_DOMAIN:'admin.localhost',
        BIZISMART_HOSTING_PROFILE:sharedHosting?'shared':'standard',
        SESSION_DRIVER:sharedHosting?'database':'file', CACHE_STORE:sharedHosting?'database':'array',
        CACHE_LIMITER:sharedHosting?'database':'array', QUEUE_CONNECTION:sharedHosting?'database':'sync' },
      url: 'http://127.0.0.1:18080/api/v1/health',
      reuseExistingServer: false,
      // Allow the bounded cold PHP dependency boot before any test begins.
      timeout: 180000,
    },
    {
      command: 'npm run dev --workspace frontend -- --host 127.0.0.1',
      url: 'http://127.0.0.1:15173',
      reuseExistingServer: false,
      timeout: 60000,
    },
  ],
});
