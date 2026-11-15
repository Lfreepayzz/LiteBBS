<?php
declare(strict_types=1);

function plugin_runtime_load(): array {
    static $plugins = null;
    if ($plugins !== null) return $plugins;
    // Legacy plugins are unsupported and never loaded; V2 plugins use the
    // extension registry directly. Avoid scanning every enabled directory.
    return $plugins = [];
}

function plugin_runtime_invalidate(): void {
    extension_invalidate('plugins');
}

function plugin_runtime_register_routes(\LiteBBS\Framework\Router $router): void {
    foreach (plugin_runtime_load() as $plugin) {
        foreach ($plugin['routes'] ?? [] as $route) {
            $router->add($route['methods'], $route['pattern'], static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params) use ($route): \LiteBBS\Framework\Response {
                ob_start();
                try {
                    $result = ($route['handler'])($request->context(current_user(), db()), $params);
                    $body = (string)ob_get_clean();
                } catch (Throwable $error) {
                    ob_end_clean();
                    throw $error;
                }
                if ($result instanceof \LiteBBS\Framework\Response) return $result;
                return new \LiteBBS\Framework\Response($body. (is_string($result) ? $result : ''));
            });
        }
    }
}

function plugin_route_dispatch(array $context): bool {
    if (plugin_asset_dispatch($context)) return true;
    return false;
}

function plugin_stylesheets(): string {
    $html = '';
    foreach (plugin_runtime_load() as $name => $plugin) {
        foreach ($plugin['_styles'] ?? [] as $style) {
            $segments = array_map('rawurlencode', explode('/', $style));
            $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$name, $style);
            if ($file === null) continue;
            $html .= '<link rel="stylesheet" href="/plugin-assets/'.rawurlencode((string)$name).'/'.implode('/', $segments).'?v='.asset_version($file).'">';
        }
    }
    return $html;
}

function plugin_asset_dispatch(array $context): bool {
    if (($context['method'] ?? '') !== 'GET' || !preg_match('~^/plugin-assets/([a-z][a-z0-9_-]{1,31})/(.+)$~', (string)($context['path'] ?? ''), $match)) return false;
    $name = $match[1];
    $asset = rawurldecode($match[2]);
    $plugin = plugin_runtime_load()[$name] ?? null;
    $allowed = is_array($plugin) && in_array($asset, $plugin['_styles'] ?? [], true);
    // Assets are the only legacy-plugin path that needs the extension registry.
    // Avoid booting plugins for every ordinary application request.
    if (!$allowed && function_exists('extension_runtime_load')) {
        $records = extension_enabled_plugins();
        foreach ($records as $record) {
            if (($record['name'] ?? '') !== $name) continue;
            $manifest = extension_plugin_manifest($name);
            if (is_array($manifest) && ($manifest['schema'] ?? null) === 1 && ($manifest['id'] ?? '') === $name) {
                $assets = extension_manifest_assets($manifest);
                $allowed = is_array($assets) && in_array($asset, array_column($assets, 'path'), true);
            } else {
                extension_runtime_load($name);
                foreach (extension_registry()['assets'] as $registered) if ($registered['plugin'] === $name && $registered['path'] === $asset && in_array($registered['type'], ['css','js'], true)) { $allowed = true; break; }
            }
            break;
        }
    }
    if (!$allowed) return false;
    $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$name, $asset);
    if ($file === null) return false;
    if (headers_sent()) return false;
    $etag = '"'.asset_version($file).'"';
    header('ETag: '.$etag);
    header('Cache-Control: public, max-age=31536000, immutable');
    if (hash_equals($etag, (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))) {
        http_response_code(304);
        return true;
    }
    header('Content-Type: '.(str_ends_with($asset, '.js') ? 'application/javascript' : 'text/css').'; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return true;
}
