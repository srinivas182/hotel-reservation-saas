import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

/**
 * Sprint H: the owner's WhatsApp kit, and the short link a guest opens.
 */
test.describe('the owner', () => {
    test.use({ storageState: 'tests/e2e/.auth/owner.json' });

    test('finds the posters, the website button and the links', async ({
        page,
    }) => {
        await page.goto('/hotel/whatsapp-kit');
        await expect(
            page.getByRole('heading', { name: 'Kit WhatsApp', exact: true }),
        ).toBeVisible();
        await expect(page.getByText('Affiche à la réception')).toBeVisible();
        await expect(page.getByText(/button\.js/)).toBeVisible();
        await expectAccessible(page);
    });
});

test.describe('a guest', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('opens the short link of a poster', async ({ page }) => {
        const response = await page.request.get('/w/first-class-lodge/R1');
        expect(response.ok()).toBeTruthy();
        const html = await response.text();
        expect(html).toContain('%23R1');
        expect(response.headers()['set-cookie']).toBeUndefined();
    });
});
