import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate } from 'k6/metrics';

const base = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const topicId = __ENV.TOPIC_ID;
const csrf = __ENV.CSRF_TOKEN;
const cookie = __ENV.SESSION_COOKIE;
if (!base || !topicId || !csrf || !cookie) throw new Error('Set BASE_URL, TOPIC_ID, CSRF_TOKEN, and SESSION_COOKIE for staging.');
if (!/^[1-9][0-9]*$/.test(topicId)) throw new Error('TOPIC_ID must be a positive integer.');

const origin = /^(https?:\/\/(?:\[[0-9a-f:.]+\]|[^\s/:@?#\\]+)(?::[0-9]+)?)(?=\/|$)/i.exec(base);
if (!origin || /[\s?#\\]/.test(base)) throw new Error('BASE_URL must be an HTTP(S) URL without credentials, a query, or a fragment.');

const replyLocation = new RegExp(`^/t/${topicId}#p[1-9][0-9]*$`);
const replySuccess = new Rate('reply_success');

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
    reply_success: ['rate>0.98'],
  },
};

export default function () {
  const response = http.post(`${base}/t/${topicId}/reply`, {
    csrf,
    body: `load-test reply ${__VU}-${__ITER}`,
  }, {
    headers: { Cookie: cookie, Origin: origin[1], 'Content-Type': 'application/x-www-form-urlencoded' },
    redirects: 0,
  });
  const accepted = check(response, {
    'reply redirects to the created post': (r) => r.status === 303 && replyLocation.test(r.headers.Location || ''),
  });
  replySuccess.add(accepted);
  sleep(0.1);
}
