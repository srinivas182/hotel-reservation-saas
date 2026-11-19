import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

/**
 * Sprint G: the owner sets what the arrival card says the day before.
 */
test.use({ storageState: 'tests/e2e/.auth/owner.json' });

test('the owner sets the directions and the extras of the arrival card', async ({
    page,
}, info) => {
    await page.goto('/hotel/arrival-settings');
    await expect(
        page.getByRole('heading', { name: 'Carte d’arrivée', exact: true }),
    ).toBeVisible();
    await expectAccessible(page);

    const directions = `En face de la Grande Mosquée, portail bleu (${info.project.name})`;
    await page
        .getByRole('group', { name: 'Comment trouver l’hôtel' })
        .getByRole('textbox')
        .first()
        .fill(directions);
    await page
        .getByRole('checkbox', { name: 'Navette (gare, aéroport)' })
        .check();
    await page.getByRole('button', { name: 'Sauvegarder' }).click();

    await expect(
        page.getByText('Réglages de l’arrivée enregistrés.'),
    ).toBeVisible();
    await page.reload();
    await expect(
        page
            .getByRole('group', { name: 'Comment trouver l’hôtel' })
            .getByRole('textbox')
            .first(),
    ).toHaveValue(/Grande Mosquée, portail bleu/);
});
