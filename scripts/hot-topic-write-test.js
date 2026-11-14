import http from 'k6/http';
import { check, sleep } from 'k6';

const base = __ENV.BASE_URL;
const topicId = __ENV.TOPIC_ID;
const csrf = __ENV.CSRF_TOKEN;
const cookie = __ENV.SESSION_COOKIE;
if (!base || !topicId || !csrf || !cookie) throw new Error('Set BASE_URL, TOPIC_ID, CSRF_TOKEN, and SESSION_COOKIE for staging.');

export const options = {
  scenarios: {
    hot_topic_replies: {
      executor: 'ramping-arrival-rate',
      startRate: 1,
      timeUnit: '1s',
      preAllocatedVUs: 20,
      maxVUs: 200,
      stages: [
        { target: 10, duration: '1m' },
        { target: 50, duration: '3m' },
        { target: 100, duration: '3m' },
        { target: 0, duration: '1m' },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<1000', 'p(99)<2000'],
  },
};

export default function () {
  const response = http.post(`${base}/t/${topicId}/reply`, {
    csrf,
    body: `load-test reply ${__VU}-${__ITER}`,
  }, { headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, redirects: 0 });
  check(response, { 'reply accepted or throttled': (r) => [303, 429].includes(r.status) });
  sleep(0.1);
}
