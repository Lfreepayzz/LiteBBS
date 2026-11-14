<?php
declare(strict_types=1);

function theme_runtime_template(string $defaultFile, array $variables): string
{
    $root = dirname(__DIR__);
    $theme = (string)($variables['safe'] ?? 'default');
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $theme)) $theme = 'default';
    $directory = $root.'/templates/'.$theme;
    $manifestFile = $directory.'/theme.json';
    $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
    $candidate = extension_secure_runtime_file($directory, 'views/'.basename($defaultFile));
    if (!is_array($manifest) || ($manifest['schema'] ?? 1) !== 1 || $candidate === null) $candidate = extension_secure_runtime_file($directory, basename($defaultFile));
    if ($candidate === null) $candidate = extension_secure_runtime_file(dirname(__DIR__), ltrim(str_replace(dirname(__DIR__).DIRECTORY_SEPARATOR, '', $defaultFile), DIRECTORY_SEPARATOR));
    if ($candidate === null) throw new RuntimeException('Theme template unavailable.');
    ob_start();
    extract($variables, EXTR_SKIP);
    include $candidate;
    return (string)ob_get_clean();
}

function theme_view(string $view, array $variables, string $fallback): string
{
    if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $view)) throw new InvalidArgumentException('Invalid theme view.');
    $theme = (string)($variables['safe'] ?? 'default');
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $theme)) $theme = 'default';
    $file = extension_secure_runtime_file(dirname(__DIR__).'/templates/'.$theme, 'views/'.str_replace('.', '/', $view).'.php');
    if ($file === null) $file = extension_secure_runtime_file(dirname(__DIR__), ltrim(str_replace(dirname(__DIR__).DIRECTORY_SEPARATOR, '', $fallback), DIRECTORY_SEPARATOR));
    if ($file === null) throw new RuntimeException('Theme view unavailable.');
    ob_start();
    extract($variables, EXTR_SKIP);
    include $file;
    return (string)ob_get_clean();
}

function theme_stylesheet(string $theme, string $asset): string
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $theme) || !preg_match('/^[A-Za-z0-9._-]+\.css$/', $asset)) return '';
    $file = extension_secure_runtime_file(dirname(__DIR__).'/templates/'.$theme, 'assets/'.$asset);
    if ($file === null) return '';
    return '/templates/'.rawurlencode($theme).'/assets/'.rawurlencode($asset).'?v='.asset_version($file);
}

function theme_runtime_assets(string $theme, bool $home, bool $topic): string
{
    $assets = ['theme.css'];
    if ($home) $assets[] = 'home.css';
    if ($topic) $assets[] = 'topic.css';
    $html = '';
    foreach ($assets as $asset) {
        $url = theme_stylesheet($theme, $asset);
        if ($url !== '') $html .= '<link rel="stylesheet" href="'.$url.'">';
    }
    if ($topic) {
        $file = extension_secure_runtime_file(dirname(__DIR__).'/templates/'.$theme, 'assets/topic.js');
        if ($file !== null) $html .= '<script src="/templates/'.rawurlencode($theme).'/assets/topic.js?v='.asset_version($file).'" defer></script>';
    }
    return $html;
}
