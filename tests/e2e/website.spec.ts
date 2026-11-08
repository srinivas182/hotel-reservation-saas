import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

test('the website in French: home, prices, demo request', async ({
    page,
}, info) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectAccessible(page);

    await page
        .getByRole('navigation', { name: 'Principal' })
        .getByRole('link', { name: 'Tarifs' })
        .click();
    await expect(page).toHaveURL(/\/pricing/);
    await expectAccessible(page);

    await page.goto('/demo');
    await expectAccessible(page);
    await page.getByLabel('Votre nom').fill('Moussa Koné');
    await page
        .getByLabel(/Nom de l.hôtel/)
        .fill(`Hôtel E2E ${info.project.name}`);
    await page.getByLabel('Numéro WhatsApp').fill('+221 77 123 45 67');
    await page.getByRole('button', { name: 'Envoyer ma demande' }).click();
    await expect(page.getByRole('status')).toContainText('Merci');
});

test('the website in English', async ({ page }) => {
    await page.goto('/en/features');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expectAccessible(page);
});
