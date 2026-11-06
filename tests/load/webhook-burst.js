// 50 guest messages per second for 2 minutes: Meta delivering a burst.
// Target: no errors, webhook acknowledged in under 300 ms (p95).
import { check } from 'k6';
import { guestNumber, sendGuestMessage } from './lib/common.js';

export const options = {
    scenarios: {
        burst: {
            executor: 'constant-arrival-rate',
            rate: 50,
            timeUnit: '1s',
            duration: '2m',
            preAllocatedVUs: 60,
            maxVUs: 150,
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:webhook}': ['p(95)<300'],
    },
};

export default function () {
    const res = sendGuestMessage(guestNumber(Math.floor(Math.random() * 5000)));
    check(res, { 'acknowledged with 200': (r) => r.status === 200 });
}
