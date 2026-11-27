<?php
declare(strict_types=1);

/**
 * Versioned, single-file template discovery and rendering runtime.
 */

const LITEBBS_THEME_VIEW_API_VERSION = 4;

function theme_pagination_manifest(string $theme): array
{
    return theme_effective_package($theme)['manifest'] ?? [];
}

function theme_page_size(string $theme, string $context): int
{
    $defaults = ['home'=>30, 'category'=>30, 'topic'=>20];
    if (!isset($defaults[$context])) throw new InvalidArgumentException('Invalid pagination context.');
    return theme_pagination_manifest($theme)['pagination'][$context] ?? $defaults[$context];
}

function theme_manifest_settings(array $manifest): array
{
    $definitions = $manifest['settings'] ?? [];
    if (!is_array($definitions) || count($definitions) > 32) throw new RuntimeException('Theme settings declaration is invalid.');
    $validated = [];
    foreach ($definitions as $key=>$definition) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || !is_array($definition)) throw new RuntimeException('Theme setting name is invalid.');
        $type = $definition['type'] ?? '';
        $label = $definition['label'] ?? '';
        if (!in_array($type, ['text','textarea','select','checkbox','color'], true) || !is_string($label) || $label === '' || text_length($label) > 80) throw new RuntimeException('Theme setting definition is invalid.');
        $item = ['type'=>$type,'label'=>$label,'default'=>$definition['default'] ?? ($type === 'checkbox' ? false : '')];
        if (in_array($type, ['text','textarea'], true)) {
            $item['max'] = max(1, min(2000, (int)($definition['max'] ?? ($type === 'textarea' ? 500 : 120))));
            if (!is_string($item['default']) || text_length($item['default']) > $item['max']) throw new RuntimeException('Theme setting default is invalid.');
        } elseif ($type === 'color') {
            if (!is_string($item['default']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $item['default'])) throw new RuntimeException('Theme color default is invalid.');
        } elseif ($type === 'checkbox') {
            if (!is_bool($item['default'])) throw new RuntimeException('Theme checkbox default is invalid.');
        } else {
            $options = $definition['options'] ?? [];
            if (!is_array($options) || !$options || count($options) > 32 || !is_string($item['default'])) throw new RuntimeException('Theme select setting is invalid.');
            $item['options'] = [];
            foreach ($options as $value=>$optionLabel) {
                if (!is_string($value) || !preg_match('/^[a-z0-9_-]{1,32}$/', $value) || !is_string($optionLabel) || $optionLabel === '' || text_length($optionLabel) > 80) throw new RuntimeException('Theme select option is invalid.');
                $item['options'][$value] = $optionLabel;
            }
            if (!array_key_exists($item['default'], $item['options'])) throw new RuntimeException('Theme select default is invalid.');
        }
        $validated[$key] = $item;
    }
    return $validated;
}

function theme_settings_from_input(array $input, array $definitions): array
{
    $values = [];
    foreach ($definitions as $key=>$definition) {
        $value = $input['theme_setting_'.$key] ?? null;
        if ($definition['type'] === 'checkbox') { $values[$key] = $value === '1'; continue; }
        if (!is_string($value)) throw new InvalidArgumentException('Theme setting input is invalid.');
        $value = trim($value);
        if (in_array($definition['type'], ['text','textarea'], true) && text_length($value) <= $definition['max']) $values[$key] = $value;
        elseif ($definition['type'] === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) $values[$key] = $value;
        elseif ($definition['type'] === 'select' && isset($definition['options'][$value])) $values[$key] = $value;
        else throw new InvalidArgumentException('Theme setting value is invalid.');
    }
    return $values;
}

