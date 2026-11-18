import { expect, test } from '@playwright/test';
import { expectAccessible, logIn } from './helpers';

/**
 * Sprint E: a new receptionist gets the one-minute welcome tour once,
 * the simple dashboard, and can replay the tour from the user menu.
 * Each browser project has its own newcomer (see e2e:prepare), so the
 * two projects never share the tour state.
 */
test('a newcomer gets the welcome tour once and the simple dashboard', async ({
    page,
}, info) => {
    const isPhone = info.project.name === 'phone';
    await logIn(page, {
        email: isPhone
            ? 'new.phone.lodge@example.com'
            : 'new.desktop.lodge@example.com',
        password: 'password',
    });
    await page.waitForURL(/\/hotel\/today/);

    const tour = page.getByRole('dialog', { name: 'Bienvenue' });
    await expect(tour).toBeVisible();
    await expectAccessible(page);

    await tour.getByRole('button', { name: 'Suivant' }).click();
    await expect(
        page.getByRole('dialog', { name: 'Quoi faire ensuite' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Passer' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    if (isPhone) {
        // Simple mode on a phone: three big tabs at the bottom.
        const tabs = page.getByRole('navigation', { name: 'Principal' });
        await expect(
            tabs.getByRole('link', { name: "Aujourd'hui" }),
        ).toBeVisible();
        await expect(
            tabs.getByRole('link', { name: 'Messages' }),
        ).toBeVisible();
        await expect(
            tabs.getByRole('link', { name: 'Réservations' }),
        ).toBeVisible();
    }

    // Seen once: it does not come back.
    await page.reload();
    await expect(
        page.getByRole('heading', { name: "Aujourd'hui" }),
    ).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expectAccessible(page);
});
