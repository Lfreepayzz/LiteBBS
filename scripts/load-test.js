import http from 'k6/http';
import { check, sleep } from 'k6';

const base = __ENV.BASE_URL;
const topicId = __ENV.TOPIC_ID;
const categoryId = __ENV.CATEGORY_ID;
const hotTopicId = __ENV.HOT_TOPIC_ID || topicId;
const sessionCookie = __ENV.SESSION_COOKIE;

function latencyBudget(route, defaultP95, defaultP99) {
  const p95 = Number(__ENV[`${route}_P95_MS`] || defaultP95);
  const p99 = Number(__ENV[`${route}_P99_MS`] || defaultP99);
  if (!Number.isFinite(p95) || !Number.isFinite(p99) || p95 <= 0 || p99 < p95) {
    throw new Error(`Invalid latency budget for ${route}.`);
  }
  return [`p(95)<${p95}`, `p(99)<${p99}`];
}

if (!base || !topicId || !categoryId) {
  throw new Error('Set BASE_URL, TOPIC_ID, and CATEGORY_ID for staging data.');
}

export const options = {
  scenarios: {
    forum_reads: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '2m', target: 25 },
        { duration: '5m', target: 25 },
        { duration: '1m', target: 100 },
        { duration: '5m', target: 100 },
        { duration: '2m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
    hot_topic_reads: {
      executor: 'constant-arrival-rate',
      rate: 50,
      timeUnit: '1s',
      duration: '5m',
      preAllocatedVUs: 20,
      maxVUs: 100,
      exec: 'hotTopicRead',
    },
    anonymous_home_reads: {
      executor: 'constant-arrival-rate',
      rate: 100,
      timeUnit: '1s',
      duration: '5m',
      preAllocatedVUs: 20,
      maxVUs: 100,
      exec: 'publicHomeRead',
    },
    ...(sessionCookie ? { signed_in_reads: {
      executor: 'constant-vus', vus: 1, duration: '5m', exec: 'signedInRead',
    } } : {}),
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    checks: ['rate==1'],
    dropped_iterations: ['count==0'],
    'http_req_duration{route:home}': latencyBudget('HOME', 500, 1500),
    'http_req_duration{route:category}': latencyBudget('CATEGORY', 500, 1500),
    'http_req_duration{route:topic}': latencyBudget('TOPIC', 500, 1500),
    'http_req_duration{route:hot_topic}': latencyBudget('HOT_TOPIC', 500, 1500),
    ...(sessionCookie ? {
      'http_req_duration{route:signed_in_home}': latencyBudget('SIGNED_IN_HOME', 500, 1500),
    } : {}),
  },
};

export default function () {
  const responses = http.batch([
    ['GET', `${base}/`, null, { redirects: 0, tags: { route: 'home' } }],
    ['GET', `${base}/c/${categoryId}`, null, { redirects: 0, tags: { route: 'category' } }],
    ['GET', `${base}/t/${topicId}`, null, { redirects: 0, tags: { route: 'topic' } }],
  ]);
  check(responses[0], { 'home responds successfully': (r) => r.status === 200 });
  check(responses[1], { 'category responds successfully': (r) => r.status === 200 });
  check(responses[2], { 'topic responds successfully': (r) => r.status === 200 });
  sleep(1);
}

export function hotTopicRead() {
  const response = http.get(`${base}/t/${hotTopicId}`, { redirects: 0, tags: { route: 'hot_topic' } });
  check(response, { 'hot topic responds successfully': (r) => r.status === 200 });
}

export function publicHomeRead() {
  const response = http.get(`${base}/`, { redirects: 0, tags: { route: 'home' } });
  check(response, {
    'anonymous home responds successfully': (r) => r.status === 200,
    'anonymous home opts out of HTTP caching': (r) => {
      const directives = (r.headers['Cache-Control'] || '').toLowerCase().split(',')
        .map((directive) => directive.split('=', 1)[0].trim());
      return directives.includes('private') && directives.includes('no-store')
        && !directives.includes('public') && !directives.includes('s-maxage');
    },
  });
}

export function signedInRead() {
  const response = http.get(`${base}/`, {
    redirects: 0,
    tags: { route: 'signed_in_home' },
    headers: { Cookie: sessionCookie, 'User-Agent': __ENV.SESSION_USER_AGENT || 'LiteBBS staging test' },
  });
  check(response, {
    'signed-in home renders the member action': (r) => r.status === 200 && r.body.includes('class="feed-create" href="/new"'),
  });
  sleep(1);
}