function theme_settings(string $theme): array
{
    $definitions = theme_runtime_package($theme)['settings'] ?? [];
    if (!$definitions) return [];
    $version = cache_version('theme-settings:'.$theme);
    $cached = cached('theme-settings:v1:'.$theme.':'.$version, 300, static function () use ($theme): array {
        $json = h('SELECT config_json FROM themes WHERE name=?', [$theme])->fetchColumn();
        $values = is_string($json) ? json_decode($json, true) : [];
        return [is_array($values) ? $values : []];
    });
    $stored = is_array($cached[0] ?? null) ? $cached[0] : [];
    $values = [];
    foreach ($definitions as $key=>$definition) {
        $value = $stored[$key] ?? $definition['default'];
        $valid = match($definition['type']) {
            'text','textarea' => is_string($value) && text_length($value) <= $definition['max'],
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value),
            'checkbox' => is_bool($value),
            'select' => is_string($value) && isset($definition['options'][$value]),
        };
        $values[$key] = $valid ? $value : $definition['default'];
    }
    return $values;
}

final class ThemeTrustedHtml
{
    private function __construct(private string $html) {}
    public static function fromCore(string $html): self { return new self($html); }
    public function __toString(): string { return $this->html; }
}

function theme_view_contracts(): array
{
    return [
        'home'=>['topics'=>'array','viewer'=>'nullable_array','pagination'=>'html'],
        'category'=>['category'=>'array','topics'=>'array','viewer'=>'nullable_array','pagination'=>'html'],
        'topic'=>['topic'=>'array','posts'=>'array','viewer'=>'nullable_array','content'=>'html'],
        'pagination'=>['pagination'=>'array'],
        'account.login'=>['viewer'=>'nullable_array','content'=>'html'],
        'account.register'=>['viewer'=>'nullable_array','content'=>'html'],
        'profile'=>['profile'=>'array','viewer'=>'nullable_array','tabs'=>'array','active_tab'=>'nullable_array','posts'=>'array','content'=>'html'],
        'search'=>['viewer'=>'nullable_array','search'=>'array','results'=>'array','content'=>'html'],
        'member'=>['viewer'=>'array','tabs'=>'array','active_tab'=>'array','overview'=>'array','content'=>'html'],
        'messages'=>['viewer'=>'array','mode'=>'string','conversation'=>'nullable_array','conversations'=>'array','messages'=>'array','content'=>'html'],
    ];
}

/** Renders validated, theme-local partials without exposing filesystem paths. */
final class ThemePartials
{
    private int $depth = 0;

    public function __construct(private ?array $package) {}

    public function render(string $name, array $variables = []): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*$/D', $name) || strlen($name) > 64) {
            throw new InvalidArgumentException('Invalid theme partial.');
        }
        if ($this->package === null) return '';
        $relative = 'partials/'.str_replace('.', '/', $name).'.php';
        if (!isset($this->package['php'][$relative])) return '';
        $file = extension_secure_runtime_file($this->package['directory'], $relative);
        if ($file === null || !hash_equals($this->package['php'][$relative], (string)@hash_file('sha256', $file))) return '';
        if ($this->depth >= 16) throw new RuntimeException('Theme partial nesting limit exceeded.');
        $variables['partials'] = $this;
        ++$this->depth;
        try {
            return theme_include_view($file, $variables);
        } finally {
            --$this->depth;
        }
    }
}

function theme_view_variables(string $view, array $variables, ?array $package): array
{
    $contract=theme_view_contracts()[$view]??null;
    if(!is_array($contract))throw new InvalidArgumentException('Unknown standard theme view.');
    if(array_diff(array_keys($variables),array_keys($contract))||array_diff(array_keys($contract),array_keys($variables)))throw new InvalidArgumentException('Invalid theme variables for '.$view.'.');
    $theme = $package['name'] ?? 'default';
    $safe=['theme_api_version'=>LITEBBS_THEME_VIEW_API_VERSION, 'theme'=>$theme, 'safe'=>$theme, 'settings'=>$package === null ? [] : theme_settings($theme), 'partials'=>new ThemePartials($package)];
    foreach($contract as $name=>$type){$value=$variables[$name];$valid=match($type){'array'=>is_array($value),'nullable_array'=>$value===null||is_array($value),'string'=>is_string($value),'html'=>$value instanceof ThemeTrustedHtml,default=>false};if(!$valid)throw new InvalidArgumentException('Invalid theme variable '.$view.'.'.$name.'.');$safe[$name]=$value;}
    return $safe;
}

