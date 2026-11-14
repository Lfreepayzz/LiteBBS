<?php
declare(strict_types=1);

const LITEBBS_EXTENSION_API_VERSION = '1.0';

interface LiteBBSPlugin
{
    public function boot(PluginContext $context): void;
}

final class PluginContext
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function id(): string { return $this->id; }

    public function routes(): PluginRouteRegistrar { return new PluginRouteRegistrar($this->id, $this->registry); }
    public function slots(): PluginSlotRegistrar { return new PluginSlotRegistrar($this->id, $this->registry); }
    public function preloads(): PluginPreloadRegistrar { return new PluginPreloadRegistrar($this->id, $this->registry); }
    public function events(): PluginEventRegistrar { return new PluginEventRegistrar($this->id, $this->registry); }
    public function assets(): PluginAssetRegistrar { return new PluginAssetRegistrar($this->id, $this->registry); }
    public function database(): PluginDatabase { return new PluginDatabase($this->id); }
    public function logger(): PluginLogger { return new PluginLogger($this->id); }
    public function config(): array { return $this->registry['plugins'][$this->id]['config'] ?? []; }
}

final class PluginRouteRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string|array $methods, string $pattern, callable $handler): void
    {
        $methods = (array)$methods;
        extension_validate_plugin_route($this->id, $methods, $pattern);
        $this->registry['routes'][] = ['plugin'=>$this->id, 'methods'=>$methods, 'pattern'=>$pattern, 'handler'=>$handler];
    }
}

final class PluginSlotRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $slot, callable $renderer, int $priority = 0): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $slot)) throw new InvalidArgumentException('Invalid plugin slot.');
        $this->registry['slots'][$slot][] = ['plugin'=>$this->id, 'priority'=>$priority, 'renderer'=>$renderer];
    }
}

final class PluginEventRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function listen(string $event, callable $listener): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $event)) throw new InvalidArgumentException('Invalid plugin event.');
        $this->registry['events'][$event][] = ['plugin'=>$this->id, 'listener'=>$listener];
    }
}

final class PluginPreloadRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $slot, callable $loader): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $slot)) throw new InvalidArgumentException('Invalid plugin preload slot.');
        $this->registry['preloads'][$slot][] = ['plugin'=>$this->id, 'loader'=>$loader];
    }
}

final class PluginAssetRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function css(string $path): void
    {
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.css$~', $path)) throw new InvalidArgumentException('Invalid plugin CSS asset.');
        $this->registry['assets'][] = ['plugin'=>$this->id, 'path'=>$path, 'type'=>'css'];
    }
}

final class PluginDatabase
{
    private string $prefix;

    public function __construct(string $id) { $this->prefix = 'plg_'.str_replace('-', '_', $id).'_'; }

    public function table(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,55}$/', $name)) throw new InvalidArgumentException('Invalid plugin table name.');
        return $this->prefix.$name;
    }

    public function query(string $sql, array $parameters = []): PDOStatement
    {
        $normalized = trim($sql);
        if (str_contains($normalized, ';') || !preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/i', $normalized) || preg_match('/\b(?:ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|RENAME|CALL|LOAD|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $normalized)) throw new RuntimeException('Plugin queries must be a single DML statement on plugin tables; use migrations for schema changes.');
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+(`?[a-zA-Z0-9_]+`?)/i', $normalized, $matches);
        $tables = array_map(static fn(string $table): string => trim($table, '`'), $matches[1] ?? []);
        if (!$tables) throw new RuntimeException('Plugin query must reference a plugin table.');
        foreach ($tables as $table) if (!str_starts_with($table, $this->prefix)) throw new RuntimeException('Plugin queries may only reference the plugin table prefix.');
        return h($normalized, $parameters);
    }

}

final class PluginLogger
{
    public function __construct(private string $id) {}
    public function error(string $message): void { error_log('plugin['.$this->id.'] '.$message); }
}

