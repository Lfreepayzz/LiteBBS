<?php
declare(strict_types=1);

const LITEBBS_VERSION = '1.1.0';

function extension_manifest_admin_path(array $manifest, string $id): ?string {
    if (!array_key_exists('admin_path', $manifest)) return null;
    $path = $manifest['admin_path'];
    if (!is_string($path) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $path)) {
        throw new RuntimeException('Invalid plugin admin path.');
    }
    return $path;
}

function extension_manifest_public_route_prefix(array $manifest): ?string {
    if (!array_key_exists('public_route_prefix', $manifest)) return null;
    $prefix = (string)$manifest['public_route_prefix'];
    if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $prefix) || in_array($prefix, ['admin','plugin','plugin-assets','assets','uploads','install','login','logout','register','me','new','search','market','robots','sitemap','t','c','u','p','avatar','messages','topic','post','report','user'], true)) throw new RuntimeException('Invalid public plugin route prefix.');
    return $prefix;
}

function extension_validate_directory(string $directory, string $kind, string $name): void {
    static $validated = [];
    if (!in_array($kind, ['plugin', 'theme'], true) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name) || !is_dir($directory)) {
        throw new RuntimeException('扩展目录或类型无效');
    }

    if ($kind === 'plugin' && is_file($directory.'/plugin.json')) {
        extension_validate_v2_plugin($directory, $name);
        return;
    }

    $signature = $kind.':'.$name.':'.@filemtime($directory).':'.@filemtime($directory.($kind === 'plugin' ? '/manifest.json' : '/theme.json')).':'.@filesize($directory.($kind === 'plugin' ? '/plugin.php' : '/layout.php'));
    if (isset($validated[$signature])) return;

    $manifestFile = $directory.($kind === 'plugin' ? '/manifest.json' : '/theme.json');
    $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
    $entry = $kind === 'plugin' ? 'plugin.php' : 'layout.php';
    $manifestName = is_array($manifest) ? (string)($manifest['name'] ?? '') : '';
    $nameMatches = $kind === 'theme'
        ? preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $manifestName)
        : $manifestName === $name;
    if (!is_array($manifest) || !$nameMatches || !preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? '')) || ($manifest['entry'] ?? $entry) !== $entry || !is_file($directory.'/'.$entry)) {
        throw new RuntimeException('清单必须包含有效名称、语义版本和固定入口文件');
    }
    if ($kind === 'theme' && function_exists('theme_manifest_settings')) theme_manifest_settings($manifest);
    if ($kind === 'theme') extension_validate_theme_pagination($manifest);
    if (!isset($manifest['requires']) || !extension_supports_core((string)$manifest['requires'])) {
        throw new RuntimeException('扩展声明的核心版本范围不兼容');
    }
    if ($kind === 'plugin') {
        $tables = $manifest['database_tables'] ?? [];
        // Database identifiers use underscores even when the extension ID contains hyphens.
        $prefix = 'plugin_'.str_replace('-', '_', $name).'_';
        if (!is_array($tables) || count($tables) > 100) throw new RuntimeException('插件 database_tables 必须是有效数组');
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) || strpos($table, $prefix) !== 0) throw new RuntimeException('插件数据表必须以 '.$prefix.' 开头');
        }
    }

    // Only the documented entry file may be PHP. Plugin code is trusted and
    // executes through the explicit runtime hook contract below.
    $allowedPhp = $kind === 'plugin' ? ['plugin.php'] : ['layout.php'];
    $allowedExtensions = ['json', 'php', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'txt', 'md', 'map'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($files as $file) {
        if (++$count > 200 || $file->isLink() || !$file->isFile()) throw new RuntimeException('扩展包含过多文件、链接或非普通文件');
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($file->getSize() > 2097152 || !in_array($extension, $allowedExtensions, true) || substr($relative, 0, 1) === '.' || strpos($relative, '/.') !== false || substr($relative, 0, 7) === 'vendor/' || substr($relative, 0, 13) === 'node_modules/') {
            throw new RuntimeException('扩展包含不允许的文件或路径：'.$relative);
        }
        if ($extension === 'php') {
            $themeView = $kind === 'theme' && preg_match('~^views/[A-Za-z0-9._/-]+\.php$~', $relative) === 1;
            if (!in_array($relative, $allowedPhp, true) && !$themeView) throw new RuntimeException('扩展包含未声明的 PHP 文件：'.$relative);
            extension_structure_preflight_php((string)file_get_contents($file->getPathname()), $relative);
        }
    }
    $validated[$signature] = true;
}