function theme_value(mixed $value): string
{
    return $value instanceof ThemeTrustedHtml ? (string)$value : e(is_scalar($value)?(string)$value:'');
}

function theme_render_standard(string $view,array $variables,string|callable $fallback): string
{
    if (extension_candidate_plugins('view', $view)) {
        $data = $variables;
        if (isset($data['viewer'])) $data['viewer'] = plugin_viewer_summary($data['viewer']);
        $html = extension_view_html($view, $data);
        if ($html !== null) return $html;
    }
    $package = theme_effective_package((string)(load_config()['theme'] ?? 'default'));
    $variables = theme_view_variables($view, $variables, $package);
    $file = theme_package_view($package, $view);
    if ($file !== null) return theme_include_view($file, $variables);
    if (is_callable($fallback)) return $fallback($variables);
    return theme_include_view(theme_core_view($fallback), $variables);
}

/**
 * Supplies layouts with the same versioned theme metadata as standard views
 * while retaining the core layout variables used by existing packages.
 */
function theme_layout_variables(array $variables, ?array $package, array $slots, string $assets, string $styles, string $scripts): array
{
    $theme = $package['name'] ?? 'default';
    $variables['theme_api_version'] = LITEBBS_THEME_VIEW_API_VERSION;
    $variables['theme'] = $theme;
    $variables['settings'] = $package === null ? [] : theme_settings($theme);
    $variables['theme_slots'] = $slots;
    $variables['theme_assets'] = $assets;
    $variables['plugin_stylesheets'] = $styles;
    $variables['plugin_javascripts'] = $scripts;
    $variables['partials'] = new ThemePartials($package);
    return $variables;
}