function &extension_registry(): array
{
    static $registry = ['plugins'=>[], 'routes'=>[], 'slots'=>[], 'events'=>[], 'assets'=>[], 'preloads'=>[]];
    return $registry;
}

function extension_config_decode(string $json): array
{
    $config = json_decode($json, true);
    return is_array($config) ? $config : [];
}

function extension_validate_plugin_route(string $id, array $methods, string $pattern): void
{
    if (!$methods || array_diff($methods, ['GET','POST','PUT','PATCH','DELETE','OPTIONS']) || count($methods) !== count(array_unique($methods))) throw new InvalidArgumentException('Invalid plugin route methods.');
    if (str_contains($pattern, "\0") || preg_match('/[\r\n]/', $pattern) || !preg_match('~^(.)(.*)\\1([a-z]*)$~sD', $pattern, $match)) throw new InvalidArgumentException('Invalid plugin route pattern.');
    $body = $match[2];
    if (!preg_match('~^\^/plugin/'.preg_quote($id, '~').'(?:/|\$)~', $body) || !str_ends_with($body, '$') || str_contains($body, '|') || @preg_match($pattern, '') === false) throw new InvalidArgumentException('Plugin routes must be exact routes within their namespace.');
}

function extension_runtime_load(): array
{
    static $loaded = false;
    $registry =& extension_registry();
    if ($loaded) return $registry['plugins'];
    $loaded = true;
    $enabled = cached('extensions:v1:'.cache_version('plugins'), 30, static function (): array {
        return h('SELECT name,version,config_json FROM plugins WHERE enabled=1')->fetchAll();
    });
    $root = dirname(__DIR__).'/plugins/';
    foreach ($enabled as $record) {
        $id = (string)($record['name'] ?? '');
        $manifestFile = $root.$id.'/plugin.json';
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id) || !is_file($manifestFile)) continue;
        try {
            $manifest = json_decode((string)file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
            $entry = is_array($manifest) ? (string)($manifest['entry'] ?? '') : '';
            $class = is_array($manifest) ? (string)($manifest['class'] ?? '') : '';
            $entryFile = extension_v2_entry_file($root.$id, $entry);
            if (($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $id || $entryFile === null || !extension_class_name_valid($class)) throw new RuntimeException('Invalid plugin manifest.');
            require_once $entryFile;
            $plugin = new $class();
            if (!$plugin instanceof LiteBBSPlugin) throw new RuntimeException('Plugin entry must implement LiteBBSPlugin.');
            $config = extension_config_decode((string)($record['config_json'] ?? '{}'));
            $temporary = ['plugins'=>[$id=>['config'=>$config]], 'routes'=>[], 'slots'=>[], 'events'=>[], 'assets'=>[], 'preloads'=>[]];
            $context = new PluginContext($id, $temporary);
            $plugin->boot($context);
            foreach (['routes','slots','events','assets','preloads'] as $key) {
                if ($key === 'slots' || $key === 'events' || $key === 'preloads') foreach ($temporary[$key] as $slot => $entries) $registry[$key][$slot] = array_merge($registry[$key][$slot] ?? [], $entries);
                else $registry[$key] = array_merge($registry[$key], $temporary[$key]);
            }
            $registry['plugins'][$id] = ['manifest'=>$manifest, 'plugin'=>$plugin, 'config'=>$config];
        } catch (Throwable $error) {
            error_log('extension plugin '.$id.' disabled: '.$error->getMessage());
        }
    }
    foreach ($registry['slots'] as &$entries) {
        usort($entries, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
    }
    unset($entries);
    return $registry['plugins'];
}

function extension_runtime_register_routes(\LiteBBS\Framework\Router $router): void
{
    extension_runtime_load();
    foreach (extension_registry()['routes'] as $route) {
        $router->add($route['methods'], $route['pattern'], static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params) use ($route): \LiteBBS\Framework\Response {
            try {
                $result = ($route['handler'])($request, $params);
                return $result instanceof \LiteBBS\Framework\Response ? $result : new \LiteBBS\Framework\Response(is_string($result) ? $result : '');
            } catch (Throwable $error) {
                if ($error instanceof \LiteBBS\Framework\ResponseHalt) return $error->response;
                error_log('plugin route '.$route['plugin'].' failed: '.$error->getMessage());
                return new \LiteBBS\Framework\Response('Plugin temporarily unavailable.', 503);
            }
        });
    }
}