function extension_validate_v2_plugin(string $directory, string $name): void {
    $manifest = json_decode((string)file_get_contents($directory.'/plugin.json'), true);
    $entry = is_array($manifest) ? (string)($manifest['entry'] ?? '') : '';
    $class = is_array($manifest) ? (string)($manifest['class'] ?? '') : '';
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $name || !preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? '')) || !is_array($manifest['requires'] ?? null) || !extension_supports_core((string)($manifest['requires']['core'] ?? '')) || !extension_v2_entry_file($directory, $entry) || !extension_class_name_valid($class)) throw new RuntimeException('新插件清单无效');
    $prefix = 'plg_'.str_replace('-', '_', $name).'_';
    $tables = $manifest['database_tables'] ?? [];
    if (!is_array($tables) || count($tables) > 100 || count($tables) !== count(array_unique($tables))) throw new RuntimeException('新插件 database_tables 必须是无重复的有效数组');
    foreach ($tables as $table) if (!is_string($table) || !preg_match('/^'.preg_quote($prefix, '/').'[a-z][a-z0-9_]{0,55}$/', $table)) throw new RuntimeException('新插件数据表必须以 '.$prefix.' 开头');
    extension_manifest_admin_path($manifest, $name);
    extension_manifest_public_route_prefix($manifest);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($files as $file) {
        if (++$count > 300 || $file->isLink() || !$file->isFile() || $file->getSize() > 2097152) throw new RuntimeException('新插件包含过多文件、链接或超限文件');
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json','php','css','js','png','jpg','jpeg','gif','webp','svg','txt','md','map'], true) || str_starts_with($relative, '.') || str_contains($relative, '/.')) throw new RuntimeException('新插件包含不允许的文件：'.$relative);
        if ($extension === 'php' && !str_starts_with($relative, 'src/') && !str_starts_with($relative, 'migrations/')) throw new RuntimeException('新插件 PHP 文件必须位于 src 或 migrations 目录');
        if ($extension === 'php') extension_structure_preflight_php((string)file_get_contents($file->getPathname()), $relative);
        if (str_starts_with($relative, 'migrations/')) {
            if (!preg_match('~^migrations/[0-9]{8}_[0-9]{4}_[a-z0-9_]+\.php$~', $relative)) throw new RuntimeException('新插件迁移文件名无效');
            extension_validate_migration_source((string)file_get_contents($file->getPathname()), $relative, $prefix);
        }
    }
    if ($prefix === '') throw new RuntimeException('插件数据表前缀无效');
}

function extension_v2_entry_file(string $directory, string $entry): ?string {
    // Validate the manifest path and its resolved target. A regex alone accepts
    // `src/../...`, which can escape the plugin directory when it is required.
    if (!preg_match('~^src/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$~', $entry)) return null;
    return extension_secure_runtime_file($directory, $entry);
}

function extension_validate_migration_source(string $source, string $relative, string $prefix): void {
    // This is a conservative naming check in addition to PHP preflight, not a sandbox.
    if (preg_match_all('~["\']([A-Za-z0-9_]+)["\']~', $source, $matches)) foreach ($matches[1] as $identifier) {
        if (str_starts_with($identifier, 'plg_') && !str_starts_with($identifier, $prefix)) throw new RuntimeException($relative.' 引用了其他插件的数据表前缀');
    }
}

