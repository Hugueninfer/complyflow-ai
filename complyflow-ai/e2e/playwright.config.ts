import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45000,
  expect: { timeout: 10000 },
  reporter: [['list']],
  webServer: process.env.E2E_BASE_URL ? undefined : { command: 'node proxy.mjs', url: 'http://127.0.0.1:4173', timeout: 15000 },
  use: {
    actionTimeout: 10000,
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:4173',
    browserName: 'chromium',
    launchOptions: { executablePath: process.env.E2E_CHROMIUM_PATH },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
})
