<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/core.php';
require_once dirname(__DIR__).'/app/feed-cursor.php';
require_once dirname(__DIR__).'/app/topic-cursor.php';

use LiteBBS\Framework\Container;
use LiteBBS\Framework\Kernel;
use LiteBBS\Framework\Request;
use LiteBBS\Framework\Response;
use LiteBBS\Framework\Router;
use LiteBBS\Application\PasswordPolicy;
use LiteBBS\Application\FeedCursor;
use LiteBBS\Application\TopicCursor;

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

$request = new Request('GET', '/hello', [], [], [], [], [], []);
$router = new Router();
$router->add('GET', '~^/hello$~', static fn(): Response => new Response('world'));
$kernel = new Kernel(new Container(), $router);
assert_same('world', $kernel->handle($request)?->body, 'Kernel must dispatch a matching route.');

$methodRouter = new Router();
$methodRouter->add('POST', '~^/hello$~', static fn(): Response => new Response('created'));
$methodResponse = $methodRouter->dispatch($request, new Container());
assert_same(405, $methodResponse?->status, 'Router must reject unsupported methods.');
assert_same('POST', $methodResponse?->headers()['Allow'] ?? null, '405 response must advertise allowed methods.');

$badHeaderRejected = false;
try {
    (new Response())->header('X-Test', "value\r\nInjected: yes");
} catch (InvalidArgumentException) {
    $badHeaderRejected = true;
}
assert_same(true, $badHeaderRejected, 'Response must reject header injection.');

assert_same(true, PasswordPolicy::accepts('123456'), 'Password policy must accept six-character passwords.');
assert_same(true, PasswordPolicy::accepts('123456789012'), 'Password policy must accept twelve-character passwords.');
assert_same(false, PasswordPolicy::accepts('12345'), 'Password policy must reject passwords below six characters.');
assert_same(false, PasswordPolicy::accepts('1234567890123'), 'Password policy must reject passwords above twelve characters.');

$cursor = FeedCursor::fromTopic(['is_pinned'=>'1', 'last_post_at'=>'2026-11-24 10:20:30.123456', 'id'=>'42']);
assert_same(['p'=>1, 'd'=>'2026-11-24 10:20:30.123456', 'i'=>42], FeedCursor::decode($cursor), 'Feed cursor must preserve pagination fields.');
foreach ([['invalid'], str_repeat('a', 257), base64_encode('{"p":1,"d":"2026-11-24 10:20:30","i":"42"}')] as $invalidCursor) {
    $rejected = false;
    try {
        FeedCursor::decode($invalidCursor);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    assert_same(true, $rejected, 'Feed cursor must reject invalid query shapes and fields.');
}

$topicCursor = new TopicCursor('local-test-secret');
$signature = $topicCursor->signature(7, 100, 20, 1, 32);
assert_same(true, $topicCursor->accepts($signature, 7, 100, 20, 1, 32), 'Signed topic floor must round-trip.');
assert_same(false, $topicCursor->accepts($signature, 7, 100, 20, 2, 32), 'Deleting a post must invalidate older floor cursors.');
assert_same(false, $topicCursor->accepts($signature, 7, 100, 20, 1, 33), 'A newly committed reply must invalidate older floor cursors.');
assert_same(false, $topicCursor->accepts($signature, 7, 100, 21, 1, 32), 'Changing the floor must invalidate the signature.');
assert_same(false, $topicCursor->accepts($signature, 8, 100, 20, 1, 32), 'A cursor must be bound to the topic.');

echo "Framework tests passed.\n";
