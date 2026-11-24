<?php
declare(strict_types=1);
require_once __DIR__.'/extension-hooks.php';

const LITEBBS_EXTENSION_API_VERSION = '2.2';

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
    public function content(): PluginContentRegistrar { return new PluginContentRegistrar($this->id, $this->registry); }
    public function filters(): PluginFilterRegistrar { return new PluginFilterRegistrar($this->id, $this->registry); }
    public function database(): PluginDatabase { return new PluginDatabase($this->id); }
    public function logger(): PluginLogger { return new PluginLogger($this->id); }
    public function settings(): PluginSettings { return new PluginSettings($this->id, $this->registry); }
    public function cache(): PluginCache { return new PluginCache($this->id); }
    public function admin(): PluginAdminRegistrar { return new PluginAdminRegistrar($this->id, $this->registry); }
    public function forms(): PluginForms { return new PluginForms(); }
    public function tasks(): PluginTaskRegistrar { return new PluginTaskRegistrar($this->id, $this->registry); }
    public function metrics(): PluginMetrics { return new PluginMetrics($this->id); }
    public function uploads(): PluginUploads { return new PluginUploads($this->id); }
    public function notifications(): PluginNotifications { return new PluginNotifications($this->id); }
    public function search(): PluginSearchRegistrar { return new PluginSearchRegistrar($this->id, $this->registry); }
    public function userFields(): PluginUserFields { return new PluginUserFields($this->id, $this->registry); }
    public function config(): array { return $this->registry['plugins'][$this->id]['config'] ?? []; }
}

final class PluginRouteRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string|array $methods, string $pattern, callable $handler, array $options = []): void
    {
        $this->front($methods, $pattern, $handler, $options);
    }

    public function front(string|array $methods, string $pattern, callable $handler, array $options = []): void
    {
        $methods = array_map('strtoupper', (array)$methods);
        extension_validate_public_plugin_route($this->id, $methods, $pattern);
        $options = extension_route_options($methods, $options);
        $this->registry['routes'][] = ['plugin'=>$this->id, 'methods'=>$methods, 'pattern'=>$pattern, 'handler'=>$handler, 'options'=>$options];
    }
}

final class PluginSlotRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $slot, callable $renderer, int $priority = 0): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $slot)) throw new InvalidArgumentException('Invalid plugin slot.');
        if (!extension_hook_contract('slot', $slot)) throw new InvalidArgumentException('Unknown plugin slot.');
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
        if (!extension_hook_contract('event', $event)) throw new InvalidArgumentException('Unknown plugin event.');
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
        if (!extension_hook_contract('preload', $slot)) throw new InvalidArgumentException('Unknown plugin preload slot.');
        $this->registry['preloads'][$slot][] = ['plugin'=>$this->id, 'loader'=>$loader];
    }
}

final class PluginAssetRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function css(string $path, array $pages = ['all']): void
    {
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.css$~', $path)) throw new InvalidArgumentException('Invalid plugin CSS asset.');
        $this->registry['assets'][] = ['plugin'=>$this->id, 'path'=>$path, 'type'=>'css', 'pages'=>extension_asset_pages($pages)];
    }

    public function js(string $path, array $pages = ['all']): void
    {
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.js$~', $path)) throw new InvalidArgumentException('Invalid plugin JavaScript asset.');
        $this->registry['assets'][] = ['plugin'=>$this->id, 'path'=>$path, 'type'=>'js', 'pages'=>extension_asset_pages($pages)];
    }
}

final class PluginContentRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function markdown(callable $renderer, int $priority = 0): void
    {
        $this->registry['renderers']['post.markdown'][] = ['plugin'=>$this->id, 'priority'=>$priority, 'renderer'=>$renderer];
    }
}

final class PluginFilterRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $filter, callable $handler, int $priority = 0): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $filter)) throw new InvalidArgumentException('Invalid plugin filter.');
        if (!extension_hook_contract('filter', $filter)) throw new InvalidArgumentException('Unknown plugin filter.');
        $this->registry['filters'][$filter][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler];
    }
}

final class PluginSettings
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function get(): array { return $this->registry['plugins'][$this->id]['config'] ?? []; }

    public function save(array $config): void
    {
        if (!extension_config_value_valid($config)) throw new InvalidArgumentException('Plugin configuration must contain JSON-compatible scalar values, arrays, and objects only.');
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65535) throw new InvalidArgumentException('Plugin configuration exceeds 64 KiB.');
        h('UPDATE plugins SET config_json=? WHERE name=? AND enabled=1', [$json, $this->id]);
        if (!h('SELECT 1 FROM plugins WHERE name=? AND enabled=1', [$this->id])->fetchColumn()) throw new RuntimeException('Plugin configuration could not be saved.');
        $this->registry['plugins'][$this->id]['config'] = $config;
        audit('plugin.config.updated', 'plugin', null, ['name'=>$this->id]);
        extension_invalidate('plugins');
    }
}

