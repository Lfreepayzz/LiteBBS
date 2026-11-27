<?php
declare(strict_types=1);

/** Single core file: configuration, framework, public routes, and services. */

namespace {
function config_directory(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $dir = dirname(__DIR__).'/database';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建 database 配置目录');
    }
    if (function_exists('chmod')) @chmod($dir, 0700);
    return $dir;
}

function config_file(): string { return config_directory().'/config.php'; }

function &config_runtime_cache(): array
{
    static $state = ['loaded' => false, 'data' => []];
    return $state;
}

function install_lock_file(): string
{
    return config_directory().'/install.lock';
}

function write_install_lock(): void
{
    $path = config_directory().'/install.lock';
    $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
    if (@file_put_contents($tmp, bin2hex(random_bytes(32))."\n", LOCK_EX) === false) throw new RuntimeException('无法写入 database 安装锁');
    if (function_exists('chmod')) @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('无法原子写入 database 安装锁'); }
    if (function_exists('chmod')) @chmod($path, 0600);
}

function load_config(): array
{
    $state =& config_runtime_cache();
    if ($state['loaded']) return $state['data'];
    $file = config_file();
    if (!is_file($file)) { $state = ['loaded' => true, 'data' => []]; return []; }
    $data = require $file;
    if (!is_array($data)) throw new RuntimeException('站点配置格式无效');
    $state = ['loaded' => true, 'data' => $data];
    return $data;
}

function write_config(array $config): void
{
    $dir = config_directory();
    $path = $dir.'/config.php';
    $tmp = $dir.'/config.php.'.bin2hex(random_bytes(8)).'.tmp';
    $contents = "<?php\nreturn ".var_export($config, true).";\n";
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) throw new RuntimeException('配置写入失败');
    if (function_exists('chmod')) @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('配置原子替换失败'); }
    if (function_exists('chmod')) @chmod($path, 0600);
    clearstatcache(true, $path);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
    $state =& config_runtime_cache();
    $state = ['loaded' => true, 'data' => $config];
}

}

namespace LiteBBS\Framework {
final class ResponseHalt extends \RuntimeException {
    public function __construct(public Response $response) { parent::__construct('Request completed.'); }
}

final class Request {
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    public array $cookies;
    public array $server;
    public array $headers;

    public function __construct(
        string $method,
        string $path,
        array $query,
        array $post,
        array $files,
        array $cookies,
        array $server,
        array $headers
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
        $this->cookies = $cookies;
        $this->server = $server;
        $this->headers = $headers;
    }

    public static function fromGlobals(): self {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            rtrim(is_string($path) ? $path : '/', '/') ?: '/',
            $_GET,
            $_POST,
            $_FILES,
            $_COOKIE,
            $_SERVER,
            is_array($headers) ? $headers : []
        );
    }

    public function context(mixed $user = null, ?\PDO $pdo = null): array {
        return [
            'method'=>$this->method,
            'path'=>$this->path,
            'request_uri'=>(string)($this->server['REQUEST_URI'] ?? $this->path),
            'query'=>$this->query,
            'post'=>$this->post,
            'files'=>$this->files,
            'cookies'=>$this->cookies,
            'server'=>$this->server,
            'headers'=>$this->headers,
            'user'=>$user,
            'pdo'=>$pdo,
        ];
    }

    public function input(string $key, mixed $default = null): mixed {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function isSafeMethod(): bool {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}

final class Response {
    private array $headers = [];
    private ?\Closure $stream = null;
    public string $body;
    public int $status;

    public function __construct(string $body = '', int $status = 200) {
        $this->body = $body;
        $this->status = $status;
    }

    public function header(string $name, string $value): self {
        if (!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) || preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException('Invalid response header.');
        }
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(array $data): self {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->body = $encoded;
        return $this->header('Content-Type', 'application/json; charset=utf-8');
    }

    public function stream(callable $stream): self {
        $this->stream = \Closure::fromCallable($stream);
        return $this;
    }

    public function headers(): array { return $this->headers; }

    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $name=>$value) header($name.': '.$value, true);
        if ($this->stream !== null) ($this->stream)();
        else echo $this->body;
    }
}

final class Container {
    private array $bindings = [];
    private array $instances = [];

    public function set(string $id, mixed $value, bool $shared = true): void {
        $this->bindings[$id] = [$value, $shared];
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed {
        if (array_key_exists($id, $this->instances)) return $this->instances[$id];
        if (!isset($this->bindings[$id])) throw new \RuntimeException('Service not registered: '.$id);
        [$factory, $shared] = $this->bindings[$id];
        $value = $factory instanceof \Closure ? $factory($this) : $factory;
        if ($shared) $this->instances[$id] = $value;
        return $value;
    }
}

interface Middleware {
    public function process(Request $request, callable $next, Container $container): ?Response;
}

final class Router
{
    private array $routes = [];

    public function add(string|array $methods, string $pattern, callable $handler): self
    {
        $methods = array_map('strtoupper', (array)$methods);
        $this->routes[] = [$methods, $pattern, $handler];
        return $this;
    }

    public function dispatch(Request $request, Container $container): ?Response
    {
        $allowed = [];
        foreach ($this->routes as [$methods, $pattern, $handler]) {
            if (!preg_match($pattern, $request->path, $matches)) {
                continue;
            }
            if (!in_array($request->method, $methods, true) && !in_array('*', $methods, true)) {
                $allowed = array_merge($allowed, $methods);
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $response = $handler($request, $container, $params);
            if ($response instanceof Response) {
                return $response;
            }
            if (is_string($response)) {
                return new Response($response);
            }
            return null;
        }
        return $allowed
            ? (new Response('Method Not Allowed', 405))->header('Allow', implode(', ', array_unique($allowed)))
            : null;
    }
}

final class Kernel
{
    private array $middleware = [];
    public Container $container;
    public Router $router;

    public function __construct(?Container $container = null, ?Router $router = null)
    {
        $this->container = $container ?: new Container();
        $this->router = $router ?: new Router();
    }

    public function middleware(Middleware $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function handle(Request $request, ?callable $fallback = null): ?Response
    {
        $destination = fn(Request $request): ?Response => $this->router->dispatch($request, $this->container)
            ?? ($fallback ? $fallback($request) : null);
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $destination;
            $destination = fn(Request $request): ?Response => $middleware->process($request, $next, $this->container);
        }
        try {
            return $destination($request);
        } catch (ResponseHalt $halt) {
            return $halt->response;
        }
    }
}

}

namespace LiteBBS\Application {
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
    public const MAX_CHARACTERS = 12;
    public const MAX_BYTES = 48;

