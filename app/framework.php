<?php
declare(strict_types=1);

namespace LiteBBS\Framework;

final class ResponseHalt extends \RuntimeException {
    public function __construct(public Response $response) { parent::__construct('Request completed.'); }
}

final class Request {
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    public array $server;
    public array $headers;

    public function __construct(
        string $method,
        string $path,
        array $query,
        array $post,
        array $files,
        array $server,
        array $headers
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
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
            'server'=>$this->server,
            'headers'=>$this->headers,
            'user'=>$user,
            'pdo'=>$pdo,
        ];
    }
}

final class Response {
    private array $headers = [];
    public string $body;
    public int $status;

    public function __construct(string $body = '', int $status = 200) {
        $this->body = $body;
        $this->status = $status;
    }

    public function header(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function headers(): array { return $this->headers; }

    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $name=>$value) header($name.': '.$value, true);
        echo $this->body;
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

final class Router {
    private array $routes = [];

    public function add(string|array $methods, string $pattern, callable $handler): self {
        $methods = array_map('strtoupper', (array)$methods);
        $this->routes[] = [$methods, $pattern, $handler];
        return $this;
    }

    public function dispatch(Request $request, Container $container): ?Response {
        $allowed = [];
        foreach ($this->routes as [$methods, $pattern, $handler]) {
            if (!preg_match($pattern, $request->path, $matches)) continue;
            if (!in_array($request->method, $methods, true) && !in_array('*', $methods, true)) { $allowed = array_merge($allowed, $methods); continue; }
            $params = [];
            foreach ($matches as $key=>$value) if (is_string($key)) $params[$key] = $value;
            $response = $handler($request, $container, $params);
            if ($response instanceof Response) return $response;
            if (is_string($response)) return new Response($response);
            return null;
        }
        return $allowed ? (new Response('Method Not Allowed', 405))->header('Allow', implode(', ', array_unique($allowed))) : null;
    }
}

final class Kernel {
    private array $middleware = [];
    public Container $container;
    public Router $router;

    public function __construct(?Container $container = null, ?Router $router = null) {
        $this->container = $container ?: new Container();
        $this->router = $router ?: new Router();
    }

    public function middleware(callable $middleware): self {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function handle(Request $request, ?callable $fallback = null): ?Response {
        $destination = fn(Request $request): ?Response => $this->router->dispatch($request, $this->container)
            ?? ($fallback ? $fallback($request) : null);
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $destination;
            $destination = fn(Request $request): ?Response => $middleware($request, $next, $this->container);
        }
        return $destination($request);
    }
}
