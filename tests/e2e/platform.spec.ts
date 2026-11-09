import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

test.describe('platform', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('the platform home and the sign-ups page, with the go-live request', async ({
        page,
    }) => {
        await page.goto('/platform');
        await expect(
            page.getByRole('heading', { name: 'Accueil plateforme' }),
        ).toBeVisible();
        await expect(
            page.getByText('Demande la mise en service').first(),
        ).toBeVisible();
        await expectAccessible(page);

        await page.goto('/platform/signups');
        await expectAccessible(page);
    });

    test('the launch checklist and launch-week watch', async ({ page }) => {
        await page.goto('/platform/launch');
        await expect(
            page.getByRole('heading', { name: 'Lancement', exact: true }),
        ).toBeVisible();
        await expectAccessible(page);
    });

    test('sales opens the demo hotel and plays a scenario', async ({
        page,
        browser,
    }) => {
        await page.goto('/platform/demo');
        const create = page.getByRole('button', {
            name: 'Créer l’hôtel de démonstration',
        });
        if (await create.isVisible()) {
            await create.click();
        }
        const link = await page
            .getByRole('link', { name: /Ouvrir la démo/ })
            .getAttribute('href');

        // The demo opens in a separate, empty browser, as on the prospect's computer
        // (new contexts inherit the test's saved login unless told otherwise).
        const prospect = await browser.newContext({
            locale: 'fr-FR',
            storageState: { cookies: [], origins: [] },
        });
        const demo = await prospect.newPage();
        await demo.goto(link ?? '/');
        await expect(demo).toHaveURL(/\/hotel\/demo/);
        await expectAccessible(demo);
        await demo
            .getByRole('button', {
                name: 'Un client demande une chambre pour ce soir',
            })
            .click();
        await expect(demo.getByText(/chambre pour 2 personnes/)).toBeVisible({
            timeout: 30_000,
        });
        await prospect.close();
        // The sales person is still signed in on their own browser.
        await page.goto('/platform');
        await expect(page).toHaveURL(/\/platform$/);
    });
});

test('unknown addresses show the branded page in French', async ({ page }) => {
    const response = await page.goto('/cette-page-n-existe-pas');
    expect(response?.status()).toBe(404);
    await expect(
        page.getByRole('heading', { name: 'Page introuvable' }),
    ).toBeVisible();
    await expectAccessible(page);
});