final class PluginCache
{
    public function __construct(private string $id) {}

    public function remember(string $key, int $ttl, callable $loader): mixed
    {
        $baseKey = $this->key($key);
        $key = $baseKey.':'.cache_version($baseKey);
        $state =& request_cache_state();
        $existing = read_cache($key, max(1, min(86400, $ttl)));
        if ($existing !== null) {
            $state['@plugin-cache-hits:'.$this->id] = ((int)($state['@plugin-cache-hits:'.$this->id] ?? 0)) + 1;
            return $existing['value'] ?? null;
        }
        $state['@plugin-cache-misses:'.$this->id] = ((int)($state['@plugin-cache-misses:'.$this->id] ?? 0)) + 1;
        $value = cached($key, max(1, min(86400, $ttl)), static function () use ($loader): array {
            $loaded = $loader();
            if (!extension_config_value_valid($loaded)) throw new RuntimeException('Plugin cache values must be JSON-compatible.');
            return ['value'=>$loaded];
        });
        return $value['value'] ?? null;
    }

    public function forget(string $key): void { invalidate($this->key($key)); }

    private function key(string $key): string
    {
        if (!preg_match('/^[a-z][a-z0-9._:-]{0,95}$/', $key)) throw new InvalidArgumentException('Invalid plugin cache key.');
        return 'plugin:'.$this->id.':'.$key;
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
        if (str_contains($normalized, ';') || preg_match('~(?:--|/\*|\*/)~', $normalized) || !preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/i', $normalized) || preg_match('/\b(?:ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|RENAME|CALL|LOAD|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $normalized)) throw new RuntimeException('Plugin queries must be a single comment-free DML statement on plugin tables; use migrations for schema changes.');
        preg_match_all('/\b(?:FROM|JOIN|INTO)\s+(`?[a-zA-Z0-9_]+`?)/i', $normalized, $matches);
        $tables = array_map(static fn(string $table): string => trim($table, '`'), $matches[1] ?? []);
        if (preg_match('/^UPDATE\s+(`?[a-zA-Z0-9_]+`?)/i', $normalized, $update)) $tables[] = trim($update[1], '`');
        $tables = array_values(array_unique($tables));
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
    static $registry = ['plugins'=>[], 'routes'=>[], 'slots'=>[], 'events'=>[], 'assets'=>[], 'preloads'=>[], 'renderers'=>[], 'filters'=>[], 'admin_pages'=>[], 'tasks'=>[], 'search'=>[], 'user_fields'=>[]];
    return $registry;
}

function extension_config_decode(string $json): array
{
    $config = json_decode($json, true);
    return is_array($config) ? $config : [];
}

function extension_manifest_hooks(array $manifest, string $kind): ?array
{
    $key = match($kind) { 'slot' => 'slots', 'preload'=>'preloads', 'event' => 'events', 'renderer' => 'renderers', 'filter'=>'filters', default => throw new InvalidArgumentException('Invalid plugin hook kind.') };
    if (!isset($manifest['hooks']) || !is_array($manifest['hooks']) || !array_key_exists($key, $manifest['hooks'])) return null;
    $hooks = $manifest['hooks'][$key];
    if (!is_array($hooks)) throw new RuntimeException('Plugin '.$key.' declaration must be an array.');
    foreach ($hooks as $hook) {
        if (!is_string($hook) || !preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $hook)) throw new RuntimeException('Invalid plugin '.$key.' declaration.');
        if (!extension_hook_contract($kind, $hook)) throw new RuntimeException('Unknown plugin '.$key.' declaration: '.$hook);
    }
    return array_values(array_unique($hooks));
}

function extension_manifest_assets(array $manifest): ?array
{
    if (!array_key_exists('assets', $manifest)) return null;
    if (!is_array($manifest['assets'])) throw new RuntimeException('Plugin assets declaration must be an array.');
    $assets = [];
    foreach ($manifest['assets'] as $asset) {
        if (is_string($asset)) { $path = $asset; $pages = ['all']; }
        elseif (is_array($asset)) { $path = (string)($asset['path'] ?? ''); $pages = $asset['pages'] ?? ['all']; }
        else throw new RuntimeException('Invalid plugin asset declaration.');
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.(css|js)$~', $path)) throw new RuntimeException('Invalid plugin asset declaration.');
        $assets[] = ['path'=>$path, 'type'=>strtolower((string)pathinfo($path, PATHINFO_EXTENSION)), 'pages'=>extension_asset_pages(is_array($pages) ? $pages : [])];
    }
    $unique = [];
    foreach ($assets as $asset) $unique[$asset['path'].'|'.implode(',', $asset['pages'])] = $asset;
    return array_values($unique);
}

