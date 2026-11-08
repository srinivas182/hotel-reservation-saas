// Mixed load at scale (S16): WhatsApp messages arriving at a steady rate
// while staff use the busy screens.
//
//   SCALE=small  weekly in CI (2 messages/s, 10 staff, 2 minutes)
//   SCALE=full   on staging   (20 messages/s, 100 staff, 10 minutes)
//
// Targets (full): 95% of pages under 500 ms, webhooks answered under 1 s,
// under 1% errors. The small run uses looser page targets: the CI runner
// serves pages with PHP's built-in server.
import { check, sleep } from 'k6';
import {
    guestNumber,
    page,
    sendGuestMessage,
    sharedSession,
    useSession,
} from './lib/common.js';

const FULL = (__ENV.SCALE || 'small') === 'full';

export const options = {
    scenarios: {
        guests: {
            executor: 'constant-arrival-rate',
            rate: FULL ? 20 : 2,
            timeUnit: '1s',
            duration: FULL ? '10m' : '2m',
            preAllocatedVUs: FULL ? 60 : 6,
            maxVUs: FULL ? 200 : 20,
            exec: 'guest',
        },
        staff: {
            executor: 'constant-vus',
            vus: FULL ? 100 : 10,
            duration: FULL ? '10m' : '2m',
            exec: 'staff',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:webhook}': [FULL ? 'p(95)<1000' : 'p(95)<1500'],
        'http_req_duration{name:/hotel/today}': [
            FULL ? 'p(95)<500' : 'p(95)<2500',
        ],
        'http_req_duration{name:/hotel/inbox}': [
            FULL ? 'p(95)<500' : 'p(95)<2500',
        ],
        'http_req_duration{name:/hotel/bookings}': [
            FULL ? 'p(95)<500' : 'p(95)<2500',
        ],
    },
};

export function setup() {
    const session = sharedSession();
    if (!session.ok) {
        throw new Error('Staff login failed');
    }

    return session;
}

export function guest() {
    const from = guestNumber(200000 + Math.floor(Math.random() * 5000));
    check(sendGuestMessage(from), {
        'webhook acknowledged': (r) => r.status === 200,
    });
}

export function staff(session) {
    // k6 clears each virtual user's cookies between iterations: restore the session every time.
    useSession(session);
    for (const path of ['/hotel/today', '/hotel/inbox', '/hotel/bookings']) {
        check(page(path), { [`${path} ok`]: (r) => r.status === 200 });
        sleep(2 + Math.random() * 3);
    }
}
