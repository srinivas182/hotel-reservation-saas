import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

// Sprint I: the sign-in and sign-up screens, in light and dark mode.
for (const colorScheme of ['light', 'dark'] as const) {
    test.describe(`entry screens (${colorScheme})`, () => {
        test.use({ colorScheme });

        test('the sign-in page', async ({ page }) => {
            await page.goto('/login');
            await expect(
                page.getByRole('heading', {
                    name: 'Connectez-vous à votre hôtel',
                }),
            ).toBeVisible();
            await expect(
                page.getByRole('link', { name: /Créer un compte hôtel/ }),
            ).toBeVisible();
            await expectAccessible(page);
        });

        test('sign-up goes step by step, with the WhatsApp greeting preview', async ({
            page,
        }) => {
            await page.goto('/signup');
            const next = page.getByRole('button', { name: 'Continuer' });
            await expect(next).toBeDisabled();
            await expectAccessible(page);

            await page.getByLabel('Prénom').fill('Awa');
            await page
                .getByLabel('E-mail', { exact: true })
                .fill('awa@example.com');
            await page
                .getByLabel('Mot de passe', { exact: true })
                .fill('un-mot-de-passe');
            await page
                .getByLabel('Confirmer le mot de passe')
                .fill('un-mot-de-passe');
            await next.click();

            await page.getByLabel('Nom de l’hôtel').fill('Hôtel du Lac');
            await page.getByLabel('Ville').fill('Yamoussoukro');
            await expect(
                page.getByText(/Hôtel du Lac \(Yamoussoukro\)/),
            ).toBeVisible();
            await expectAccessible(page);
            await next.click();

            await expect(
                page.getByRole('button', { name: 'Créer mon compte' }),
            ).toBeDisabled();
            await expectAccessible(page);
            await page.getByRole('button', { name: 'Retour' }).click();
            await expect(page.getByLabel('Ville')).toHaveValue('Yamoussoukro');
        });
    });
}
