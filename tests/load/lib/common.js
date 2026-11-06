// Shared helpers for the k6 load tests. Configuration comes from the
// environment (k6 run -e NAME=value):
//   BASE_URL         the staging site, e.g. https://staging.example.com
//   APP_SECRET       WHATSAPP_APP_SECRET of that site (to sign webhooks)
//   PHONE_NUMBER_ID  simulated hotel number (simulation:prepare prints it)
//   STAFF_EMAIL / STAFF_PASSWORD   a reception login on that site
//   INERTIA_JSON     set to 1 when the frontend is not built (CI)
import crypto from 'k6/crypto';
import http from 'k6/http';

export const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(
    /\/$/,
    '',
);
// INERTIA_JSON=1 asks for page data as JSON (CI, where the frontend is not
// built). On staging leave it unset: full pages are loaded, as staff would.
const PAGE_HEADERS = __ENV.INERTIA_JSON ? { 'X-Inertia': 'true' } : {};
const SECRET = __ENV.APP_SECRET || 'simulation-secret';
const PHONE_NUMBER_ID = __ENV.PHONE_NUMBER_ID || 'SIM-5550001';

const LINES = [
    'Bonjour',
    'Vous avez une chambre pour ce week-end ?',
    'C’est combien la nuit ?',
    'Je voudrais réserver',
    'Hello, do you have a room tonight?',
    'Le petit-déjeuner est inclus ?',
];

export function guestNumber(n) {
    return '22507' + String(n % 100000000).padStart(8, '0');
}

/**
 * Send one signed guest message to the WhatsApp webhook, as Meta would.
 */
export function sendGuestMessage(from, text) {
    const id = `wamid.K6${Date.now()}${Math.floor(Math.random() * 1e9)}`;
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
                            contacts: [
                                {
                                    wa_id: from,
                                    profile: { name: 'Load test guest' },
                                },
                            ],
                            messages: [
                                {
                                    from,
                                    id,
                                    timestamp: String(
                                        Math.floor(Date.now() / 1000),
                                    ),
                                    type: 'text',
                                    text: {
                                        body:
                                            text ||
                                            LINES[
                                                Math.floor(
                                                    Math.random() *
                                                        LINES.length,
                                                )
                                            ],
                                    },
                                },
                            ],
                        },
                    },
                ],
            },
        ],
    });

    return http.post(`${BASE_URL}/webhooks/whatsapp`, body, {
        headers: {
            'Content-Type': 'application/json',
            'X-Hub-Signature-256':
                'sha256=' + crypto.hmac('sha256', SECRET, body, 'hex'),
        },
        tags: { name: 'webhook' },
    });
}

/**
 * Log in as staff. Uses Inertia JSON requests so no built frontend is needed.
 */
export function login() {
    const jar = http.cookieJar();
    http.get(`${BASE_URL}/login`, {
        headers: PAGE_HEADERS,
        tags: { name: 'login-page' },
    });
    const xsrf = decodeURIComponent(
        (jar.cookiesForURL(BASE_URL)['XSRF-TOKEN'] || [''])[0],
    );

    return http.post(
        `${BASE_URL}/login`,
        JSON.stringify({
            email: __ENV.STAFF_EMAIL || 'reception.lodge@example.com',
            password: __ENV.STAFF_PASSWORD || 'password',
        }),
        {
            headers: {
                ...PAGE_HEADERS,
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf,
            },
            redirects: 5,
            tags: { name: 'login' },
        },
    );
}

export function page(path) {
    return http.get(`${BASE_URL}${path}`, {
        headers: PAGE_HEADERS,
        tags: { name: path },
    });
}
