<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/core.php';
if (!is_file(config_file())) exit("Private configuration is missing.\n");
$redis = redis_cache_client();
if (!$redis) exit("Redis is required for topic view aggregation.\n");
$key = shared_cache_key('topic-views');
$processing = shared_cache_key('topic-views:processing');
$lock = shared_cache_key('topic-views:flush-lock');
$lockToken = bin2hex(random_bytes(16));
if (!$redis->set($lock, $lockToken, ['nx', 'ex' => 300])) exit("Another topic view flush is running.\n");
try {
    // Keep one stable processing key so a killed worker can resume the batch.
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
$db = db();
$statement = $db->prepare('UPDATE topics SET view_count=view_count+? WHERE id=?');
$flushed = 0;
    $db->beginTransaction();
    $batch = (string)($views['_batch_key'] ?? '');
    unset($views['_batch_key']);
    if (!preg_match('/^[a-f0-9]{64}$/', $batch)) throw new RuntimeException('Topic view batch metadata is invalid.');
    $alreadyCommitted = h('SELECT batch_key FROM topic_view_flush_batches WHERE batch_key=? FOR UPDATE', [$batch])->fetchColumn();
    if ($alreadyCommitted !== false) {
        $db->commit();
        $redis->del($processing);
        fwrite(STDOUT, "Topic view batch was already committed.\n");
        return;
    }
    foreach ($views as $id=>$count) { if ((int)$id > 0 && (int)$count > 0) { $statement->execute([(int)$count, (int)$id]); $flushed += (int)$count; } }
    h('INSERT INTO topic_view_flush_batches(batch_key,flushed_at) VALUES(?,UTC_TIMESTAMP(6))', [$batch]);
    $db->commit();
    // If Redis is temporarily unavailable, leave the committed batch marker
    // and processing hash for the next run; never add it back to the source.
    $redis->del($processing);
    fwrite(STDOUT, "Flushed {$flushed} topic views.\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
} finally {
    try { $redis->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0", [$lock, $lockToken], 1); }
    catch (Throwable $error) { error_log('topic view flush lock release failed: '.$error->getMessage()); }
}
