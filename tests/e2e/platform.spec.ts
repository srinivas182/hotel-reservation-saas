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
});

test('unknown addresses show the branded page in French', async ({ page }) => {
    const response = await page.goto('/cette-page-n-existe-pas');
    expect(response?.status()).toBe(404);
    await expect(
        page.getByRole('heading', { name: 'Page introuvable' }),
    ).toBeVisible();
    await expectAccessible(page);
});