    public static function accepts(string $password): bool
    {
        return strlen($password) <= self::MAX_BYTES
            && !str_contains($password, "\0")
            && preg_match('/\A.{'.self::MIN_CHARACTERS.','.self::MAX_CHARACTERS.'}\z/su', $password) === 1;
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
final class AttachmentService
{
    public function markPostsDeleted(array $postIds): array { return []; }
}

final class TopicViewService
{
    public function record(int $topicId): ?int
    {
        // Validate before enqueueing so the batch worker never accumulates invalid IDs.
        $current = $this->current($topicId);
        if ($current === null) {
            return null;
        }

        \count_topic_view($topicId);
        // View counts are eventually consistent: the periodic worker applies the batch.
        // Avoiding a synchronous UPDATE prevents a hot topic from invalidating the
        // home-page cache on every visit.
        return $current;
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

        // Anonymous view tracking has no session CSRF token. Require a same-origin
        // browser request so third-party forms cannot inflate view counters.
        $source = (string)($request->server['HTTP_ORIGIN'] ?? $request->server['HTTP_REFERER'] ?? '');
        $expected = trim((string)(load_config()['url'] ?? ''));
        if ($expected === '') $expected = install_request_origin();
        if ($source === '' || $expected === '' || !same_request_origin($source, $expected)) {
            return new Response('', 403);
        }

        \limit_action('topic-view', 120, 60, hash('sha256', (string)($request->server['REMOTE_ADDR'] ?? '')));

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
        $passwordHint = PasswordPolicy::MIN_CHARACTERS.' 至 '.PasswordPolicy::MAX_CHARACTERS.' 个字符';
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
                    \extension_authorize('user.username_reserved', ['actor'=>null, 'username'=>$username, 'operation'=>'registration']);
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

        $formFields = \input('username', '用户名（3-32 字符）', 'text', 'minlength="3" maxlength="32"')
            .\input('email', '邮箱', 'email', 'maxlength="254"')
            .\input('password', '密码（'.$passwordHint.'）', 'password', 'minlength="'.PasswordPolicy::MIN_CHARACTERS.'" maxlength="'.PasswordPolicy::MAX_CHARACTERS.'" autocomplete="new-password"');
        $coreBody = '<p class="error">'.\e($message).'</p>'.\form('/register', '创建账户',
            $formFields.\extension_slot('register.form.fields.after', []),
            '注册').\extension_slot('register.form.after', []);
        $body = \theme_render_page('account.register', ['viewer'=>\current_user(), 'content'=>\ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
        \page('注册', $body);
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
            if ($request->path === '/admin') {
                require_once __DIR__.'/admin.php';
                return (new \AdminLoginController())->handle($request);
            }
            \redirect('/admin');
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
            if ($this->file === 'admin.php' && !function_exists($this->handler)) require_once __DIR__.'/admin.php';
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

}

namespace LiteBBS\Application {
final class AccountAuthenticator
{
    public function verify(\LiteBBS\Framework\Request $request): ?array
    {
        $identity = trim((string)($request->post['account'] ?? ''));
        $password = (string)($request->post['password'] ?? '');
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($identity, 'UTF-8') : strtolower($identity);
        \limit_action('login-ip', 10, 900, hash('sha256', (string)($request->server['REMOTE_ADDR'] ?? '')));
        \limit_action('login-account', 5, 900, hash('sha256', $normalized));
        $identityColumn = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = \h('SELECT id,password_hash,status,role FROM users WHERE '.$identityColumn.'=? LIMIT 1', [$identity])->fetch();
        if (!is_array($user) || $user['status'] !== 'active' || !password_verify($password, (string)$user['password_hash'])) return null;
        if (password_needs_rehash((string)$user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            \h('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), $user['id']]);
        }
        return $user;
    }
}

/** Runs declared security plugins before authorization and route dispatch. */
final class PluginRequestMiddleware implements \LiteBBS\Framework\Middleware
{
    public function process(\LiteBBS\Framework\Request $request, callable $next, \LiteBBS\Framework\Container $container): ?\LiteBBS\Framework\Response
    {
        \extension_request_boot($request);
        $response = \extension_request_before($request);
        return $response ?? $next($request);
    }
}
}

namespace {
// ===== core.php =====




function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function text_slice(string $value, int $start, int $length): string { return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length); }
function text_length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
function &session_runtime_state(): array { static $state=['migrate_legacy_cookie'=>false];return $state; }
function session_cookie_is_secure(): bool { if(request_is_https())return true;if(!is_file(config_file()))return false;$scheme=parse_url((string)(load_config()['url']??''),PHP_URL_SCHEME);return is_string($scheme)&&strtolower($scheme)==='https'; }
function configure_session(): void { static $configured=false;if($configured)return;if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Session was started before LiteBBS configured it');$secure=session_cookie_is_secure();ini_set('session.use_only_cookies','1');ini_set('session.use_strict_mode','1');ini_set('session.use_trans_sid','0');$name=$secure?'__Host-lbbs':'lbbs';session_name($name);session_set_cookie_params(['secure'=>$secure,'httponly'=>true,'samesite'=>'Lax','path'=>'/']);if($secure&&!isset($_COOKIE[$name])&&isset($_COOKIE['lbbs'])&&is_string($_COOKIE['lbbs'])&&preg_match('/^[A-Za-z0-9,-]{1,128}$/',$_COOKIE['lbbs'])){$_COOKIE[$name]=$_COOKIE['lbbs'];$state=&session_runtime_state();$state['migrate_legacy_cookie']=true;}$configured=true; }
function start_session(): void { if (session_status() !== PHP_SESSION_ACTIVE) { configure_session();$host=getenv('LITEBBS_REDIS_HOST');if($host!==false&&$host!==''){if(!class_exists(Redis::class))throw new RuntimeException('Redis session storage is configured but ext-redis is unavailable');$port=(int)(getenv('LITEBBS_REDIS_PORT')?:6379);$path='tcp://'.$host.':'.$port;$options=['timeout=0.25','read_timeout=0.25'];$password=getenv('LITEBBS_REDIS_PASSWORD');if($password!==false&&$password!=='')$options[]='auth='.rawurlencode($password);$database=(int)(getenv('LITEBBS_REDIS_DATABASE')?:0);if($database)$options[]='database='.$database;$prefix=getenv('LITEBBS_REDIS_PREFIX')?:'litebbs';$options[]='prefix='.rawurlencode($prefix.':session:');$path.='?'.implode('&',$options);ini_set('session.save_handler','redis');ini_set('session.save_path',$path); }if(!session_start()||session_id()==='')throw new RuntimeException('PHP session_start failed');$state=&session_runtime_state();if($state['migrate_legacy_cookie']){$params=session_get_cookie_params();setcookie(session_name(),session_id(),['expires'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);setcookie('lbbs','',['expires'=>time()-3600,'path'=>'/','secure'=>(bool)($params['secure']??true),'httponly'=>true,'samesite'=>'Lax']);unset($_COOKIE['lbbs']);$state['migrate_legacy_cookie']=false;} } }
function release_session(): void { if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) throw new RuntimeException('PHP session write failed'); }
function clear_session_cookie(): void { $params=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>(string)($params['path']??'/'),'secure'=>(bool)($params['secure']??false),'httponly'=>(bool)($params['httponly']??true),'samesite'=>(string)($params['samesite']??'Lax')]); }
function request_is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (PHP_SAPI !== 'cli' && getenv('LITEBBS_HTTPS') === '1'); }
function database_error_message(PDOException $error): string { $info=$error->errorInfo;$driverCode=(int)($info[1]??$error->getCode());return match($driverCode){1045,1698=>'数据库认证失败：请检查数据库用户名、密码，以及该用户是否允许从当前 Web 服务器连接。',1049=>'数据库不存在：请先创建填写的数据库，或更正数据库名称。',1044,1142,1143=>'数据库权限不足：该账户需要访问目标数据库并创建表、索引和初始数据。',default=>'数据库不可用：请检查数据库主机、端口、网络访问和服务状态。'}; }
function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $startedAt = hrtime(true);
    try {
        $config = load_config();
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (getenv('LITEBBS_DB_PERSISTENT') === '1') $options[PDO::ATTR_PERSISTENT] = true;
        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['name'].';charset=utf8mb4';
        return $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
    } catch (PDOException $error) {
        if (PHP_SAPI === 'cli') throw $error;
        error_log('database connection failed code='.(string)$error->getCode());
        // The themed error page may depend on the same database.
        $response = (new \LiteBBS\Framework\Response('数据库暂不可用，请稍后重试。', 503))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'private, no-store');
        if (defined('LITEBBS_FRAMEWORK_DISPATCH')) throw new \LiteBBS\Framework\ResponseHalt($response);
        $response->send();
        exit;
    } finally {
        $state =& request_cache_state();
        $state['@db-connect-ms'] = (float)($state['@db-connect-ms'] ?? 0) + (hrtime(true) - $startedAt) / 1e6;
    }
}
function database_host_is_valid(string $host): bool {
    $host=trim($host);
    if ($host==='' || strlen($host)>253 || preg_match('~[\x00-\x20\\\\/]~',$host)) return false;
    if (strcasecmp($host,'localhost')===0) return true;
    if (filter_var($host,FILTER_VALIDATE_IP)) return true;
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',$host)) return false;
    return true;
}
function h(string $sql, array $args = []): PDOStatement
{
    $pdo = db();
    $startedAt = hrtime(true);
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($args);
        return $statement;
    } finally {
        $state =& request_cache_state();
        $state['@sql-count'] = (int)($state['@sql-count'] ?? 0) + 1;
        $state['@sql-ms'] = (float)($state['@sql-ms'] ?? 0) + (hrtime(true) - $startedAt) / 1e6;
    }
}
function redirect(string $to, int $status=303): void { $location=preg_match('~^/(?!/)~',$to)?$to:'/';if(defined('LITEBBS_FRAMEWORK_DISPATCH'))throw new \LiteBBS\Framework\ResponseHalt((new \LiteBBS\Framework\Response('', $status))->header('Location',$location));header('Location: '.$location,true,$status);exit; }
function csrf(): string { static $token=null;if($token!==null)return $token;start_session();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));$token=(string)$_SESSION['csrf'];release_session();return $token; }
function verify_csrf_token(string $submitted): bool { start_session();$stored=$_SESSION['csrf']??null;$valid=is_string($stored)&&$stored!==''&&$submitted!==''&&hash_equals($stored,$submitted);if(!$valid)error_log('csrf rejected: '.(!isset($_COOKIE[session_name()])?'session cookie missing':(!is_string($stored)||$stored===''?'session token missing':'token mismatch')));release_session();return $valid; }
function confirmation_token(string $action,string $subject): string { start_session();$token=bin2hex(random_bytes(32));$_SESSION['confirmations'][$token]=['action'=>$action,'subject'=>$subject,'expires'=>time()+300];release_session();return $token; }
function require_confirmation(string $action,string $subject,string $token): void { start_session();$item=$_SESSION['confirmations'][$token]??null;unset($_SESSION['confirmations'][$token]);release_session();if(!is_array($item)||($item['expires']??0)<time()||!hash_equals((string)($item['action']??''),$action)||!hash_equals((string)($item['subject']??''),$subject))fail(403,'确认令牌无效或已过期'); }
function setting(string $key, string $default=''): string { static $values=null,$version=null;$current=cache_version('settings');if($values===null||$version!==$current){$values=cached('settings:all:'.$current,30,static function(): array {$out=[];foreach(h('SELECT setting_key,setting_value FROM settings')->fetchAll() as $row)$out[$row['setting_key']]=$row['setting_value'];return $out;});$version=$current;}return (string)($values[$key]??$default); }
function install_token(): string { start_session();$token=time().'.'.bin2hex(random_bytes(16));$_SESSION['install_token']=$token;release_session();return $token; }
function verify_install_token(string $token): bool { start_session();$valid=isset($_SESSION['install_token']) && hash_equals($_SESSION['install_token'],$token) && abs(time()-(int)explode('.',$token)[0])<3600;release_session();return $valid; }
function request_url_parts(string $url): ?array { if($url===''||preg_match('/[\x00-\x20\\\\@]/',$url))return null;$parts=parse_url($url);if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||strtolower((string)($parts['scheme']??''))!=='https'||!isset($parts['host']))return null;$host=strtolower((string)$parts['host']);if($host===''||strlen($host)>253)return null;$port=(int)($parts['port']??443);if($port<1||$port>65535)return null;return ['scheme'=>'https','host'=>$host,'port'=>$port]; }
function request_origin_parts(string $url): ?array { if($url===''||preg_match('/[\x00-\x20\\\\@]/',$url))return null;$parts=parse_url($url);$scheme=strtolower((string)($parts['scheme']??''));if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||!in_array($scheme,['http','https'],true)||!isset($parts['host']))return null;$host=strtolower((string)$parts['host']);$defaultPort=$scheme==='https'?443:80;$port=(int)($parts['port']??$defaultPort);if($host===''||strlen($host)>253||$port<1||$port>65535)return null;return ['scheme'=>$scheme,'host'=>$host,'port'=>$port]; }
function same_request_origin(string $source,string $expected): bool { $actual=request_origin_parts($source);$target=request_origin_parts($expected);return $actual!==null&&$target!==null&&$actual===$target; }
function install_request_origin(): ?string { $host=(string)($_SERVER['HTTP_HOST']??'');if($host===''||preg_match('/[\x00-\x20\\\\\/\?\#@]/',$host))return null;$scheme=request_is_https()?'https':'http';return request_origin_parts($scheme.'://'.$host)!==null?$scheme.'://'.$host:null; }
function require_post(): void { if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail(405,'只接受 POST 请求');$requestPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$install=in_array($requestPath,['/install','/install.php'],true);if($install){if(!verify_install_token((string)($_POST['install_token']??'')))fail(403,'安装 nonce 无效');}elseif(!verify_csrf_token((string)($_POST['csrf']??'')))fail(403,'CSRF token 无效');$source=(string)($_SERVER['HTTP_ORIGIN']??$_SERVER['HTTP_REFERER']??'');$configuredUrl=$install?'':trim((string)(load_config()['url']??''));$expected=$install?install_request_origin():($configuredUrl!==''?$configuredUrl:install_request_origin());if($source===''||$expected===''||!same_request_origin($source,$expected))fail(403,'来源校验失败');if($install){start_session();unset($_SESSION['install_token']);release_session();} }
function fail(int $status, string $message): never
{
    if (defined('LITEBBS_FRAMEWORK_DISPATCH')) {
        throw new \LiteBBS\Framework\ResponseHalt(litebbs_error_response($status, $message));
    }

    (new \LiteBBS\Framework\Response($message, $status))
        ->header('Content-Type', 'text/plain; charset=utf-8')
        ->header('Cache-Control', 'private, no-store')
        ->send();
    exit;
}
function session_auth_state(array $session): string { $present=array_map(static fn(string $key):bool=>array_key_exists($key,$session),['sid','uid','token']);if(!in_array(true,$present,true))return 'anonymous';return !in_array(false,$present,true)&&(int)$session['sid']>0&&(int)$session['uid']>0&&is_string($session['token'])&&$session['token']!==''?'complete':'invalid'; }
function current_user(): ?array {
    static $loaded=false,$user=null;
    if($loaded)return $user;
    $loaded=true;
    if(!is_file(install_lock_file())||!is_file(config_file())||empty($_COOKIE[session_name()]))return null;

    start_session();
    $authState=session_auth_state($_SESSION);
    if($authState==='anonymous'){
        release_session();
        return null;
    }
    $sid=(int)($_SESSION['sid']??0);
    $uid=(int)($_SESSION['uid']??0);
    $token=(string)($_SESSION['token']??'');
    if($authState==='invalid'){
        $_SESSION=[];
        release_session();
        clear_session_cookie();
        return null;
    }
    // Release the per-browser PHP session lock before authentication queries.
    // This permits parallel reads from the same signed-in browser.
    release_session();

    $user=h('SELECT u.id,u.username,u.email,u.role,u.is_developer,u.points,u.locale,u.avatar_path,s.user_agent_hash,s.last_seen_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.user_id=? AND s.token_hash=? AND s.expires_at>UTC_TIMESTAMP(6) AND u.status="active"',[$sid,$uid,hash('sha256',$token)])->fetch()?:null;
    if(!$user){
        start_session();
        $_SESSION=[];
        release_session();
        clear_session_cookie();
        return null;
    }
    $ua=hash('sha256',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200));
    if(!hash_equals((string)$user['user_agent_hash'],$ua)){
        h('DELETE FROM sessions WHERE id=?',[$sid]);
        start_session();
        $_SESSION=[];
        release_session();
        clear_session_cookie();
        return null;
    }
    if(strtotime((string)$user['last_seen_at'])<time()-300)h('UPDATE sessions SET last_seen_at=UTC_TIMESTAMP(6) WHERE id=?',[$sid]);
    unset($user['user_agent_hash'],$user['last_seen_at']);
    return $user;
}
function login_user(int $id): void { start_session();if(!session_regenerate_id(true))throw new RuntimeException('PHP session ID regeneration failed');$token=bin2hex(random_bytes(32));$ip=(string)($_SERVER['REMOTE_ADDR']??'');$prefix=strpos($ip,':')!==false?substr($ip,0,strrpos($ip,':')):$ip;h('INSERT INTO sessions(user_id,token_hash,expires_at,last_seen_at,user_agent_hash,ip_prefix_hash) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY),UTC_TIMESTAMP(6),?,?)',[$id,hash('sha256',$token),hash('sha256',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200)),hash('sha256',$prefix)]);$_SESSION=['uid'=>$id,'sid'=>(int)db()->lastInsertId(),'token'=>$token,'csrf'=>bin2hex(random_bytes(32))];release_session(); }
function admin(): array { $u=current_user();if(!$u)redirect('/login');if($u['role']!=='admin')fail(403,'无权访问');return $u; }
function enforce_post_interval(int $userId, bool $lockUser = false): void { $interval=(int)setting('post_interval','0');if($interval<=0)return;if($lockUser)h('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId])->fetchColumn();$last=h('SELECT created_at FROM posts WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$userId])->fetchColumn();if(!is_string($last)||$last==='')return;$remaining=$interval-(time()-(int)strtotime($last.' UTC'));if($remaining>0)fail(429,'发帖间隔尚未结束，请在 '.$remaining.' 秒后重试'); }
function enforce_content_rate_limit(string $kind,int $userId): void { if(!in_array($kind,['topic','reply'],true))throw new InvalidArgumentException('Unknown content rate-limit kind.');$max=(int)setting($kind.'_rate_max','0');if($max<=0)return;$window=max(1,(int)setting($kind.'_rate_window','300'));$label=$kind==='topic'?'发布主题':'回复';limit_action($kind.'-create:configured:'.$max.':'.$window,$max,$window,'user:'.$userId,$label.'操作过于频繁，请稍后再试'); }
function limit_action(string $action,int $max,int $seconds,string $identity,string $message='操作过于频繁，请稍后再试'): void { $max=max(1,min(1000000,$max));$seconds=max(1,min(86400,$seconds));$bucket=hash('sha256',$action.'|'.$identity);$redis=redis_cache_client();if($redis){try{$key=shared_cache_key('rate:'.$bucket);$count=(int)$redis->eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],ARGV[1]); end; return n",[$key,(string)$seconds],1);if($count>$max)fail(429,$message);return;}catch(\LiteBBS\Framework\ResponseHalt $halt){throw $halt;}catch(Throwable $error){cache_error('Redis rate limit failed: '.$error->getMessage());if((getenv('LITEBBS_REDIS_HOST')?:'')!=='')throw new RuntimeException('Shared Redis rate limiting is unavailable',0,$error);}}elseif((getenv('LITEBBS_REDIS_HOST')?:'')!=='')throw new RuntimeException('Shared Redis rate limiting is unavailable');$pdo=db();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();try{h('INSERT INTO rate_limits(bucket,counter,reset_at) VALUES(?,1,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL '.$seconds.' SECOND)) ON DUPLICATE KEY UPDATE counter=IF(reset_at<=UTC_TIMESTAMP(6),1,counter+1),reset_at=IF(reset_at<=UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL '.$seconds.' SECOND),reset_at)',[$bucket]);$count=(int)h('SELECT counter FROM rate_limits WHERE bucket=? FOR UPDATE',[$bucket])->fetchColumn();if($ownsTransaction)$pdo->commit();}catch(Throwable $error){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $error;}if($count>$max)fail(429,$message); }
function unread_counts(int $userId): array { return cached('unread:v1:'.$userId.':'.cache_version('unread-user:'.$userId),10,static function()use($userId):array{$counts=h('SELECT (SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL) notifications,(SELECT COUNT(*) FROM private_messages WHERE recipient_id=? AND read_at IS NULL) messages',[$userId,$userId])->fetch()?:[];return [(int)($counts['notifications']??0),(int)($counts['messages']??0)];}); }
function render_md(string $text): string { $lines=preg_split('/\R/u',$text)?:[];$html='';$quote=[];$flush=function()use(&$html,&$quote):void{if($quote){$html.='<blockquote>'.nl2br(e(implode("\n",$quote)),false).'</blockquote>';$quote=[];}};foreach($lines as $index=>$line){if(str_starts_with($line,'> ')){$quote[]=substr($line,2);continue;}$flush();$html.=e($line);if($index<count($lines)-1)$html.='<br>';} $flush();return $html; }
function form(string $action,string $title,string $fields,string $submitLabel='保存'): string { $token=in_array($action,['/install','/install.php'],true)?'<input type="hidden" name="install_token" value="'.e(install_token()).'">':'<input type="hidden" name="csrf" value="'.e(csrf()).'">';return '<form class="form" method="post" action="'.e($action).'"><h1>'.e($title).'</h1>'.$token.$fields.'<button class="primary">'.e($submitLabel).'</button></form>'; }
function input(string $name,string $label,string $type='text',string $attrs=''): string { return '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" '.$attrs.' required></label>'; }
function audit(string $action,string $type,?int $id,array $meta=[]): void { \LiteBBS\Application\audit_logger()->record($action,$type,$id,$meta); }
function remove_tree(string $path): void { if (is_link($path)) { @unlink($path); return; } if (!is_dir($path)) return; foreach (scandir($path) ?: [] as $item) { if ($item === '.' || $item === '..') continue; $child = $path.'/'.$item; if (is_dir($child) && !is_link($child)) remove_tree($child); else @unlink($child); } @rmdir($path); }
function permanently_delete_topic(int $id): void { $categoryId=0;$postIds=[];$files=[];$actorId=(int)(current_user()['id']??0);\LiteBBS\Application\transaction_manager()->run(static function()use($id,&$categoryId,&$postIds,&$files,$actorId):void{$topic=h('SELECT id,category_id,user_id FROM topics WHERE id=? FOR UPDATE',[$id])->fetch();if(!$topic)fail(404,'主题不存在或已删除');extension_authorize('topic.delete',['actor'=>current_user(),'topic'=>$topic]);$categoryId=(int)$topic['category_id'];$postIds=array_map('intval',array_column(h('SELECT id FROM posts WHERE topic_id=? FOR UPDATE',[$id])->fetchAll(),'id'));$files=(new \LiteBBS\Application\AttachmentService())->markPostsDeleted($postIds);extension_event_sync('topic.deleting',['topic_id'=>$id,'category_id'=>$categoryId,'actor_id'=>$actorId,'post_ids'=>$postIds]);extension_event_sync('topic.deleting.v2',['topic_id'=>$id,'category_id'=>$categoryId,'actor_id'=>$actorId,'post_ids'=>$postIds]);$where='(ref_type="topic" AND ref_id=?)';$args=[$id];if($postIds){$marks=implode(',',array_fill(0,count($postIds),'?'));$where.=' OR (ref_type="post" AND ref_id IN ('.$marks.'))';$args=array_merge($args,$postIds);}$deltas=h('SELECT user_id,SUM(delta) AS delta FROM point_ledger WHERE '.$where.' GROUP BY user_id FOR UPDATE',$args)->fetchAll();foreach($deltas as $delta)h('UPDATE users SET points=points-? WHERE id=?',[(int)$delta['delta'],(int)$delta['user_id']]);h('DELETE FROM point_ledger WHERE '.$where,$args);h('DELETE FROM notifications WHERE topic_id=?',[$id]);if($postIds){$marks=implode(',',array_fill(0,count($postIds),'?'));h('DELETE FROM notifications WHERE post_id IN ('.$marks.')',$postIds);h('DELETE FROM post_revisions WHERE post_id IN ('.$marks.')',$postIds);h('UPDATE post_attachments SET post_id=NULL WHERE post_id IN ('.$marks.')',$postIds);}h('DELETE FROM posts WHERE topic_id=?',[$id]);h('DELETE FROM topics WHERE id=?',[$id]);audit('topic.permanently_deleted','topic',$id);extension_enqueue_async_event('topic.deleted',['topic_id'=>$id,'category_id'=>$categoryId,'post_ids'=>$postIds,'actor_id'=>$actorId]);});attachment_delete_files($files);invalidate('topic:'.$id);invalidate_topic_listing_cache($categoryId,true); }
function moderate_topic(int $id,string $column,int $value,string $action): void { if($id<1||!in_array($column,['is_pinned','is_locked'],true)||!in_array($value,[0,1],true))fail(400,'操作无效');$topic=h('SELECT category_id FROM topics WHERE id=?',[$id])->fetch();if(!$topic)fail(404,'主题不存在或已删除');h('UPDATE topics SET '.$column.'=? WHERE id=?',[$value,$id]);audit('topic.'.$action,'topic',$id);extension_event_async('topic.moderated',['topic_id'=>$id,'category_id'=>(int)$topic['category_id'],'action'=>$action,'value'=>$value,'actor_id'=>(int)(current_user()['id']??0)]);if($column==='is_locked')invalidate_topic_read_cache($id,(int)$topic['category_id']);else invalidate_topic_listing_cache((int)$topic['category_id']); }
function cache_error(string $message): void { error_log('cache: '.$message); }
function request_observe(\LiteBBS\Framework\Request $request, int $status, float $startedAt): void {
    $duration = round((microtime(true) - $startedAt) * 1000, 1);
    $state =& request_cache_state();
    $slowMilliseconds = max(1, (int)(getenv('LITEBBS_SLOW_REQUEST_MS') ?: 300));
    if ($status < 400 && $duration < $slowMilliseconds) return;
    error_log('request method='.$request->method.' path='.$request->path.' status='.$status.' duration_ms='.$duration.' sql_count='.(int)($state['@sql-count'] ?? 0).' sql_ms='.round((float)($state['@sql-ms'] ?? 0), 1).' cache_wait_ms='.round((float)($state['@cache-wait-ms'] ?? 0), 1));
}
function asset_version(string $file): string { static $versions=[];if(isset($versions[$file]))return $versions[$file];$modified=@filemtime($file);$size=@filesize($file);if($modified===false||$size===false)throw new RuntimeException('Asset is unavailable.');$key='asset-version:v1:'.hash('sha256',$file.'|'.$modified.'|'.$size);$value=cached($key,86400,static function()use($file):array{$hash=hash_file('sha256',$file);if($hash===false)throw new RuntimeException('Cannot hash asset.');return [substr($hash,0,12)];});return $versions[$file]=(string)($value[0]??''); }
function redis_cache_client(): ?Redis {
    static $client = false;
    if ($client instanceof Redis) {
        $state =& request_cache_state();
        if (!empty($state['@redis-failed'])) return null;
    }
    if ($client !== false) return $client;
    $host = getenv('LITEBBS_REDIS_HOST');
    if ($host === false || $host === '') return $client = null;
    if (!class_exists(Redis::class)) { cache_error('Redis is configured but ext-redis is unavailable'); return $client = null; }
    try {
        $redis = new Redis();
        $timeout = (float)(getenv('LITEBBS_REDIS_TIMEOUT') ?: 0.25);
        if ($timeout <= 0 || $timeout > 5) $timeout = 0.25;
        if (!$redis->connect($host, (int)(getenv('LITEBBS_REDIS_PORT') ?: 6379), $timeout)) throw new RuntimeException('connect failed');
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        $password = getenv('LITEBBS_REDIS_PASSWORD');
        if ($password !== false && $password !== '' && !$redis->auth($password)) throw new RuntimeException('authentication failed');
        $database = (int)(getenv('LITEBBS_REDIS_DATABASE') ?: 0);
        if ($database !== 0 && !$redis->select($database)) throw new RuntimeException('database selection failed');
        return $client = $redis;
    } catch (Throwable $error) { $state=&request_cache_state();$state['@redis-failed']=true;cache_error('Redis unavailable: '.$error->getMessage()); return $client = null; }
}
function shared_cache_key(string $key): string { return (getenv('LITEBBS_REDIS_PREFIX') ?: 'litebbs').':'.hash('sha256', $key); }
function &request_cache_state(): array { static $state=[];return $state; }
function write_cache(string $key,array $data,int $ttl=300): void { $state=&request_cache_state();$state[$key]=['expires'=>time()+min($ttl,300),'value'=>$data];try{$encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(Throwable $error){cache_error('cannot encode cache value');return;} $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();if($redis){try{$redis->setex(shared_cache_key($key),max(1,$ttl),$encoded);return;}catch(Throwable $error){cache_error('Redis write failed: '.$error->getMessage());if($redisConfigured)return;}}elseif($redisConfigured){return;} $dir=dirname(__DIR__).'/cache';if(!is_dir($dir)&&!@mkdir($dir,0700,true)){cache_error('cannot create cache directory');return;}$path=$dir.'/'.hash('sha256',$key).'.cache';$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';if(@file_put_contents($tmp,$encoded,LOCK_EX)===false){cache_error('cannot write cache temporary file');return;}if(!@rename($tmp,$path)){if(is_file($path))@unlink($path);if(!@rename($tmp,$path)){@unlink($tmp);cache_error('cannot rename cache temporary file');}} }
function read_cache(string $key,int $ttl=300): ?array { $state=&request_cache_state();if(isset($state[$key])){if($state[$key]['expires']>=time())return $state[$key]['value'];unset($state[$key]);}$redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();if($redisConfigured&&!$redis)return null;if($redis){try{$raw=$redis->get(shared_cache_key($key));$data=is_string($raw)?json_decode($raw,true):null;if(is_array($data)){$state[$key]=['expires'=>time()+min($ttl,300),'value'=>$data];return $data;}return null;}catch(Throwable $error){$state['@redis-failed']=true;cache_error('Redis read failed: '.$error->getMessage());return null;}}$path=dirname(__DIR__).'/cache/'.hash('sha256',$key).'.cache';if(!is_file($path))return null;$modified=filemtime($path);if($modified===false||$modified<time()-$ttl)return null;$data=json_decode((string)@file_get_contents($path),true);if(!is_array($data))return null;$state[$key]=['expires'=>min($modified+$ttl,time()+300),'value'=>$data];return $data; }
function public_cached(string $key,int $ttl,callable $load): array { return cached($key,$ttl,$load); }
function cached(string $key, int $ttl, callable $load): array
{
    $value = page_cache_read($key, $ttl);
    if ($value !== null) return $value;

    try {
        $value = $load();
        if (!is_array($value)) throw new RuntimeException('Cache loader must return an array.');
        page_cache_write($key, $value, $ttl);
        return $value;
    } finally {
        page_cache_release('@page-lock:'.$key);
    }
}
function cache_lock_path(string $key): string {
    // Locks must be per cache key. Sharing a small hash prefix serializes
    // unrelated cold-cache requests when Redis is not configured.
    return dirname(__DIR__).'/cache/active-lock-'.hash('sha256',$key).'.lock';
}
function clean_old_cache_locks(): int {
    $removed=0;$cacheDir=dirname(__DIR__).'/cache';
    if(!is_dir($cacheDir))return 0;
    foreach(new DirectoryIterator($cacheDir) as $file){
        if($removed>=200)break;
        if(!$file->isFile()||$file->isLink()||!preg_match('/^(?:[a-f0-9]{64}\.cache(?:\.page)?|active-lock-[a-f0-9]{3,64})\.lock$/',$file->getFilename()))continue;
        if($file->getMTime()>time()-172800)continue;
        if(@unlink($file->getPathname()))$removed++;
    }
    return $removed;
}
function page_cache_read(string $key, int $ttl): ?array {
    $value=read_cache($key,$ttl);if($value!==null)return $value;
    $state=&request_cache_state();$stateKey='@page-lock:'.$key;
    if (isset($state[$stateKey])) return null;
    $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();
    if($redisConfigured&&!$redis)return null;
    $contended = false;
    if ($redis) {
        $lockKey = shared_cache_key('page-lock:'.$key);
        $token = bin2hex(random_bytes(16));
        try {
            if ($redis->set($lockKey, $token, ['nx','ex'=>10])) {
                $state[$stateKey] = ['redis'=>$redis, 'key'=>$lockKey, 'token'=>$token];
            } else $contended = true;
        } catch (Throwable $error) {
            cache_error('page cache single-flight failed: '.$error->getMessage());
        }
    } else {
        $dir = dirname(__DIR__).'/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        $lock = @fopen(cache_lock_path($key), 'c');
        if (!$lock) return null;
        if (flock($lock, LOCK_EX | LOCK_NB)) $state[$stateKey] = ['file'=>$lock];
        else { fclose($lock); $contended = true; }
    }

    if (isset($state[$stateKey])) page_cache_register_shutdown();
    if ($contended) {
        // Give the single-flight owner a bounded opportunity to publish before
        // falling back to the loader. This avoids a cold-cache burst sending
        // every concurrent request through the same database query.
        $waitMilliseconds = (int)(getenv('LITEBBS_PAGE_CACHE_WAIT_MS') ?: 50);
        $waitMilliseconds = max(0, min(200, $waitMilliseconds));
        $deadline = hrtime(true) + ($waitMilliseconds * 1000000);
        $waitStartedAt = hrtime(true);
        do {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) break;
            usleep((int)min(10000, max(1000, intdiv($remaining, 1000))));
            $value = read_cache($key, $ttl);
            if ($value !== null) {
                $state['@cache-wait-ms'] = (float)($state['@cache-wait-ms'] ?? 0) + (hrtime(true) - $waitStartedAt) / 1e6;
                return $value;
            }
        } while (hrtime(true) < $deadline);
        $state['@cache-wait-ms'] = (float)($state['@cache-wait-ms'] ?? 0) + (hrtime(true) - $waitStartedAt) / 1e6;
    }
    $value = read_cache($key, $ttl);
    if ($value !== null && isset($state[$stateKey])) page_cache_release($stateKey);
    return $value;
}
function page_cache_register_shutdown(): void {
    static $registered=false;
    if($registered)return;
    $registered=true;
    register_shutdown_function(static function():void {
        $state=&request_cache_state();
        foreach(array_keys($state) as $key)if(str_starts_with($key,'@page-lock:'))page_cache_release($key);
    });
}
function page_cache_release(string $stateKey): void {
    $state=&request_cache_state();$lock=$state[$stateKey]??null;unset($state[$stateKey]);
    if(!is_array($lock))return;
    if(isset($lock['redis'])){try{$lock['redis']->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0",[$lock['key'],$lock['token']],1);}catch(Throwable $error){cache_error('page cache lock release failed: '.$error->getMessage());}}
    elseif(isset($lock['file'])&&is_resource($lock['file'])){flock($lock['file'],LOCK_UN);fclose($lock['file']);}
}
function page_cache_write(string $key, array $data, int $ttl): void {
    $state=&request_cache_state();$stateKey='@page-lock:'.$key;
    // A request that timed out waiting for the cache owner must not replace its result.
    if (!isset($state[$stateKey])) return;
    try {
        $lock = $state[$stateKey];
        if (isset($lock['redis'])) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $lock['redis']->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('SETEX',KEYS[2],ARGV[2],ARGV[3]) end; return false", [$lock['key'], shared_cache_key($key), $lock['token'], (string)max(1, $ttl), $encoded], 2);
        } else {
            write_cache($key, $data, $ttl);
        }
    } catch (Throwable $error) {
        cache_error('Cache publish failed: '.$error->getMessage());
    } finally {
        page_cache_release($stateKey);
    }
}
function cached_topic_count(string $scope,?int $categoryId=null): int { if($categoryId===null){$key='topic-count:v2:'.$scope.':'.cache_version('topics').':'.cache_version('categories');}else{$key='topic-count:v2:'.$scope.':'.$categoryId.':'.cache_version('category-topics:'.$categoryId);}$values=cached($key,30,static function() use($categoryId): array {$sql=$categoryId===null?'SELECT COUNT(*) FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1':'SELECT COUNT(*) FROM topics WHERE category_id=? AND status="normal"';$count=$categoryId===null?h($sql)->fetchColumn():h($sql,[$categoryId])->fetchColumn();return [(int)$count];});return (int)($values[0]??0); }
function cached_sitemap_max_topic_id(): int { $versions=cache_versions(['topics','categories','sitemap']);$key='sitemap:max-topic-id:v1:'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['sitemap'];$value=public_cached($key,3600,static function():array{return [(int)h('SELECT COALESCE(MAX(t.id),0) FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1')->fetchColumn()];});return (int)($value[0]??0); }
function topic_last_post_pointer_available(): bool { $value=cached('schema:topics:last-post-pointer',60,static function():array{return [(bool)h('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="topics" AND column_name="last_post_id" LIMIT 1')->fetchColumn()];});return !empty($value[0]); }
function migrate_topic_last_post_pointer(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-last-post-pointer']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        $column=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="topics" AND column_name="last_post_id" LIMIT 1');$column->execute();
        if ($column->fetchColumn()) { write_cache('schema:topics:last-post-pointer',[true],60); return false; }
        $pdo->exec('ALTER TABLE topics ADD COLUMN last_post_id BIGINT UNSIGNED NULL AFTER view_count');
        $pdo->exec('UPDATE topics t LEFT JOIN (SELECT topic_id,MAX(id) AS post_id FROM posts WHERE status="normal" GROUP BY topic_id) p ON p.topic_id=t.id SET t.last_post_id=p.post_id');
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');$migration->execute(['3.2.0-last-post-pointer']);
        write_cache('schema:topics:last-post-pointer',[true],60);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-last-post-pointer']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function private_messages_sender_index_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $index=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name="private_messages" AND index_name="ix_messages_sender" LIMIT 1');
    $index->execute();
    return (bool)$index->fetchColumn();
}
function migrate_private_messages_sender_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:private-messages-sender-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (private_messages_sender_index_available($pdo)) return false;
        $pdo->exec('ALTER TABLE private_messages ADD INDEX ix_messages_sender (sender_id,id)');
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');$migration->execute(['3.3.0-private-messages-sender-index']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:private-messages-sender-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function private_messages_conversation_reverse_index_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $index=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name="private_messages" AND index_name="ix_messages_conversation_reverse" LIMIT 1');
    $index->execute();
    return (bool)$index->fetchColumn();
}
function migrate_private_messages_conversation_reverse_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:private-messages-conversation-reverse-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (private_messages_conversation_reverse_index_available($pdo)) return false;
        $pdo->exec('ALTER TABLE private_messages ADD INDEX ix_messages_conversation_reverse (recipient_id,sender_id,id)');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.6.0-message-conversation-index']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:private-messages-conversation-reverse-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function migrate_performance_indexes(?PDO $pdo=null): array {
    $pdo=$pdo??db();
    $changed = [
        'last_post_pointer'=>migrate_topic_last_post_pointer($pdo),
        'private_messages_sender_index'=>migrate_private_messages_sender_index($pdo),
        'private_messages_conversation_reverse_index'=>migrate_private_messages_conversation_reverse_index($pdo),
        'topic_participants'=>migrate_topic_participants($pdo),
        'topic_search_prefix_index'=>migrate_topic_search_prefix_index($pdo),
        'queue_cleanup_indexes'=>migrate_queue_cleanup_indexes($pdo),
        'users_status_created_index'=>migrate_users_status_created_index($pdo),
        'topic_participants_duplicate_index_removed'=>migrate_topic_participants_duplicate_index($pdo),
        'message_review_indexes'=>migrate_message_review_indexes($pdo),
    ];
    return $changed;
}
function database_maintenance_report(?PDO $pdo=null): array {
    $pdo=$pdo??db();
    $checks=[
        '主题最后回复指针'=>topic_last_post_pointer_available($pdo),
        '用户状态索引'=>users_status_created_index_available($pdo),
        '私信发送者索引'=>private_messages_sender_index_available($pdo),
        '私信会话反向索引'=>private_messages_conversation_reverse_index_available($pdo),
        '私信收件箱和审核队列索引'=>message_review_indexes_available($pdo),
        '主题参与者表'=>topic_participants_available($pdo),
        '主题搜索索引'=>topic_search_prefix_index_available($pdo),
        '队列清理索引'=>queue_cleanup_indexes_available($pdo),
        '重复索引已移除'=>topic_participants_duplicate_index_removed($pdo),
    ];
    $legacy=array_keys(legacy_database_table_pairs($pdo));
    return ['checks'=>$checks,'legacy_tables'=>$legacy,'ready'=>!in_array(false,$checks,true)];
}
function legacy_database_table_pairs(?PDO $pdo=null): array {
    $pdo=$pdo??db();
    $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'plugin!_%' ESCAPE '!'")->fetchAll(PDO::FETCH_COLUMN);
    $available=array_fill_keys(array_map('strval',$tables),true);$pairs=[];
    $protected=[];
    foreach($pdo->query('SELECT name FROM plugins WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN) as $plugin){
        $name=(string)$plugin;$directory=dirname(__DIR__).'/plugins/'.$name;
        foreach(['/plugin.json'] as $manifestPath){
            $file=$directory.$manifestPath;if(!is_file($file))continue;
            $manifest=json_decode((string)file_get_contents($file),true);
            foreach(is_array($manifest)?($manifest['database_tables']??[]):[] as $table){
                if(!is_string($table)||!preg_match('/^[a-zA-Z0-9_]{1,64}$/',$table))continue;
                $protected[$table]=true;
                if(str_starts_with($table,'plg_'))$protected['plugin_'.substr($table,4)]=true;
                if(str_starts_with($table,'plugin_'))$protected['plg_'.substr($table,7)]=true;
            }
            break;
        }
    }
    foreach($tables as $table){
        $table=(string)$table;
        if(!preg_match('/^plugin_[a-z0-9_]+$/',$table))continue;
        $current='plg_'.substr($table,7);
        if(isset($available[$current])&&!isset($protected[$table])&&!isset($protected[$current]))$pairs[$table]=$current;
    }
    return $pairs;
}
function cleanup_legacy_database_tables(?PDO $pdo=null): array {
    $pdo=$pdo??db();$removed=[];
    foreach(legacy_database_table_pairs($pdo) as $legacy=>$current){
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $exists->execute([$legacy]);if(!$exists->fetchColumn())continue;
        $exists->execute([$current]);if(!$exists->fetchColumn())throw new RuntimeException('新附件表不存在，拒绝删除 '.$legacy);
        $oldCount=(int)$pdo->query('SELECT COUNT(*) FROM `'.$legacy.'`')->fetchColumn();
        $newCount=(int)$pdo->query('SELECT COUNT(*) FROM `'.$current.'`')->fetchColumn();
        if($newCount<$oldCount)throw new RuntimeException('新附件表数据少于旧表，拒绝删除 '.$legacy);
        $pdo->exec('DROP TABLE `'.$legacy.'`');$removed[]=$legacy;
    }
    return $removed;
}
function database_index_available(PDO $pdo,string $table,string $index): bool {
    $statement=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
    $statement->execute([$table,$index]);
    return (bool)$statement->fetchColumn();
}
function message_review_indexes(): array {
    return [
        'private_messages'=>['ix_messages_recipient_id','recipient_id,id'],
        'reports'=>['ix_reports_status_id','status,id'],
        'market_submissions'=>['ix_market_status','status,id'],
    ];
}
function message_review_indexes_available(PDO $pdo): bool {
    foreach(message_review_indexes() as $table=>[$index]){
        if(!database_index_available($pdo,$table,$index))return false;
    }
    return true;
}
function migrate_message_review_indexes(PDO $pdo): bool {
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute(['litebbs:migrate']);
    if(!(bool)$lock->fetchColumn())throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        $changed=false;
        foreach(message_review_indexes() as $table=>[$index,$columns]){
            if(database_index_available($pdo,$table,$index))continue;
            $pdo->exec('ALTER TABLE `'.$table.'` ADD INDEX `'.$index.'` ('.$columns.')');
            $changed=true;
        }
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');
        $migration->execute(['3.9.0-message-review-indexes']);
        return $changed;
    } finally {
        try {
            $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['litebbs:migrate']);
        } catch(Throwable $error) {
            cache_error('database migration lock release failed: '.$error->getMessage());
        }
    }
}
function users_status_created_index_available(?PDO $pdo=null): bool {
    return database_index_available($pdo??db(),'users','ix_users_status_created');
}
function migrate_users_status_created_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:users-status-created-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if(users_status_created_index_available($pdo))return false;
        $pdo->exec('ALTER TABLE users ADD INDEX ix_users_status_created (status,created_at,id)');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.8.0-runtime-performance']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:users-status-created-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_participants_duplicate_index_removed(?PDO $pdo=null): bool {
    return !database_index_available($pdo??db(),'topic_participants','ix_topic_participants_topic_user');
}
function migrate_topic_participants_duplicate_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-participants-duplicate-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if(topic_participants_duplicate_index_removed($pdo))return false;
        $pdo->exec('ALTER TABLE topic_participants DROP INDEX ix_topic_participants_topic_user');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.8.0-runtime-performance']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-participants-duplicate-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function queue_cleanup_indexes_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    foreach(['outbox_events'=>'ix_outbox_claim_ready','failed_outbox_events'=>'ix_failed_outbox_event_failed','extension_jobs'=>'ix_extension_jobs_claim_ready','extension_job_failures'=>'ix_extension_job_failures_failed'] as $table=>$index)if(!database_index_available($pdo,$table,$index))return false;
    return true;
}
function migrate_queue_cleanup_indexes(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:queue-cleanup-indexes']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        $indexes=['outbox_events'=>['ix_outbox_claim_ready'=>'processed_at,lease_until,available_at,id','ix_outbox_processed'=>'processed_at,id'],'failed_outbox_events'=>['ix_failed_outbox_event_failed'=>'failed_at,id'],'extension_jobs'=>['ix_extension_jobs_claim_ready'=>'completed_at,lease_until,available_at,id','ix_extension_jobs_completed'=>'completed_at,id'],'extension_job_failures'=>['ix_extension_job_failures_failed'=>'failed_at,id']];
        $changed=false;
        foreach($indexes as $table=>$list)foreach($list as $name=>$columns)if(!database_index_available($pdo,$table,$name)){$pdo->exec('ALTER TABLE `'.$table.'` ADD INDEX `'.$name.'` ('.$columns.')');$changed=true;}
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');$migration->execute(['3.7.0-queue-cleanup-indexes']);
        return $changed;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:queue-cleanup-indexes']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_search_prefix_index_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $index=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name="topics" AND index_name="ix_topics_search_prefix" LIMIT 1');
    $index->execute();
    return (bool)$index->fetchColumn();
}
function migrate_topic_search_prefix_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-search-prefix-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (topic_search_prefix_index_available($pdo)) return false;
        $pdo->exec('ALTER TABLE topics ADD INDEX ix_topics_search_prefix (status,title)');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.5.0-topic-search-prefix-index']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-search-prefix-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_participants_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $value=cached('schema:topic-participants',60,static function() use($pdo): array {$table=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="topic_participants" LIMIT 1');$table->execute();return [(bool)$table->fetchColumn()];});
    return !empty($value[0]);
}
function migrate_topic_participants(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-participants']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (topic_participants_available($pdo)) return false;
        $pdo->exec('CREATE TABLE topic_participants(topic_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,last_post_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(topic_id,user_id),FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('INSERT INTO topic_participants(topic_id,user_id,last_post_id) SELECT topic_id,user_id,MAX(id) FROM posts WHERE status="normal" GROUP BY topic_id,user_id ON DUPLICATE KEY UPDATE last_post_id=GREATEST(last_post_id,VALUES(last_post_id))');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.4.0-topic-participants']);
        write_cache('schema:topic-participants',[true],60);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-participants']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_last_post_join_sql(): string { return topic_last_post_pointer_available() ? 'LEFT JOIN posts rp ON rp.id=t.last_post_id' : 'LEFT JOIN posts rp ON rp.id=(SELECT MAX(p.id) FROM posts p WHERE p.topic_id=t.id AND p.status="normal")'; }