function extension_asset_pages(array $pages): array
{
    $allowed = ['all','home','category','topic.view','topic.create','post.edit','profile','member','search','plugin','admin'];
    if (!$pages || count($pages) > 16) throw new InvalidArgumentException('Invalid plugin asset page declaration.');
    foreach ($pages as $page) if (!is_string($page) || !in_array($page, $allowed, true)) throw new InvalidArgumentException('Invalid plugin asset page declaration.');
    return array_values(array_unique($pages));
}

function extension_asset_matches(array $asset, string $path): bool
{
    $surfaces = ['all'];
    if ($path === '/') $surfaces[] = 'home';
    elseif ($path === '/new') $surfaces[] = 'topic.create';
    elseif (preg_match('~^/t/[0-9]+$~', $path)) $surfaces[] = 'topic.view';
    elseif (preg_match('~^/p/[0-9]+/edit$~', $path)) $surfaces[] = 'post.edit';
    elseif (preg_match('~^/c/[0-9]+$~', $path)) $surfaces[] = 'category';
    elseif (str_starts_with($path, '/u/')) $surfaces[] = 'profile';
    elseif ($path === '/me') $surfaces[] = 'member';
    elseif ($path === '/search') $surfaces[] = 'search';
    elseif (str_starts_with($path, '/plugin/')) $surfaces[] = 'plugin';
    elseif ($path === '/admin' || str_starts_with($path, '/admin/')) $surfaces[] = 'admin';
    return (bool)array_intersect($asset['pages'] ?? ['all'], $surfaces);
}

function extension_declared_assets(?string $onlyPlugin = null): array
{
    static $assets = [];
    $version = cache_version('plugins');
    $key = $version.':'.($onlyPlugin ?? '*');
    if (!isset($assets[$key])) {
        $declared = [];
        $fallback = false;
        foreach (extension_enabled_plugins() as $record) {
            $id = (string)($record['name'] ?? '');
            if ($onlyPlugin !== null && $id !== $onlyPlugin) continue;
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
            try {
                $manifest = extension_plugin_manifest($id);
                if ($manifest === null) { $fallback = true; continue; }
                if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $id) { $fallback = true; continue; }
                $paths = extension_manifest_assets($manifest);
                if ($paths === null) { $fallback = true; continue; }
                foreach ($paths as $asset) $declared[$id.'|'.$asset['path']] = ['plugin'=>$id] + $asset;
            } catch (Throwable $error) {
                $fallback = true;
                error_log('extension assets '.$id.' failed: '.$error->getMessage());
            }
        }
        $assets[$key] = ['declared'=>$declared, 'fallback'=>$fallback];
    }
    if ($assets[$key]['fallback']) extension_runtime_load($onlyPlugin);
    $declared = $assets[$key]['declared'];
    foreach (extension_registry()['assets'] as $asset) {
        if ($onlyPlugin !== null && $asset['plugin'] !== $onlyPlugin) continue;
        $declared[$asset['plugin'].'|'.$asset['path']] = $asset;
    }
    return array_values($declared);
}

