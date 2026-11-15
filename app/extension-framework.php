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

function extension_manifest_hooks(array $manifest, string $kind): ?array
{
    $key = $kind === 'slot' ? 'slots' : 'events';
    if (!isset($manifest['hooks']) || !is_array($manifest['hooks']) || !array_key_exists($key, $manifest['hooks'])) return null;
    $hooks = $manifest['hooks'][$key];
    if (!is_array($hooks)) throw new RuntimeException('Plugin '.$key.' declaration must be an array.');
    foreach ($hooks as $hook) {
        if (!is_string($hook) || !preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $hook)) throw new RuntimeException('Invalid plugin '.$key.' declaration.');
    }
    return array_values(array_unique($hooks));
}

function extension_manifest_assets(array $manifest): ?array
{
    if (!array_key_exists('assets', $manifest)) return null;
    if (!is_array($manifest['assets'])) throw new RuntimeException('Plugin assets declaration must be an array.');
    $assets = [];
    foreach ($manifest['assets'] as $asset) {
        if (!is_string($asset) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.css$~', $asset)) throw new RuntimeException('Invalid plugin asset declaration.');
        $assets[] = $asset;
    }
    return array_values(array_unique($assets));
}

function extension_plugin_manifest(string $id): ?array
{
    static $cache = [];
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) return null;
    $file = dirname(__DIR__).'/plugins/'.$id.'/plugin.json';
    if (!is_file($file)) return null;
    $signature = (string)@filemtime($file).':'.(string)@filesize($file);
    if (($cache[$id]['signature'] ?? null) === $signature) return $cache[$id]['manifest'];
    try {
        $manifest = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $cache[$id] = ['signature'=>$signature, 'manifest'=>is_array($manifest) ? $manifest : null];
        return $cache[$id]['manifest'];
    } catch (Throwable $error) {
        error_log('extension manifest '.$id.' failed: '.$error->getMessage());
        $cache[$id] = ['signature'=>$signature, 'manifest'=>null];
        return null;
    }
}

function extension_enabled_plugins(): array
{
    return cached('extensions:v1:'.cache_version('plugins'), 30, static function (): array {
        return h('SELECT name,version,config_json FROM plugins WHERE enabled=1')->fetchAll();
    });
}

