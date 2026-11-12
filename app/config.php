<?php
declare(strict_types=1);

function config_directory(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $configured = getenv('LITEBBS_PRIVATE_DIR');
    if ($configured !== false && $configured !== '') {
        if ($configured[0] !== '/' || $configured === '/' || strpos($configured, "\0") !== false) {
            throw new RuntimeException('LITEBBS_PRIVATE_DIR 必须是绝对目录');
        }
        $dir = rtrim($configured, '/');
    } else {
        // This follows the application directory, including subdirectory deployments.
        $dir = dirname(__DIR__).'/private';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建站点私有配置目录');
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
    $private = config_directory().'/install.lock';
    $legacy = dirname(__DIR__).'/install.lock';
    if (!is_file($private) && is_file($legacy)) {
        if (@rename($legacy, $private) && function_exists('chmod')) @chmod($private, 0600);
        elseif (is_file($legacy)) return $legacy;
    }
    return $private;
}

function write_install_lock(): void
{
    $path = config_directory().'/install.lock';
    $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
    if (@file_put_contents($tmp, bin2hex(random_bytes(32))."\n", LOCK_EX) === false) throw new RuntimeException('无法写入私有安装锁');
    if (function_exists('chmod')) @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('无法原子写入私有安装锁'); }
    if (function_exists('chmod')) @chmod($path, 0600);
}

function load_config(): array
{
    $state =& config_runtime_cache();
    if ($state['loaded']) return $state['data'];
    $private = config_file();
    $legacy = dirname(__DIR__).'/config.php';
    if (!is_file($private) && is_file($legacy)) {
        $data = require $legacy;
        if (!is_array($data)) throw new RuntimeException('旧配置格式无效');
        write_config($data);
        if (is_file($private) && !@unlink($legacy)) {
            @unlink($private);
            throw new RuntimeException('私有配置已写入，但旧根配置无法删除');
        }
    }
    if (!is_file($private)) { $state = ['loaded' => true, 'data' => []]; return []; }
    $data = require $private;
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