function extension_config_value_valid(mixed $value, int $depth = 0): bool
{
    if ($depth > 12) return false;
    if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) return true;
    if (!is_array($value)) return false;
    foreach ($value as $key => $item) if ((!is_int($key) && (!is_string($key) || strlen($key) > 128)) || !extension_config_value_valid($item, $depth + 1)) return false;
    return true;
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
    static $indexes = [];
    $scanKey = cache_version('plugins').':'.$kind;
    if (isset($indexes[$scanKey])) return array_merge($indexes[$scanKey]['fallback'], $indexes[$scanKey]['hooks'][$hook] ?? []);
    // Cache only manifest metadata. This prevents repeated directory and JSON
    // scans whenever one page invokes several slots or events.
    $index = cached('extensions:hook-index:v2:'.$scanKey, 300, static function () use ($enabled, $kind): array {
        $root = dirname(__DIR__).'/plugins/';
        $built = ['fallback'=>[], 'hooks'=>[]];
        foreach ($enabled as $record) {
            $id = (string)($record['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
            $manifestFile = $root.$id.'/plugin.json';
            if (!is_file($manifestFile)) {
                $built['fallback'][] = $record;
                continue;
            }
            try {
                $manifest = extension_plugin_manifest($id);
                if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
                $hooks = extension_manifest_hooks($manifest, $kind);
                if ($hooks === null) $built['fallback'][] = $record;
                else foreach ($hooks as $declaredHook) $built['hooks'][$declaredHook][] = $record;
            } catch (Throwable $error) {
                error_log('extension manifest '.$id.' failed: '.$error->getMessage());
            }
        }
        return $built;
    });
    $indexes[$scanKey] = $index;
    return array_merge($index['fallback'], $index['hooks'][$hook] ?? []);
}

function extension_validate_public_plugin_route(string $id, array $methods, string $pattern): void
{
    if (!$methods || array_diff($methods, ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS']) || count($methods) !== count(array_unique($methods))) throw new InvalidArgumentException('Invalid plugin route methods.');
    if (str_contains($pattern, "\0") || preg_match('/[\r\n]/', $pattern) || !preg_match('~^(.)(.*)\\1([a-z]*)$~sD', $pattern, $match)) throw new InvalidArgumentException('Invalid plugin route pattern.');
    $manifest = extension_plugin_manifest($id);
    $prefix = is_array($manifest) ? extension_manifest_public_route_prefix($manifest) : null;
    if ($prefix === null) throw new InvalidArgumentException('Plugin must declare a public_route_prefix before registering front-end routes.');
    $body = $match[2];
    if (!preg_match('~^\^/'.preg_quote($prefix, '~').'(?:/|\$)~', $body) || !str_ends_with($body, '$') || str_contains($body, '|') || @preg_match($pattern, '') === false) throw new InvalidArgumentException('Plugin routes must be exact routes within their declared public prefix.');
}

function extension_public_route_plugin(string $path): ?string
{
    if (!preg_match('~^/([a-z][a-z0-9-]{1,63})(?:/|$)~', $path, $match)) return null;
    $prefix = $match[1];
    // These built-in namespaces can never be valid V2 plugin prefixes. Avoid
    // loading the enabled-plugin registry on every normal forum request.
    if (in_array($prefix, ['admin','plugin','plugin-assets','assets','uploads','install','login','logout','register','me','new','search','market','robots','sitemap','t','c','u','p','avatar','messages','topic','post','report','user'], true)) return null;
    static $maps = [];
    $version = cache_version('plugins');
    if (!isset($maps[$version])) {
        $maps[$version] = cached('extensions:public-route-prefixes:v1:'.$version, 300, static function (): array {
            $map = [];
            foreach (extension_enabled_plugins() as $record) {
                $id = (string)($record['name'] ?? '');
                if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
                try {
                    $manifest = extension_plugin_manifest($id);
                    $declared = is_array($manifest) ? extension_manifest_public_route_prefix($manifest) : null;
                    if ($declared === null) continue;
                    if (isset($map[$declared])) {
                        error_log('duplicate public plugin route prefix: '.$declared);
                        $map[$declared] = '';
                    } else $map[$declared] = $id;
                } catch (Throwable $error) {
                    error_log('extension manifest '.$id.' failed: '.$error->getMessage());
                }
            }
            return $map;
        });
    }
    $owner = (string)($maps[$version][$prefix] ?? '');
    return $owner !== '' ? $owner : null;
}

function extension_plugin_asset_response(string $plugin, string $asset): \LiteBBS\Framework\Response
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $plugin) || $asset === '' || str_contains($asset, "\0")) return new \LiteBBS\Framework\Response('Not Found', 404);
    $enabled = false;
    foreach (extension_enabled_plugins() as $record) {
        if (($record['name'] ?? '') === $plugin) { $enabled = true; break; }
    }
    if (!$enabled) return new \LiteBBS\Framework\Response('Not Found', 404);

    $manifest = extension_plugin_manifest($plugin);
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $plugin) return new \LiteBBS\Framework\Response('Not Found', 404);
    $assets = extension_manifest_assets($manifest);
    $type = null;
    foreach ($assets ?? [] as $entry) {
        if (($entry['path'] ?? '') === $asset && in_array($entry['type'] ?? '', ['css', 'js'], true)) { $type = $entry['type']; break; }
    }
    if ($type === null) return new \LiteBBS\Framework\Response('Not Found', 404);

    $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$plugin, $asset);
    if ($file === null) return new \LiteBBS\Framework\Response('Not Found', 404);
    $etag = '"'.asset_version($file).'"';
    $cacheControl = isset($_GET['v']) && is_string($_GET['v']) && hash_equals(trim($etag, '"'), $_GET['v'])
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=3600';
    if (hash_equals($etag, (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))) {
        return (new \LiteBBS\Framework\Response('', 304))
            ->header('ETag', $etag)
            ->header('Cache-Control', $cacheControl);
    }
    $body = file_get_contents($file);
    if (!is_string($body)) return new \LiteBBS\Framework\Response('Not Found', 404);
    return (new \LiteBBS\Framework\Response($body))
        ->header('Content-Type', $type === 'js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8')
        ->header('ETag', $etag)
        ->header('Cache-Control', $cacheControl)
        ->header('X-Content-Type-Options', 'nosniff');
}