function extension_market_manifest(string $directory, string $kind, string $expectedName): array {
    extension_validate_directory($directory, $kind, $expectedName);
    $file=$directory.($kind === 'plugin' && is_file($directory.'/plugin.json') ? '/plugin.json' : ($kind === 'plugin' ? '/manifest.json' : '/theme.json'));
    $manifest=json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    $name=(string)($kind === 'plugin' && isset($manifest['id']) ? $manifest['id'] : ($manifest['name'] ?? ''));
    $version=(string)($manifest['version'] ?? '');
    if ($name !== $expectedName || !preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new RuntimeException('包清单身份或版本无效');
    return ['kind'=>$kind,'name'=>$name,'version'=>$version,'manifest_json'=>json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
}

function extension_class_name_valid(string $class): bool {
    $parts = explode('\\', $class);
    if (!$parts || in_array('', $parts, true)) return false;
    foreach ($parts as $part) if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) return false;
    return true;
}

function extension_theme_runtime_valid(string &$directory, string $name): bool {
    static $checked=[];
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$name) || is_link($directory) || is_link($directory.'/layout.php')) return false;
    if (!is_dir($directory)) {
        $match = null;
        $templatesDirectory = dirname($directory);
        foreach (is_dir($templatesDirectory) ? new DirectoryIterator($templatesDirectory) : [] as $item) {
            $folder = $item->getFilename();
            if (!$item->isDir() || $item->isDot() || $item->isLink() || is_link($item->getPathname().'/layout.php') || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $folder)) continue;
            $manifest = json_decode((string)@file_get_contents($item->getPathname().'/theme.json'), true);
            if (($manifest['name'] ?? null) !== $name) continue;
            if ($match !== null) return false;
            $match = ['directory'=>$item->getPathname(), 'folder'=>$folder];
        }
        if ($match === null) return false;
        $directory = $match['directory'];
    }
    $signature=@filemtime($directory.'/theme.json').':'.@filesize($directory.'/theme.json').':'.@filemtime($directory.'/layout.php').':'.@filesize($directory.'/layout.php');
    if (isset($checked[$directory])&&$checked[$directory]===$signature) return true;
    $manifest=json_decode((string)@file_get_contents($directory.'/theme.json'),true);
    if (!is_array($manifest)||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',(string)($manifest['name']??''))||($manifest['entry']??'layout.php')!=='layout.php'||!isset($manifest['requires'])||!extension_supports_core((string)$manifest['requires'])||!preg_match('/^\d+\.\d+\.\d+$/',(string)($manifest['version']??''))||extension_secure_runtime_file($directory,'layout.php')===null) return false;
    try { extension_validate_theme_pagination($manifest); } catch (RuntimeException $error) { return false; }
    $checked[$directory]=$signature;
    return true;
}

function extension_validate_theme_pagination(array $manifest): void {
    $pagination = $manifest['pagination'] ?? [];
    if (!is_array($pagination)) throw new RuntimeException('Invalid theme pagination declaration.');
    foreach ($pagination as $context=>$size) {
        if ($context === 'home_path' && $size === 'forum') continue;
        if (!in_array($context, ['home','category','topic'], true) || !is_int($size) || $size < 1 || $size > 100) throw new RuntimeException('Invalid theme pagination size.');
    }
}

function extension_secure_runtime_file(string $root, string $relative): ?string {
    static $resolvedCache = [];
    $cacheKey = $root."\0".$relative;
    if (array_key_exists($cacheKey, $resolvedCache)) return $resolvedCache[$cacheKey];
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, "\n") || str_contains($relative, "\r")) return null;
    $rootReal = realpath($root);
    $relative = str_replace('\\', '/', $relative);
    if ($rootReal === false || !is_dir($rootReal) || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $relative)) return $resolvedCache[$cacheKey] = null;
    $parts = preg_split('~/+~', $relative, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return $resolvedCache[$cacheKey] = null;
    $current = $rootReal;
    foreach ($parts as $part) {
        if ($part === '.' || $part === '' || is_link($current.'/'.$part)) return $resolvedCache[$cacheKey] = null;
        $current .= DIRECTORY_SEPARATOR.$part;
    }
    $resolved = realpath($current);
    if ($resolved === false || !is_file($resolved) || is_link($resolved)) return $resolvedCache[$cacheKey] = null;
    $prefix = rtrim($rootReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (!str_starts_with($resolved, $prefix)) return $resolvedCache[$cacheKey] = null;
    return $resolvedCache[$cacheKey] = $resolved;
}

function extension_supports_core(string $range): bool {
    if (!preg_match('/^>=([0-9]+\.[0-9]+(?:\.[0-9]+)?)\s+<([0-9]+\.[0-9]+(?:\.[0-9]+)?)$/', $range, $match)) return false;
    return version_compare(LITEBBS_VERSION, $match[1], '>=') && version_compare(LITEBBS_VERSION, $match[2], '<');
}

// This rejects unsupported package shapes; it does not sandbox or establish PHP safety.
function extension_structure_preflight_php(string $source, string $relative): void {
    $forbiddenTokens = [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT, T_GLOBAL];
    $forbiddenCalls = ['assert', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client', 'putenv', 'ini_set', 'set_error_handler', 'register_shutdown_function', 'get_defined_vars', 'reflectionclass', 'reflectionfunction', 'class_alias'];
    $forbiddenVariables = ['$GLOBALS', '$_ENV', '$_SERVER'];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) continue;
        if (in_array($token[0], $forbiddenTokens, true)) throw new RuntimeException($relative.' 使用了受禁止的 PHP 语法');
        if ($token[0] === T_VARIABLE && in_array($token[1], $forbiddenVariables, true)) throw new RuntimeException($relative.' 直接读取了请求或全局变量');
        if ($token[0] === T_STRING && in_array(strtolower($token[1]), $forbiddenCalls, true)) throw new RuntimeException($relative.' 调用了受禁止的系统、文件或网络 API');
    }
}