function read_feed_rows(?array $cursor, int $pageSize, ?int $categoryId, ?array $selectedIds, ?array $viewer = null): array
{
    if ($selectedIds === []) return [];
    $where = 't.status="normal" AND c.is_visible=1';
    $args = [];
    if ($categoryId !== null) {
        $where .= ' AND t.category_id=?';
        $args[] = $categoryId;
    }
    if ($cursor !== null) {
        ['p'=>$pinned, 'd'=>$date, 'i'=>$id] = $cursor;
        $where .= ' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';
        array_push($args, $pinned, $pinned, $date, $date, $id);
    }
    if ($selectedIds !== null) {
        $where .= ' AND t.id IN ('.implode(',', array_fill(0, count($selectedIds), '?')).')';
        array_push($args, ...$selectedIds);
    }
    $from = ' FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id '.topic_last_post_join_sql().' LEFT JOIN users ru ON ru.id=rp.user_id';
    $fields = 't.id,t.title,t.reply_count,t.view_count,t.created_at,t.last_post_at,t.is_pinned,c.id category_id,c.title category,u.username,ru.username reply_username,rp.created_at reply_created_at';
    $rows = h('SELECT '.$fields.$from.' WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT '.($pageSize + 1), $args)->fetchAll();
    if (extension_candidate_plugins('policies', 'category.view') === []) return $rows;
    return array_values(array_filter($rows, static fn(array $row): bool => extension_permits('category.view', ['actor'=>$viewer, 'category'=>['id'=>(int)$row['category_id'], 'title'=>(string)$row['category']]])));
}
function anonymous_topic_view_is_new(int $topicId): bool {
    $now = time();
    $seen = [];
    foreach (explode(',', (string)($_COOKIE['lbbs_topic_views'] ?? '')) as $entry) {
        [$seenId, $expiresAt] = array_pad(explode(':', $entry, 2), 2, '');
        if (ctype_digit($seenId) && ctype_digit($expiresAt) && (int)$expiresAt > $now) $seen[(int)$seenId] = (int)$expiresAt;
    }
    if (isset($seen[$topicId])) return false;
    $seen[$topicId] = $now + 60;
    arsort($seen, SORT_NUMERIC);
    $seen = array_slice($seen, 0, 20, true);
    $value = implode(',', array_map(static fn(int $id, int $expiresAt): string => $id.':'.$expiresAt, array_keys($seen), $seen));
    // The asynchronous view endpoint also needs this cookie on /topic-views.
    setcookie('lbbs_topic_views', $value, ['expires'=>$now + 60, 'path'=>'/', 'secure'=>request_is_https(), 'httponly'=>true, 'samesite'=>'Lax']);
    return true;
}
function count_topic_view(int $id): void { $increment=static function()use($id):void{$redis=redis_cache_client();if($redis){$redis->hIncrBy(shared_cache_key('topic-views'),(string)$id,1);return;}$dir=dirname(__DIR__).'/cache/topic-views';if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)){cache_error('topic view cache is unavailable');return;}$path=$dir.'/'.$id.'.cache';if(@file_put_contents($path,'x',FILE_APPEND|LOCK_EX)===false)cache_error('topic view cache write failed');};$redis=redis_cache_client();if($redis){try{$viewer=current_user();$identity=$viewer?'user:'.(int)$viewer['id']:'ip:'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));$seen=shared_cache_key('topic-view-seen:'.$id.':'.$identity);if($redis->set($seen,'1',['nx','ex'=>60]))$increment();return;}catch(Throwable $error){cache_error('topic view increment failed: '.$error->getMessage());}}if(!anonymous_topic_view_is_new($id))return;$increment(); }
function local_cache_version(string $key): int
{
    $path = dirname(__DIR__).'/cache/version-'.hash('sha256', $key);
    return max(1, (int)@file_get_contents($path));
}

function advance_local_cache_version(string $key): int
{
    $dir = dirname(__DIR__).'/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cache directory is unavailable.');
    $path = $dir.'/version-'.hash('sha256', $key);
    $lock = @fopen($path.'.lock', 'c');
    if (!$lock) throw new RuntimeException('Cache version lock is unavailable.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Cache version lock failed.');
        // Time-based generations keep a new Redis key from reusing a previous key after eviction.
        $version = max(local_cache_version($key) + 1, (int)(microtime(true) * 1000000));
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, (string)$version) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cache version write failed.');
        }
        return $version;
    } finally {
        fclose($lock);
    }
}

function cache_versions(array $keys): array
{
    $state =& request_cache_state();
    $versions = [];
    $missing = [];
    foreach (array_unique(array_filter($keys, static fn(mixed $key): bool => is_string($key) && $key !== '')) as $key) {
        if (isset($state['@version:'.$key])) $versions[$key] = (int)$state['@version:'.$key]['value'];
        else $missing[] = $key;
    }
    if (!$missing) return $versions;

    $values = [];
    $redis = redis_cache_client();
    if ($redis) {
        try {
            $sharedKeys = array_map(static fn(string $key): string => shared_cache_key('@version:'.$key), $missing);
            $values = $redis->mGet($sharedKeys);
            if (!is_array($values)) throw new RuntimeException('Unexpected Redis MGET response.');

            // Version keys have no TTL. Initialize only absent keys from the
            // local fallback, so regular Redis-backed reads avoid one file read
            // per cache dependency.
            $absent = [];
            foreach ($missing as $index => $key) {
                if (!is_numeric($values[$index] ?? null) || (int)$values[$index] < 1) $absent[$index] = $key;
            }
            if ($absent) {
                $pipeline = $redis->multi(Redis::PIPELINE);
                foreach ($absent as $index => $key) $pipeline->setNx($sharedKeys[$index], (string)local_cache_version($key));
                $pipeline->mGet(array_values(array_intersect_key($sharedKeys, $absent)));
                $result = $pipeline->exec();
                $initialized = $result[count($absent)] ?? null;
                if (!is_array($initialized)) throw new RuntimeException('Unexpected Redis version initialization response.');
                foreach (array_keys($absent) as $position => $index) $values[$index] = $initialized[$position] ?? null;
            }
        } catch (Throwable $error) {
            $values = [];
            $state['@redis-failed'] = true;
            cache_error('Redis version batch read failed: '.$error->getMessage());
        }
    }
    foreach ($missing as $index => $key) {
        $sharedVersion = $values[$index] ?? null;
        $version = is_numeric($sharedVersion) && (int)$sharedVersion > 0
            ? (int)$sharedVersion
            : local_cache_version($key);
        $state['@version:'.$key] = ['expires'=>PHP_INT_MAX, 'value'=>$version];
        $versions[$key] = $version;
    }
    return $versions;
}
function cache_version(string $key): int { $versions=cache_versions([$key]);return (int)($versions[$key]??1); }
function invalidate(string $key): void
{
    $next = advance_local_cache_version($key);
    if ($redis = redis_cache_client()) {
        try {
            $versionKey = shared_cache_key('@version:'.$key);
            $shared = $redis->eval("local current=redis.call('GET',KEYS[1]); if not current or tonumber(current)<tonumber(ARGV[1]) then redis.call('SET',KEYS[1],ARGV[1]); return ARGV[1] end; return redis.call('INCR',KEYS[1])", [$versionKey, (string)$next], 1);
            if (!is_numeric($shared)) throw new RuntimeException('Unexpected Redis version write response.');
            $next = (int)$shared;
        } catch (Throwable $error) {
            cache_error('Redis version write failed; using local invalidation: '.$error->getMessage());
        }
    }
    $state =& request_cache_state();
    $state['@version:'.$key] = ['expires'=>PHP_INT_MAX, 'value'=>$next];
    foreach (array_keys($state) as $cacheKey) {
        if (!str_starts_with($cacheKey, '@') && str_contains($cacheKey, $key)) unset($state[$cacheKey]);
    }
}
function invalidate_user_cache(int $userId): void { invalidate('unread-user:'.$userId); }
function invalidate_user_caches(array $userIds, bool $allowTtlFallback=false): void {
    $userIds=array_values(array_unique(array_filter(array_map('intval',$userIds),static fn(int $id):bool=>$id>0)));
    if(!$userIds)return;
    if(count($userIds)===1){invalidate_user_cache($userIds[0]);return;}
    $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();
    if(!$redis){
        // Fan-out can touch hundreds of users. The unread value already expires
        // after 10 seconds, so avoid hundreds of local version files and locks.
        if($allowTtlFallback&&!$redisConfigured)return;
        foreach($userIds as $userId)invalidate_user_cache($userId);
        return;
    }
    try{
        $pipeline=$redis->multi(Redis::PIPELINE);
        foreach($userIds as $userId){$key=shared_cache_key('@version:unread-user:'.$userId);$pipeline->setNx($key,1);$pipeline->incr($key);}
        $results=$pipeline->exec();
        $state=&request_cache_state();
        foreach($userIds as $index=>$userId){$version=max(2,(int)($results[$index*2+1]??2));$state['@version:unread-user:'.$userId]=['expires'=>PHP_INT_MAX,'value'=>$version];}
    }catch(Throwable $error){
        cache_error('Redis user cache batch invalidation failed: '.$error->getMessage());
        if(!$redisConfigured)foreach($userIds as $userId)invalidate_user_cache($userId);
    }
}
function invalidate_topic_listing_cache(int $categoryId, bool $countChanged=false): void { invalidate('home-feed');if($categoryId>0){invalidate('category-feed:'.$categoryId);if($countChanged)invalidate('category-topics:'.$categoryId);}if($countChanged)invalidate('topics'); }
function invalidate_topic_read_cache(int $topicId, ?int $categoryId = null, bool $invalidateListings = true, bool $invalidateSitemap = true): void { if ($categoryId === null) $categoryId=(int)h('SELECT category_id FROM topics WHERE id=?',[$topicId])->fetchColumn();invalidate('topic:'.$topicId);if($invalidateSitemap)invalidate('sitemap');if($invalidateListings)invalidate_topic_listing_cache($categoryId); }
function slug(string $s): string { $lower=function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);$s=trim((string)preg_replace('/[^\pL\pN]+/u','-',$lower),'-');return $s?:'topic'; }
function template_unavailable_document(array $variables): string {
    $stylesheet = '/assets/css/template-unavailable.css';
    $file = dirname(__DIR__).$stylesheet;
    if (is_file($file)) $stylesheet .= '?v='.asset_version($file);
    $site = (string)($variables['c']['site'] ?? 'LiteBBS');
    $locale = (string)($variables['c']['locale'] ?? 'zh-CN');
    return '<!doctype html><html lang="'.e($locale).'" class="template-unavailable"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>模板不可用 · '.e($site).'</title><link rel="stylesheet" href="'.e($stylesheet).'"></head><body class="template-unavailable-body"><main class="template-notice"><div class="template-notice-mark" aria-hidden="true">!</div><h1>前台模板不可用</h1><p>未找到可用的前台模板，请管理员安装或启用模板后重试。</p></main></body></html>';
}
function render_core_layout(array $variables, bool $allowPlugins = true): string {
    $activeTheme = (string)($variables['c']['theme'] ?? 'default');
    $requestPath = (string)($variables['requestPath'] ?? '/');
    $package = theme_effective_package($activeTheme);
    if ($package === null) return template_unavailable_document($variables);
    $layout = theme_package_view($package, 'layout');
    $slots = theme_layout_slots($variables);
    $styles = function_exists('extension_stylesheets') ? extension_stylesheets($requestPath) : '';
    $scripts = function_exists('extension_javascripts') ? extension_javascripts($requestPath) : '';
    $override = $allowPlugins && extension_candidate_plugins('view', 'layout') ? extension_view_html('layout', [
        'title'=>(string)($variables['title'] ?? ''),
        'path'=>$requestPath,
        'site'=>$variables['c'],
        'viewer'=>plugin_viewer_summary($variables['user'] ?? null),
        'content'=>ThemeTrustedHtml::fromCore((string)($variables['body'] ?? '')),
        'navigation'=>ThemeTrustedHtml::fromCore((string)($variables['nav'] ?? '')),
        'slots'=>array_map(ThemeTrustedHtml::fromCore(...), $slots),
        'styles'=>ThemeTrustedHtml::fromCore($styles),
        'scripts'=>ThemeTrustedHtml::fromCore($scripts),
    ]) : null;
    if ($override !== null) return $override;
    if ($layout === null) return template_unavailable_document($variables);
    $variables['c']['theme'] = $variables['safe'] = $package['name'];
    $surface = (string)($variables['themeSurface'] ?? theme_asset_surface($requestPath));
    $variables = theme_layout_variables(
        $variables,
        $package,
        $slots,
        theme_runtime_assets($package['name'], $surface).core_page_assets($surface),
        $styles,
        $scripts
    );
    return theme_include_view($layout, $variables);
}
function page(string $title, string $body, string $description='轻量、开放的社区讨论', bool $indexable=false): void {
    $installed = is_file(install_lock_file()) && is_file(config_file());
    $config = $installed ? load_config() : ['site'=>'LiteBBS', 'theme'=>'default', 'url'=>''];

    $theme = (string)($config['theme'] ?? 'default');
    if (theme_effective_package($theme) === null) {
        echo template_unavailable_document([
            'c'=>[
                'site'=>(string)($config['site'] ?? 'LiteBBS'),
                'locale'=>(string)($config['locale'] ?? 'zh-CN'),
            ],
        ]);
        return;
    }

    $user = $installed ? current_user() : null;
    if ($user || !$indexable) header('Cache-Control: private, no-store');

    $scheme = request_is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $canonical = rtrim((string)($config['url'] ?? ($scheme.'://'.$host)), '/').$requestPath;
    $nav = '';
    if ($installed) {
        $version = cache_version('categories');
        $categories = cached('categories:nav:'.$version, 60, static function(): array {
            return h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll();
        });
        foreach ($categories as $category) {
            if (!extension_permits('category.view', ['actor'=>$user, 'category'=>$category])) continue;
            $id = (int)$category['id'];
            $nav .= '<a class="nav-item'.($requestPath === '/c/'.$id ? ' active' : '').'" href="/c/'.$id.'"><span>'.e($category['title']).'</span></a>';
        }
    }

    $variables = [
        'title'=>$title,
        'body'=>$body,
        'description'=>$description,
        'indexable'=>$indexable,
        'canonical'=>$canonical,
        'requestPath'=>$requestPath,
        'c'=>[
            'site'=>(string)($config['site'] ?? 'LiteBBS'),
            'url'=>(string)($config['url'] ?? ''),
            'theme'=>$theme,
            'locale'=>(string)($config['locale'] ?? 'zh-CN'),
        ],
        'isHomePage'=>$requestPath === '/',
        'isTopicPage'=>preg_match('~^/t/\d+~', $requestPath) === 1,
        'user'=>$user,
        'safe'=>$theme,
        'nav'=>$nav,
        'left'=>$nav,
    ];

    $html = render_core_layout($variables, $installed);
    if ($installed) $html = extension_filter('view.document', $html, ['path'=>$requestPath, 'title'=>$title, 'viewer'=>plugin_viewer_summary($user)]);
    echo seo_inject_head($html, $variables);
}

}

