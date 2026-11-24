<?php
declare(strict_types=1);

namespace LiteBBS\Application;

use LiteBBS\Framework\Container;
use LiteBBS\Framework\Middleware;
use LiteBBS\Framework\Request;
use LiteBBS\Framework\Response;
use LiteBBS\Framework\ResponseHalt;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Coordinates database writes so every migrated use case has identical
 * commit/rollback behavior. It deliberately reuses the request PDO.
 */
final class TransactionManager
{
    public function __construct(private ?PDO $connection = null)
    {
    }

    public function run(callable $operation): mixed
    {
        $database = $this->connection ?? \db();
        $ownsTransaction = !$database->inTransaction();

        if ($ownsTransaction) {
            $database->beginTransaction();
        }

        try {
            $result = $operation($database);
            if ($ownsTransaction) {
                $database->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }
}

final class AuditLogger
{
    public function record(string $action, string $targetType, ?int $targetId, array $metadata = []): void
    {
        $actor = \current_user();
        \h(
            'INSERT INTO audit_logs(actor_id,action,target_type,target_id,metadata_json,ip_prefix_hash,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$actor['id'] ?? null, $action, $targetType, $targetId, json_encode($metadata, JSON_UNESCAPED_UNICODE), hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''))]
        );
    }
}

final class PasswordPolicy
{
    public const MIN_CHARACTERS = 6;
    public const MAX_BYTES = 12;

    public static function accepts(string $password): bool
    {
        return strlen($password) <= self::MAX_BYTES
            && !str_contains($password, "\0")
            && preg_match('/\A.{'.self::MIN_CHARACTERS.',}\z/su', $password) === 1;
    }
}

final class DuplicateAccountIdentity extends RuntimeException
{
    public static function matches(PDOException $error): bool
    {
        $info = $error->errorInfo;
        // MySQL can qualify the index with the table name. MariaDB can return just the index name.
        return ($info[0] ?? null) === '23000'
            && (int)($info[1] ?? 0) === 1062
            && preg_match("~\\bfor key ['`](?:users\\.)?(?:username|email)['`]\\s*$~i", (string)($info[2] ?? '')) === 1;
    }
}

/** Owns the atomic persistence boundary for public account registration. */
final class AccountRegistrationService
{
    public function register(string $username, string $email, string $password): int
    {
        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        return transaction_manager()->run(static function (PDO $database) use ($username, $email, $hash): int {
            try {
                \h(
                    'INSERT INTO users(username,email,password_hash,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',
                    [$username, $email, $hash]
                );
            } catch (PDOException $error) {
                if (!DuplicateAccountIdentity::matches($error)) {
                    throw $error;
                }
                throw new DuplicateAccountIdentity('Username or email is already in use.', 0, $error);
            }
            return (int)$database->lastInsertId();
        });
    }
}

/** Owns the small, asynchronous write made after a visitor has opened a topic. */
final class TopicViewService
{
    public function record(int $topicId): ?int
    {
        if (!$this->isNewVisit($topicId)) {
            return $this->current($topicId);
        }

        $updated = \h(
            'UPDATE topics AS t JOIN categories AS c ON c.id=t.category_id SET t.view_count=t.view_count+1 WHERE t.id=? AND t.status="normal" AND c.is_visible=1',
            [$topicId]
        )->rowCount();
        if ($updated !== 1) {
            return null;
        }

        // The home feed embeds view counts in its cached HTML and row cache.
        // Invalidate only after a deduplicated, successful increment.
        \invalidate('topic:'.$topicId);
        \invalidate('home-feed');
        return $this->current($topicId);
    }

    private function isNewVisit(int $topicId): bool
    {
        $redis = \redis_cache_client();
        if ($redis) {
            try {
                $viewer = \current_user();
                $identity = $viewer ? 'user:'.(int)$viewer['id'] : 'ip:'.hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
                return (bool)$redis->set(\shared_cache_key('topic-view-seen:'.$topicId.':'.$identity), '1', ['nx', 'ex'=>60]);
            } catch (Throwable $error) {
                \cache_error('topic view deduplication failed: '.$error->getMessage());
            }
        }
        return \anonymous_topic_view_is_new($topicId);
    }

    private function current(int $topicId): ?int
    {
        $views = \h(
            'SELECT t.view_count FROM topics AS t JOIN categories AS c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1',
            [$topicId]
        )->fetchColumn();
        return $views === false ? null : (int)$views;
    }

    public function read(array $topicIds): array
    {
        if (!$topicIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $rows = \h(
            'SELECT t.id,t.view_count FROM topics AS t JOIN categories AS c ON c.id=t.category_id WHERE t.id IN ('.$placeholders.') AND t.status="normal" AND c.is_visible=1',
            $topicIds
        )->fetchAll();
        $views = [];
        foreach ($rows as $row) {
            $views[(int)$row['id']] = (int)$row['view_count'];
        }
        return $views;
    }
}

final class TopicViewController
{
    public function __construct(private TopicViewService $views = new TopicViewService())
    {
    }

    public function __invoke(Request $request, Container $container, array $params): Response
    {
        $topicId = (int)($params['topicId'] ?? 0);
        if ($topicId < 1) {
            return new Response('', 404);
        }

        $views = $this->views->record($topicId);
        if ($views === null) {
            return new Response('', 404);
        }
        return (new Response())->json(['views'=>$views])->header('Cache-Control', 'no-store');
    }

    public function list(Request $request): Response
    {
        $rawIds = $request->query['ids'] ?? '';
        if (!is_string($rawIds)) {
            return new Response('', 400);
        }
        $topicIds = [];
        foreach (explode(',', $rawIds) as $rawId) {
            if (!ctype_digit($rawId) || (int)$rawId < 1) {
                return new Response('', 400);
            }
            $topicIds[(int)$rawId] = (int)$rawId;
        }
        $topicIds = array_values($topicIds);
        if (!$topicIds || count($topicIds) > 100) {
            return new Response('', 400);
        }
        return (new Response())->json(['views'=>$this->views->read($topicIds)])->header('Cache-Control', 'no-store');
    }
}

final class AccountRegistrationController
{
    public function __construct(private AccountRegistrationService $registration = new AccountRegistrationService())
    {
    }

    public function __invoke(Request $request): void
    {
        if (\setting('registration', '1') !== '1') {
            \fail(403, '本站当前未开放注册。');
        }

        $message = '加入这个社区';
        $passwordHint = '至少 '.PasswordPolicy::MIN_CHARACTERS.' 个字符，最多 '.PasswordPolicy::MAX_BYTES.' 字节';
        if ($request->method === 'POST') {
            \require_post();
            \limit_action('register', 3, 3600, hash('sha256', (string)($request->server['REMOTE_ADDR'] ?? '')));

            $username = $request->post['username'] ?? null;
            $username = is_string($username) ? trim($username) : '';
            $email = $request->post['email'] ?? null;
            $email = is_string($email) ? trim($email) : '';
            $password = $request->post['password'] ?? '';
            if (!preg_match('/\A[\pL\pN_-]{3,32}\z/u', $username)
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !is_string($password) || !PasswordPolicy::accepts($password)) {
                $message = '请检查用户名、邮箱和密码。密码'.$passwordHint.'。';
            } else {
                try {
                    $registeredId = $this->registration->register($username, $email, $password);
                } catch (DuplicateAccountIdentity) {
                    http_response_code(409);
                    $message = '用户名或邮箱已使用。';
                }

                if (isset($registeredId)) {
                    \extension_event_async('user.registered', ['user_id'=>$registeredId]);
                    \login_user($registeredId);
                    \redirect('/');
                }
            }
        }

        \page('注册', '<p class="error">'.\e($message).'</p>'.\form('/register', '创建账户',
            \input('username', '用户名（3-32 字符）', 'text', 'minlength="3" maxlength="32"')
            .\input('email', '邮箱', 'email', 'maxlength="254"')
            .\input('password', '密码（'.$passwordHint.'）', 'password', 'minlength="'.PasswordPolicy::MIN_CHARACTERS.'" maxlength="'.PasswordPolicy::MAX_BYTES.'" autocomplete="new-password"'),
            '注册'));
    }
}

/** Applies authorization once for the complete administration route space. */
final class AdminAuthorizationMiddleware implements Middleware
{
    public function process(Request $request, callable $next, Container $container): ?Response
    {
        if ($request->path !== '/admin' && !str_starts_with($request->path, '/admin/')) {
            return $next($request);
        }

        $user = \current_user();
        if ($user === null) {
            \redirect('/login');
        }
        if (($user['role'] ?? '') !== 'admin') {
            \fail(403, '无权访问');
        }

        return $next($request);
    }
}

/** Records only failed or slow requests, keeping the common path allocation-free. */
final class RequestObservabilityMiddleware implements Middleware
{
    public function process(Request $request, callable $next, Container $container): ?Response
    {
        $startedAt = microtime(true);
        try {
            $response = $next($request);
            \request_observe($request, $response?->status ?? 404, $startedAt);
            return $response;
        } catch (ResponseHalt $halt) {
            \request_observe($request, $halt->response->status, $startedAt);
            throw $halt;
        } catch (Throwable $error) {
            \request_observe($request, 500, $startedAt);
            error_log('request failed path='.$request->path.' error='.$error->getMessage());
            throw $error;
        }
    }
}

/**
 * Transitional adapter: legacy handlers retain their response contract while
 * route registration and output ownership move into an object boundary.
 */
final class LegacyActionController
{
    public function __construct(private string $file, private string $handler)
    {
    }

    public function __invoke(Request $request): Response
    {
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            require_once __DIR__.'/'.$this->file;
            ($this->handler)($request);
            while (ob_get_level() > $bufferLevel + 1) ob_end_flush();
            $body = (string)ob_get_clean();
            return new Response($body, http_response_code() ?: 200);
        } catch (ResponseHalt $halt) {
            return $halt->response;
        } finally {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
        }
    }
}

function transaction_manager(): TransactionManager
{
    static $manager;
    return $manager ??= new TransactionManager();
}

function audit_logger(): AuditLogger
{
    static $logger;
    return $logger ??= new AuditLogger();
}
