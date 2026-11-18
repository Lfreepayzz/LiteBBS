<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/core.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$sessions = 0; do { $count = h('DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP(6) ORDER BY expires_at,id LIMIT 1000')->rowCount(); $sessions += $count; } while ($count === 1000);
$limits = 0; do { $count = h('DELETE FROM rate_limits WHERE reset_at <= UTC_TIMESTAMP(6) ORDER BY reset_at,bucket LIMIT 1000')->rowCount(); $limits += $count; } while ($count === 1000);
$packages = h('SELECT package_file FROM market_submissions WHERE status="rejected" AND created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY)')->fetchAll(PDO::FETCH_COLUMN);
$packageRoot = dirname(__DIR__).'/market-packages';
foreach ($packages as $package) {
    if (preg_match('/^[a-f0-9]{48}\.zip$/', (string)$package)) @unlink($packageRoot.'/'.$package);
}
$rejected = 0; do { $count = h('DELETE FROM market_submissions WHERE status="rejected" AND created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 DAY) ORDER BY created_at,id LIMIT 500')->rowCount(); $rejected += $count; } while ($count === 500);
$outbox = 0; do { $count = h('DELETE FROM outbox_events WHERE processed_at IS NOT NULL AND processed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 90 DAY) ORDER BY processed_at,id LIMIT 1000')->rowCount(); $outbox += $count; } while ($count === 1000);
$failedOutbox = 0; do { $count = h('DELETE FROM failed_outbox_events WHERE failed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 180 DAY) ORDER BY failed_at,id LIMIT 1000')->rowCount(); $failedOutbox += $count; } while ($count === 1000);
$viewFlushBatches = 0; do { $count = h('DELETE FROM topic_view_flush_batches WHERE flushed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 90 DAY) ORDER BY flushed_at,batch_key LIMIT 1000')->rowCount(); $viewFlushBatches += $count; } while ($count === 1000);
$audit = 0; do { $count = h('DELETE FROM audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 365 DAY) ORDER BY created_at,id LIMIT 1000')->rowCount(); $audit += $count; } while ($count === 1000);
$notifications = 0; do { $count = h('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 180 DAY) ORDER BY read_at,id LIMIT 1000')->rowCount(); $notifications += $count; } while ($count === 1000);
$notificationOutbox = 0; do { $count = h('DELETE FROM notification_outbox WHERE processed_at IS NOT NULL AND processed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 90 DAY) ORDER BY processed_at,id LIMIT 1000')->rowCount(); $notificationOutbox += $count; } while ($count === 1000);
$messages = 0; do { $count = h('DELETE FROM private_messages WHERE created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 365 DAY) ORDER BY created_at,id LIMIT 1000')->rowCount(); $messages += $count; } while ($count === 1000);
$extensionJobs = 0; do { $count = h('DELETE FROM extension_jobs WHERE completed_at IS NOT NULL AND completed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 90 DAY) ORDER BY completed_at,id LIMIT 1000')->rowCount(); $extensionJobs += $count; } while ($count === 1000);
$extensionFailures = 0; do { $count = h('DELETE FROM extension_job_failures WHERE failed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 180 DAY) ORDER BY failed_at,id LIMIT 1000')->rowCount(); $extensionFailures += $count; } while ($count === 1000);
$root = dirname(__DIR__);
$removed = 0;
$cutoff = time() - 86400;
$cacheValueCutoff = time() - 172800;
$cache = $root.'/cache';
if (is_dir($cache)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($cache) + 1));
        if ($item->isDir() && $item->getMTime() < $cutoff && preg_match('~^(?:inspect|install)-[a-f0-9]+$~', $relative)) {
            if (@rmdir($item->getPathname())) $removed++;
        } elseif ($item->isFile() && $item->getMTime() < $cutoff && preg_match('~^(?:[a-f0-9]{64}\.cache\.[a-f0-9]+\.tmp|topic-views(?:-processing-[a-f0-9]{64})?/[0-9]+\.cache|(?:inspect|install)-[a-f0-9]+/.+)$~', $relative)) {
            if (@unlink($item->getPathname())) $removed++;
        } elseif ($item->isFile() && $item->getMTime() < $cacheValueCutoff && preg_match('~^(?:[a-f0-9]{64}\.cache|active-lock-[a-f0-9]{3,64}\.lock)$~', $relative)) {
            // Cache keys are content-addressed; no active cache uses a value older
            // than two days, while long-lived asset hashes are rebuilt on demand.
            if (@unlink($item->getPathname())) $removed++;
        }
    }
}
echo "Deleted {$sessions} sessions, {$limits} rate limits, {$rejected} rejected packages, {$outbox} processed outbox rows, {$failedOutbox} failed outbox rows, {$viewFlushBatches} topic view batch rows, {$audit} audit rows, {$notifications} read notifications, {$notificationOutbox} notification outbox rows, {$messages} private messages, {$extensionJobs} completed extension jobs, {$extensionFailures} extension job failures and {$removed} stale cache files.\n";
