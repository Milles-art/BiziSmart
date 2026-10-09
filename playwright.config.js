import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/setup.js',
  fullyParallel: true,
  // Artisan's local HTTP server handles one request at a time, including PIN hashing.
  workers: 1,
  // Whole owner flows include setup, TOTP, catalogue, staff and devices on the serial local server.
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
      command: 'php backend/artisan serve --env=testing --host=127.0.0.1 --port=18080 --no-reload',
      env: { APP_ENV: 'testing', TENANT_DOMAIN: 'localhost', CACHE_LIMITER: 'array' },
      url: 'http://127.0.0.1:18080/api/v1/health',
      reuseExistingServer: false,
      timeout: 60000,
    },
    {
      command: 'npm run dev --workspace frontend -- --host 127.0.0.1',
      url: 'http://127.0.0.1:15173',
      reuseExistingServer: false,
      timeout: 60000,
    },
  ],
});
