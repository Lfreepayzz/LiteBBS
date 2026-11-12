<?php
declare(strict_types=1);

const LITEBBS_VERSION = '1.0.0';

function extension_validate_directory(string $directory, string $kind, string $name): void {
    if (!in_array($kind, ['plugin', 'theme'], true) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name) || !is_dir($directory)) {
        throw new RuntimeException('扩展目录或类型无效');
    }

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
    if (!isset($manifest['requires']) || !extension_supports_core((string)$manifest['requires'])) {
        throw new RuntimeException('扩展声明的核心版本范围不兼容');
    }
    if ($kind === 'plugin') {
        $tables = $manifest['database_tables'] ?? [];
        $prefix = 'plugin_'.$name.'_';
        if (!is_array($tables) || count($tables) > 100) throw new RuntimeException('插件 database_tables 必须是有效数组');
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) || strpos($table, $prefix) !== 0) throw new RuntimeException('插件数据表必须以 '.$prefix.' 开头');
        }
    }

    // Only the documented entry file may be PHP. This is a package boundary,
    // not a sandbox, but allowing unused PHP views creates future execution risk.
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
            if (!in_array($relative, $allowedPhp, true)) throw new RuntimeException('扩展包含未声明的 PHP 文件：'.$relative);
            extension_structure_preflight_php((string)file_get_contents($file->getPathname()), $relative);
        }
    }
}

function extension_theme_runtime_valid(string &$directory, string &$name): bool {
    static $checked=[];
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$name) || is_link($directory) || is_link($directory.'/layout.php')) return false;
    if (!is_dir($directory)) {
        $match = null;
        $templatesDirectory = dirname($directory);
        foreach (is_dir($templatesDirectory) ? new DirectoryIterator($templatesDirectory) : [] as $item) {
            $folder = $item->getFilename();
            if (!$item->isDir() || $item->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $folder)) continue;
            $manifest = json_decode((string)@file_get_contents($item->getPathname().'/theme.json'), true);
            if (($manifest['name'] ?? null) !== $name) continue;
            if ($match !== null) return false;
            $match = ['directory'=>$item->getPathname(), 'folder'=>$folder];
        }
        if ($match === null) return false;
        $directory = $match['directory'];
        $name = $match['folder'];
    }
    $signature=@filemtime($directory.'/theme.json').':'.@filesize($directory.'/theme.json').':'.@filemtime($directory.'/layout.php').':'.@filesize($directory.'/layout.php');
    if (isset($checked[$directory])&&$checked[$directory]===$signature) return true;
    $manifest=json_decode((string)@file_get_contents($directory.'/theme.json'),true);
    if (!is_array($manifest)||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',(string)($manifest['name']??''))||($manifest['entry']??'layout.php')!=='layout.php'||!isset($manifest['requires'])||!extension_supports_core((string)$manifest['requires'])||!preg_match('/^\d+\.\d+\.\d+$/',(string)($manifest['version']??''))||!is_file($directory.'/layout.php')) return false;
    $checked[$directory]=$signature;
    return true;
}

function extension_supports_core(string $range): bool {
    if (!preg_match('/^>=([0-9]+\.[0-9]+(?:\.[0-9]+)?)\s+<([0-9]+\.[0-9]+(?:\.[0-9]+)?)$/', $range, $match)) return false;
    return version_compare(LITEBBS_VERSION, $match[1], '>=') && version_compare(LITEBBS_VERSION, $match[2], '<');
}

// This rejects unsupported package shapes; it does not sandbox or establish PHP safety.
function extension_structure_preflight_php(string $source, string $relative): void {
    $forbiddenTokens = [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT, T_GLOBAL];
    $forbiddenCalls = ['assert', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client', 'file_get_contents', 'file_put_contents', 'fopen', 'unlink', 'rename', 'copy', 'chmod', 'chown', 'putenv', 'getenv', 'ini_set', 'set_error_handler', 'register_shutdown_function', 'get_defined_vars', 'reflectionclass', 'reflectionfunction', 'class_alias'];
    $forbiddenVariables = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER', '$_ENV', '$_SESSION', '$pdo'];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) continue;
        if (in_array($token[0], $forbiddenTokens, true)) throw new RuntimeException($relative.' 使用了受禁止的 PHP 语法');
        if ($token[0] === T_VARIABLE && in_array($token[1], $forbiddenVariables, true)) throw new RuntimeException($relative.' 直接读取了请求或全局变量');
        if ($token[0] === T_STRING && in_array(strtolower($token[1]), $forbiddenCalls, true)) throw new RuntimeException($relative.' 调用了受禁止的系统、文件或网络 API');
    }
}
