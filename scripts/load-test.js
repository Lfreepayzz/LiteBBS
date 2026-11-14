import http from 'k6/http';
import { check, sleep } from 'k6';

const base = __ENV.BASE_URL;
const topicId = __ENV.TOPIC_ID;
const categoryId = __ENV.CATEGORY_ID;
const hotTopicId = __ENV.HOT_TOPIC_ID || topicId;

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
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500', 'p(99)<1500'],
  },
};

export default function () {
  const responses = http.batch([
    ['GET', `${base}/`],
    ['GET', `${base}/c/${categoryId}`],
    ['GET', `${base}/t/${topicId}`],
  ]);
  check(responses[0], { 'home responds successfully': (r) => r.status === 200 });
  check(responses[1], { 'category responds successfully': (r) => r.status === 200 });
  check(responses[2], { 'topic responds successfully': (r) => r.status === 200 });
  sleep(1);
}

export function hotTopicRead() {
  const response = http.get(`${base}/t/${hotTopicId}`);
  check(response, { 'hot topic responds successfully': (r) => r.status === 200 });
}
