import { defineConfig, devices } from '@playwright/test';

/*
 * Browser tests (S16): the main journeys in French, on a phone and on a
 * desktop, with accessibility checks. They run against a simulated staging
 * prepared with `php artisan e2e:prepare` (see .github/workflows/e2e.yml).
 * Locally, PW_CHROMIUM_PATH can point at any Chromium.
 */
const executablePath = process.env.PW_CHROMIUM_PATH || undefined;

export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 60_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI
        ? [
              // Failures become annotations, readable from the pull request and the API.
              ['github'],
              ['list'],
              ['html', { open: 'never', outputFolder: 'playwright-report' }],
          ]
        : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000',
        locale: 'fr-FR',
        timezoneId: 'Africa/Abidjan',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: executablePath
            ? { executablePath, args: ['--no-sandbox'] }
            : {},
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
            use: {
                locale: 'fr-FR',
                launchOptions: executablePath
                    ? { executablePath, args: ['--no-sandbox'] }
                    : {},
            },
        },
        {
            name: 'phone',
            dependencies: ['setup'],
            use: {
                ...devices['Pixel 7'],
                locale: 'fr-FR',
                launchOptions: executablePath
                    ? { executablePath, args: ['--no-sandbox'] }
                    : {},
            },
        },
        {
            name: 'desktop',
            dependencies: ['setup'],
            use: {
                ...devices['Desktop Chrome'],
                locale: 'fr-FR',
                launchOptions: executablePath
                    ? { executablePath, args: ['--no-sandbox'] }
                    : {},
            },
        },
    ],
});