function extension_slot(string $slot, array $view = []): string
{
    $registry =& extension_registry();
    if (!isset($registry['slots'][$slot])) extension_runtime_load();
    $entries = $registry['slots'][$slot] ?? [];
    $html = '';
    foreach ($entries as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        try {
            $output = ($entry['renderer'])($view);
            if (is_string($output)) $html .= $output;
            if ((microtime(true) - $started) > 0.02) { error_log('plugin slot '.$entry['plugin'].' exceeded 20ms budget'); extension_plugin_failure($entry['plugin']); }
        } catch (Throwable $error) {
            error_log('plugin slot '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return $html;
}

function extension_preload(string $slot, array $view = []): void
{
    extension_runtime_load();
    foreach (extension_registry()['preloads'][$slot] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try { ($entry['loader'])($view); }
        catch (Throwable $error) { error_log('plugin preload '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); }
    }
}

function extension_event(string $event, array $payload): void
{
    extension_runtime_load();
    foreach (extension_registry()['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try { ($entry['listener'])($payload); }
        catch (Throwable $error) { error_log('plugin event '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); }
    }
}

function extension_event_or_fail(string $event, array $payload): void
{
    $registry =& extension_registry();
    if (!isset($registry['events'][$event])) extension_runtime_load();
    foreach ($registry['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try {
            ($entry['listener'])($payload);
        } catch (Throwable $error) {
            extension_plugin_failure($entry['plugin']);
            throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $error);
        }
    }
}

function extension_event_sync(string $event, array $payload): void
{
    extension_runtime_load();
    foreach (extension_registry()['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try { ($entry['listener'])($payload); }
        catch (\LiteBBS\Framework\ResponseHalt $error) { extension_plugin_failure($entry['plugin']); throw $error; }
        catch (Throwable $error) { error_log('plugin sync event '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $error); }
    }
}

function extension_invalidate(string $key): void
{
    invalidate($key);
    invalidate('extension-read-pages');
}

function extension_plugin_degraded(string $id): bool
{
    static $requestState = [];
    if (array_key_exists($id, $requestState)) return $requestState[$id];
    $redis = redis_cache_client();
    if (!$redis) return $requestState[$id] = false;
    try { return $requestState[$id] = (bool)$redis->get(shared_cache_key('plugin-degraded:'.$id)); }
    catch (Throwable $error) { cache_error('plugin health read failed: '.$error->getMessage()); return $requestState[$id] = false; }
}

function extension_plugin_failure(string $id): void
{
    $redis = redis_cache_client();
    if (!$redis) return;
    try { $key=shared_cache_key('plugin-failures:'.$id);$count=(int)$redis->incr($key);if($count===1)$redis->expire($key,60);if($count>=3)$redis->setex(shared_cache_key('plugin-degraded:'.$id),60,'1'); }
    catch (Throwable $error) { cache_error('plugin health write failed: '.$error->getMessage()); }
}

function extension_stylesheets(): string
{
    extension_runtime_load();
    $html = '';
    foreach (extension_registry()['assets'] as $asset) {
        if ($asset['type'] !== 'css') continue;
        $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$asset['plugin'], $asset['path']);
        if ($file === null) continue;
        $version = asset_version($file);
        $html .= '<link rel="stylesheet" href="/plugin-assets/'.rawurlencode($asset['plugin']).'/'.implode('/', array_map('rawurlencode', explode('/', $asset['path']))).'?v='.$version.'">';
    }
    return $html;
}
