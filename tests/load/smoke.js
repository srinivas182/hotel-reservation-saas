// CI smoke test: proves the scripts and the simulated stack work end to end.
// A few guest messages and one receptionist; no performance target.
import { check, sleep } from 'k6';
import { guestNumber, login, page, sendGuestMessage } from './lib/common.js';

export const options = {
    scenarios: {
        guests: {
            executor: 'shared-iterations',
            vus: 2,
            iterations: 6,
            exec: 'guests',
        },
        staff: {
            executor: 'per-vu-iterations',
            vus: 1,
            iterations: 1,
            exec: 'staff',
            startTime: '3s',
        },
    },
    thresholds: {
        checks: ['rate==1.0'],
    },
};

export function guests() {
    check(sendGuestMessage(guestNumber(__ITER)), {
        'webhook 200': (r) => r.status === 200,
    });
}

export function staff() {
    check(login(), { 'logged in': (r) => r.status === 200 });
    sleep(1);
    for (const path of ['/hotel/today', '/hotel/inbox', '/hotel/calendar']) {
        check(page(path), { [`${path} 200`]: (r) => r.status === 200 });
    }
}
