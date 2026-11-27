import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

/*
 * 0.40.0 (tracker row 6): the main forms, with their panels open, have a
 * name for every field (linked label or aria-label), checked by axe
 * (WCAG 2.1 AA). A field without a name fails the build.
 */

test.use({ storageState: 'tests/e2e/.auth/owner.json' });

test('the booking page and its panels', async ({ page }) => {
    await page.goto('/hotel/bookings');
    await page
        .getByRole('link', { name: /^FCL-\d{2}-\d{4}$/ })
        .locator('visible=true')
        .first()
        .click();
    await expect(page).toHaveURL(/\/hotel\/bookings\/[0-9A-Za-z]+$/);
    await expectAccessible(page);

    await page.getByRole('button', { name: 'Modifier le séjour' }).click();
    await expect(page.locator('#edit-in')).toBeVisible();
    await expectAccessible(page);

    await page
        .getByRole('button', { name: 'Annuler la réservation' })
        .first()
        .click();
    await expect(page.locator('#cancel-reason')).toBeVisible();
    await expectAccessible(page);
});

test('rooms, rates and staff forms', async ({ page }) => {
    for (const path of [
        '/hotel/rooms',
        '/hotel/rates',
        '/hotel/rates#settings',
        '/hotel/staff',
    ]) {
        await page.goto(path);
        await expect(page.locator('main')).toBeVisible();
        await expectAccessible(page);
    }

    await page.goto('/hotel/rates#settings');
    await expect(page.locator('#early_departure_charge')).toBeVisible();
});