namespace {
// ===== seo.php =====
function seo_normalize_path(string $path): string
{
    $path = parse_url($path, PHP_URL_PATH) ?: '/';
    if (!str_starts_with($path, '/')) $path = '/';
    return rtrim($path, '/') ?: '/';
}

function seo_normalize_canonical(string $canonical): string
{
    $parts = parse_url($canonical);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return $canonical;
    $port = isset($parts['port']) ? ':'.(int)$parts['port'] : '';
    $path = seo_normalize_path((string)($parts['path'] ?? '/'));
    $query = [];
    if (isset($parts['query'])) parse_str((string)$parts['query'], $query);
    elseif ($path === seo_normalize_path((string)($_SERVER['REQUEST_URI'] ?? '/'))) $query = $_GET;
    return strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']).$port.$path;
}

function seo_indexing_enabled(): bool
{
    $config = load_config();
    $seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
    return (string)($seo['indexing'] ?? '1') !== '0';
}

function seo_inject_head(string $html, array $page): string
{
    if (!preg_match('~</head\s*>~i', $html) || preg_match('~<meta\s+[^>]*name\s*=\s*["\']robots["\']~i', $html)) return $html;
    $head = seo_head(
        (string)($page['title'] ?? ''),
        (string)($page['description'] ?? ''),
        !empty($page['indexable']),
        (string)($page['canonical'] ?? ''),
        is_array($page['c'] ?? null) ? $page['c'] : []
    );
    if (preg_match('~<title\b~i', $html)) $head = (string)preg_replace('~<title>.*?</title>\s*~s', '', $head, 1);
    return (string)preg_replace('~</head\s*>~i', $head."\n</head>", $html, 1);
}

function seo_head(string $title, string $description, bool $indexable, string $canonical, array $site): string
{
    $config = load_config();
    $seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
    $siteName = (string)($site['site'] ?? 'LiteBBS');
    $fullTitle = $title === '' || $title === $siteName ? $siteName : $title.' · '.$siteName;
    $canonicalPath = seo_normalize_path($canonical);
    if ($canonicalPath === '/' && trim((string)($seo['home_description'] ?? '')) !== '') $description = (string)$seo['home_description'];
    $indexable = $indexable && seo_indexing_enabled();
    $contributions = is_file(install_lock_file()) && is_file(config_file())
        ? extension_seo_contributions(['title'=>$title, 'description'=>$description, 'canonical'=>$canonical, 'indexable'=>$indexable, 'site'=>$site])
        : [];
    $jsonLd = [];
    $robots = $indexable ? 'index,follow' : 'noindex,follow';
    foreach ($contributions as $contribution) {
        if ($contribution->canonical !== null) $canonical = $contribution->canonical;
        if ($contribution->robots !== null) { $robots = $contribution->robots; $indexable = $robots === 'index,follow'; }
        if ($contribution->jsonLd) $jsonLd[] = $contribution->jsonLd;
    }
    $summary = trim((string)preg_replace('/\s+/u', ' ', strip_tags($description)));
    $summary = text_slice($summary, 0, 220);
    $html = '<meta name="description" content="'.e($summary).'">'."\n";
    $html .= '<meta name="robots" content="'.e($robots).'">'."\n";
    $html .= '<title>'.e($fullTitle).'</title>';
    foreach (['google-site-verification'=>'google_verification','msvalidate.01'=>'bing_verification','baidu-site-verification'=>'baidu_verification','yandex-verification'=>'yandex_verification','naver-site-verification'=>'naver_verification'] as $name=>$key) {
        $value = trim((string)($seo[$key] ?? ''));
        if ($value !== '') $html .= "\n".'<meta name="'.e($name).'" content="'.e($value).'">';
    }
    if (!$indexable) return $html;

    $canonical = seo_normalize_canonical($canonical);
    $parts = parse_url($canonical);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return $html;
    $origin = strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']).(isset($parts['port']) ? ':'.(int)$parts['port'] : '');
    $locale = str_replace('-', '_', (string)($site['locale'] ?? 'zh-CN'));
    $page = ['@type'=>'WebPage','@id'=>$canonical.'#webpage','url'=>$canonical,'name'=>$fullTitle,'description'=>$summary,'isPartOf'=>['@id'=>$origin.'/#website']];
    if (preg_match('~^/t/[1-9][0-9]*$~', (string)($parts['path'] ?? ''))) {
        $page['mainEntity'] = ['@type'=>'DiscussionForumPosting','@id'=>$canonical.'#discussion','url'=>$canonical,'headline'=>$title,'description'=>$summary];
    }
    $schema = ['@context'=>'https://schema.org','@graph'=>[
        ['@type'=>'WebSite','@id'=>$origin.'/#website','url'=>$origin.'/','name'=>$siteName],
        $page,
    ]];
    $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html .= "\n".'<link rel="canonical" href="'.e($canonical).'">';
    $html .= "\n".'<meta property="og:type" content="website">';
    $html .= "\n".'<meta property="og:locale" content="'.e($locale).'">';
    $html .= "\n".'<meta property="og:site_name" content="'.e($siteName).'">';
    $html .= "\n".'<meta property="og:title" content="'.e($fullTitle).'">';
    $html .= "\n".'<meta property="og:description" content="'.e($summary).'">';
    $html .= "\n".'<meta property="og:url" content="'.e($canonical).'">';
    $html .= "\n".'<meta name="twitter:card" content="summary">';
    $html .= "\n".'<meta name="twitter:title" content="'.e($fullTitle).'">';
    $html .= "\n".'<meta name="twitter:description" content="'.e($summary).'">';
    if (is_string($schemaJson)) $html .= "\n".'<script type="application/ld+json">'.$schemaJson.'</script>';
    foreach ($jsonLd as $item) {
        $encoded = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (is_string($encoded)) $html .= "\n".'<script type="application/ld+json">'.$encoded.'</script>';
    }
    return $html;
}

}

