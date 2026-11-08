import { test as setup } from '@playwright/test';
import { logIn, logInAsAdmin, RECEPTION } from './helpers';

// Log in once per role (login is rate limited) and reuse the session.
setup('reception session', async ({ page }) => {
    await logIn(page, RECEPTION);
    await page.waitForURL(/\/hotel\/today/);
    await page
        .context()
        .storageState({ path: 'tests/e2e/.auth/reception.json' });
});

setup('platform admin session (two-step login)', async ({ page }) => {
    await logInAsAdmin(page);
    await page.waitForURL(/\/platform$/);
    await page.context().storageState({ path: 'tests/e2e/.auth/admin.json' });
});
