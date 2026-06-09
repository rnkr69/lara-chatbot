import { defineConfig, devices } from '@playwright/test';

/**
 * E13 E2E config — Playwright runs against static fixtures served via the
 * built-in webServer. Same hermetic pattern as E12's smoke fixture: the bundle
 * is loaded into an HTML page that mocks `fetch` for `/chatbot/stream`, so no
 * Laravel boot is required. Chromium-only to keep CI light; cross-browser
 * coverage can be opened up in E20 if needed.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 2 : 0,
  workers: process.env['CI'] ? 1 : undefined,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: {
    command: 'node scripts/e2e-server.mjs',
    url: 'http://127.0.0.1:4173/health',
    reuseExistingServer: !process.env['CI'],
    timeout: 30_000,
  },
});
