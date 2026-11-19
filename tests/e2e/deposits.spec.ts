import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { expectAccessible } from './helpers';

/**
 * Sprint F: a guest pays the deposit from the link (simulated mobile
 * money), the booking confirms itself, and reception sees it paid.
 * e2e:prepare made one tentative booking per browser project.
 */
type Prepared = Record<
    string,
    { token: string; booking: string; reference: string }
>;

function prepared(project: string) {
    const all = JSON.parse(
        readFileSync('tests/e2e/.auth/deposits.json', 'utf8'),
    ) as Prepared;

    return all[project];
}

test.describe('the guest', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('pays the deposit by mobile money and gets the receipt', async ({
        page,
    }, info) => {
        const { token, reference } = prepared(info.project.name);

        await page.goto(`/pay/${token}`);
        await expect(
            page.getByRole('heading', { name: `Réservation ${reference}` }),
        ).toBeVisible();
        await expect(page.getByText(/bloquées jusqu’au/)).toBeVisible();
        await expectAccessible(page);

        await page.getByRole('radio', { name: /Tout le séjour/ }).check();
        await page
            .getByRole('button', { name: 'Payer par mobile money' })
            .click();

        // The simulated provider's checkout.
        await expect(
            page.getByText('Simulation : pas d’argent réel'),
        ).toBeVisible();
        await page.getByLabel('Payer avec').selectOption('orange_money');
        await page.getByRole('button', { name: 'Payer', exact: true }).click();

        await expect(page).toHaveURL(new RegExp(`/pay/${token}$`));
        await expect(
            page.getByText('Paiement reçu. Votre réservation est confirmée.'),
        ).toBeVisible();
        await expect(page.getByText(/par Orange Money · reçu R/)).toBeVisible();
        await expectAccessible(page);
    });
});

test.describe('reception', () => {
    test.use({ storageState: 'tests/e2e/.auth/reception.json' });

    test('sees the deposit paid on the booking and on the deposits page', async ({
        page,
    }, info) => {
        const { booking } = prepared(info.project.name);

        await page.goto(`/hotel/bookings/${booking}`);
        const panel = page.getByRole('region', { name: /Acompte/ });
        await expect(panel).toBeVisible();
        await expectAccessible(page);

        await page.goto('/hotel/deposits');
        await expect(
            page.getByRole('heading', { name: 'Acomptes', exact: true }),
        ).toBeVisible();
        await expectAccessible(page);
    });
});
