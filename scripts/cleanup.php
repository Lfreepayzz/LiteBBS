<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/core.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$sessions = h('DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP(6)')->rowCount();
$limits = h('DELETE FROM rate_limits WHERE reset_at <= UTC_TIMESTAMP(6)')->rowCount();
$packages = h('SELECT package_file FROM market_submissions WHERE status="rejected" AND created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY)')->fetchAll(PDO::FETCH_COLUMN);
$packageRoot = dirname(__DIR__).'/market-packages';
foreach ($packages as $package) {
    if (preg_match('/^[a-f0-9]{48}\.zip$/', (string)$package)) @unlink($packageRoot.'/'.$package);
}
$rejected = h('DELETE FROM market_submissions WHERE status="rejected" AND created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY)')->rowCount();
$root = dirname(__DIR__);
$removed = 0;
$cutoff = time() - 86400;
$cache = $root.'/cache';
if (is_dir($cache)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($cache) + 1));
        if ($item->isDir() && $item->getMTime() < $cutoff && preg_match('~^(?:inspect|install)-[a-f0-9]+$~', $relative)) {
            if (@rmdir($item->getPathname())) $removed++;
        } elseif ($item->isFile() && $item->getMTime() < $cutoff && preg_match('~^(?:[a-f0-9]{64}\.cache|[a-f0-9]{64}\.cache\.[a-f0-9]+\.tmp|topic-views/[0-9]+\.cache|(?:inspect|install)-[a-f0-9]+/.+)$~', $relative)) {
            if (@unlink($item->getPathname())) $removed++;
        }
    }
}
echo "Deleted {$sessions} expired sessions, {$limits} expired rate limits, {$rejected} old rejected packages and {$removed} stale cache files.\n";