function extension_candidate_plugins(string $kind, ?string $hook): array
{
    $enabled = extension_enabled_plugins();
    if ($hook === null) return $enabled;
    static $candidateCache = [];
    $scanKey = cache_version('plugins').':'.$kind.':'.$hook;
    if (array_key_exists($scanKey, $candidateCache)) return $candidateCache[$scanKey];
    $root = dirname(__DIR__).'/plugins/';
    $candidates = [];
    foreach ($enabled as $record) {
        $id = (string)($record['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
        $manifestFile = $root.$id.'/plugin.json';
        if (!is_file($manifestFile)) {
            $candidates[] = $record;
            continue;
        }
        try {
            $manifest = extension_plugin_manifest($id);
            if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
            $hooks = extension_manifest_hooks($manifest, $kind);
            if ($hooks === null || in_array($hook, $hooks, true)) $candidates[] = $record;
        } catch (Throwable $error) {
            error_log('extension manifest '.$id.' failed: '.$error->getMessage());
        }
    }
    return $candidateCache[$scanKey] = $candidates;
}

function extension_validate_plugin_route(string $id, array $methods, string $pattern): void
{
    if (!$methods || array_diff($methods, ['GET','POST','PUT','PATCH','DELETE','OPTIONS']) || count($methods) !== count(array_unique($methods))) throw new InvalidArgumentException('Invalid plugin route methods.');
    if (str_contains($pattern, "\0") || preg_match('/[\r\n]/', $pattern) || !preg_match('~^(.)(.*)\\1([a-z]*)$~sD', $pattern, $match)) throw new InvalidArgumentException('Invalid plugin route pattern.');
    $body = $match[2];
    if (!preg_match('~^\^/plugin/'.preg_quote($id, '~').'(?:/|\$)~', $body) || !str_ends_with($body, '$') || str_contains($body, '|') || @preg_match($pattern, '') === false) throw new InvalidArgumentException('Plugin routes must be exact routes within their namespace.');
}

function extension_runtime_load(?string $onlyPlugin = null, ?string $hookKind = null, ?string $hook = null): array
{
    static $loaded = [];
    $registry =& extension_registry();
    $enabled = $hookKind === null ? extension_enabled_plugins() : extension_candidate_plugins($hookKind, $hook);
    $root = dirname(__DIR__).'/plugins/';
    foreach ($enabled as $record) {
        $id = (string)($record['name'] ?? '');
        if ($onlyPlugin !== null && $id !== $onlyPlugin) continue;
        if (isset($loaded[$id])) continue;
        $loaded[$id] = true;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
        try {
            $manifest = extension_plugin_manifest($id);
            if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
            $entry = is_array($manifest) ? (string)($manifest['entry'] ?? '') : '';
            $class = is_array($manifest) ? (string)($manifest['class'] ?? '') : '';
            $entryFile = extension_v2_entry_file($root.$id, $entry);
            if (($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $id || $entryFile === null || !extension_class_name_valid($class)) throw new RuntimeException('Invalid plugin manifest.');
            extension_manifest_hooks($manifest, 'slot');
            extension_manifest_hooks($manifest, 'event');
            extension_manifest_assets($manifest);
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

function extension_runtime_register_routes(\LiteBBS\Framework\Router $router, ?string $onlyPlugin = null): void
{
    extension_runtime_load($onlyPlugin);
    foreach (extension_registry()['routes'] as $route) {
        if ($onlyPlugin !== null && $route['plugin'] !== $onlyPlugin) continue;
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
    if (!isset($registry['slots'][$slot])) extension_runtime_load(null, 'slot', $slot);
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
    extension_runtime_load(null, 'slot', $slot);
    foreach (extension_registry()['preloads'][$slot] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try { ($entry['loader'])($view); }
        catch (Throwable $error) { error_log('plugin preload '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); }
    }
}

function extension_event(string $event, array $payload): void
{
    extension_runtime_load(null, 'event', $event);
    foreach (extension_registry()['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try { ($entry['listener'])($payload); }
        catch (Throwable $error) { error_log('plugin event '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); }
    }
}

function extension_event_or_fail(string $event, array $payload): void
{
    $registry =& extension_registry();
    if (!isset($registry['events'][$event])) extension_runtime_load(null, 'event', $event);
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
    extension_runtime_load(null, 'event', $event);
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
    $records = extension_enabled_plugins();
    $root = dirname(__DIR__).'/plugins/';
    $fallback = false;
    $manifestById = [];
    foreach ($records as $record) {
        $id = (string)($record['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
        $manifestFile = $root.$id.'/plugin.json';
        if (!is_file($manifestFile)) { $fallback = true; continue; }
        try {
            $manifest = extension_plugin_manifest($id);
            if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $id || extension_manifest_assets($manifest) === null) $fallback = true;
            else $manifestById[$id] = $manifest;
        } catch (Throwable $error) {
            $fallback = true;
        }
    }
    if ($fallback) extension_runtime_load();
    $html = '';
    $declared = [];
    foreach ($records as $record) {
        $id = (string)($record['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
        $plugin = extension_registry()['plugins'][$id] ?? null;
        $manifest = $manifestById[$id] ?? null;
        $paths = is_array($manifest) ? extension_manifest_assets($manifest) : null;
        if (is_array($paths) && ($manifest['schema'] ?? null) === 1 && ($manifest['id'] ?? '') === $id) {
            foreach ($paths as $path) $declared[$id.'|'.$path] = ['plugin'=>$id, 'path'=>$path];
        }
        if (is_array($plugin)) foreach ($plugin['manifest_assets'] ?? [] as $path) $declared[$id.'|'.$path] = ['plugin'=>$id, 'path'=>$path];
    }
    foreach (extension_registry()['assets'] as $asset) {
        if ($asset['type'] === 'css') $declared[$asset['plugin'].'|'.$asset['path']] = ['plugin'=>$asset['plugin'], 'path'=>$asset['path']];
    }
    foreach ($declared as $asset) {
        $file = extension_secure_runtime_file($root.$asset['plugin'], $asset['path']);
        if ($file === null) continue;
        $version = asset_version($file);
        $html .= '<link rel="stylesheet" href="/plugin-assets/'.rawurlencode($asset['plugin']).'/'.implode('/', array_map('rawurlencode', explode('/', $asset['path']))).'?v='.$version.'">';
    }
    return $html;
}
