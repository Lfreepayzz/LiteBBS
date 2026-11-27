<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__).'/app/kernel.php';
if (!is_file(config_file())) exit("Database configuration is missing.\n");

$redis = redis_cache_client();
$db = null;
$flushed = 0;

if ($redis) {
    $key = shared_cache_key('topic-views');
    $processing = shared_cache_key('topic-views:processing');
    $lock = shared_cache_key('topic-views:flush-lock');
    $lockToken = bin2hex(random_bytes(16));
    if (!$redis->set($lock, $lockToken, ['nx', 'ex' => 300])) exit("Another topic view flush is running.\n");
    try {
        if (!$redis->exists($processing)) {
            $claim = $redis->eval(
                "if redis.call('EXISTS', KEYS[2]) == 1 then return 2 end " .
                "if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end " .
                "redis.call('RENAME', KEYS[1], KEYS[2]); " .
                "redis.call('HSET', KEYS[2], '_batch_key', ARGV[1]); return 1",
                [$key, $processing, bin2hex(random_bytes(32))],
                2
            );
            if ((int)$claim === 0) { fwrite(STDOUT, "No topic views to flush.\n"); return; }
        }
        $views = $redis->hGetAll($processing);
        $batch = (string)($views['_batch_key'] ?? '');
        unset($views['_batch_key']);
        $db = db();
        $db->beginTransaction();
        if (!preg_match('/^[a-f0-9]{64}$/', $batch)) throw new RuntimeException('Topic view batch metadata is invalid.');
        $alreadyCommitted = h('SELECT batch_key FROM topic_view_flush_batches WHERE batch_key=? FOR UPDATE', [$batch])->fetchColumn();
        if ($alreadyCommitted === false) {
            $statement = $db->prepare('UPDATE topics AS t JOIN categories AS c ON c.id=t.category_id SET t.view_count=t.view_count+? WHERE t.id=? AND t.status="normal" AND c.is_visible=1');
            foreach ($views as $id=>$count) if ((int)$id > 0 && (int)$count > 0) { $statement->execute([(int)$count, (int)$id]); $flushed += (int)$count; }
            h('INSERT INTO topic_view_flush_batches(batch_key,flushed_at) VALUES(?,UTC_TIMESTAMP(6))', [$batch]);
        }
        $db->commit();
        $redis->del($processing);
    } catch (Throwable $error) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        throw $error;
    } finally {
        try { $redis->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0", [$lock, $lockToken], 1); }
        catch (Throwable $error) { error_log('topic view flush lock release failed: '.$error->getMessage()); }
    }
} else {
    $cache = dirname(__DIR__).'/cache';
    $current = $cache.'/topic-views';
    if (!is_dir($current) && !@mkdir($current, 0700, true) && !is_dir($current)) exit("Topic view cache is unavailable.\n");
    $processing = null;
    foreach (glob($cache.'/topic-views-processing-*', GLOB_ONLYDIR) ?: [] as $candidate) { $processing = $candidate; break; }
    if ($processing === null) {
        $lock = @fopen($cache.'/topic-views.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) exit("Topic view cache lock is unavailable.\n");
        try {
            $files = glob($current.'/*.cache') ?: [];
            if (!$files) { fwrite(STDOUT, "No topic views to flush.\n"); return; }
            $processing = $cache.'/topic-views-processing-'.bin2hex(random_bytes(32));
            if (!@rename($current, $processing) || (!@mkdir($current, 0700, true) && !is_dir($current))) throw new RuntimeException('Cannot claim local topic view batch.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    $batch = substr((string)strrchr($processing, '-'), 1);
    $views = [];
    foreach (glob($processing.'/*.cache') ?: [] as $file) {
        $id = (int)basename($file, '.cache');
        $count = (int)@filesize($file);
        if ($id > 0 && $count > 0) $views[$id] = $count;
    }
    try {
        $db = db();
        $db->beginTransaction();
        if (!preg_match('/^[a-f0-9]{64}$/', $batch)) throw new RuntimeException('Local topic view batch metadata is invalid.');
        $alreadyCommitted = h('SELECT batch_key FROM topic_view_flush_batches WHERE batch_key=? FOR UPDATE', [$batch])->fetchColumn();
        if ($alreadyCommitted === false) {
            $statement = $db->prepare('UPDATE topics AS t JOIN categories AS c ON c.id=t.category_id SET t.view_count=t.view_count+? WHERE t.id=? AND t.status="normal" AND c.is_visible=1');
            foreach ($views as $id=>$count) { $statement->execute([$count, $id]); $flushed += $count; }
            h('INSERT INTO topic_view_flush_batches(batch_key,flushed_at) VALUES(?,UTC_TIMESTAMP(6))', [$batch]);
        }
        $db->commit();
        remove_tree($processing);
    } catch (Throwable $error) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

fwrite(STDOUT, "Flushed {$flushed} topic views.\n");
