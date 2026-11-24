<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;

putenv('LITEBBS_REDIS_HOST');
require_once dirname(__DIR__).'/app/core.php';

$key = 'test:'.bin2hex(random_bytes(12));
$first = $key.':1';
$versionFile = dirname(__DIR__).'/cache/version-'.hash('sha256', $key);
$valueFile = dirname(__DIR__).'/cache/'.hash('sha256', $first).'.cache';
$lockFile = cache_lock_path($first);
$held = null;
try {
    if (page_cache_read($first, 60) !== null) throw new RuntimeException('Unexpected initial cache hit.');
    page_cache_write($first, ['published'], 60);
    $state =& request_cache_state();
    unset($state[$first]);
    if (read_cache($first, 60) !== ['published']) throw new RuntimeException('Owner did not publish the cache entry.');
    unset($state[$first]);
    unlink($valueFile);

    $held = fopen($lockFile, 'c');
    if (!$held || !flock($held, LOCK_EX | LOCK_NB)) throw new RuntimeException('Test lock unavailable.');
    if (cached($first, 60, static fn(): array => ['fallback']) !== ['fallback']) throw new RuntimeException('Contended read did not use its loader.');
    page_cache_write($first, ['unexpected'], 60);
    if (read_cache($first, 60) !== null) throw new RuntimeException('A request without the lock published a cache entry.');

    invalidate($key);
    $version = cache_version($key);
    invalidate($key);
    if (cache_version($key) <= $version || local_cache_version($key) !== cache_version($key)) {
        throw new RuntimeException('Cache version did not advance and persist.');
    }
} finally {
    if (is_resource($held)) fclose($held);
    foreach ([$valueFile, $lockFile, $versionFile, $versionFile.'.lock'] as $file) {
        if (is_file($file)) unlink($file);
    }
}

echo "File cache tests passed.\n";
