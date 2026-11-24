import { defineConfig, devices } from '@playwright/test'

// The suite starts its own server on this port. Set E2E_PORT when the port is
// in use on your machine.
const port = process.env.E2E_PORT ?? '8787'
const baseURL = `http://127.0.0.1:${port}`

// The steps of the server: write .env.e2e, build the Vite assets, make the
// database again from the migrations and the E2E seeder, then serve.
const serverCommand = [
  'node tests/e2e/support/prepare-env.mjs',
  'npm run build',
  'php artisan migrate:fresh --seed --seeder=E2eSeeder --force --no-interaction --env=e2e',
  `php artisan serve --env=e2e --host=127.0.0.1 --port=${port}`,
].join(' && ')

export default defineConfig({
  testDir: './tests/e2e',
  // `php artisan serve` handles one request at a time, and the specs share one
  // SQLite file. One worker keeps the order of writes known.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // CI uses the Chromium of `npx playwright install chromium`. On a
        // machine where that build does not run, set E2E_BROWSER_CHANNEL=chrome
        // to use the installed Google Chrome.
        channel: process.env.E2E_BROWSER_CHANNEL || undefined,
      },
    },
  ],
  webServer: {
    command: serverCommand,
    url: `${baseURL}/login`,
    // Never reuse a server: each run starts from the seeded state.
    reuseExistingServer: false,
    timeout: 180_000,
    env: {
      APP_ENV: 'e2e',
      E2E_PORT: port,
    },
    // The request log of `php artisan serve` is long. Errors still print.
    stdout: 'ignore',
    stderr: 'pipe',
  },
})