function extension_route_options(array $methods, array $options): array
{
    $unknown = array_diff(array_keys($options), ['auth', 'csrf']);
    if ($unknown) throw new InvalidArgumentException('Unknown plugin route option.');
    $auth = (string)($options['auth'] ?? 'public');
    if (!in_array($auth, ['public', 'user', 'moderator', 'admin'], true)) throw new InvalidArgumentException('Invalid plugin route authorization.');
    $unsafeMethods = array_diff($methods, ['GET', 'HEAD', 'OPTIONS']);
    if ($unsafeMethods && $methods !== ['POST']) throw new InvalidArgumentException('Plugin state-changing routes must be POST-only.');
    $csrf = $unsafeMethods ? true : (bool)($options['csrf'] ?? false);
    if ($csrf && $methods !== ['POST']) throw new InvalidArgumentException('Plugin CSRF enforcement requires a POST-only route.');
    return ['auth'=>$auth, 'csrf'=>$csrf];
}

function extension_authorize_route(array $options): void
{
    $auth = (string)($options['auth'] ?? 'public');
    if ($auth === 'admin') {
        admin();
    } elseif ($auth !== 'public') {
        $user = current_user();
        if (!$user) redirect('/login');
        if ($auth === 'moderator' && !in_array((string)($user['role'] ?? ''), ['moderator', 'admin'], true)) fail(403, '无权访问');
    }
    if (!empty($options['csrf'])) require_post();
}