namespace {
// ===== outbox.php =====
function enqueue_outbox_event(string $name, array $payload, int $delaySeconds = 0): void
{
    if (!preg_match('/^[a-z][a-z0-9._-]{1,95}$/', $name)) throw new InvalidArgumentException('Invalid outbox event name.');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // Each committed domain action is an event. Reusing a payload must not
    // suppress a later action from the same user or aggregate.
    $key = hash('sha256', $name.'|'.bin2hex(random_bytes(32)));
    h('INSERT IGNORE INTO outbox_events(event_name,event_key,payload_json,available_at,created_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),UTC_TIMESTAMP(6))', [$name, $key, $json, $delaySeconds]);
}

function process_outbox_events(int $limit = 100): int
{
    $processed = 0;
    $db = db();
    for ($i = 0; $i < $limit; $i++) {
        $lockAcquired = false;
        $db->beginTransaction();
        try {
            // Prefer never-leased work. Keeping expired leases in a second query
            // preserves the queue contract while letting MySQL use a narrow index.
            $event = h('SELECT id,event_name,event_key,payload_json,attempts FROM outbox_events WHERE processed_at IS NULL AND lease_until IS NULL AND available_at<=UTC_TIMESTAMP(6) ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$event) $event = h('SELECT id,event_name,event_key,payload_json,attempts FROM outbox_events WHERE processed_at IS NULL AND lease_until IS NOT NULL AND lease_until<=UTC_TIMESTAMP(6) AND available_at<=UTC_TIMESTAMP(6) ORDER BY lease_until,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$event) { $db->rollBack(); break; }
            $id = (string)$event['id'];
            // Check the advisory lock before replacing an expired lease. The
            // previous worker can still finish while it holds this lock.
            $lockName = 'litebbs:outbox:'.$id;
            $lockResult = (string)h('SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn();
            if ($lockResult === '0') {
                h('UPDATE outbox_events SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 5 SECOND) WHERE id=?', [$id]);
                $db->commit();
                continue;
            }
            if ($lockResult !== '1') throw new RuntimeException('Could not acquire outbox advisory lock.');
            $lockAcquired = true;
            $leaseToken = bin2hex(random_bytes(32));
            // Long enough for the worker timeout; consumers must still make side effects idempotent.
            h('UPDATE outbox_events SET attempts=attempts+1,lease_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 300 SECOND) WHERE id=?', [$leaseToken, $id]);
            $attempt = (int)$event['attempts'] + 1;
            $db->commit();
            try {
                $name = (string)$event['event_name'];
                $payload = json_decode((string)$event['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new RuntimeException('Outbox payload must be an object.');
                extension_validate_async_event_payload($name, $payload);
                $eventContext = ['outbox_event_key'=>(string)$event['event_key']];
                extension_runtime_load(null, 'event', $name);
                $listeners = extension_registry()['events'][$name] ?? [];
                $checked = [];
                foreach ($listeners as $entry) {
                    $plugin = $entry['plugin'];
                    if (isset($checked[$plugin])) continue;
                    $checked[$plugin] = true;
                    if (!extension_plugin_degraded($plugin, true)) continue;
                    // Wait out the health cooldown before invoking any listener.
                    // A cooldown is not a delivery attempt.
                    h('UPDATE outbox_events SET attempts=attempts-1,available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', ['Plugin event '.$plugin.' deferred during health cooldown.', $id, $leaseToken]);
                    continue 2;
                }
                foreach ($listeners as $entry) {
                    try {
                        ($entry['listener'])($payload, $eventContext);
                    } catch (Throwable $listenerError) {
                        extension_plugin_failure($entry['plugin']);
                        throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $listenerError);
                    }
                }
                if (h("UPDATE outbox_events SET processed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error='' WHERE id=? AND lease_token=?", [$id, $leaseToken])->rowCount() === 1) $processed++;
            } catch (Throwable $error) {
                $message = text_slice($error->getMessage(), 0, 500);
                if ($attempt >= 10) {
                    $db->beginTransaction();
                    if (h('UPDATE outbox_events SET processed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, $id, $leaseToken])->rowCount() === 1) {
                        h('INSERT IGNORE INTO failed_outbox_events(event_id,event_name,event_key,payload_json,attempts,last_error,failed_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$id, $event['event_name'], $event['event_key'], $event['payload_json'], $attempt, $message]);
                    }
                    $db->commit();
                } else {
                    h('UPDATE outbox_events SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL LEAST(300,POW(2,attempts)) SECOND),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, $id, $leaseToken]);
                }
                error_log('outbox event delivery failed: '.$message);
            }
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } finally {
            if ($lockAcquired) h('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
    return $processed;
}

}

namespace {
// ===== notifications.php =====
function extension_create_notification(int $recipientId, int $actorId, string $kind, ?int $topicId = null, ?int $postId = null): int
{
    if ($recipientId < 1 || $actorId < 1 || !preg_match('/^[a-z][a-z0-9._-]{1,95}$/', $kind)) throw new InvalidArgumentException('Invalid notification.');
    h('INSERT INTO notifications(user_id,actor_id,topic_id,post_id,kind,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))', [$recipientId, $actorId, $topicId, $postId, $kind]);
    $notificationId = (int)db()->lastInsertId();
    if ($notificationId < 1) throw new RuntimeException('Notification could not be created.');
    extension_enqueue_async_event('notification.created', ['notification_id'=>$notificationId,'recipient_id'=>$recipientId,'actor_id'=>$actorId,'kind'=>$kind,'topic_id'=>$topicId ?? 0,'post_id'=>$postId ?? 0]);
    return $notificationId;
}

function process_notification_outbox(int $limit = 1): int {
    $db=db();
    $batches=0;
    $limit=max(1,min(100,$limit));
    while($batches<$limit){
        $invalidatedUsers=[];
        $db->beginTransaction();
        try{
            $event=h('SELECT id,topic_id,post_id,actor_id,fanout_cursor FROM notification_outbox WHERE processed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if(!$event){$db->commit();break;}
            $visible = h('SELECT 1 FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.id=? AND p.topic_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 FOR UPDATE', [(int)$event['post_id'], (int)$event['topic_id']])->fetchColumn();
            if (!$visible) { h('UPDATE notification_outbox SET processed_at=UTC_TIMESTAMP(6),pending_topic_id=NULL WHERE id=?', [(int)$event['id']]); $db->commit(); $batches++; continue; }
            h('UPDATE notification_outbox SET pending_topic_id=NULL WHERE id=?',[(int)$event['id']]);
            // Participants are maintained on writes, so a hot topic never rescans its posts.
            // The cursor is a user ID so batches remain deterministic across workers.
            $recipientSource=topic_participants_available()?'topic_participants':'posts';
            $participantWhere=$recipientSource==='posts'?'topic_id=? AND status="normal" AND user_id>?':'topic_id=? AND user_id>?';
            $recipients=h('SELECT user_id FROM (SELECT user_id FROM topic_subscriptions WHERE topic_id=? AND user_id>? UNION SELECT user_id FROM topics WHERE id=? AND user_id>? UNION SELECT user_id FROM '.$recipientSource.' WHERE '.$participantWhere.') candidates WHERE user_id<>? AND user_id<>(SELECT user_id FROM topics WHERE id=?) ORDER BY user_id LIMIT 501',[(int)$event['topic_id'],(int)$event['fanout_cursor'],(int)$event['topic_id'],(int)$event['fanout_cursor'],(int)$event['topic_id'],(int)$event['fanout_cursor'],(int)$event['actor_id'],(int)$event['topic_id']])->fetchAll(PDO::FETCH_COLUMN);
            $hasMore=count($recipients)>500;
            if($hasMore)array_pop($recipients);
            if($recipients){
                $tuples=implode(',',array_fill(0,count($recipients),'(?,?,?,?,?,UTC_TIMESTAMP(6))'));
                $parameters=[];
                foreach($recipients as $userId)array_push($parameters,(int)$userId,(int)$event['actor_id'],(int)$event['topic_id'],(int)$event['topic_id'],(int)$event['post_id']);
                $marks=implode(',',array_fill(0,count($recipients),'?'));
                $existing=array_flip(array_map('intval',h('SELECT user_id FROM notifications WHERE coalesce_topic_id=? AND kind="reply" AND read_at IS NULL AND user_id IN ('.$marks.') FOR UPDATE',array_merge([(int)$event['topic_id']],array_map('intval',$recipients)))->fetchAll(PDO::FETCH_COLUMN)));
                h('INSERT INTO notifications(user_id,actor_id,topic_id,coalesce_topic_id,post_id,created_at) VALUES '.$tuples.' ON DUPLICATE KEY UPDATE actor_id=IF(VALUES(post_id)>post_id,VALUES(actor_id),actor_id),created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))',$parameters);
                $created=array_values(array_filter(array_map('intval',$recipients),static fn(int $userId):bool=>!isset($existing[$userId])));
                if($created){$createdMarks=implode(',',array_fill(0,count($created),'?'));foreach(h('SELECT id,user_id FROM notifications WHERE coalesce_topic_id=? AND kind="reply" AND read_at IS NULL AND user_id IN ('.$createdMarks.')',array_merge([(int)$event['topic_id']],$created))->fetchAll() as $notification)extension_enqueue_async_event('notification.created',['notification_id'=>(int)$notification['id'],'recipient_id'=>(int)$notification['user_id'],'actor_id'=>(int)$event['actor_id'],'kind'=>'reply','topic_id'=>(int)$event['topic_id'],'post_id'=>(int)$event['post_id']]);}
                $nextCursor=(int)end($recipients);
                if($hasMore)h('UPDATE notification_outbox SET fanout_cursor=? WHERE id=?',[$nextCursor,(int)$event['id']]);
            }
            if(!$hasMore)h('UPDATE notification_outbox SET processed_at=UTC_TIMESTAMP(6) WHERE id=?',[(int)$event['id']]);
            $db->commit();
            foreach($recipients??[] as $userId)$invalidatedUsers[(int)$userId]=true;
            invalidate_user_caches(array_keys($invalidatedUsers),true);
            $batches++;
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    return $batches;
}

}

namespace {
// ===== read-model.php =====
function topic_page_viewer_state(array $posts, int $topicId, ?array $viewer): array
{
    $state = ['bookmarked'=>false, 'subscribed'=>false, 'likes'=>[], 'liked'=>[], 'blocked'=>[]];
    if (!$viewer) return $state;
    $viewerId = (int)$viewer['id'];
    $preferences = h('SELECT EXISTS(SELECT 1 FROM bookmarks WHERE user_id=? AND topic_id=?) AS bookmarked,EXISTS(SELECT 1 FROM topic_subscriptions WHERE user_id=? AND topic_id=?) AS subscribed', [$viewerId, $topicId, $viewerId, $topicId])->fetch();
    $state['bookmarked'] = !empty($preferences['bookmarked']);
    $state['subscribed'] = !empty($preferences['subscribed']);
    if (!$posts) return $state;
    $postIds = array_values(array_unique(array_map(static fn(array $post): int => (int)$post['id'], $posts)));
    $authorIds = array_values(array_unique(array_map(static fn(array $post): int => (int)$post['user_id'], $posts)));
    $postMarks = implode(',', array_fill(0, count($postIds), '?'));
    $authorMarks = implode(',', array_fill(0, count($authorIds), '?'));
    foreach (h('SELECT post_id,COUNT(*) total,MAX(user_id=?) liked FROM reactions WHERE reaction="like" AND post_id IN ('.$postMarks.') GROUP BY post_id', array_merge([$viewerId], $postIds))->fetchAll() as $row) { $postId=(int)$row['post_id'];$state['likes'][$postId]=(int)$row['total'];if(!empty($row['liked']))$state['liked'][$postId]=true; }
    foreach (h('SELECT blocked_user_id FROM user_blocks WHERE user_id=? AND blocked_user_id IN ('.$authorMarks.')', array_merge([$viewerId], $authorIds))->fetchAll(PDO::FETCH_COLUMN) as $authorId) $state['blocked'][(int)$authorId] = true;
    return $state;
}

}

namespace {
// ===== account-routes.php =====
use LiteBBS\Application\AccountRegistrationController;
use LiteBBS\Application\DuplicateAccountIdentity;
use LiteBBS\Application\PasswordPolicy;

function handle_account_routes(\LiteBBS\Framework\Request $request): void
{
$query = $request->query;
$post = $request->post;
if ($request->path === '/logout' && $request->method === 'POST') {
    require_post();
    if ($user = current_user()) h('DELETE FROM sessions WHERE id=? AND user_id=?', [$_SESSION['sid'], $user['id']]);
    start_session();
    $_SESSION = [];
    session_destroy();
    clear_session_cookie();
    redirect('/');
}

if ($request->path === '/register' && in_array($request->method, ['GET', 'POST'], true)) {
    (new AccountRegistrationController())($request);
    return;
}

if ($request->path === '/login' && in_array($request->method, ['GET', 'POST'], true)) {
    $returnTo = (string)($post['return_to'] ?? $query['return_to'] ?? '');
    if (!preg_match('~^/new(?:\?[^#\r\n]{0,14000})?$~D', $returnTo)) $returnTo = '';
    if ($request->method === 'POST') {
        require_post();
        $user = (new \LiteBBS\Application\AccountAuthenticator())->verify($request);
        if ($user !== null) {
            $decision = extension_authentication_decision($user, ['ip'=>(string)($request->server['REMOTE_ADDR'] ?? ''), 'user_agent'=>(string)($request->server['HTTP_USER_AGENT'] ?? '')]);
            if ($decision->state === 'deny') fail(403, '认证被拒绝');
            login_user((int)$user['id']);
            redirect($returnTo !== '' ? $returnTo : '/');
        }
        $error = '账号或密码不正确。';
    }
    $loginFields = ($returnTo !== '' ? '<input type="hidden" name="return_to" value="'.e($returnTo).'">' : '').input('account', '用户名或邮箱').input('password', '密码', 'password');
    $coreBody = '<p class="error">'.e($error ?? '欢迎回来。').'</p>'.form('/login', '登录', $loginFields.extension_slot('login.form.fields.after', []), '登录').extension_slot('login.form.after', []);
    $body = theme_render_page('account.login', ['viewer'=>current_user(), 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
    page('登录', $body);
    return;
}

if ($request->path === '/me' && in_array($request->method, ['GET', 'POST'], true)) {
    $user = current_user();
    if (!$user) redirect('/login');
    $tab = (string)($query['tab'] ?? 'overview');
    if ($request->method === 'POST') {
        require_post();
        $action = (string)($post['action'] ?? 'profile');
        if ($action === 'password') {
            $current = (string)($post['current_password'] ?? '');
            $new = $post['new_password'] ?? '';
            $hash = (string)h('SELECT password_hash FROM users WHERE id=?', [$user['id']])->fetchColumn();
            if (!password_verify($current, $hash) || !is_string($new) || !PasswordPolicy::accepts($new)) fail(400, '当前密码不正确，或新密码长度不符合要求');
            \LiteBBS\Application\transaction_manager()->run(static function () use ($new, $user): void {
                h('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), $user['id']]);
                h('DELETE FROM sessions WHERE user_id=?', [$user['id']]);
                audit('account.password.changed', 'user', (int)$user['id']);
            });
            login_user((int)$user['id']);
            redirect('/me?tab=security');
        }
        if ($action === 'profile') {
            $email = trim((string)($post['email'] ?? ''));
            $bio = trim((string)($post['bio'] ?? ''));
            $locale = (string)($post['locale'] ?? 'zh-CN');
            $draft = extension_apply_profile_command(new UserProfileDraft($email, $bio, $locale), ['user'=>$user]);
            $email = $draft->email;
            $bio = $draft->bio;
            $locale = $draft->locale;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($bio) > 500 || !in_array($locale, ['zh-CN', 'en-US'], true)) fail(400, '资料格式无效');
            try { h('UPDATE users SET email=?,bio=?,locale=? WHERE id=?', [$email, $bio, $locale, $user['id']]); }
            catch (PDOException $error) {
                if (!DuplicateAccountIdentity::matches($error)) throw $error;
                fail(409, '该邮箱已被使用');
            }
            extension_event_async('user.profile.updated', ['user_id'=>(int)$user['id']]);
            redirect('/me?tab=profile');
        }
        if ($action === 'notifications-read') {
            h('UPDATE notifications SET read_at=UTC_TIMESTAMP(6),coalesce_topic_id=NULL WHERE user_id=? AND read_at IS NULL', [$user['id']]);
            invalidate_user_cache((int)$user['id']);
            redirect('/me?tab=notifications');
        }
        if ($action === 'notifications-clear') {
            h('DELETE FROM notifications WHERE user_id=?', [$user['id']]);
            invalidate_user_cache((int)$user['id']);
            redirect('/me?tab=notifications');
        }
        fail(400, '操作无效');
    }
    $user = h('SELECT id,username,email,role,points,locale,bio,created_at FROM users WHERE id=?', [$user['id']])->fetch();
    $tabs = ['overview'=>'概览', 'profile'=>'个人资料', 'security'=>'账户安全', 'notifications'=>'通知', 'messages'=>'私信', 'bookmarks'=>'收藏', 'blocks'=>'屏蔽用户'];
    $pluginTabs = extension_profile_tabs();
    foreach ($pluginTabs as $entry) $tabs[$entry['tab']->id] = $entry['tab']->label;
    $activePluginTab = null;
    foreach ($pluginTabs as $entry) if ($entry['tab']->id === $tab) { $activePluginTab = $entry; break; }
    if (!isset($tabs[$tab])) fail(404, '会员中心标签不存在');
    $themeViewer = ['id'=>(int)$user['id'],'username'=>(string)$user['username'],'role'=>(string)$user['role'],'points'=>(int)$user['points'],'locale'=>(string)$user['locale'],'created_at'=>(string)$user['created_at']];
    $themeTabs = [];
    foreach ($tabs as $key=>$label) $themeTabs[] = ['id'=>$key,'label'=>$label,'href'=>$key === 'messages' ? '/messages' : '/me?tab='.rawurlencode($key),'active'=>$tab === $key];
    $themeOverview = ['unread_notifications'=>0,'unread_messages'=>0,'bookmark_count'=>0,'recent_posts'=>[]];
    $body = '<section class="member-center"><header class="member-hero"><span class="member-avatar">'.e(text_slice($user['username'], 0, 1)).'</span><div><p class="eyebrow">ACCOUNT CENTER</p><h1>'.e($user['username']).'</h1><p>'.e($user['email']).' · 积分 '.(int)$user['points'].'</p></div><a href="/u/'.rawurlencode($user['username']).'">公开资料</a></header><nav class="member-tabs" aria-label="会员中心">';
    foreach ($tabs as $key=>$label) $body .= '<a href="'.($key === 'messages' ? '/messages' : '/me?tab='.$key).'"'.($tab === $key ? ' aria-current="page"' : '').'>'.$label.'</a>';
    $body .= '</nav><div class="member-panel">';
    if ($activePluginTab !== null) {
        $content = ($activePluginTab['tab']->renderer)(['user'=>$user, 'viewer'=>$user, 'tab'=>$tab]);
        if (!is_string($content) || strlen($content) > 1048576) throw new RuntimeException('Plugin profile tab returned invalid output.');
        $body .= $content;
    } elseif ($tab === 'profile') $body .= '<h2>个人资料</h2><form class="member-form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="profile"><label>用户名<input value="'.e($user['username']).'" disabled></label><label>邮箱<input type="email" name="email" maxlength="254" value="'.e($user['email']).'" required></label><label>个人介绍<textarea name="bio" maxlength="500" rows="5">'.e($user['bio']).'</textarea></label><label>界面语言<select name="locale"><option value="zh-CN"'.($user['locale'] === 'zh-CN' ? ' selected' : '').'>简体中文</option><option value="en-US"'.($user['locale'] === 'en-US' ? ' selected' : '').'>English</option></select></label>'.extension_slot('member.profile.form.after', ['user'=>$user]).'<button class="primary">保存资料</button></form><form id="avatar-form" class="member-form avatar-form" method="post" action="/avatar/upload" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.e(csrf()).'"><label>头像<input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required></label><button>上传头像</button></form>';
    elseif ($tab === 'security') $body .= '<h2>修改密码</h2><form class="member-form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="password"><label>当前密码<input type="password" name="current_password" autocomplete="current-password" required></label><label>新密码<input type="password" name="new_password" minlength="'.PasswordPolicy::MIN_CHARACTERS.'" maxlength="'.PasswordPolicy::MAX_CHARACTERS.'" autocomplete="new-password" required><small>'.PasswordPolicy::MIN_CHARACTERS.' 至 '.PasswordPolicy::MAX_CHARACTERS.' 个字符。保存后会退出其他设备。</small></label><button class="primary">更新密码</button></form>';
    elseif ($tab === 'notifications') {
        $notificationTabs = extension_notification_tabs();
        $notificationBox = (string)($query['box'] ?? 'all');
        $activeNotificationTab = null;
        foreach ($notificationTabs as $entry) if ($entry['tab']->id === $notificationBox) { $activeNotificationTab = $entry; break; }
        if ($notificationBox !== 'all' && $activeNotificationTab === null) fail(404, '通知标签不存在');
        $body .= '<h2>通知</h2><nav class="member-tabs" aria-label="通知标签"><a href="/me?tab=notifications"'.($notificationBox === 'all' ? ' aria-current="page"' : '').'>全部</a>';
        foreach ($notificationTabs as $entry) $body .= '<a href="/me?tab=notifications&amp;box='.rawurlencode($entry['tab']->id).'"'.($notificationBox === $entry['tab']->id ? ' aria-current="page"' : '').'>'.e($entry['tab']->label).'</a>';
        $body .= '</nav>';
        if ($activeNotificationTab !== null) {
            $content = ($activeNotificationTab['tab']->renderer)(['user'=>$user, 'viewer'=>$user, 'tab'=>$notificationBox]);
            if (!is_string($content) || strlen($content) > 1048576) throw new RuntimeException('Plugin notification tab returned invalid output.');
            $body .= $content;
        } else {
        $rows = h('SELECT n.id,n.actor_id,n.topic_id,n.post_id,n.kind,n.read_at,n.created_at,u.username,t.title FROM notifications n JOIN users u ON u.id=n.actor_id LEFT JOIN topics t ON t.id=n.topic_id LEFT JOIN categories c ON c.id=t.category_id WHERE n.user_id=? AND (n.kind="message" OR (n.kind LIKE "plugin.%" AND (n.topic_id IS NULL OR (t.status="normal" AND c.is_visible=1))) OR (t.status="normal" AND c.is_visible=1)) ORDER BY n.created_at DESC,n.id DESC LIMIT 30', [$user['id']])->fetchAll();
        extension_preload('notification.list', ['notifications'=>$rows, 'recipient'=>$user]);
        $body .= '<div class="member-actions"><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="notifications-read"><button>全部标记为已读</button></form><form class="member-clear-notifications" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="notifications-clear"><button>清空通知</button></form></div><div class="member-list">';
        foreach ($rows as $row) {
            $presentation = str_starts_with((string)$row['kind'], 'plugin.') ? extension_notification_presentation($row, $user) : null;
            if (str_starts_with((string)$row['kind'], 'plugin.') && $presentation === null) continue;
            $href = $presentation?->href ?? ($row['kind'] === 'message' ? '/messages?to='.rawurlencode($row['username']) : '/t/'.(int)$row['topic_id'].'#p'.(int)$row['post_id']);
            $title = $presentation?->title ?? ((string)$row['username'].' '.($row['kind'] === 'message' ? '给你发送了私信' : '回复了你的主题'));
            $summary = $presentation?->summary ?? (string)($row['title'] ?? '');
            $body .= '<a class="member-row" href="'.e($href).'"><span>'.e($title).'</span><span>'.e($summary).'</span><time>'.e(substr((string)$row['created_at'], 0, 16)).'</time></a>';
        }
        $body .= $rows ? '' : '<p class="empty-state">暂无通知</p>';
        $body .= '</div>';
        }
    } elseif ($tab === 'bookmarks') {
        $rows = h('SELECT t.id,t.title,t.reply_count,t.last_post_at FROM bookmarks b JOIN topics t ON t.id=b.topic_id JOIN categories c ON c.id=t.category_id WHERE b.user_id=? AND t.status="normal" AND c.is_visible=1 ORDER BY b.created_at DESC LIMIT 50', [$user['id']])->fetchAll();
        $body .= '<h2>收藏的主题</h2><div class="member-list">';
        foreach ($rows as $row) $body .= '<a class="member-row" href="/t/'.(int)$row['id'].'"><strong>'.e($row['title']).'</strong><span>'.(int)$row['reply_count'].' 条回复</span><time>'.e(substr((string)$row['last_post_at'], 0, 16)).'</time></a>';
        $body .= $rows ? '' : '<p class="empty-state">还没有收藏主题</p>';
        $body .= '</div>';
    } elseif ($tab === 'blocks') {
        $rows = h('SELECT u.id,u.username FROM user_blocks b JOIN users u ON u.id=b.blocked_user_id WHERE b.user_id=? ORDER BY b.created_at DESC', [$user['id']])->fetchAll();
        $body .= '<h2>屏蔽用户</h2><div class="member-list">';
        foreach ($rows as $row) $body .= '<div class="member-row"><a href="/u/'.rawurlencode($row['username']).'">'.e($row['username']).'</a><form method="post" action="/user/'.(int)$row['id'].'/unblock"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>取消屏蔽</button></form></div>';
        $body .= $rows ? '' : '<p class="empty-state">没有屏蔽用户</p>';
        $body .= '</div>';
    } else {
        $posts = h('SELECT p.id,p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.user_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 ORDER BY p.id DESC LIMIT 20', [$user['id']])->fetchAll();
        [$unreadNotifications,$unreadMessages] = unread_counts((int)$user['id']);
        $bookmarks = (int)h('SELECT COUNT(*) FROM bookmarks WHERE user_id=?', [$user['id']])->fetchColumn();
        $body .= '<div class="member-stats"><a href="/me?tab=notifications"><strong>'.$unreadNotifications.'</strong><span>未读通知</span></a><a href="/me?tab=bookmarks"><strong>'.$bookmarks.'</strong><span>收藏主题</span></a><a href="/messages"><strong>'.$unreadMessages.'</strong><span>未读私信</span></a></div><h2>最近发帖</h2><div class="member-list">';
        foreach ($posts as $post) $body .= '<article class="member-row"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><span>'.e(text_slice($post['body_markdown'], 0, 100)).'</span><a href="/p/'.(int)$post['id'].'/edit">编辑</a></article>';
        $body .= $posts ? '' : '<p class="empty-state">还没有发布内容</p>';
        $body .= '</div>';
    }
    $coreBody = $body.'</div></section>';
    if ($tab === 'overview') {
        $themeOverview = ['unread_notifications'=>$unreadNotifications ?? 0,'unread_messages'=>$unreadMessages ?? 0,'bookmark_count'=>$bookmarks ?? 0,'recent_posts'=>array_map(static fn(array $post): array => ['topic'=>['id'=>(int)$post['topic_id'],'title'=>(string)$post['title'],'href'=>'/t/'.(int)$post['topic_id']],'excerpt'=>text_slice((string)$post['body_markdown'], 0, 100),'created_at'=>(string)$post['created_at'],'edit_href'=>'/p/'.(int)$post['id'].'/edit'], $posts ?? [])];
    }
    $body = theme_render_page('member', ['viewer'=>$themeViewer, 'tabs'=>$themeTabs, 'active_tab'=>['id'=>$tab,'label'=>(string)$tabs[$tab]], 'overview'=>$themeOverview, 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
    page('会员中心', $body);
    return;
}

if (preg_match('~^/u/([^/]+)(?:/tab-([a-z][a-z0-9_-]{0,63})-([2-9]|[1-9][0-9]+))?$~', $request->path, $match) && $request->method === 'GET') {
    $name = rawurldecode($match[1]);
    $profile = h('SELECT id,username,points,bio,avatar_path,created_at FROM users WHERE username=? AND status="active"', [$name])->fetch();
    if (!$profile) fail(404, '用户不存在');
    $viewer = current_user();
    extension_authorize('profile.view', ['actor'=>$viewer, 'profile'=>$profile]);
    if (array_diff(array_keys($query), ['tab'])) fail(400, '公开资料标签分页参数无效');
    $profileTab = (string)($match[2] ?? $query['tab'] ?? 'posts');
    $profilePageNumber = isset($match[3]) ? (int)$match[3] : 1;
    if (isset($match[3]) && $query) fail(400, '公开资料标签分页参数无效');
    $profileTabs = extension_public_profile_tabs($profile, $viewer);
    $activeProfileTab = null;
    foreach ($profileTabs as $entry) if ($entry['tab']->id === $profileTab) { $activeProfileTab = $entry; break; }
    if ($profileTab !== 'posts' && $activeProfileTab === null) fail(404, '公开资料标签不存在');
    $posts = [];
    $profilePage = null;
    if ($activeProfileTab === null) {
        $posts = h('SELECT p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.user_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 ORDER BY p.id DESC LIMIT 20', [$profile['id']])->fetchAll();
    } else {
        $profilePage = extension_public_profile_page($activeProfileTab, new PublicProfilePageRequest($profile, $viewer, $profilePageNumber, LITEBBS_EXTENSION_PROFILE_PAGE_SIZE));
    }
    $avatarUrl = extension_avatar_url($profile);
    $avatar = $avatarUrl !== null ? '<img src="'.e($avatarUrl).'" alt="">' : e(text_slice($profile['username'], 0, 1));
    $body = '<section class="member-center"><header class="member-hero"><span class="member-avatar">'.$avatar.'</span><div><h1>'.e($profile['username']).'</h1><p>积分 '.(int)$profile['points'].' · 加入于 '.e(substr((string)$profile['created_at'], 0, 10)).'</p></div></header>'.extension_slot('profile.header.after', ['profile'=>$profile, 'viewer'=>$viewer]).'<p class="public-bio">'.nl2br(e((string)$profile['bio'])).'</p>';
    if ($viewer && (int)$viewer['id'] !== (int)$profile['id']) $body .= '<div class="topic-actions"><a href="/messages?to='.rawurlencode($profile['username']).'">发送私信</a><form method="post" action="/user/'.(int)$profile['id'].'/block"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>屏蔽用户</button></form><details class="report-control"><summary>举报用户</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="user"><input type="hidden" name="target_id" value="'.(int)$profile['id'].'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details></div>';
    if ($profileTabs) {
        $body .= '<nav class="member-tabs" aria-label="公开资料标签"><a href="/u/'.rawurlencode($profile['username']).'"'.($profileTab === 'posts' ? ' aria-current="page"' : '').'>最近发布</a>';
        foreach ($profileTabs as $entry) $body .= '<a href="/u/'.rawurlencode($profile['username']).'?tab='.rawurlencode($entry['tab']->id).'"'.($profileTab === $entry['tab']->id ? ' aria-current="page"' : '').'>'.e($entry['tab']->label).'</a>';
        $body .= '</nav>';
    }
    if ($activeProfileTab !== null) {
        $content = extension_item_callback($activeProfileTab['plugin'], 'public profile tab renderer', $activeProfileTab['tab']->renderer, [['profile'=>$profile, 'viewer'=>$viewer, 'tab'=>$profileTab, 'page'=>$profilePage], new PluginPreloadContext($activeProfileTab['plugin'], 'public-profile-tab', ['profile'=>$profile, 'viewer'=>$viewer])]);
        if ($content === null) $content = '';
        if (!is_string($content) || strlen($content) > 1048576) throw new RuntimeException('Public profile tab returned invalid output.');
        $body .= $content;
        if ($profilePage instanceof PublicProfilePage && $profilePage->hasMore()) $body .= '<nav class="pagination"><a class="pagination-next" href="/u/'.rawurlencode($profile['username']).'/tab-'.rawurlencode($profileTab).'-'.($profilePageNumber + 1).'">加载更多</a></nav>';
    } else {
        $body .= '<h2>最近发布</h2>';
        foreach ($posts as $post) $body .= '<article class="member-post"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><p>'.e(text_slice($post['body_markdown'], 0, 180)).'</p></article>';
    }
    $coreBody = $body.extension_slot('profile.content.after', ['profile'=>$profile, 'viewer'=>$viewer]).'</section>';
    $tabModels = [['id'=>'posts','label'=>'最近发布','active'=>$profileTab === 'posts']];
    foreach ($profileTabs as $entry) $tabModels[] = ['id'=>$entry['tab']->id,'label'=>$entry['tab']->label,'active'=>$profileTab === $entry['tab']->id];
    $body = theme_render_page('profile', ['profile'=>$profile, 'viewer'=>$viewer, 'tabs'=>$tabModels, 'active_tab'=>$activeProfileTab === null ? null : ['id'=>$activeProfileTab['tab']->id,'label'=>$activeProfileTab['tab']->label], 'posts'=>$posts, 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
    page($profile['username'], $body);
    return;
}

if ($request->path === '/search' && $request->method === 'GET') {
    $term = trim((string)($query['q'] ?? ''));
    if (text_length($term) > 60) fail(400, '搜索词不能超过 60 个字符');
    $body = '<form class="search"><label>搜索主题标题<input name="q" maxlength="60" value="'.e($term).'"></label><button>搜索</button></form>';
    $searchResults = [];
    $viewer = current_user();
    if ($term !== '') {
        if (text_length($term) < 3) $body .= '<p>请输入至少三个字符。</p>';
        else {
            limit_action('search', 10, 300, $viewer ? 'user:'.$viewer['id'] : 'ip:'.($request->server['REMOTE_ADDR'] ?? ''));
            $ascii = preg_match('/^[\x00-\x7F]+$/', $term) === 1;
            $sql = $ascii ? 'MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE)' : 't.title LIKE ?';
            $arg = $ascii ? $term : $term.'%';
            $versions = cache_versions(['topics', 'categories']);
            $rows = cached('search:v2:'.hash('sha256', ($ascii ? 'fulltext:' : 'prefix:').$term).':'.(int)$versions['topics'].':'.(int)$versions['categories'], 30, static function () use ($sql, $arg): array {
                return h('SELECT t.id,t.title,t.reply_count,t.last_post_at,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1 AND '.$sql.' ORDER BY t.last_post_at DESC LIMIT 50', [$arg])->fetchAll();
            });
            foreach ($rows as $row) { $searchResults[] = ['source'=>'core','title'=>(string)$row['title'],'excerpt'=>'','href'=>'/t/'.(int)$row['id'],'category'=>['id'=>(int)$row['category_id'],'title'=>(string)$row['category']]]; $body .= '<article class="topic"><a href="/c/'.(int)$row['category_id'].'">'.e($row['category']).'</a><h2><a href="/t/'.(int)$row['id'].'">'.e($row['title']).'</a></h2></article>'; }
            foreach (extension_search($term, max(1, 50 - count($rows))) as $result) {
                $searchResults[] = ['source'=>(string)$result['plugin'],'title'=>(string)$result['title'],'excerpt'=>(string)($result['excerpt'] ?? ''),'href'=>(string)$result['url'],'category'=>null];
                $body .= '<article class="topic extension-search-result"><span>'.e((string)$result['plugin']).'</span><h2><a href="'.e((string)$result['url']).'">'.e((string)$result['title']).'</a></h2><p>'.e((string)($result['excerpt'] ?? '')).'</p></article>';
            }
            if (!$rows) $body .= '<p>没有找到匹配的主题。</p>';
        }
    }
    $coreBody = $body;
    $body = theme_render_page('search', ['viewer'=>$viewer, 'search'=>['term'=>$term,'submitted'=>$term !== ''], 'results'=>$searchResults, 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
    page('搜索讨论', $body, '仅搜索公开主题标题；中文搜索匹配标题前缀。');
}
}

}

namespace {
// ===== community.php =====
function handle_community_routes(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$post = $request->post;
$files = $request->files;
if ($path === '/avatar/upload' && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login');
    limit_action('avatar-upload', 5, 3600, 'user:'.$user['id']);
    $file = $files['avatar'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] > 2097152) fail(400, '头像无效或超过 2 MiB');
    $info = @getimagesize((string)$file['tmp_name']); $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
    if (!$info || !in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048 || $info[0] * $info[1] > 4194304) fail(400, '头像必须是 2048px 内的 JPG、PNG、GIF 或 WebP 图片');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) fail(503, '头像图片处理组件不可用');
    $image = @imagecreatefromstring((string)file_get_contents((string)$file['tmp_name']));
    if (!$image) fail(400, '头像图片无法解码');
    $width = imagesx($image); $height = imagesy($image); $scale = min(1, 512 / max($width, $height));
    $resized = imagecreatetruecolor(max(1, (int)floor($width * $scale)), max(1, (int)floor($height * $scale)));
    if (!$resized) { imagedestroy($image); fail(500, '头像图片处理失败'); }
    imagealphablending($resized, false); imagesavealpha($resized, true);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
    $dir = dirname(__DIR__).'/uploads/avatars'; if (!is_dir($dir) && !mkdir($dir, 0750, true)) { imagedestroy($resized); imagedestroy($image); fail(500, '头像目录不可用'); }
    $name = bin2hex(random_bytes(24)); $target = $dir.'/'.$name.'.png';
    $saved = imagepng($resized, $target, 6); imagedestroy($resized); imagedestroy($image);
    if (!$saved) fail(500, '头像保存失败');
    try { $old = (string)h('SELECT avatar_path FROM users WHERE id=?', [$user['id']])->fetchColumn(); h('UPDATE users SET avatar_path=? WHERE id=?', [$name.'.png', $user['id']]); }
    catch (Throwable $error) { @unlink($target); throw $error; }
    if ($old !== '') @unlink($dir.'/'.basename($old)); extension_event_async('user.avatar.updated',['user_id'=>(int)$user['id']]); redirect('/me?tab=profile');
}
if (preg_match('~^/avatar/(\d+)$~', $path, $match) && $method === 'GET') {
    $avatar = h('SELECT avatar_path FROM users WHERE id=? AND status="active"', [(int)$match[1]])->fetchColumn(); $file = $avatar ? dirname(__DIR__).'/uploads/avatars/'.basename((string)$avatar) : ''; if (!$file || !is_file($file)) fail(404, '头像不存在');
    $version = (string)($request->query['v'] ?? ''); $versioned = $version !== '' && hash_equals((string)$avatar, $version);
    $type=(new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'image/png';header('Content-Type: '.$type); header('X-Content-Type-Options: nosniff'); header('Cache-Control: '.($versioned ? 'public, max-age=86400, immutable' : 'no-cache'));if(getenv('LITEBBS_X_ACCEL_REDIRECT')==='1'){header('X-Accel-Redirect: /__litebbs-uploads/avatars/'.rawurlencode(basename((string)$avatar)));return;}readfile($file); return;
}
if (preg_match('~^/topic/(\d+)/(bookmark|subscribe)$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $topicId = (int)$match[1];
    if (!h('SELECT t.id FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1', [$topicId])->fetch()) fail(404, '主题不存在');
    $table = $match[2] === 'bookmark' ? 'bookmarks' : 'topic_subscriptions'; $db=db(); $db->beginTransaction();
    try { $exists = (bool)h('SELECT 1 FROM '.$table.' WHERE user_id=? AND topic_id=? FOR UPDATE', [$user['id'], $topicId])->fetchColumn(); if ($exists) h('DELETE FROM '.$table.' WHERE user_id=? AND topic_id=?', [$user['id'], $topicId]); else h('INSERT INTO '.$table.'(user_id,topic_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))', [$user['id'], $topicId]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    extension_event_async($match[2] === 'bookmark' ? ($exists ? 'bookmark.deleted' : 'bookmark.created') : ($exists ? 'subscription.deleted' : 'subscription.created'), ['topic_id'=>$topicId,'actor_id'=>(int)$user['id']]);
    redirect('/t/'.$topicId);
}
if (preg_match('~^/post/(\d+)/like$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $postId = (int)$match[1];
    $post = h('SELECT p.id,p.topic_id FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1', [$postId])->fetch();
    if (!$post) fail(404, '回复不存在');
    $db=db(); $db->beginTransaction();
    try { $exists = (bool)h('SELECT 1 FROM reactions WHERE user_id=? AND post_id=? AND reaction="like" FOR UPDATE', [$user['id'], $postId])->fetchColumn(); if ($exists) h('DELETE FROM reactions WHERE user_id=? AND post_id=? AND reaction="like"', [$user['id'], $postId]); else h('INSERT INTO reactions(user_id,post_id,reaction,created_at) VALUES(?, ?, "like", UTC_TIMESTAMP(6))', [$user['id'], $postId]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    extension_event_async($exists ? 'reaction.deleted' : 'reaction.created', ['post_id'=>$postId,'topic_id'=>(int)$post['topic_id'],'actor_id'=>(int)$user['id'],'reaction'=>'like']);
    redirect('/t/'.(int)$post['topic_id'].'#p'.$postId);
}
if (preg_match('~^/post/(\d+)/delete$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $postId = (int)$match[1]; $db = db(); $db->beginTransaction();
    try {
        h('SELECT id FROM notification_outbox WHERE post_id=? AND processed_at IS NULL FOR UPDATE', [$postId])->fetchAll();
        $post = h('SELECT p.id,p.topic_id,p.user_id,t.category_id,c.is_visible,(SELECT MIN(fp.id) FROM posts fp WHERE fp.topic_id=p.topic_id AND fp.status="normal") AS original_post_id FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.id=? AND p.status="normal" AND t.status="normal" FOR UPDATE', [$postId])->fetch();
        if (!$post || (!(int)$post['is_visible'] && $user['role'] !== 'admin')) fail(404, '回复不存在');
        if ((int)$post['user_id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin') fail(403, '无权删除此回复');
        if ((int)$post['id'] === (int)$post['original_post_id']) fail(400, '主题首帖不能在此删除');
        extension_authorize('post.delete', ['actor'=>$user, 'post'=>$post]);
        $delete = extension_apply_delete_command(new DeleteRequest('post', $postId, (int)$post['topic_id'], (int)$post['category_id'], (int)$user['id'], 'soft', 'user'), ['target_type'=>'post','target_id'=>$postId,'actor'=>$user,'post'=>$post]);
        extension_event_sync('post.deleting.v2', ['post_id'=>$postId,'topic_id'=>(int)$post['topic_id'],'category_id'=>(int)$post['category_id'],'actor_id'=>(int)$user['id'],'reason'=>$delete->reason]);
        if (h('UPDATE posts SET status="deleted" WHERE id=? AND status="normal"', [$postId])->rowCount() !== 1) fail(409, '回复状态已变化，请刷新后重试');
        if (topic_last_post_pointer_available()) h('UPDATE topics SET reply_count=IF(reply_count>0,reply_count-1,0),last_post_id=(SELECT id FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),last_post_at=COALESCE((SELECT created_at FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),created_at) WHERE id=?', [(int)$post['topic_id'],(int)$post['topic_id'],(int)$post['topic_id']]);
        else h('UPDATE topics SET reply_count=IF(reply_count>0,reply_count-1,0) WHERE id=?', [(int)$post['topic_id']]);
        h('DELETE FROM notifications WHERE post_id=?', [$postId]);
        h('DELETE FROM notification_outbox WHERE post_id=? AND processed_at IS NULL', [$postId]);
        audit('post.deleted', 'post', $postId, ['topic_id'=>(int)$post['topic_id'],'mode'=>$delete->mode,'reason'=>$delete->reason]);
        extension_enqueue_async_event('post.deleted', ['post_id'=>$postId,'topic_id'=>(int)$post['topic_id'],'actor_id'=>(int)$user['id'],'reason'=>$delete->reason]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    invalidate('topic-floor:'.(int)$post['topic_id']);
    invalidate_topic_read_cache((int)$post['topic_id'], (int)$post['category_id'], true, false);
    redirect('/t/'.(int)$post['topic_id'].'#replies');
}
if ($path === '/report' && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); limit_action('report', 10, 300, 'user:'.$user['id']); limit_action('report-ip', 30, 3600, hash('sha256',(string)($request->server['REMOTE_ADDR'] ?? ''))); $type = (string)($post['target_type'] ?? ''); $target = (int)($post['target_id'] ?? 0); $reason = trim((string)($post['reason'] ?? ''));
    if (!in_array($type, ['topic','post','user'], true) || $target < 1 || text_length($reason) < 5 || text_length($reason) > 1000) fail(400, '举报信息无效'); $table = ['topic'=>'topics','post'=>'posts','user'=>'users'][$type]; if (!h('SELECT id FROM '.$table.' WHERE id=?', [$target])->fetch()) fail(404, '举报目标不存在');
    h('INSERT INTO reports(reporter_id,target_type,target_id,reason,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))', [$user['id'],$type,$target,$reason]); $reportId = (int)db()->lastInsertId(); audit('report.created', 'report', $reportId, ['target_type'=>$type,'target_id'=>$target]); extension_event_async('report.created',['report_id'=>$reportId,'target_type'=>$type,'target_id'=>$target,'actor_id'=>(int)$user['id']]); page('举报已提交', '<section class="community-message"><h1>举报已提交</h1><p>举报编号 #'. $reportId .'，管理员会审核这条举报。</p><a href="/">返回首页</a></section>'); return;
}
if (preg_match('~^/user/(\d+)/(block|unblock)$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $blocked = (int)$match[1];
    if ($blocked === (int)$user['id']) fail(400, '不能屏蔽自己');
    $changed = \LiteBBS\Application\transaction_manager()->run(static function () use ($user, $blocked, $match): bool {
        $firstId = min((int)$user['id'], $blocked);
        $secondId = max((int)$user['id'], $blocked);
        $users = h('SELECT id,status FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE', [$firstId, $secondId])->fetchAll();
        $byId = [];
        foreach ($users as $lockedUser) $byId[(int)$lockedUser['id']] = (string)$lockedUser['status'];
        if (($byId[$blocked] ?? '') !== 'active') fail(404, '用户不存在');
        return $match[2] === 'block'
            ? h('INSERT IGNORE INTO user_blocks(user_id,blocked_user_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))', [$user['id'], $blocked])->rowCount() === 1
            : h('DELETE FROM user_blocks WHERE user_id=? AND blocked_user_id=?', [$user['id'], $blocked])->rowCount() === 1;
    });
    if ($changed) extension_event_async($match[2] === 'block' ? 'user.blocked' : 'user.unblocked', ['actor_id'=>(int)$user['id'],'user_id'=>$blocked]);
    redirect('/me?tab=blocks');
}
}

}

namespace {
// ===== market.php =====
function handle_market(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
$files = $request->files;
    if ($path === '/market/index.json' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $envelope = cached('market:index:v1:'.cache_version('market'), 60, static function (): array {
        $items=h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id LIMIT 5000')->fetchAll();
        $data=['schema'=>1,'generated'=>gmdate('c'),'items'=>$items];
        $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $private=market_signing_key();
        $signature='';if($private&&openssl_sign($json,$raw,$private,OPENSSL_ALGO_SHA256))$signature=base64_encode($raw);
        return ['payload'=>$data,'signature'=>$signature,'algorithm'=>'RSA-SHA256'];
    });
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);return;
}
if (preg_match('~^/market/package/(\d+)$~',$path,$m)&&$method==='GET') {
    // Revocation must be checked before a conditional response or file offload.
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    $item=h('SELECT package_file,sha256,package_name FROM market_submissions WHERE id=? AND status="approved"',[(int)$m[1]])->fetch();
    if(!$item)fail(404,'审核通过的扩展不存在');
    if(!preg_match('/^[a-f0-9]{48}\.zip$/',$item['package_file']))fail(404,'扩展包不存在');$file=__DIR__.'/../market-packages/'.$item['package_file'];
    if(!is_file($file)||!market_package_hash_valid($file,(string)$item['sha256']))fail(410,'扩展包校验失败');
    $etag='"'.strtolower($item['sha256']).'"';header('ETag: '.$etag);header('X-Content-Type-Options: nosniff');if(hash_equals($etag,(string)($request->server['HTTP_IF_NONE_MATCH']??''))){http_response_code(304);return;}header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($item['package_name']).'.zip"');if(getenv('LITEBBS_X_ACCEL_REDIRECT')==='1'){header('X-Accel-Redirect: /__litebbs-market-packages/'.rawurlencode((string)$item['package_file']));return;}header('Content-Length: '.filesize($file));readfile($file);return;
}
if ($path === '/market' && $method === 'GET') {
    $items = h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id DESC LIMIT 100')->fetchAll();
    $user = current_user();
    $body = '<header class="market-heading"><div><h1>开发者</h1><p>发现社区开发者提交的插件与主题。所有作品均经过管理员审核后公开。</p></div><a class="primary market-submit-link" href="'.($user ? '/market/submit' : '/login').'">'.($user ? '发布作品' : '登录后发布').'</a></header><section class="market-grid" aria-label="已审核开发者作品">';
    if (!$items) $body .= '<div class="market-empty"><h2>还没有公开作品</h2><p>成为第一个向社区分享插件或主题的开发者。</p>'.($user ? '<a href="/market/submit">提交一个作品</a>' : '<a href="/login">登录后提交</a>').'</div>';
    foreach ($items as $item) {
        $kind = $item['kind'] === 'theme' ? '主题' : '插件';
        $body .= '<article class="market-card"><div class="market-card-top"><span class="market-kind">'.e($kind).'</span><span class="market-version">v'.e($item['version']).'</span></div><h2>'.e($item['title']).'</h2><p class="market-package">'.e($item['package_name']).'</p><p>'.e($item['summary']).'</p><a class="market-download" href="/market/package/'.(int)$item['id'].'">下载作品 <span aria-hidden="true">↓</span></a></article>';
    }
    $body .= '</section>';
    page('开发者',$body,'社区开发者作品',false);
    return;
}
if ($path === '/market/submit' && in_array($method,['GET','POST'],true)) {
    $user=current_user();
    if (!$user) redirect('/login');
    if($method==='POST'){
        require_post();limit_action('market-submit',3,3600,'user:'.$user['id']);limit_action('market-submit-ip',10,3600,hash('sha256',(string)($request->server['REMOTE_ADDR']??'')));
        $kind=(string)($post['kind']??'');$name=(string)($post['package_name']??'');$title=trim((string)($post['title']??''));$version=trim((string)($post['version']??''));$summary=trim((string)($post['summary']??''));$upload=$files['package']??null;
        if(!in_array($kind,['plugin','theme'],true)||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$name)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)||text_length($title)<2||text_length($title)>100||text_length($summary)>500||!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($upload['size']??0)>5242880||!is_uploaded_file($upload['tmp_name'])||strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION))!=='zip')fail(400,'提交信息无效，ZIP 文件上限为 5 MiB');
         if((int)h('SELECT COUNT(*) FROM market_submissions WHERE user_id=? AND status="pending"',[$user['id']])->fetchColumn()>=10) fail(429,'待审核提交数量已达上限');
          $dir=__DIR__.'/../market-packages';if(!is_dir($dir)&&!mkdir($dir,0750,true))fail(500,'市场存储目录不可写');$stored=bin2hex(random_bytes(24)).'.zip';$target=$dir.'/'.$stored;if(!move_uploaded_file($upload['tmp_name'],$target))fail(500,'无法保存扩展包');try{$tmp=dirname(__DIR__).'/cache/inspect-'.bin2hex(random_bytes(8));try{$root=market_unpack($target,$tmp);$metadata=extension_market_manifest($root,$kind,$name);}finally{remove_tree($tmp);}if((string)$metadata['version']!==$version)throw new RuntimeException('提交版本必须与清单一致');$hash=hash_file('sha256',$target);$db=db();$db->beginTransaction();h('INSERT INTO market_submissions(user_id,kind,package_name,title,version,summary,manifest_json,package_file,sha256,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,"pending",UTC_TIMESTAMP(6))',[$user['id'],$kind,$name,$title,$version,$summary,$metadata['manifest_json'],$stored,$hash]);$id=(int)$db->lastInsertId();audit('market.submitted','market',$id,['kind'=>$kind,'name'=>$name,'version'=>$version]);$db->commit();}catch(Throwable $e){if(isset($db)&&$db instanceof PDO&&$db->inTransaction())$db->rollBack();@unlink($target);error_log('market submission failed: '.$e->getMessage());fail(400,'扩展包无效，请检查后重试');}redirect('/market/submit');
    }
    $rows=h('SELECT kind,title,version,status,review_note,created_at FROM market_submissions WHERE user_id=? ORDER BY id DESC LIMIT 50',[$user['id']])->fetchAll();
    $fields='<label>类型<select name="kind"><option value="plugin">插件</option><option value="theme">主题</option></select></label><label>作品 ID<input name="package_name" pattern="[a-z][a-z0-9_-]{1,31}" required></label><label>显示名称<input name="title" maxlength="100" required></label><label>版本（x.y.z）<input name="version" pattern="[0-9]+\\.[0-9]+\\.[0-9]+" required></label><label>简介<input name="summary" maxlength="500"></label><label>ZIP 包<input type="file" name="package" accept=".zip" required></label>';
    $body='<section class="market-submit"><a class="market-back" href="/market">← 返回开发者</a><h1>发布作品</h1><p>提交插件或主题后，管理员会进行审核。审核通过后会显示在开发者页面。ZIP 文件上限为 5 MiB，作品代码会以 PHP 进程权限运行，请只提交自己审查过的代码。</p><form class="form" method="post" enctype="multipart/form-data" action="/market/submit"><input type="hidden" name="csrf" value="'.e(csrf()).'">'.$fields.'<button class="primary">提交审核</button></form><h2>我的提交</h2>';
    if (!$rows) $body .= '<p class="admin-intro">你还没有提交过作品。</p>';
     foreach ($rows as $r) { $status = ['pending'=>'审核中','approved'=>'已通过','rejected'=>'已拒绝','revoked'=>'已撤销'][$r['status']] ?? $r['status']; $body .= '<p class="market-submission">'.e($r['title']).' · '.e($r['version']).' · '.e($status).($r['review_note'] !== '' ? ' · '.e($r['review_note']) : '').'</p>'; }
    page('发布作品',$body.'</section>');
}

}

function market_package_hash_valid(string $file, string $expectedHash): bool {
    $modified=@filemtime($file);$size=@filesize($file);
    if($modified===false||$size===false||!preg_match('/^[a-f0-9]{64}$/i',$expectedHash))return false;
    $key='market-package-hash:v1:'.strtolower($expectedHash).':'.$modified.':'.$size;
    $result=cached($key,300,static function()use($file,$expectedHash):array{$hash=hash_file('sha256',$file);return [$hash!==false&&hash_equals(strtolower($expectedHash),strtolower($hash))];});
    return !empty($result[0]);
}
function market_signing_key(): string {
    $config=load_config();
    if (!empty($config['market_signing_key'])) return (string)$config['market_signing_key'];
    $legacy=(string)h('SELECT setting_value FROM settings WHERE setting_key="market_signing_key"')->fetchColumn();
    if ($legacy==='') return '';
    $config['market_signing_key']=$legacy;
    write_config($config);
    h('DELETE FROM settings WHERE setting_key="market_signing_key"');
    return $legacy;
}
function market_unpack(string $archive,string $out):string{
    if(!class_exists('ZipArchive'))throw new RuntimeException('启用 PHP zip 扩展后才能处理扩展包');$z=new ZipArchive();if($z->open($archive)!==true)throw new RuntimeException('ZIP 包不可读');if($z->numFiles<1||$z->numFiles>200){$z->close();throw new RuntimeException('ZIP 文件数量超限');}$entries=[];$total=0;
    for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$path=(string)($s['name']??'');$size=(int)($s['size']??0);$directory=str_ends_with($path,'/');$normalized=$directory?rtrim($path,'/'):$path;$parts=explode('/',$normalized);$opsys=0;$attrs=0;$z->getExternalAttributesIndex($i,$opsys,$attrs);$unixMode=($attrs>>16)&0170000;$type=$unixMode&0170000;$total+=$size;if($normalized===''||strpos($path,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$)|//)~',$path)||count($parts)>16||isset($entries[$normalized])||$size>2097152||$total>10485760||($opsys===ZipArchive::OPSYS_UNIX&&$type!==0&&$type!==0100000&&$type!==0040000)){$z->close();throw new RuntimeException('ZIP 含重复路径、特殊文件、路径穿越或超出解压限额');}$entries[$normalized]=['directory'=>$directory,'size'=>$size,'index'=>$i];}
    foreach($entries as $path=>$entry){$parts=explode('/',$path);for($i=1;$i<count($parts);$i++){$parent=implode('/',array_slice($parts,0,$i));if(isset($entries[$parent])&&!$entries[$parent]['directory']){$z->close();throw new RuntimeException('ZIP 文件与目录路径冲突');}}if(!$entry['directory'])foreach($entries as $other=>$candidate)if($candidate['directory']&&str_starts_with($other,$path.'/')){$z->close();throw new RuntimeException('ZIP 文件与目录路径冲突');}}
    if(!mkdir($out,0700,true)){$z->close();throw new RuntimeException('临时目录创建失败');}foreach($entries as $path=>$entry){if($entry['directory'])continue;$parent=dirname($out.'/'.$path);if(!is_dir($parent)&&!mkdir($parent,0700,true)){$z->close();throw new RuntimeException('无法创建包目录');}$stat=$z->statIndex($entry['index']);$stream=$z->getStream($stat['name']);if(!$stream){$z->close();throw new RuntimeException('ZIP 文件项无法读取');}$data=stream_get_contents($stream,2097153);fclose($stream);if($data===false||strlen($data)!==$entry['size']){$z->close();throw new RuntimeException('ZIP 文件项大小不一致');}if(file_put_contents($out.'/'.$path,$data,LOCK_EX)===false){$z->close();throw new RuntimeException('无法写入扩展文件');}}$z->close();$children=array_values(array_diff(scandir($out)?:[],['.','..']));return count($children)===1&&is_dir($out.'/'.$children[0])?$out.'/'.$children[0]:$out;
}

}

namespace {
// ===== messages.php =====


function handle_messages(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
if ($path !== '/messages' || !in_array($method, ['GET', 'POST'], true)) return;

$user = current_user();
if (!$user) redirect('/login');
$viewerId = (int)$user['id'];
$recipientName = trim((string)($query['to'] ?? $post['to'] ?? ''));

if ($method === 'POST') {
    require_post();
    $action = (string)($post['action'] ?? 'send');
    if ($action === 'mark_read') {
        if ($recipientName === '' || text_length($recipientName) > 32) fail(400, '私信会话无效');
        $recipient = h('SELECT id,username FROM users WHERE username=? AND status="active"', [$recipientName])->fetch();
        if (!$recipient || (int)$recipient['id'] === $viewerId) fail(404, '用户不存在');
        h('UPDATE private_messages SET read_at=UTC_TIMESTAMP(6) WHERE sender_id=? AND recipient_id=? AND read_at IS NULL', [(int)$recipient['id'], $viewerId]);
        invalidate_user_cache($viewerId);
        redirect('/messages?to='.rawurlencode((string)$recipient['username']));
    }
    if ($action !== 'send') fail(400, '私信操作无效');
    $body = trim((string)($post['body'] ?? ''));
    if ($recipientName === '' || text_length($recipientName) > 32 || text_length($body) < 1 || text_length($body) > 100) fail(400, '私信内容无效');
    limit_action('message-send', 10, 300, 'user:'.$viewerId);
    $recipient = h('SELECT id,username FROM users WHERE username=? AND status="active"', [$recipientName])->fetch();
    if (!$recipient) fail(404, '收件人不存在');
    $recipientId = (int)$recipient['id'];
    $service = new \LiteBBS\Application\PrivateMessageService(\LiteBBS\Application\transaction_manager(), \LiteBBS\Application\audit_logger());
    $service->send($viewerId, $recipientId, $body);
    redirect('/messages?to='.rawurlencode((string)$recipient['username']));
}

if ($recipientName !== '') {
    $recipient = h('SELECT id,username FROM users WHERE username=? AND status="active"', [$recipientName])->fetch();
    if (!$recipient) fail(404, '用户不存在');
    $recipientId = (int)$recipient['id'];
    if ($recipientId === $viewerId) fail(400, '不能查看与自己的私信');
    $messages = h('SELECT m.id message_id,m.sender_id,m.recipient_id,m.body,m.created_at,u.username sender_name FROM (SELECT id,sender_id,recipient_id,body,created_at FROM private_messages WHERE sender_id=? AND recipient_id=? ORDER BY id DESC LIMIT 100) m JOIN users u ON u.id=m.sender_id UNION ALL SELECT m.id message_id,m.sender_id,m.recipient_id,m.body,m.created_at,u.username sender_name FROM (SELECT id,sender_id,recipient_id,body,created_at FROM private_messages WHERE sender_id=? AND recipient_id=? ORDER BY id DESC LIMIT 100) m JOIN users u ON u.id=m.sender_id ORDER BY created_at DESC,message_id DESC LIMIT 100', [$viewerId, $recipientId, $recipientId, $viewerId])->fetchAll();
    $messages = array_reverse($messages);
    $body = '<section class="messages"><a class="messages-back" href="/messages">返回私信列表</a><header><h1>与 '.e($recipient['username']).' 的私信</h1></header><div class="message-thread">';
    foreach ($messages as $message) $body .= '<article class="message'.((int)$message['sender_id'] === $viewerId ? ' message-sent' : '').'"><strong>'.e($message['sender_name']).'</strong><p>'.nl2br(e($message['body'])).'</p><time>'.e(substr((string)$message['created_at'], 0, 16)).'</time></article>';
    if (!$messages) $body .= '<p class="empty-state">还没有私信，发一条消息开始对话。</p>';
    $body .= '</div><form method="post" action="/messages"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="to" value="'.e($recipient['username']).'"><button type="submit">标记会话已读</button></form><form class="message-compose" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="send"><input type="hidden" name="to" value="'.e($recipient['username']).'"><label>发送给 '.e($recipient['username']).'<textarea name="body" maxlength="100" rows="4" required></textarea><small>最多 100 字</small></label><button class="primary">发送私信</button></form></section>';
    $coreBody = $body;
    $themeMessages = array_map(static fn(array $message): array => ['id'=>(int)$message['message_id'],'sender'=>['username'=>(string)$message['sender_name'],'profile_href'=>'/u/'.rawurlencode((string)$message['sender_name'])],'body'=>(string)$message['body'],'created_at'=>(string)$message['created_at'],'sent_by_viewer'=>(int)$message['sender_id'] === $viewerId], $messages);
    $body = theme_render_page('messages', ['viewer'=>['id'=>$viewerId,'username'=>(string)$user['username']], 'mode'=>'thread', 'conversation'=>['participant'=>['username'=>(string)$recipient['username'],'profile_href'=>'/u/'.rawurlencode((string)$recipient['username'])],'href'=>'/messages?to='.rawurlencode((string)$recipient['username'])], 'conversations'=>[], 'messages'=>$themeMessages, 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
    page('私信', $body);
    return;
}

// Bound both directions with covering index reads before loading message bodies.
$rows = h('SELECT m.id message_id,m.sender_id,m.recipient_id,m.body,m.created_at,m.read_at,s.username sender_name,r.username recipient_name
    FROM (
        (SELECT id FROM private_messages WHERE sender_id=? ORDER BY id DESC LIMIT 100)
        UNION ALL
        (SELECT id FROM private_messages WHERE recipient_id=? ORDER BY id DESC LIMIT 100)
    ) recent
    JOIN private_messages m ON m.id=recent.id
    JOIN users s ON s.id=m.sender_id
    JOIN users r ON r.id=m.recipient_id
    ORDER BY m.created_at DESC,m.id DESC LIMIT 100', [$viewerId, $viewerId])->fetchAll();
$conversations = [];
foreach ($rows as $message) {
    $otherId = (int)$message['sender_id'] === $viewerId ? (int)$message['recipient_id'] : (int)$message['sender_id'];
    if (isset($conversations[$otherId])) continue;
    $name = (int)$message['sender_id'] === $viewerId ? (string)$message['recipient_name'] : (string)$message['sender_name'];
    $unread = (int)$message['recipient_id'] === $viewerId && $message['read_at'] === null;
    $conversations[$otherId] = ['name'=>$name, 'body'=>(string)$message['body'], 'created_at'=>(string)$message['created_at'], 'unread'=>$unread];
}
$body = '<section class="messages"><header><h1>私信</h1></header><div class="member-list">';
foreach ($conversations as $conversation) $body .= '<a class="member-row" href="/messages?to='.rawurlencode($conversation['name']).'"><span><strong>'.e($conversation['name']).'</strong>'.($conversation['unread'] ? ' <b class="message-unread">未读</b>' : '').'</span><span>'.e(text_slice($conversation['body'], 0, 100)).'</span><time>'.e(substr($conversation['created_at'], 0, 16)).'</time></a>';
if (!$conversations) $body .= '<p class="empty-state">暂无私信。可在用户主页选择“发送私信”。</p>';
$body .= '</div></section>';
$coreBody = $body;
$themeConversations = array_map(static fn(array $conversation): array => ['participant'=>['username'=>(string)$conversation['name'],'profile_href'=>'/u/'.rawurlencode((string)$conversation['name'])],'href'=>'/messages?to='.rawurlencode((string)$conversation['name']),'preview'=>text_slice((string)$conversation['body'], 0, 100),'updated_at'=>(string)$conversation['created_at'],'unread'=>(bool)$conversation['unread']], array_values($conversations));
$body = theme_render_page('messages', ['viewer'=>['id'=>$viewerId,'username'=>(string)$user['username']], 'mode'=>'inbox', 'conversation'=>null, 'conversations'=>$themeConversations, 'messages'=>[], 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);
page('私信', $body);
}

}

namespace {
// ===== plugin-topic.php =====
function handle_topic_create(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$post = $request->post;
$files = $request->files;
if ($path !== '/new' || !in_array($method, ['GET', 'POST'], true)) return;
$user = current_user();
if (!$user) {
    $returnTo = (string)($request->server['REQUEST_URI'] ?? '/new');
    if (!preg_match('~^/new(?:\?[^#\r\n]{0,14000})?$~D', $returnTo)) $returnTo = '/new';
    redirect('/login?return_to='.rawurlencode($returnTo));
}
$categories = h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll();
if ($method === 'POST') {
    require_post();
    $title = trim((string)($post['title'] ?? ''));
    $body = trim((string)($post['body'] ?? ''));
    $category = (int)($post['category'] ?? 0);
    extension_authorize_speaking($user, 'topic.create', ['category_id'=>$category]);
    extension_authorize('topic.create', ['actor'=>$user, 'category_id'=>$category]);
    $draft = extension_apply_command('topic.create', new ContentDraft('topic', $title, $body, $category), ['actor'=>$user]);
    $title = $draft->title;
    $body = $draft->body;
    $category = $draft->categoryId;
    $categoryIds = array_map('intval', array_column($categories, 'id'));
    if (text_length($title) < 4 || text_length($title) > 120 || text_length($body) < 5 || text_length($body) > 12000 || !in_array($category, $categoryIds, true)) fail(400, '标题、正文或分区无效');
    // Upload inspection runs before the transaction so extension file work does
    // not extend category and quota locks during topic creation.
    extension_event_sync('topic.create.preflight', ['post'=>$post, 'files'=>$files, 'user'=>$user]);
    extension_event_sync('topic.create.preflight.v2', ['actor_id'=>(int)$user['id'],'title'=>$title,'body'=>$body,'category_id'=>$category,'uploads'=>extension_safe_uploads($files)]);
    enforce_content_rate_limit('topic', (int)$user['id']);
    $body = extension_preprocess_post_markdown($body, ['actor_id'=>(int)$user['id'],'kind'=>'topic']);
    if (text_length($body) < 5 || text_length($body) > 12000) fail(400, '主题正文无效');
    $bodyHtml = extension_render_post_body($body, ['actor_id'=>(int)$user['id'],'kind'=>'topic']);
    [$topicId, $postId] = \LiteBBS\Application\transaction_manager()->run(static function (PDO $pdo) use ($category, $user, $title, $body, $bodyHtml, $post, $files): array {
        enforce_post_interval((int)$user['id'], true);
        // A shared lock prevents a concurrent hide without serializing topic
        // creators in the same visible category.
        $categoryRow = h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE', [$category])->fetch();
        if (!$categoryRow) {
            fail(400, '分区已隐藏或无效');
        }
        h('INSERT INTO topics(category_id,user_id,slug,title,created_at,last_post_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$category,$user['id'],slug($title),$title]);
        $topicId = (int)$pdo->lastInsertId();
        h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))', [$topicId,$user['id'],$body,$bodyHtml]);
        $postId = (int)$pdo->lastInsertId();
        if (topic_last_post_pointer_available()) h('UPDATE topics SET last_post_id=? WHERE id=?', [$postId,$topicId]);
        if (topic_participants_available()) h('INSERT INTO topic_participants(topic_id,user_id,last_post_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_post_id=GREATEST(last_post_id,VALUES(last_post_id))', [$topicId,$user['id'],$postId]);
        h('INSERT INTO notification_outbox(topic_id,post_id,actor_id,pending_topic_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))', [$topicId,$postId,(int)$user['id'],$topicId]);
        // Creation plugins are part of the domain write: a requested upload
        // must either persist with its post or fail the entire submission.
        extension_event_or_fail('topic.created', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id'], 'post'=>$post, 'files'=>$files, 'user'=>$user]);
        extension_event_or_fail('topic.created.v2', ['topic_id'=>$topicId,'post_id'=>$postId,'actor_id'=>(int)$user['id'],'title'=>$title,'body'=>$body,'category_id'=>$category,'uploads'=>extension_safe_uploads($files)]);
        extension_enqueue_async_event('topic.created.async', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id']]);
        return [$topicId, $postId];
    });
    invalidate('topic:'.$topicId);
    invalidate_topic_listing_cache($category, true);
    redirect('/t/'.$topicId);
}
$options = '';
foreach ($categories as $category) $options .= '<option value="'.(int)$category['id'].'">'.e($category['title']).'</option>';
    $draftTitle = trim((string)($request->query['title'] ?? ''));
    $draftBody = trim((string)($request->query['body'] ?? ''));
    if (text_length($draftTitle) > 120) $draftTitle = '';
    if (text_length($draftBody) > 12000) $draftBody = '';
    page('发布主题','<form class="form" method="post" action="/new"><h1>发布一个主题</h1><input type="hidden" name="csrf" value="'.e(csrf()).'"><label>标题<input name="title" minlength="4" maxlength="120" value="'.e($draftTitle).'" required></label><label>分区<select name="category">'.$options.'</select></label><label>正文<textarea name="body" rows="12" maxlength="12000" required>'.e($draftBody).'</textarea></label>'.extension_slot('topic.create.fields', ['user'=>$user]).extension_slot('topic.create.sidebar', ['user'=>$user]).'<button class="primary">发布主题</button></form>');
    return;
}

}

namespace {
// ===== post-edit.php =====
function handle_post_edit(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$postInput = $request->post;
if (!preg_match('~^/p/(\d+)/edit$~', $path, $match) || !in_array($method, ['GET', 'POST'], true)) return;

$user = current_user();
if (!$user) redirect('/login');

$post = h(
    'SELECT p.*,t.status topic_status,t.title topic_title,t.category_id,c.is_visible,(SELECT MIN(fp.id) FROM posts fp WHERE fp.topic_id=p.topic_id AND fp.status="normal") original_post_id FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.id=? AND p.status="normal"',
    [(int)$match[1]]
)->fetch();
if (!$post || $post['topic_status'] !== 'normal' || (!(int)$post['is_visible'] && $user['role'] !== 'admin')) fail(404, '帖子不存在');
if ((int)$post['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') fail(403, '无权编辑');
$editAbility = (int)$post['id'] === (int)$post['original_post_id'] ? 'topic.edit' : 'post.edit';
$speakPost = ['id'=>(int)$post['id'],'topic_id'=>(int)$post['topic_id'],'user_id'=>(int)$post['user_id'],'is_topic'=>$editAbility === 'topic.edit'];
extension_authorize_speaking($user, $editAbility, ['post'=>$speakPost]);
extension_authorize($editAbility, ['actor'=>$user, 'post'=>$post]);

$isOriginalPost = (int)$post['id'] === (int)$post['original_post_id'];
$categories = $isOriginalPost
    ? h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll()
    : [];

if ($method === 'POST') {
    require_post();
    $body = trim((string)($postInput['body'] ?? ''));
    $revision = (int)($postInput['revision'] ?? 0);
    if (text_length($body) < 1 || text_length($body) > 12000 || $revision < 1) fail(400, '回复内容或版本无效');

    $title = (string)$post['topic_title'];
    $categoryId = (int)$post['category_id'];
    if ($isOriginalPost) {
        $title = trim((string)($postInput['title'] ?? ''));
        $categoryId = (int)($postInput['category'] ?? 0);
        $categoryIds = array_map('intval', array_column($categories, 'id'));
        if (text_length($title) < 4 || text_length($title) > 120 || !in_array($categoryId, $categoryIds, true)) fail(400, '标题或分区无效');
    }

    $draft = extension_apply_update_command(
        $isOriginalPost ? 'topic.update' : 'post.update',
        new PostUpdateDraft((int)$post['id'], (int)$post['topic_id'], $revision, $isOriginalPost, $title, $body, $categoryId),
        ['actor'=>$user, 'post'=>$post]
    );
    $title = $draft->title;
    $body = $draft->body;
    $categoryId = $draft->categoryId;
    if (text_length($body) < 1 || text_length($body) > 12000 || ($isOriginalPost && (text_length($title) < 4 || text_length($title) > 120 || !in_array($categoryId, array_map('intval', array_column($categories, 'id')), true)))) fail(400, '编辑内容无效');

    $oldCategoryId = (int)$post['category_id'];
    $body = extension_preprocess_post_markdown($body, ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'kind'=>'edit']);
    if (text_length($body) < 1 || text_length($body) > 12000) fail(400, '编辑内容无效');
    $bodyHtml = extension_render_post_body($body, ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'kind'=>'edit']);
    $eventPost = $postInput;
    $eventPost['body'] = $body;
    \LiteBBS\Application\transaction_manager()->run(static function () use ($post, $user, $isOriginalPost, $categoryId, $oldCategoryId, $title, $body, $bodyHtml, $eventPost, $revision): void {
        $topic = h('SELECT category_id FROM topics WHERE id=? AND status="normal" FOR UPDATE', [$post['topic_id']])->fetch();
        if (!$topic) fail(404, '帖子不存在');
        if ((int)$topic['category_id'] !== $oldCategoryId) fail(409, '主题分区已变化，请刷新后重试');
        if ($user['role'] !== 'admin' && !h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE', [$oldCategoryId])->fetchColumn()) {
            fail(404, '帖子不存在');
        }
        if ($isOriginalPost) {
            if (!h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE', [$categoryId])->fetch()) fail(400, '分区已隐藏或无效');
            h('UPDATE topics SET title=?,slug=?,category_id=? WHERE id=? AND status="normal"', [$title,slug($title),$categoryId,$post['topic_id']]);
        }
        h('INSERT INTO post_revisions(post_id,editor_id,body_markdown,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$post['id'],$user['id'],$post['body_markdown']]);
        $updated = h(
            'UPDATE posts SET body_markdown=?,body_html=?,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND revision=? AND status="normal"',
            [$body,$bodyHtml,$post['id'],$revision]
        );
        if ($updated->rowCount() !== 1) {
            fail(409, '帖子已被其他人修改，请刷新后重试');
        }
        audit('post.edited', 'post', (int)$post['id']);
        if ($isOriginalPost && ($title !== (string)$post['topic_title'] || $categoryId !== $oldCategoryId)) {
            audit('topic.edited', 'topic', (int)$post['topic_id'], ['old_category_id'=>$oldCategoryId,'category_id'=>$categoryId]);
            $topicUpdatedEvent = [
                'topic_id'=>(int)$post['topic_id'],
                'actor_id'=>(int)$user['id'],
                'title'=>$title,
                'category_id'=>$categoryId,
                'previous_title'=>(string)$post['topic_title'],
                'previous_category_id'=>$oldCategoryId,
            ];
            extension_enqueue_async_event('topic.updated.async', $topicUpdatedEvent);
        }
        extension_event_or_fail('post.updated', ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'post'=>$eventPost,'previous_body_markdown'=>(string)$post['body_markdown'],'user'=>$user]);
        extension_event_or_fail('post.updated.v2', ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'body'=>$body,'previous_body_markdown'=>(string)$post['body_markdown']]);
    });
    $categoryChanged = $isOriginalPost && $categoryId !== $oldCategoryId;
    $titleChanged = $isOriginalPost && $title !== (string)$post['topic_title'];
    invalidate_topic_read_cache((int)$post['topic_id'], $categoryId, $categoryChanged || $titleChanged);
    if ($categoryChanged) {
        invalidate_topic_listing_cache($oldCategoryId, true);
        invalidate_topic_listing_cache($categoryId, true);
    }
    redirect('/t/'.(int)$post['topic_id'].'#p'.(int)$post['id']);
}

$fields = '<input type="hidden" name="revision" value="'.(int)$post['revision'].'">';
if ($isOriginalPost) {
    $options = '';
    foreach ($categories as $category) {
        $selected = (int)$category['id'] === (int)$post['category_id'] ? ' selected' : '';
        $options .= '<option value="'.(int)$category['id'].'"'.$selected.'>'.e($category['title']).'</option>';
    }
    $fields .= '<label>标题<input name="title" minlength="4" maxlength="120" value="'.e($post['topic_title']).'" required></label>'.
        '<label>分区<select name="category" required>'.$options.'</select></label>';
}
$formKind = $isOriginalPost ? 'topic' : 'reply';
$fields .= '<label>正文<textarea name="body" rows="12" maxlength="12000" required>'.e($post['body_markdown']).'</textarea></label>'.
    extension_slot($formKind.'.edit.fields', ['post'=>$post,'user'=>$user]).
    extension_slot('post.edit.fields', ['post'=>$post,'user'=>$user]).
    extension_slot($formKind.'.edit.sidebar', ['post'=>$post,'user'=>$user]).
    extension_slot('post.edit.form.after', ['post'=>$post,'user'=>$user]);
page('编辑帖子', form('/p/'.(int)$post['id'].'/edit', '编辑帖子', $fields));
return;
}

}

namespace {
// ===== read-pages.php =====




function handle_read_pages(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$cookies = $request->cookies;
if ($method !== 'GET') return;

if (preg_match('~^/(?:t/(\d+)|topic-(\d+)(?:-(\d+))?)$~', $path, $match)) {
    $topicId = (string)($match[1] ?? '');
    $numericTopicId = (string)($match[2] ?? '');
    $numericPage = $numericTopicId !== '' ? (int)($match[3] ?? 1) : 1;
    $id = (int)($topicId !== '' ? $topicId : $numericTopicId);
    if ($topicId !== '' && $path !== '/t/'.$id) redirect('/t/'.$id, 301);

    if ($query) fail(400, '分页参数无效');
    if ($numericTopicId !== '') \LiteBBS\Application\NumericPageCursor::assertAllowed($numericPage);
    $config = load_config();
    $theme = (string)($config['theme'] ?? 'default');
    $pageSize = theme_page_size($theme, 'topic');
    $afterId = $numericPage > 1 ? \LiteBBS\Application\NumericPageCursor::topicAfter($id, $numericPage, $pageSize) : 0;
    $anonymous = empty($cookies[session_name()]);
    $cachePage = $anonymous && $afterId === 0 && theme_effective_package($theme) !== null && !extension_dynamic_read_page('topic');
    $versions = cache_versions(['topic:'.$id,'topic-floor:'.$id,'extension-read-pages','categories','plugins','theme-settings:'.$theme]);
    $shellVersion = hash('sha256', implode("\0", [
        (string)($config['site'] ?? ''),
        (string)($config['url'] ?? ''),
        (string)($config['theme'] ?? 'default'),
        (string)($config['locale'] ?? 'zh-CN'),
        json_encode($config['seo'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        theme_asset_signature($theme),
    ]));
    $pageCacheKey = 'topic-page:v5:'.$pageSize.':'.$id.':'.$shellVersion.':'.(int)$versions['topic:'.$id].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'].':'.(int)$versions['plugins'].':'.(int)$versions['theme-settings:'.$theme];
    if ($cachePage && ($cachedPage = page_cache_read($pageCacheKey, 120)) !== null) {
        echo (string)($cachedPage[0] ?? '');
        return;
    }

    $topic = h('SELECT t.*,c.id category_id,c.title category,c.slug category_slug,u.username,(SELECT MIN(p.id) FROM posts p WHERE p.topic_id=t.id AND p.status="normal") original_post_id FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1', [$id])->fetch();
    if (!$topic) fail(404, '主题不存在');
    $topicViewer = current_user();
    if (!extension_permits('category.view', ['actor'=>$topicViewer, 'category'=>['id'=>(int)$topic['category_id'], 'title'=>(string)$topic['category']]]) || !extension_permits('topic.view', ['actor'=>$topicViewer, 'topic'=>$topic])) fail(404, '主题不存在');

    $afterFloor = 0;
    if ($afterId > 0) {
        $cursorPost = h('SELECT id FROM posts WHERE id=? AND topic_id=? AND status="normal"', [$afterId, $id])->fetchColumn();
        if (!$cursorPost) fail(404, '页面不存在');
        $afterFloor = ($numericPage - 1) * $pageSize;
    }
    $user = $topicViewer;
    $originalId = (int)$topic['original_post_id'];
    // Providers return candidate IDs with one lookahead. Core hydrates only visible posts.
    $readContext = new ReadSelection('topic', $pageSize + 1, $id, [
        'after_id'=>$afterId, 'after_floor'=>$afterFloor, 'original_post_id'=>$originalId,
    ], isset($user['id']) ? (int)$user['id'] : null);
    $selection = $numericPage > 1 ? null : extension_select_ids($readContext);
    // Core renders stored HTML. Keep the Markdown field for plugin hook payloads.
    $fields = 'p.id,p.user_id,p.body_html,p.created_at,u.username';
    if (extension_runtime_plugins()) $fields .= ',p.body_markdown';
    $where = 'p.topic_id=? AND p.status="normal"';
    $args = [$id];
    if ($afterId > 0) {
        $where .= ' AND p.id>?';
        $args[] = $afterId;
    }
    if ($selection !== null) {
        if ($afterId === 0 && $originalId > 0 && !in_array($originalId, $selection, true)) $selection[] = $originalId;
        if ($selection) {
            $where .= ' AND p.id IN ('.implode(',', array_fill(0, count($selection), '?')).')';
            array_push($args, ...$selection);
            $fields .= ',(SELECT COUNT(*) FROM posts visible WHERE visible.topic_id=p.topic_id AND visible.status="normal" AND visible.id<=p.id) AS reply_floor';
        }
    }
    $rows = $selection === [] ? [] : h('SELECT '.$fields.' FROM posts p JOIN users u ON u.id=p.user_id WHERE '.$where.' ORDER BY p.id LIMIT '.($pageSize+1), $args)->fetchAll();
    $hasMore = count($rows) > $pageSize;
    if ($hasMore) array_pop($rows);
    $lastRow = $hasMore ? end($rows) : null;
    $nextAfter = (int)($lastRow['id'] ?? 0);
    $rows = extension_transform_rows($readContext, $rows, $originalId);
        $replyView = ['posts'=>$rows, 'topic'=>$topic, 'viewer'=>$user];
        extension_preload('topic.replies', $replyView);
        extension_preload('post.after-body', $replyView);
        extension_preload('post.after', $replyView);
        $viewerState = topic_page_viewer_state($rows, $id, $user);
        foreach ($rows as &$post) {
            if (empty($viewerState['blocked'][(int)$post['user_id']])) continue;
            $post['body_html'] = '<p class="blocked-post">你已屏蔽该用户的内容。</p>';
            if (isset($post['body_markdown'])) $post['body_markdown'] = '';
        }
        unset($post);
        $createdAt = (string)$topic['created_at'];
        $lastPostAt = (string)$topic['last_post_at'];
        $canEditTopic = !empty($topic['original_post_id']) && $user && ((int)$user['id'] === (int)$topic['user_id'] || $user['role'] === 'admin');
        $bookmarked=$viewerState['bookmarked'];
        $subscribed=$viewerState['subscribed'];
         $threadView=['topic'=>$topic,'posts'=>$rows,'viewer'=>$user,'after_id'=>$afterId];
        $body = '<header class="topic-page-heading"><div class="topic-heading-topline"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a><span class="topic-state'.(!empty($topic['is_locked'])?' is-locked':'').'">'.(!empty($topic['is_locked'])?'已锁定':'开放讨论').'</span></div><div class="topic-title-row"><h1>'.e($topic['title']).'</h1>'.($canEditTopic?'<a class="post-edit" href="/p/'.(int)$topic['original_post_id'].'/edit">编辑</a>':'').'</div><div class="topic-page-meta"><span class="topic-owner-avatar" aria-hidden="true">'.e(text_slice($topic['username'], 0, 1)).'</span><a href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time datetime="'.e(str_replace(' ', 'T', substr($createdAt, 0, 19))).'">发布于 '.e(substr($createdAt, 0, 16)).'</time><span class="topic-views" aria-label="'.(int)$topic['view_count'].' 次浏览"><i aria-hidden="true"></i>'.(int)$topic['view_count'].'</span><span class="topic-meta-count">'.(int)$topic['reply_count'].' 条回复</span>'.($topic['reply_count']?'<time datetime="'.e(str_replace(' ', 'T', substr($lastPostAt, 0, 19))).'">最近更新 '.e(substr($lastPostAt, 0, 16)).'</time>':'').'</div>'.extension_slot('topic.header.after', ['topic'=>$topic, 'viewer'=>$user]).($user?'<div class="topic-actions"><form method="post" action="/topic/'.$id.'/bookmark"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($bookmarked?'取消收藏':'收藏主题').'</button></form><form method="post" action="/topic/'.$id.'/subscribe"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($subscribed?'取消订阅':'订阅回复').'</button></form><details class="report-control"><summary>举报主题</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="topic"><input type="hidden" name="target_id" value="'.$id.'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details></div>'.extension_slot('topic.actions.after', ['topic'=>$topic, 'viewer'=>$user]):'').'</header>'.extension_slot('topic.thread.before',$threadView).'<section class="topic-thread" id="replies">';
         $body = extension_slot('topic.thread.before', $threadView).$body;
         $body .= extension_slot('topic.actions.after', ['topic'=>$topic, 'viewer'=>$user]);
        $filterPostRows = extension_candidate_plugins('filter', 'post.row') !== [];
        $showReplyHeading = $afterFloor < 2;
        foreach ($rows as $index => $post) {
            $floor = (int)($post['reply_floor'] ?? ($afterFloor + $index + 1));
            $kind = $floor === 1 ? 'post-original' : 'post-reply';
            if ($floor > 1 && $showReplyHeading) {
                $body .= '<h2 class="replies-heading">回复 <span>'.(int)$topic['reply_count'].'</span></h2>';
                $showReplyHeading = false;
            }
            $postCreatedAt = (string)$post['created_at'];
            $canEditPost = $user && ((int)$user['id'] === (int)$post['user_id'] || $user['role'] === 'admin');
            $liked=!empty($viewerState['liked'][(int)$post['id']]);
            $postView = new PostView($post, $topic, $user, $floor, $floor === 1);
            $postDraft = extension_decorate_post($postView);
            $post['body_html'] = $postDraft->bodyHtml;
            $canDeletePost = $floor > 1 && $canEditPost;
            $body .= extension_slot('post.before', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user, 'floor'=>$floor]);
            if ($filterPostRows) $rowStart = strlen($body);
            $body .= '<article class="post '.$kind.'" id="p'.(int)$post['id'].'">';
            if ($floor > 1) $body .= '<div class="reply-author"><span class="reply-avatar" aria-hidden="true">'.e(text_slice($post['username'], 0, 1)).'</span><div class="reply-author-meta"><a href="/u/'.rawurlencode($post['username']).'">'.e($post['username']).'</a><time datetime="'.e(str_replace(' ', 'T', substr($postCreatedAt, 0, 19))).'">'.e(substr($postCreatedAt, 0, 16)).'</time></div>'.($canEditPost?'<a class="post-edit" href="/p/'.(int)$post['id'].'/edit">编辑</a>':'').($canDeletePost?'<form class="post-delete" method="post" action="/post/'.(int)$post['id'].'/delete"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button type="submit">删除</button></form>':'').'<button class="reply-floor" type="button" data-floor="'.(int)$floor.'" aria-label="回复 '.$floor.' 楼">回复</button></div>';
            $body .= '<div class="body">'.$post['body_html'].'</div>';
            $body .= extension_slot('post.after-body', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user]);
             if($user && $floor > 1){$likes=(int)($viewerState['likes'][(int)$post['id']]??0);$body.='<div class="post-tools"><form method="post" action="/post/'.(int)$post['id'].'/like"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($liked?'已点赞':'点赞').' · '.$likes.'</button></form><details class="report-control"><summary>举报</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="post"><input type="hidden" name="target_id" value="'.(int)$post['id'].'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details><form method="post" action="/user/'.(int)$post['user_id'].'/block"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>屏蔽用户</button></form>'.extension_render_post_actions($postView).'</div>';}
            $body .= '</article>';
            if ($filterPostRows) {
                $row = extension_filter('post.row', substr($body, $rowStart), ['post'=>$post, 'topic'=>$topic, 'viewer'=>plugin_viewer_summary($user), 'floor'=>$floor]);
                $body = substr_replace($body, $row, $rowStart);
            }
            $body .= extension_slot('post.after', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user, 'floor'=>$floor]);
        }
        $body .= '</section>'.extension_slot('topic.thread.after',$threadView);
        $currentPage = (int)ceil(max(1, $afterFloor + 1) / $pageSize);
        $nextUrl = '';
        if ($nextAfter > 0) $nextUrl = '/topic-'.$id.'-'.($currentPage + 1);
        $pagination = ['context'=>'topic','page_size'=>$pageSize,'first_url'=>'/t/'.$id,'current_page'=>$currentPage,'has_more'=>$nextAfter > 0,'next_url'=>$nextUrl];
        $pagination += \LiteBBS\Application\Pagination::forView($currentPage, $selection === null ? (int)$topic['reply_count'] + 1 : null, $pageSize, '/t/'.$id, '/topic-'.$id);
        $body .= theme_render_standard('pagination', ['pagination'=>$pagination], static fn(array $view): string => template_pagination_fallback($view['pagination']));
        if (!empty($topic['is_locked'])) $body .= '<p class="locked-note">该主题已锁定，暂不能回复。</p>';
        elseif ($user) $body .= '<section class="reply-composer" id="reply-composer" aria-labelledby="reply-composer-title"><div class="reply-composer-heading"><div><p class="eyebrow">YOUR REPLY</p><h2 id="reply-composer-title">参与讨论</h2></div><span>以 '.e($user['username']).' 的身份回复</span></div><div class="reply-quote-note" hidden><strong></strong><blockquote></blockquote></div><form method="post" action="/t/'.$id.'/reply"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" id="reply-to-post" name="reply_to_post" value=""><label class="reply-editor" for="reply-body"><span>回复内容</span><textarea id="reply-body" name="body" maxlength="12000" rows="7" placeholder="写下清晰、友善且有帮助的回复…" required></textarea></label>'.extension_slot('reply.create.fields', ['topic'=>$topic, 'user'=>$user]).extension_slot('reply.create.sidebar', ['topic'=>$topic, 'user'=>$user]).'<div class="reply-composer-actions"><small>支持换行纯文本，最多 12,000 字</small><button class="primary">发布回复</button></div>'.extension_slot('reply.form.after', ['topic'=>$topic, 'user'=>$user]).'</form></section>';
        $coreBody = $body;
        $body = theme_render_page('topic', ['topic'=>$topic, 'posts'=>array_values($rows), 'viewer'=>$user, 'content'=>ThemeTrustedHtml::fromCore($coreBody)], static fn(array $view): string => $coreBody);

    if ($cachePage) {
        ob_start();
        page($topic['title'], $body, '讨论主题：'.$topic['title'], true);
        $html = (string)ob_get_clean();
        page_cache_write($pageCacheKey, [$html], 120);
        echo $html;
    } else {
        page($topic['title'], $body, '讨论主题：'.$topic['title'], true);
    }
    return;
}

if (preg_match('~^/(?:c/(\d+)|category-(\d+)(?:-(\d+))?)$~', $path, $match)) {
    $categoryId = (string)($match[1] ?? '');
    $numericCategoryId = (string)($match[2] ?? '');
    $numericPage = $numericCategoryId !== '' ? (int)($match[3] ?? 1) : 1;
    $id = (int)($categoryId !== '' ? $categoryId : $numericCategoryId);
    $config = load_config();
    $theme = (string)($config['theme'] ?? 'default');
    $pageSize = theme_page_size($theme, 'category');
    if ($query) fail(400, '分页参数无效');
    if ($numericCategoryId !== '') \LiteBBS\Application\NumericPageCursor::assertAllowed($numericPage);
    $categoryCursor = $numericPage > 1 ? \LiteBBS\Application\NumericPageCursor::feed($numericPage, $pageSize, $id) : '';
    if ($numericPage > 1 && $categoryCursor === '') fail(404, '页面不存在');
    try {
        $categoryCursorData = $categoryCursor === '' ? null : \LiteBBS\Application\FeedCursor::decode($categoryCursor);
    } catch (InvalidArgumentException) {
        fail(404, '页面不存在');
    }
    $anonymous = empty($cookies[session_name()]);
    $cachePage = $anonymous && theme_effective_package($theme) !== null && !extension_dynamic_read_page('category');
    $versions = cache_versions(['topics','categories','category-feed:'.$id,'extension-read-pages','plugins','theme-settings:'.$theme]);
    $shellVersion = hash('sha256', implode("\0", [
        (string)($config['site'] ?? ''),
        (string)($config['url'] ?? ''),
        (string)($config['theme'] ?? 'default'),
        (string)($config['locale'] ?? 'zh-CN'),
        json_encode($config['seo'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        theme_asset_signature($theme),
    ]));
    $pageCacheKey = 'category-page:v6:'.$pageSize.':'.$id.':'.hash('sha256',$categoryCursor).':'.$shellVersion.':'.(int)$versions['categories'].':'.(int)$versions['category-feed:'.$id].':'.(int)$versions['extension-read-pages'].':'.(int)$versions['plugins'].':'.(int)$versions['theme-settings:'.$theme];
    if ($cachePage && ($cachedPage = page_cache_read($pageCacheKey, 120)) !== null) {
        echo (string)($cachedPage[0] ?? '');
        return;
    }
    $category = h('SELECT id,title,description FROM categories WHERE id=? AND is_visible=1', [$id])->fetch();
    if (!$category) fail(404, '分区不存在');
    $viewer = $anonymous ? null : current_user();
    if (!extension_permits('category.view', ['actor'=>$viewer, 'category'=>$category])) fail(404, '分区不存在');
    $key = 'category:v7:'.$pageSize.':'.$id.':'.hash('sha256',$categoryCursor).':'.(int)$versions['category-feed:'.$id].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'];
    $readContext = new ReadSelection('category', $pageSize + 1, $id, $categoryCursorData, isset($viewer['id']) ? (int)$viewer['id'] : null);
    $selection = $numericPage > 1 ? null : extension_select_ids($readContext);
    $loadRows = static fn(): array => read_feed_rows($categoryCursorData, $pageSize, $id, $selection, $viewer);
    $rows = $categoryCursorData === null && $selection === null && extension_candidate_plugins('policies', 'category.view') === [] ? cached($key, 120, $loadRows) : $loadRows();
    $nextCursor = '';
    if (count($rows) > $pageSize) {
        array_pop($rows);
        $nextCursor = \LiteBBS\Application\FeedCursor::fromTopic(end($rows), ($categoryCursorData['n'] ?? 1) + 1);
    }

    $rows = extension_transform_rows($readContext, $rows);
    $categoryView=['category'=>$category,'topics'=>$rows,'viewer'=>$viewer,'next_page'=>$nextCursor === '' ? null : $numericPage + 1];
    extension_preload('category.feed.items', $categoryView);
    $body = '<p class="eyebrow">SPACE / '.e($category['title']).'</p><h1>'.e($category['title']).'</h1><p>'.e($category['description']).'</p>'.extension_slot('category.header.after',$categoryView).extension_slot('category.feed.before',$categoryView);
    foreach ($rows as $topic) {$created=(string)$topic['created_at'];$replied=(string)$topic['reply_created_at'];$item=new TopicListItem($topic,$viewer,'category');$body .= '<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'" data-topic-id="'.(int)$topic['id'].'"><span class="topic-avatar" aria-hidden="true">'.e(text_slice($topic['username'], 0, 1)).'</span><div class="topic-feed-main"><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a>'.extension_topic_title_suffix($item).'</h2><div class="topic-feed-meta">'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'<a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><span class="topic-views" aria-label="'.(int)$topic['view_count'].' 次浏览"><i aria-hidden="true"></i>'.(int)$topic['view_count'].'</span><time class="topic-created" datetime="'.e(str_replace(' ','T',substr($created,0,19))).'">'.e(substr($created,0,16)).'</time>'.((int)$topic['reply_count']>0?'<a class="topic-reply-author" href="/u/'.rawurlencode((string)$topic['reply_username']).'">'.e((string)$topic['reply_username']).'</a><span class="topic-activity">回复</span><time class="topic-replied" datetime="'.e(str_replace(' ','T',substr($replied,0,19))).'">'.e(substr($replied,0,16)).'</time>':'').extension_topic_list_columns($item).'</div></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';}
    $nextUrl = $nextCursor === '' ? '' : '/category-'.$id.'-'.($numericPage + 1);
    $pagination = ['context'=>'category','page_size'=>$pageSize,'first_url'=>'/c/'.$id,'current_page'=>$numericPage,'has_more'=>$nextCursor!=='','next_url'=>$nextUrl];
    $pagination += \LiteBBS\Application\Pagination::forView($numericPage, $selection === null ? cached_topic_count('category', $id) : null, $pageSize, '/c/'.$id, '/category-'.$id);
    $paginationHtml=theme_render_standard('pagination',['pagination'=>$pagination],static fn(array $view): string => template_pagination_fallback($view['pagination']));
    $body.=extension_slot('category.feed.after',$categoryView).$paginationHtml;
    $coreBody = $body;
    $body = theme_render_page('category', ['category'=>$category, 'topics'=>$rows, 'viewer'=>$viewer, 'pagination'=>ThemeTrustedHtml::fromCore($paginationHtml)], static fn(array $view): string => $coreBody);
    if ($cachePage) ob_start();
    page($category['title'], $body, $category['description'], true);
    if ($cachePage) {
        $html = (string)ob_get_clean();
        page_cache_write($pageCacheKey, [$html], 120);
        echo $html;
    }
    return;
}
}

}

namespace {
// ===== reply-handler.php =====
function handle_topic_reply(\LiteBBS\Framework\Request $request, int $topicId): never {
    require_post();
    $input = $request->post;
    $files = $request->files;
    $user=current_user();
    if(!$user)redirect('/login');
    $body=trim((string)($input['body']??''));
    $replyTo=(int)($input['reply_to_post']??0);
    if($replyTo){
        $target=h('SELECT p.body_markdown,p.topic_id,u.username FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status="normal"',[$replyTo])->fetch();
        if(!$target||(int)$target['topic_id']!==$topicId)fail(400,'回复目标无效');
        $quoted=preg_split('/\R/u',(string)$target['body_markdown'])?:[];
        $body='回复 '.$target['username']."：\n> ".implode("\n> ",$quoted)."\n\n".$body;
    }
    if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');
    enforce_content_rate_limit('reply',(int)$user['id']);
    $topic=h('SELECT t.id,t.category_id,t.user_id FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND t.is_locked=0 AND c.is_visible=1',[$topicId])->fetch();
    if(!$topic)fail(404,'主题不存在、分区已隐藏或主题已锁定');
    extension_authorize_speaking($user, 'topic.reply', ['topic'=>$topic]);
    extension_authorize('topic.reply', ['actor'=>$user, 'topic'=>$topic]);
    $draft = extension_apply_command('reply.create', new ContentDraft('reply', '', $body, (int)$topic['category_id']), ['actor'=>$user, 'topic'=>$topic]);
    $body = $draft->body;
    if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');
    $body = extension_preprocess_post_markdown($body, ['topic_id'=>$topicId,'actor_id'=>(int)$user['id'],'kind'=>'reply']);
    if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');
    $bodyHtml=extension_render_post_body($body, ['topic_id'=>$topicId,'actor_id'=>(int)$user['id'],'kind'=>'reply']);
    $hasLastPostPointer = topic_last_post_pointer_available();
    $hasParticipants = topic_participants_available();
    $uploads = extension_safe_uploads($files);
    [$topic, $postId, $topicAuthorId] = \LiteBBS\Application\transaction_manager()->run(static function (PDO $pdo) use ($topicId, $user, $body, $bodyHtml, $input, $files, $uploads, $hasLastPostPointer, $hasParticipants): array {
        enforce_post_interval((int)$user['id'], true);
        $topic=h('SELECT t.id,t.category_id,t.user_id FROM topics t WHERE t.id=? AND t.status="normal" AND t.is_locked=0',[$topicId])->fetch();
        if(!$topic)fail(404,'主题不存在、分区已隐藏或主题已锁定');
        if(!h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE',[(int)$topic['category_id']])->fetch()) fail(404,'主题不存在、分区已隐藏或主题已锁定');
        h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$topic['id'],$user['id'],$body,$bodyHtml]);
        $postId=(int)$pdo->lastInsertId();
        $topicAuthorId=(int)$topic['user_id'];
        if($topicAuthorId!==(int)$user['id'])extension_create_notification($topicAuthorId,(int)$user['id'],'reply',$topicId,$postId);
        if ($hasParticipants) h('INSERT INTO topic_participants(topic_id,user_id,last_post_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_post_id=GREATEST(last_post_id,VALUES(last_post_id))', [$topicId,$user['id'],$postId]);
        h('INSERT INTO notification_outbox(topic_id,post_id,actor_id,pending_topic_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=IF(VALUES(post_id)>post_id,VALUES(actor_id),actor_id),created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))',[$topicId,$postId,(int)$user['id'],$topicId]);
        $updated=$hasLastPostPointer
            ? h('UPDATE topics SET reply_count=reply_count+1,last_post_id=GREATEST(COALESCE(last_post_id,0),?),last_post_at=UTC_TIMESTAMP(6) WHERE id=? AND category_id=? AND status="normal" AND is_locked=0',[$postId,$topicId,(int)$topic['category_id']])
            : h('UPDATE topics SET reply_count=reply_count+1,last_post_at=UTC_TIMESTAMP(6) WHERE id=? AND category_id=? AND status="normal" AND is_locked=0',[$topicId,(int)$topic['category_id']]);
        if($updated->rowCount()!==1)fail(409,'主题状态已变化，请刷新后重试');
        extension_event_or_fail('post.created', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id'], 'post'=>$input, 'files'=>$files, 'user'=>$user]);
        extension_event_or_fail('post.created.v2', ['topic_id'=>$topicId,'post_id'=>$postId,'actor_id'=>(int)$user['id'],'body'=>$body,'uploads'=>$uploads]);
        extension_enqueue_async_event('post.created.async', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id']]);
        return [$topic, $postId, $topicAuthorId];
    });
    if($topicAuthorId!==(int)$user['id'])invalidate_user_cache($topicAuthorId);
    // Feed caches have a bounded TTL. Avoid invalidating every public listing
    // for each reply; only the changed topic page needs immediate freshness.
    invalidate_topic_read_cache($topicId, (int)$topic['category_id'], false, false);
    redirect('/t/'.$topicId.'#p'.$postId);
}

}

namespace {
// ===== application-routes.php =====
function register_application_routes(\LiteBBS\Framework\Router $router): void
{
    $handler = static function (string $file, string $function): callable {
        return new \LiteBBS\Application\LegacyActionController($file, $function);
    };

    $account = $handler('account-routes.php', 'handle_account_routes');
    $router->add(['GET', 'POST'], '~^/new$~', $handler('plugin-topic.php', 'handle_topic_create'));
    $router->add(['GET', 'POST'], '~^/(?:login|register|me|u/[^/]+(?:/tab-[a-z][a-z0-9_-]{0,63}-(?:[2-9]|[1-9][0-9]+))?)$~', $account);
    $router->add('POST', '~^/logout$~', $account);
    $router->add('GET', '~^/search$~', $account);
    $market = $handler('market.php', 'handle_market');
    $backend = $handler('admin.php', 'handle_backend_routes');
    $router->add(['GET', 'POST'], '~^/admin/categories$~', $backend);
    $router->add(['GET', 'POST'], '~^/admin/(?:market|extensions)$~', $backend);
    $router->add('POST', '~^/admin/extension-share$~', $backend);
    $router->add('GET', '~^/extension-download/(?P<token>[a-f0-9]{48})$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
        return \ExtensionShareService::downloadResponse((string)$params['token']);
    });
    $router->add('GET', '~^/extension-draft/(?P<token>[a-f0-9]{48})$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
        return \ExtensionShareService::draftResponse((string)$params['token']);
    });
    $router->add(['GET', 'POST'], '~^/market(?:/index\.json|/submit)?$~', $market);
    $router->add('GET', '~^/market/package/[0-9]+$~', $market);
    $router->add(['GET', 'POST'], '~^/admin/(?:themes|plugins)$~', $backend);
    $router->add(['GET', 'POST'], '~^/admin/scanner$~', $backend);
    $router->add(['GET', 'POST'], '~^/admin/(?:users|settings|content)$~', $backend);
    $router->add(['GET', 'POST'], '~^/admin/reports$~', $backend);
    $router->add(['GET', 'POST'], '~^/admin/audit$~', $backend);
    $router->add(['GET', 'POST'], '~^/messages$~', $handler('messages.php', 'handle_messages'));
    $community = $handler('community.php', 'handle_community_routes');
    $router->add(['GET', 'POST'], '~^/avatar/upload$~', $community);
    $router->add('GET', '~^/avatar/[0-9]+$~', $community);
    $router->add('POST', '~^/(?:topic/[0-9]+/(?:bookmark|subscribe)|post/[0-9]+/(?:like|delete)|report|user/[0-9]+/(?:block|unblock))$~', $community);
    $router->add(['GET', 'POST'], '~^/p/[0-9]+/edit$~', $handler('post-edit.php', 'handle_post_edit'));
    $router->add('GET', '~^/(?:t/[0-9]+|c/[0-9]+|topic-[0-9]+(?:-[0-9]+)?|category-[0-9]+(?:-[0-9]+)?)$~', $handler('read-pages.php', 'handle_read_pages'));
    $router->add(['GET', 'POST'], '~^/admin$~', $backend);
}

}

namespace LiteBBS\Application {
final class FeedCursor
{
    /** @return array{p: int, d: string, i: int, n: int} */
    public static function decode(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 256) {
            throw new \InvalidArgumentException('Invalid feed cursor.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($data) || !in_array($data['p'] ?? null, [0, 1], true)
            || !is_string($data['d'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $data['d'])
            || !is_int($data['i'] ?? null) || $data['i'] < 1) {
            throw new \InvalidArgumentException('Invalid feed cursor.');
        }

        $page = $data['n'] ?? 2;
        if (!is_int($page) || $page < 2 || $page > 1000000) {
            throw new \InvalidArgumentException('Invalid feed cursor.');
        }

        return ['p' => $data['p'], 'd' => $data['d'], 'i' => $data['i'], 'n' => $page];
    }

    public static function fromTopic(array $topic, int $page = 2): string
    {
        $data = json_encode([
            'p' => (int)$topic['is_pinned'],
            'd' => (string)$topic['last_post_at'],
            'i' => (int)$topic['id'],
            'n' => max(2, $page),
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

}

namespace LiteBBS\Application {
final class Pagination
{
    /** @return array{current_page: int, page_count: int, pages: list<array{number: int, url: string, current: bool}>} */
    public static function forView(int $currentPage, ?int $itemCount, int $pageSize, string $firstUrl, string $numberedPath): array
    {
        if ($itemCount === null) return ['current_page'=>$currentPage, 'page_count'=>$currentPage, 'pages'=>[]];
        $pageCount = max(1, (int)ceil(max(0, $itemCount) / max(1, $pageSize)));
        $currentPage = min(max(1, $currentPage), $pageCount);
        $numbers = $pageCount <= 7
            ? range(1, $pageCount)
            : array_values(array_unique(array_filter([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $pageCount - 1, $pageCount], static fn(int $number): bool => $number >= 1 && $number <= $pageCount)));
        sort($numbers, SORT_NUMERIC);
        $pages = [];
        foreach ($numbers as $number) {
            $pages[] = ['number' => $number, 'url' => $number === 1 ? $firstUrl : $numberedPath.'-'.$number, 'current' => $number === $currentPage];
        }

        return ['current_page' => $currentPage, 'page_count' => $pageCount, 'pages' => $pages];
    }
}

final class NumericPageCursor
{
    // Numbered routes are compatibility URLs. Bound reconstruction work so an
    // arbitrary path cannot turn one request into an unbounded query sequence.
    public const MAX_COMPATIBILITY_PAGE = 200;

    public static function assertAllowed(int $page): void
    {
        if ($page < 1 || $page > self::MAX_COMPATIBILITY_PAGE) \fail(404, '页面不存在');
    }

    public static function feed(int $page, int $pageSize, ?int $categoryId = null): string
    {
        if ($page < 2) return '';
        $scope = $categoryId === null ? 'home' : 'category:'.$categoryId;
        $versions = $categoryId === null
            ? \cache_versions(['topics', 'categories'])
            : \cache_versions(['category-topics:'.$categoryId]);
        $version = $categoryId === null
            ? (int)$versions['topics'].':'.(int)$versions['categories']
            : (int)$versions['category-topics:'.$categoryId];
        $cached = \cached('numeric-page:v1:'.$scope.':'.$pageSize.':'.$page.':'.$version, 300, static function () use ($page, $pageSize, $categoryId): array {
            $cursor = null;
            for ($number = 2; $number <= $page; $number++) {
                $args = [];
                $where = $categoryId === null ? 't.status="normal" AND c.is_visible=1' : 't.category_id=? AND t.status="normal"';
                if ($categoryId !== null) $args[] = $categoryId;
                if ($cursor !== null) {
                    ['p'=>$pinned, 'd'=>$date, 'i'=>$id] = $cursor;
                    $where .= ' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';
                    array_push($args, $pinned, $pinned, $date, $date, $id);
                }
                $join = $categoryId === null ? ' JOIN categories c ON c.id=t.category_id' : '';
                $rows = \h('SELECT t.id,t.is_pinned,t.last_post_at FROM topics t'.$join.' WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT '.($pageSize + 1), $args)->fetchAll();
                if (count($rows) <= $pageSize) return [''];
                $cursor = $rows[$pageSize - 1];
            }
            return [FeedCursor::fromTopic($cursor, $page)];
        });
        return (string)($cached[0] ?? '');
    }

    public static function topicAfter(int $topicId, int $page, int $pageSize): int
    {
        if ($page < 2) return 0;
        $version = \cache_version('topic:'.$topicId);
        $cached = \cached('numeric-topic-page:v1:'.$topicId.':'.$pageSize.':'.$page.':'.$version, 300, static function () use ($topicId, $page, $pageSize): array {
            $after = 0;
            for ($number = 2; $number <= $page; $number++) {
                $rows = \h('SELECT id FROM posts WHERE topic_id=? AND status="normal" AND id>? ORDER BY id LIMIT '.($pageSize + 1), [$topicId, $after])->fetchAll();
                if (count($rows) <= $pageSize) return [0];
                $after = (int)$rows[$pageSize - 1]['id'];
            }
            return [$after];
        });
        return (int)($cached[0] ?? 0);
    }
}

}

namespace LiteBBS\Application {
use PDO;

final class PrivateMessageService
{
    public function __construct(private TransactionManager $transactions, private AuditLogger $audit)
    {
    }

    public function send(int $senderId, int $recipientId, string $body): int
    {
        if ($senderId === $recipientId) \fail(400, '不能给自己发送私信');

        $messageId = $this->transactions->run(function (PDO $db) use ($senderId, $recipientId, $body): int {
            $accounts = \h('SELECT id,status FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE',
                [min($senderId, $recipientId), max($senderId, $recipientId)])->fetchAll();
            if (count($accounts) !== 2 || array_column($accounts, 'status') !== ['active', 'active']) {
                \fail(404, '发件人或收件人不可用');
            }
            if (\h('SELECT 1 FROM user_blocks WHERE (user_id=? AND blocked_user_id=?) OR (user_id=? AND blocked_user_id=?) LIMIT 1',
                [$senderId, $recipientId, $recipientId, $senderId])->fetchColumn()) {
                \fail(403, '你与该用户之间无法发送私信');
            }
            \h('INSERT INTO private_messages(sender_id,recipient_id,body,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$senderId, $recipientId, $body]);
            $id = (int)$db->lastInsertId();
            \extension_create_notification($recipientId, $senderId, 'message');
            $this->audit->record('message.sent', 'private_message', $id, ['recipient_id'=>$recipientId]);
            return $id;
        });
        \invalidate_user_cache($recipientId);
        return $messageId;
    }
}

}