function theme_manifest_assets(array $manifest): array
{
    $assets = $manifest['assets'] ?? [];
    if (!is_array($assets) || count($assets) > 64) throw new RuntimeException('Theme asset declaration is invalid.');
    $allowedPages = ['all','home','category','topic.view','topic.create','post.edit','account.login','account.register','member','profile','search','messages'];
    $valid = [];
    foreach ($assets as $asset) {
        if (!is_array($asset) || array_diff(array_keys($asset), ['path','pages'])) throw new RuntimeException('Theme asset declaration is invalid.');
        $path = $asset['path'] ?? '';
        $pages = $asset['pages'] ?? null;
        if (!is_string($path) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(css|js)$/D', $path) || !is_array($pages) || !$pages || count($pages) > 16 || isset($valid[$path])) throw new RuntimeException('Theme asset declaration is invalid.');
        $seen = [];
        foreach ($pages as $page) {
            if (!in_array($page, $allowedPages, true) || isset($seen[$page])) throw new RuntimeException('Theme asset declaration is invalid.');
            $seen[$page] = true;
        }
        $valid[$path] = ['path'=>$path, 'type'=>strtolower((string)pathinfo($path, PATHINFO_EXTENSION)), 'pages'=>array_values($pages)];
    }
    return array_values($valid);
}

/**
 * Supplies the same stable extension points to package layouts and the core
 * fallback layout. Themes render these values instead of calling plugin APIs.
 */
function theme_layout_slots(array $variables): array
{
    $user = is_array($variables['user'] ?? null) ? $variables['user'] : null;
    $path = (string)($variables['requestPath'] ?? '/');
    if (!function_exists('extension_slot')) {
        return ['head'=>'', 'navigation'=>'', 'header_after'=>'', 'content_before'=>'', 'content_after'=>'', 'main_before'=>'', 'main_after'=>'', 'sidebar'=>'', 'footer'=>'', 'footer_before'=>'', 'body_end'=>''];
    }
    return [
        'head'=>extension_slot('head.assets', ['user'=>$user, 'path'=>$path]),
        'navigation'=>extension_slot('navigation.primary', ['user'=>$user, 'path'=>$path]),
        'header_after'=>extension_slot('page.header.after', ['user'=>$user, 'path'=>$path]),
        'content_before'=>extension_slot('page.content.before', ['user'=>$user, 'path'=>$path]),
        'content_after'=>extension_slot('page.content.after', ['user'=>$user, 'path'=>$path]),
        'main_before'=>extension_slot('page.main.before', ['user'=>$user, 'path'=>$path]),
        'main_after'=>extension_slot('page.main.after', ['user'=>$user, 'path'=>$path]),
        'sidebar'=>extension_slot('sidebar.sections', ['user'=>$user, 'path'=>$path]),
        'footer_before'=>extension_slot('page.footer.before', ['user'=>$user, 'path'=>$path]),
        'footer'=>extension_slot('footer.before', ['user'=>$user, 'path'=>$path]),
        'body_end'=>extension_slot('body.end', ['user'=>$user, 'path'=>$path]),
    ];
}

/**
 * Uses a theme page when present and otherwise retains the core page markup.
 * A theme can therefore override only the screens it owns.
 */
function theme_render_page(string $view, array $variables, callable $fallback): string
{
    return theme_render_standard($view, $variables, $fallback);
}

/** Resolves one package identity, without substituting a fallback package. */
function theme_runtime_package(string $name, ?string $root = null): ?array
{
    $root ??= dirname(__DIR__).'/templates';
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $name)) return null;
    static $packages = [];
    $key = $root.'/'.$name;
    if (array_key_exists($key, $packages)) return $packages[$key];
    if (!is_dir($root) || is_link($root)) return $packages[$key] = null;

    if (!is_dir($key) && !is_link($key)) return $packages[$key] = null;
    try {
        return $packages[$key] = theme_validate_package($key, $name);
    } catch (Throwable $error) {
        error_log('theme '.$name.' failed validation: '.$error->getMessage());
        return $packages[$key] = null;
    }
}

function theme_effective_package(string $name, ?string $root = null): ?array
{
    return theme_runtime_package($name, $root) ?? ($name === 'default' ? null : theme_runtime_package('default', $root));
}

function theme_read_manifest(string $directory): ?array
{
    if (is_link($directory) || is_link(dirname($directory))) return null;
    $file = extension_secure_runtime_file($directory, 'theme.json');
    $source = $file !== null ? @file_get_contents($file, false, null, 0, 2097153) : false;
    $manifest = is_string($source) && strlen($source) <= 2097152 ? json_decode($source, true, 32) : null;
    return is_array($manifest) ? $manifest : null;
}