function extension_runtime_load(?string $onlyPlugin = null, ?string $hookKind = null, ?string $hook = null): array
{
    static $loaded = [];
    $registry =& extension_registry();
    $enabled = $hookKind === null ? extension_enabled_plugins() : extension_candidate_plugins($hookKind, $hook);
    if ($enabled) require_once __DIR__.'/extension-platform.php';
    $root = dirname(__DIR__).'/plugins/';
    $registryChanged = false;
    foreach ($enabled as $record) {
        $id = (string)($record['name'] ?? '');
        if ($onlyPlugin !== null && $id !== $onlyPlugin) continue;
        if (isset($loaded[$id])) continue;
        $loaded[$id] = true;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
        try {
            $manifest = extension_plugin_manifest($id);
            if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
            $entry = (string)($manifest['entry'] ?? '');
            $class = (string)($manifest['class'] ?? '');
            $entryFile = extension_v2_entry_file($root.$id, $entry);
            if (($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $id || $entryFile === null || !extension_class_name_valid($class)) throw new RuntimeException('Invalid plugin manifest.');
            $permissions = $manifest['permissions'] ?? [];
            if (!is_array($permissions) || array_diff($permissions, ['database','storage','routes','views','assets'])) throw new RuntimeException('Invalid plugin permissions.');
            extension_manifest_hooks($manifest, 'slot');
            extension_manifest_hooks($manifest, 'preload');
            extension_manifest_hooks($manifest, 'event');
            extension_manifest_hooks($manifest, 'renderer');
            extension_manifest_hooks($manifest, 'filter');
            extension_manifest_assets($manifest);
            extension_manifest_admin_path($manifest, $id);
            extension_manifest_public_route_prefix($manifest);
            extension_manifest_relationships($manifest);
            require_once $entryFile;
            $plugin = new $class();
            if (!$plugin instanceof LiteBBSPlugin) throw new RuntimeException('Plugin entry must implement LiteBBSPlugin.');
            $config = extension_config_decode((string)($record['config_json'] ?? '{}'));
            $temporary = ['plugins'=>[$id=>['config'=>$config]], 'routes'=>[], 'slots'=>[], 'events'=>[], 'assets'=>[], 'preloads'=>[], 'renderers'=>[], 'filters'=>[], 'admin_pages'=>[], 'tasks'=>[], 'search'=>[], 'user_fields'=>[]];
            $context = new PluginContext($id, $temporary);
            $outputLevel = ob_get_level();
            ob_start();
            try { $plugin->boot($context); $bootOutput = (string)ob_get_clean(); }
            catch (Throwable $error) { while (ob_get_level() > $outputLevel) ob_end_clean(); throw $error; }
            if ($bootOutput !== '') throw new RuntimeException('Plugin boot must not produce output.');
            foreach (['slot'=>'slots','preload'=>'preloads','event'=>'events','renderer'=>'renderers','filter'=>'filters'] as $kind => $key) {
                $declared = extension_manifest_hooks($manifest, $kind);
                if ($declared === null) continue;
                $registered = array_keys($temporary[$key]);
                if (array_diff($registered, $declared)) throw new RuntimeException('Plugin '.$key.' registration is missing from the manifest declaration.');
            }
            foreach (['routes','slots','events','assets','preloads','renderers','filters','admin_pages','tasks','search','user_fields'] as $key) {
                if (in_array($key, ['slots','events','preloads','renderers','filters'], true)) foreach ($temporary[$key] as $slot => $entries) $registry[$key][$slot] = array_merge($registry[$key][$slot] ?? [], $entries);
                else $registry[$key] = array_merge($registry[$key], $temporary[$key]);
            }
            $registry['plugins'][$id] = ['manifest'=>$manifest, 'plugin'=>$plugin, 'config'=>$config];
            $registryChanged = true;
        } catch (Throwable $error) {
            error_log('extension plugin '.$id.' disabled: '.$error->getMessage());
        }
    }
    if ($registryChanged) {
        foreach (['slots','renderers','filters'] as $kind) {
            foreach ($registry[$kind] as &$entries) {
                if (count($entries) > 1) usort($entries, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
            }
            unset($entries);
        }
    }
    return $registry['plugins'];
}

function extension_runtime_register_routes(\LiteBBS\Framework\Router $router, ?string $onlyPlugin = null): void
{
    extension_runtime_load($onlyPlugin);
    foreach (extension_registry()['routes'] as $route) {
        if ($onlyPlugin !== null && $route['plugin'] !== $onlyPlugin) continue;
        $router->add($route['methods'], $route['pattern'], static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params) use ($route): \LiteBBS\Framework\Response {
            try {
                extension_authorize_route($route['options'] ?? []);
                $outputLevel = ob_get_level();
                ob_start();
                try { $result = ($route['handler'])($request, $params); $output = (string)ob_get_clean(); }
                catch (Throwable $error) { while (ob_get_level() > $outputLevel) ob_end_clean(); throw $error; }
                if ($result instanceof \LiteBBS\Framework\Response) {
                    if ($output !== '') $result->body = $output.$result->body;
                    return $result;
                }
                return new \LiteBBS\Framework\Response($output.(is_string($result) ? $result : ''));
            } catch (Throwable $error) {
                if ($error instanceof \LiteBBS\Framework\ResponseHalt) return $error->response;
                error_log('plugin route '.$route['plugin'].' failed: '.$error->getMessage());
                return new \LiteBBS\Framework\Response('Plugin temporarily unavailable.', 503);
            }
        });
    }
    foreach (extension_registry()['admin_pages'] as $adminPage) {
        if ($onlyPlugin !== null && $adminPage['plugin'] !== $onlyPlugin) continue;
        if (!empty($adminPage['plugin_management'])) continue;
        $router->add(['GET','POST'], '~^'.preg_quote($adminPage['path'], '~').'$~', static function (\LiteBBS\Framework\Request $request) use ($adminPage): \LiteBBS\Framework\Response {
            try {
                $user = current_user();
                if (!$user) redirect('/login');
                if ($adminPage['permission'] === 'admin' && ($user['role'] ?? '') !== 'admin') fail(403, '无权访问');
                if ($adminPage['permission'] === 'moderator' && !in_array((string)($user['role'] ?? ''), ['moderator','admin'], true)) fail(403, '无权访问');
                if ($request->method === 'POST') require_post();
                $result = ($adminPage['handler'])($request);
                if (!is_array($result) || !isset($result['body'])) throw new RuntimeException('Admin page handler must return a body array.');
                if ($request->method === 'POST' && $adminPage['audit'] !== '') audit($adminPage['audit'], 'plugin', null, ['name'=>$adminPage['plugin']]);
                ob_start();
                page((string)($result['title'] ?? $adminPage['title']), (string)$result['body'], '', false, true);
                return new \LiteBBS\Framework\Response((string)ob_get_clean());
            } catch (\LiteBBS\Framework\ResponseHalt $halt) { return $halt->response; }
            catch (Throwable $error) { error_log('plugin admin '.$adminPage['plugin'].' failed: '.$error->getMessage()); return new \LiteBBS\Framework\Response('Plugin temporarily unavailable.', 503); }
        });
    }
}

function extension_plugin_management_path(string $id, ?string $declaredPath = null): ?string
{
    $page = extension_plugin_management_page($id, null, $declaredPath);
    if ($page === null) return null;
    return '/admin/plugins?plugin='.rawurlencode($id).'&page='.rawurlencode((string)$page['management_page']);
}

function extension_plugin_management_page(string $id, ?string $pageName = null, ?string $declaredPath = null): ?array
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) return null;
    if ($pageName !== null && !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $pageName)) return null;
    extension_runtime_load($id);
    $fallback = null;
    foreach (extension_registry()['admin_pages'] as $page) {
        if (($page['plugin'] ?? '') !== $id || empty($page['plugin_management'])) continue;
        if ($declaredPath !== null && (($page['management_page'] ?? '') === $declaredPath || ($page['path'] ?? '') === $declaredPath)) return $page;
        if ($pageName !== null && ($page['management_page'] ?? '') === $pageName) return $page;
        if (!empty($page['plugin_management_default'])) $fallback = $page;
        elseif ($fallback === null) $fallback = $page;
    }
    if ($declaredPath !== null) throw new RuntimeException('Plugin admin_path is not registered through the management page API.');
    return $pageName === null ? $fallback : null;
}

