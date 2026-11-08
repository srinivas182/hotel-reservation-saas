import { expect, test } from '@playwright/test';
import { expectAccessible, guestWrites } from './helpers';

test.use({ storageState: 'tests/e2e/.auth/reception.json' });

test('reception sees the day and the bookings', async ({ page }) => {
    await page.goto('/hotel/today');
    await expect(
        page.getByRole('heading', { name: "Aujourd'hui" }),
    ).toBeVisible();
    await expectAccessible(page);

    await page.goto('/hotel/bookings');
    await expect(page).toHaveURL(/\/hotel\/bookings/);
    await expectAccessible(page);
});

test('a guest writes on WhatsApp and staff reply from the inbox', async ({
    page,
}, info) => {
    const stamp = `${info.project.name}-${Date.now()}`;
    const guest = '22507' + String(Date.now()).slice(-8);
    const name = `Invité ${stamp}`;
    const text = `Bonjour, avez-vous une chambre ce soir ? (${stamp})`;

    await guestWrites(page, guest, text, name);

    await page.goto('/hotel/inbox');
    await page.getByText(name).first().click({ timeout: 20_000 });
    await expect(page.getByText(text)).toBeVisible();
    await expectAccessible(page);

    const reply = `Oui, bienvenue ! (${stamp})`;
    await page.getByRole('textbox', { name: 'Répondre' }).fill(reply);
    await page.getByRole('button', { name: 'Envoyer' }).click();
    await expect(page.getByText(reply)).toBeVisible();
});

test('reception creates a booking', async ({ page }, info) => {
    await page.goto('/hotel/bookings/new');
    await page.locator('#room_category_id').selectOption({ index: 1 });
    const inDays = (n: number) =>
        new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);
    const offset = info.project.name === 'phone' ? 40 : 50;
    await page.locator('#check_in').fill(inDays(offset));
    await page.locator('#check_out').fill(inDays(offset + 2));
    const digits = String(Date.now()).slice(-6);
    await page
        .locator('#phone')
        .fill(
            `+225 07 99 ${digits.slice(0, 2)} ${digits.slice(2, 4)} ${digits.slice(4)}`,
        );
    await page.locator('#first_name').fill('Kouassi');
    await page.locator('#last_name').fill(`E2E ${info.project.name}`);
    await expectAccessible(page);

    await page
        .getByRole('button', { name: 'Enregistrer la réservation' })
        .click();
    await expect(page).toHaveURL(/\/hotel\/bookings\/[0-9A-Za-z]+$/);
    await expect(
        page.getByText(`E2E ${info.project.name}`).first(),
    ).toBeVisible();
    await expectAccessible(page);
});

test('the help panel shows the article for the screen', async ({ page }) => {
    await page.goto('/hotel/inbox');
    await page.getByRole('button', { name: 'Aide' }).click();
    await expect(
        page.getByRole('heading', {
            name: 'Répondre aux clients dans Messages',
        }),
    ).toBeVisible();
    await expectAccessible(page);
});

test('reception asks support for help, with suggested articles first', async ({
    page,
}, info) => {
    await page.goto('/hotel/requests');
    await page.getByLabel('Sujet').fill('Modèles WhatsApp en attente');
    await expect(
        page.getByText('Ces articles peuvent répondre à votre question :'),
    ).toBeVisible();
    await expectAccessible(page);
    await page
        .getByLabel('Que s’est-il passé ?')
        .fill(`Nos modèles restent en attente (${info.project.name}).`);
    await page.getByRole('button', { name: 'Envoyer la demande' }).click();
    await expect(page).toHaveURL(/\/hotel\/requests\/\d+$/);
    await expect(
        page.getByText(
            `Nos modèles restent en attente (${info.project.name}).`,
        ),
    ).toBeVisible();
    await expectAccessible(page);
});
