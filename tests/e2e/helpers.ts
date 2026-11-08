import AxeBuilder from '@axe-core/playwright';
import { createHmac } from 'node:crypto';
import { expect, type Page } from '@playwright/test';

export const RECEPTION = {
    email: 'reception.lodge@example.com',
    password: 'password',
};
export const ADMIN = {
    email: 'admin.e2e@example.com',
    password: 'password',
    secret: 'JBSWY3DPEHPK3PXP',
};
export const PHONE_NUMBER_ID = 'SIM-5550001';
const APP_SECRET = process.env.WHATSAPP_APP_SECRET || 'simulation-secret';

export async function logIn(
    page: Page,
    user: { email: string; password: string },
): Promise<void> {
    await page.goto('/login');
    await page.locator('#email').fill(user.email);
    await page.locator('#password').fill(user.password);
    await page.getByRole('button', { name: 'Se connecter' }).click();
}

/** Six-digit code for the two-step login (RFC 6238, 30-second steps). */
export function totp(secret: string, at = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '')) {
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return String(code).padStart(6, '0');
}

export async function logInAsAdmin(page: Page): Promise<void> {
    await logIn(page, ADMIN);
    await page.waitForURL(/two-factor-challenge/);
    await page
        .locator('input[name="code"], input[autocomplete="one-time-code"]')
        .first()
        .fill(totp(ADMIN.secret));
    await page
        .getByRole('button', {
            name: /continuer|connexion|valider|se connecter/i,
        })
        .click();
}

/** A guest's WhatsApp message, signed like Meta signs it. */
export async function guestWrites(
    page: Page,
    from: string,
    text: string,
    name = 'Invité E2E',
): Promise<void> {
    const body = JSON.stringify({
        object: 'whatsapp_business_account',
        entry: [
            {
                id: 'sim-waba',
                changes: [
                    {
                        field: 'messages',
                        value: {
                            messaging_product: 'whatsapp',
                            metadata: { phone_number_id: PHONE_NUMBER_ID },
                            contacts: [{ wa_id: from, profile: { name } }],
                            messages: [
                                {
                                    from,
                                    id: `wamid.E2E${Date.now()}`,
                                    timestamp: String(
                                        Math.floor(Date.now() / 1000),
                                    ),
                                    type: 'text',
                                    text: { body: text },
                                },
                            ],
                        },
                    },
                ],
            },
        ],
    });
    const signature =
        'sha256=' + createHmac('sha256', APP_SECRET).update(body).digest('hex');
    const response = await page.request.post('/webhooks/whatsapp', {
        data: body,
        headers: {
            'Content-Type': 'application/json',
            'X-Hub-Signature-256': signature,
        },
    });
    expect(response.status()).toBe(200);
}

/** No serious or critical accessibility problems (WCAG 2.1 A and AA). */
export async function expectAccessible(page: Page): Promise<void> {
    // Toasts are left out: axe measures them mid fade-in (half transparent) and
    // reports low contrast that people never see; their colours are the
    // popover colours, which pass. Everything else on the page is checked.
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .exclude('[data-sonner-toaster]')
        .analyze();
    const problems = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical',
    );
    expect(
        problems.map(
            (v) =>
                `${v.id}: ${v.help} (${v.nodes.length}) ${v.nodes
                    .slice(0, 2)
                    .map((n) => n.target.join(' '))
                    .join(' | ')}`,
        ),
    ).toEqual([]);
}