function extension_slot(string $slot, array $view = []): string
{
    $registry =& extension_registry();
    extension_runtime_load(null, 'slot', $slot);
    $entries = $registry['slots'][$slot] ?? [];
    $html = '';
    foreach ($entries as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        $beforeSql=(int)(request_cache_state()['@sql-count']??0);
        try {
            $output = ($entry['renderer'])($view);
            if (!is_string($output)) throw new RuntimeException('Plugin slot must return HTML text.');
            if (strlen($output) > 1048576 || strlen($html) + strlen($output) > 1048576) throw new RuntimeException('Plugin slot output exceeds 1 MiB.');
            $html .= $output;
            if ((microtime(true) - $started) > 0.02) { error_log('plugin slot '.$entry['plugin'].' exceeded 20ms budget'); extension_plugin_failure($entry['plugin']); }
            extension_metric_observe($entry['plugin'],'slot.'.$slot,(microtime(true)-$started)*1000,(int)(request_cache_state()['@sql-count']??0)-$beforeSql,false);
        } catch (Throwable $error) {
            extension_metric_observe($entry['plugin'],'slot.'.$slot,(microtime(true)-$started)*1000,(int)(request_cache_state()['@sql-count']??0)-$beforeSql,true);
            error_log('plugin slot '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return $html;
}

function extension_preload(string $slot, array $view = []): void
{
    extension_runtime_load(null, 'preload', $slot);
    foreach (extension_registry()['preloads'][$slot] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started=microtime(true);
        try { ($entry['loader'])($view); if((microtime(true)-$started)>0.1){error_log('plugin preload '.$entry['plugin'].' exceeded 100ms budget');extension_plugin_failure($entry['plugin']);} }
        catch (Throwable $error) { error_log('plugin preload '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); }
    }
}

function extension_event(string $event, array $payload): void
{
    extension_validate_hook_payload($event,$payload);
    extension_runtime_load(null, 'event', $event);
    foreach (extension_registry()['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try { ($entry['listener'])($payload); }
        catch (Throwable $error) { error_log('plugin event '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); }
    }
}

// The core write paths call this inside their transaction. Listener errors must escape.
function extension_event_or_fail(string $event, array $payload): void
{
    extension_validate_hook_payload($event,$payload);
    $registry =& extension_registry();
    extension_runtime_load(null, 'event', $event);
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
    extension_validate_hook_payload($event,$payload);
    $contract=extension_hook_contract('event',$event);static $deprecated=[];if(!empty($contract['sensitive'])&&!isset($deprecated[$event])){$deprecated[$event]=true;error_log('deprecated sensitive extension event dispatched: '.$event);}
    extension_runtime_load(null, 'event', $event);
    foreach (extension_registry()['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started=microtime(true);
        try { ($entry['listener'])($payload); if((microtime(true)-$started)>0.1){error_log('plugin sync event '.$entry['plugin'].' exceeded 100ms budget');extension_plugin_failure($entry['plugin']);} }
        catch (\LiteBBS\Framework\ResponseHalt $error) { extension_plugin_failure($entry['plugin']); throw $error; }
        catch (Throwable $error) { error_log('plugin sync event '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $error); }
    }
}

function extension_event_async(string $event, array $payload, int $delaySeconds = 0): void
{
    try {
        extension_enqueue_async_event($event, $payload, $delaySeconds);
    } catch (Throwable $error) {
        error_log('plugin async event '.$event.' enqueue failed: '.$error->getMessage());
    }
}

// Use this inside a write transaction when enqueue errors must roll back the write.
function extension_enqueue_async_event(string $event, array $payload, int $delaySeconds = 0): void
{
    extension_validate_async_event_payload($event,$payload);
    if (!function_exists('enqueue_outbox_event')) require_once __DIR__.'/outbox.php';
    if (!extension_config_value_valid($payload)) throw new InvalidArgumentException('Async plugin event payload must be JSON-compatible.');
    if (!extension_has_listeners('event', $event)) return;
    enqueue_outbox_event($event, $payload, max(0, min(86400, $delaySeconds)));
}

function extension_has_listeners(string $kind, string $hook): bool
{
    static $known = [];
    $key = cache_version('plugins').':'.$kind.':'.$hook;
    if (array_key_exists($key, $known)) return $known[$key];
    return $known[$key] = (bool)extension_candidate_plugins($kind, $hook);
}

function extension_render_post_body(string $markdown, array $context = []): string
{
    $registry =& extension_registry();
    extension_runtime_load(null, 'renderer', 'post.markdown');
    foreach ($registry['renderers']['post.markdown'] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        try {
            $html = ($entry['renderer'])($markdown, $context);
            if (!is_string($html) || strlen($html) > 1048576) throw new RuntimeException('Post renderer returned invalid output.');
            if ((microtime(true) - $started) > 0.1) {
                error_log('plugin renderer '.$entry['plugin'].' exceeded 100ms budget');
                extension_plugin_failure($entry['plugin']);
            }
            return extension_filter('post.rendered', $html, $context + ['markdown'=>$markdown]);
        } catch (Throwable $error) {
            error_log('plugin renderer '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return extension_filter('post.rendered', render_md($markdown), $context + ['markdown'=>$markdown]);
}

function extension_filter(string $filter, string $value, array $context = []): string
{
    extension_runtime_load(null, 'filter', $filter);
    foreach (extension_registry()['filters'][$filter] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        try {
            $filtered = ($entry['handler'])($value, $context);
            if (!is_string($filtered) || strlen($filtered) > 1048576) throw new RuntimeException('Plugin filter returned invalid output.');
            $value = $filtered;
            if ((microtime(true) - $started) > 0.1) { error_log('plugin filter '.$entry['plugin'].' exceeded 100ms budget'); extension_plugin_failure($entry['plugin']); }
        } catch (Throwable $error) {
            error_log('plugin filter '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return $value;
}

function extension_invalidate(string $key): void
{
    invalidate($key);
    invalidate('extension-read-pages');
}

function extension_plugin_degraded(string $id, bool $fresh = false): bool
{
    static $requestState = [];
    if (!$fresh && array_key_exists($id, $requestState)) return $requestState[$id];
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

function extension_stylesheets(string $requestPath, ?string $onlyPlugin = null): string
{
    $root = dirname(__DIR__).'/plugins/';
    $html = '';
    foreach (extension_declared_assets($onlyPlugin) as $asset) {
        if ($asset['type'] !== 'css' || !extension_asset_matches($asset, $requestPath)) continue;
        $file = extension_secure_runtime_file($root.$asset['plugin'], $asset['path']);
        if ($file === null) continue;
        $version = asset_version($file);
        $html .= '<link rel="stylesheet" href="/plugin-assets/'.rawurlencode($asset['plugin']).'/'.implode('/', array_map('rawurlencode', explode('/', $asset['path']))).'?v='.$version.'">';
    }
    return $html;
}

function extension_javascripts(string $requestPath, ?string $onlyPlugin = null): string
{
    $root = dirname(__DIR__).'/plugins/';
    $html = '';
    foreach (extension_declared_assets($onlyPlugin) as $asset) {
        if ($asset['type'] !== 'js' || !extension_asset_matches($asset, $requestPath)) continue;
        $file = extension_secure_runtime_file($root.$asset['plugin'], $asset['path']);
        if ($file === null) continue;
        $version = asset_version($file);
        $html .= '<script src="/plugin-assets/'.rawurlencode($asset['plugin']).'/'.implode('/', array_map('rawurlencode', explode('/', $asset['path']))).'?v='.$version.'" defer></script>';
    }
    return $html;
}
