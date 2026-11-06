// 20 receptionists using the dashboard at once for 5 minutes.
// Target: 95% of pages in under 800 ms, no errors.
import { check, sleep } from 'k6';
import { login, page } from './lib/common.js';

export const options = {
    scenarios: {
        staff: { executor: 'constant-vus', vus: 20, duration: '5m' },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{name:/hotel/today}': ['p(95)<800'],
        'http_req_duration{name:/hotel/inbox}': ['p(95)<800'],
        'http_req_duration{name:/hotel/calendar}': ['p(95)<800'],
    },
};

export function setup() {
    return {};
}

export default function () {
    if (__ITER === 0) {
        check(login(), { 'logged in': (r) => r.status === 200 });
    }

    for (const path of ['/hotel/today', '/hotel/inbox', '/hotel/calendar']) {
        check(page(path), { [`${path} ok`]: (r) => r.status === 200 });
        sleep(2 + Math.random() * 3);
    }
}