function theme_validate_package(string $directory, string $name): array
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $name) || !is_dir($directory) || is_link($directory) || is_link(dirname($directory))) {
        throw new RuntimeException('Theme directory is invalid.');
    }
    $manifest = theme_read_manifest($directory);
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name
        || !is_string($manifest['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $manifest['version'])
        || ($manifest['entry'] ?? 'layout.php') !== 'layout.php') {
        throw new RuntimeException('Theme manifest must match the package name and declare a version and fixed entry.');
    }
    if (!is_string($manifest['requires'] ?? null) || !extension_supports_core($manifest['requires'])) {
        throw new RuntimeException('Theme requires an incompatible core version.');
    }
    foreach (['title', 'author'] as $field) {
        if (isset($manifest[$field]) && !is_string($manifest[$field])) throw new RuntimeException('Invalid theme metadata.');
    }
    extension_validate_theme_pagination($manifest);
    $settings = theme_manifest_settings($manifest);
    $assets = theme_manifest_assets($manifest);
    foreach ($assets as $asset) {
        if (extension_secure_runtime_file($directory, 'assets/'.$asset['path']) === null) throw new RuntimeException('Declared theme asset is unavailable: '.$asset['path']);
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $allowedExtensions = ['json', 'php', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'txt', 'md', 'map'];
    $php = [];
    $count = 0;
    foreach ($files as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        if (++$count > 200 || $entry->isLink() || str_starts_with($relative, '.') || str_contains($relative, '/.')
            || in_array(explode('/', $relative, 2)[0], ['vendor', 'node_modules'], true)) {
            throw new RuntimeException('Invalid theme package path: '.$relative);
        }
        if ($entry->isDir()) continue;
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!$entry->isFile() || $entry->getSize() > 2097152 || !in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Invalid theme package file: '.$relative);
        }
        if ($extension !== 'php') continue;
        if ($relative !== 'layout.php' && !preg_match('~^(?:views|partials)/[A-Za-z0-9._/-]+\.php$~D', $relative)) {
            throw new RuntimeException('Unexpected theme PHP file: '.$relative);
        }
        $path = extension_secure_runtime_file($directory, $relative);
        $code = $path === null ? false : @file_get_contents($path);
        if ($code === false) throw new RuntimeException('Theme PHP file is unreadable: '.$relative);
        try { token_get_all($code, TOKEN_PARSE); }
        catch (ParseError $error) { throw new RuntimeException('Invalid PHP syntax in '.$relative, 0, $error); }
        extension_structure_preflight_php($code, $relative);
        $php[$relative] = hash('sha256', $code);
    }
    if (!isset($php['layout.php'])) throw new RuntimeException('Theme layout.php is unavailable.');
    ksort($php);
    return ['name'=>$name, 'directory'=>$directory, 'manifest'=>$manifest, 'settings'=>$settings, 'assets'=>$assets,
        'php'=>$php, 'signature'=>hash('sha256', serialize([$manifest, $php]))];
}

function theme_package_view(?array $package, string $view): ?string
{
    if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*$/D', $view) || strlen($view) > 64) throw new InvalidArgumentException('Invalid theme view.');
    $relative = $view === 'layout' ? 'layout.php' : 'views/'.str_replace('.', '/', $view).'.php';
    if (!isset($package['php'][$relative])) return null;
    $file = extension_secure_runtime_file($package['directory'], $relative);
    return $file !== null && @hash_file('sha256', $file) === $package['php'][$relative] ? $file : null;
}

function theme_include_view(string $file, array $variables): string
{
    // Keep buffer bookkeeping outside the template's variable scope.
    $include = static function (string $file, array $variables): void {
        extract($variables, EXTR_SKIP);
        include $file;
    };

    $level = ob_get_level();
    ob_start();
    try {
        $include($file, $variables);
        while (ob_get_level() > $level + 1) ob_end_flush();
        return (string)ob_get_clean();
    } catch (Throwable $error) {
        while (ob_get_level() > $level) ob_end_clean();
        throw $error;
    }
}

function theme_core_view(string $fallback): string
{
    $root = str_replace('\\', '/', dirname(__DIR__)).'/';
    $path = str_replace('\\', '/', $fallback);
    $relative = substr($path, strlen($root));
    if (str_starts_with($path, $root) && !preg_match('~^templates(?:/|$)~i', $relative)) {
        $file = extension_secure_runtime_file($root, $relative);
        if ($file !== null) return $file;
    }
    throw new RuntimeException('Theme view unavailable.');
}

function theme_asset_surface(string $path): string
{
    return match (true) {
        $path === '/' || preg_match('~^/forum-[1-9][0-9]*$~D', $path) === 1 => 'home',
        preg_match('~^/(?:c/\d+|category-\d+(?:-\d+)?)$~D', $path) === 1 => 'category',
        preg_match('~^/(?:t/\d+|topic-\d+(?:-\d+)?)$~D', $path) === 1 => 'topic.view',
        $path === '/new' => 'topic.create',
        preg_match('~^/p/\d+/edit$~D', $path) === 1 => 'post.edit',
        $path === '/login' => 'account.login',
        $path === '/register' => 'account.register',
        $path === '/me' => 'member',
        str_starts_with($path, '/u/') => 'profile',
        $path === '/search' => 'search',
        $path === '/messages' => 'messages',
        default => 'all',
    };
}

