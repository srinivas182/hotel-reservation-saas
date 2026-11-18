import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

test.use({ storageState: 'tests/e2e/.auth/reception.json' });

test('the staff app installs, and opens saved pages without a connection', async ({
    page,
    context,
}) => {
    await page.goto('/hotel/today');

    // The manifest and the service worker are there.
    const manifest = await page.evaluate(async () => {
        const link = document.querySelector<HTMLLinkElement>(
            'link[rel="manifest"]',
        );

        return link ? (await fetch(link.href)).json() : null;
    });
    expect(manifest?.display).toBe('standalone');
    await page.evaluate(() => navigator.serviceWorker.ready);

    // No install offer on the first visit (addition 2).
    await expect(
        page.getByRole('region', { name: 'Installer l’application' }),
    ).toHaveCount(0);

    // Once the service worker controls the page, the day is saved on the phone.
    await page.reload();
    await expect(
        page.getByRole('heading', { name: "Aujourd'hui" }),
    ).toBeVisible();
    await expect
        .poll(() =>
            page.evaluate(() => navigator.serviceWorker.controller !== null),
        )
        .toBe(true);
    await page.reload();

    await context.setOffline(true);
    await page.reload();
    await expect(
        page.getByRole('heading', { name: "Aujourd'hui" }),
    ).toBeVisible();
    await expect(
        page.getByRole('status').filter({ hasText: 'Pas de connexion' }),
    ).toBeVisible();
    await context.setOffline(false);
});

test('notification settings explain what to do when the app cannot notify', async ({
    page,
}) => {
    await page.goto('/hotel/my-alerts');
    await expect(page.getByText('Heures calmes')).toBeVisible();
    // No VAPID keys in the browser tests: the page says alerts come by WhatsApp.
    await expect(
        page.getByText(
            /notifications de l’application ne sont pas encore configurées/,
        ),
    ).toBeVisible();
    await expectAccessible(page);
});
