// A busy day compressed into 10 minutes: 1,000 guests, each writing three
// messages a few seconds apart (the 8-second grouping is exercised).
import { check, sleep } from 'k6';
import { guestNumber, sendGuestMessage } from './lib/common.js';

export const options = {
    scenarios: {
        guests: {
            executor: 'shared-iterations',
            vus: 40,
            iterations: 1000,
            maxDuration: '10m',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:webhook}': ['p(95)<300'],
    },
};

export default function () {
    const from = guestNumber(100000 + __ITER + __VU * 10000);
    for (let i = 0; i < 3; i++) {
        check(sendGuestMessage(from), {
            'acknowledged with 200': (r) => r.status === 200,
        });
        sleep(1 + Math.random() * 3);
    }
}