function theme_runtime_assets(string $theme, string $surface): string
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $theme) || !in_array($surface, ['all','home','category','topic.view','topic.create','post.edit','account.login','account.register','member','profile','search','messages'], true)) return '';
    $package = theme_effective_package($theme);
    if ($package === null) return '';
    $html = '';
    foreach ($package['assets'] as $asset) {
        if (!in_array('all', $asset['pages'], true) && !in_array($surface, $asset['pages'], true)) continue;
        $file = extension_secure_runtime_file($package['directory'], 'assets/'.$asset['path']);
        if ($file === null) continue;
        $url = '/theme-assets/'.rawurlencode($package['name']).'/'.rawurlencode($asset['path']).'?v='.asset_version($file);
        $html .= $asset['type'] === 'css' ? '<link rel="stylesheet" href="'.$url.'">' : '<script src="'.$url.'" defer></script>';
    }
    return $html;
}

function core_page_assets(string $surface): string
{
    if ($surface !== 'topic.create') return '';
    $file = dirname(__DIR__).'/assets/js/share-draft.js';
    return is_file($file) ? '<script src="/assets/js/share-draft.js?v='.asset_version($file).'" defer></script>' : '';
}

function theme_asset_signature(string $theme): string
{
    $package = theme_effective_package($theme);
    if ($package === null) return 'core';
    $parts = [$package['signature'], (string)cache_version('theme-settings:'.$package['name'])];
    foreach ($package['assets'] as $asset) {
        $file = extension_secure_runtime_file($package['directory'], 'assets/'.$asset['path']);
        $parts[] = $asset['path'].':'.($file === null ? 'missing' : asset_version($file));
    }
    return hash('sha256', implode("\0", $parts));
}

function theme_asset_response(string $theme, string $asset): \LiteBBS\Framework\Response
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $theme) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(css|js)$/', $asset)) return new \LiteBBS\Framework\Response('Not Found', 404);
    $package = theme_runtime_package($theme);
    if ($package === null) return new \LiteBBS\Framework\Response('Not Found', 404);
    $entry = null;
    foreach ($package['assets'] as $declared) if ($declared['path'] === $asset) { $entry = $declared; break; }
    $file = $entry === null ? null : extension_secure_runtime_file($package['directory'], 'assets/'.$asset);
    if ($file === null) return new \LiteBBS\Framework\Response('Not Found', 404);
    $etag = '"'.asset_version($file).'"';
    $versioned = isset($_GET['v']) && is_string($_GET['v']) && hash_equals(trim($etag, '"'), $_GET['v']);
    $cacheControl = $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=3600';
    if (hash_equals($etag, (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))) return (new \LiteBBS\Framework\Response('', 304))->header('ETag', $etag)->header('Cache-Control', $cacheControl);
    $body = file_get_contents($file);
    if (!is_string($body)) return new \LiteBBS\Framework\Response('Not Found', 404);
    return (new \LiteBBS\Framework\Response($body))->header('Content-Type', $entry['type'] === 'css' ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8')->header('ETag', $etag)->header('Cache-Control', $cacheControl)->header('X-Content-Type-Options', 'nosniff');
}

function template_pagination_fallback(array $pagination): string
{
    if (empty($pagination['has_more'])) return '';
    $label = ($pagination['context'] ?? '') === 'topic' ? '更多回复' : '加载更多';
    $aria = ($pagination['context'] ?? '') === 'topic' ? ' aria-label="回复分页"' : '';
    return '<nav class="pagination"'.$aria.'><a class="pagination-next" href="'.e((string)($pagination['next_url'] ?? '')).'">'.$label.'</a></nav>';
}
