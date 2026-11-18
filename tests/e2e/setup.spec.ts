import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

test.use({ storageState: 'tests/e2e/.auth/owner.json' });

test('the owner starts from pasted text and uses what was found', async ({
    page,
}, info) => {
    // A hotel has one import at a time: run it on one device only.
    test.skip(info.project.name !== 'phone', 'one import per hotel at a time');

    await page.goto('/hotel/setup');
    await expect(
        page.getByRole('heading', { name: 'Partir de ce que vous avez déjà' }),
    ).toBeVisible();

    const again = page.getByRole('button', {
        name: 'Recommencer avec d’autres éléments',
    });
    if (await again.isVisible()) {
        await again.click();
    }

    await page
        .getByRole('textbox', { name: /Texte sur votre hôtel/ })
        .fill(
            'Chambre Standard 20 000 FCFA, Suite 50 000 FCFA. Petit-déjeuner 3 000 FCFA. Parking gardé.',
        );
    await page.getByRole('button', { name: 'Lisez-le pour moi' }).click();

    // Read by a background worker; the page checks every few seconds.
    await expect(page.getByText('Voici ce que nous avons trouvé')).toBeVisible({
        timeout: 45_000,
    });
    await expectAccessible(page);

    // Keep the pilot hotel's rooms as they are: use the answers only.
    for (const box of await page
        .getByRole('checkbox', { name: 'Utiliser ce type de chambre' })
        .all()) {
        await box.uncheck();
    }
    await page.getByRole('button', { name: /^Utiliser \(\d+\)$/ }).click();
    await expect(page.getByText(/C.est fait/)).toBeVisible();
    await expect(page.getByText('Voici ce que nous avons trouvé')).toHaveCount(
        0,
    );
});
