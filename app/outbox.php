<?php
declare(strict_types=1);

function enqueue_outbox_event(string $name, array $payload, int $delaySeconds = 0): void
{
    if (!preg_match('/^[a-z][a-z0-9._-]{1,95}$/', $name)) throw new InvalidArgumentException('Invalid outbox event name.');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // Each committed domain action is an event. Reusing a payload must not
    // suppress a later action from the same user or aggregate.
    $key = hash('sha256', $name.'|'.bin2hex(random_bytes(32)));
    h('INSERT IGNORE INTO outbox_events(event_name,event_key,payload_json,available_at,created_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),UTC_TIMESTAMP(6))', [$name, $key, $json, $delaySeconds]);
}

function process_outbox_events(int $limit = 100): int
{
    $processed = 0;
    $db = db();
    for ($i = 0; $i < $limit; $i++) {
        $lockAcquired = false;
        $db->beginTransaction();
        try {
            // Prefer never-leased work. Keeping expired leases in a second query
            // preserves the queue contract while letting MySQL use a narrow index.
            $event = h('SELECT id,event_name,event_key,payload_json,attempts FROM outbox_events WHERE processed_at IS NULL AND lease_until IS NULL AND available_at<=UTC_TIMESTAMP(6) ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$event) $event = h('SELECT id,event_name,event_key,payload_json,attempts FROM outbox_events WHERE processed_at IS NULL AND lease_until IS NOT NULL AND lease_until<=UTC_TIMESTAMP(6) AND available_at<=UTC_TIMESTAMP(6) ORDER BY lease_until,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$event) { $db->rollBack(); break; }
            $id = (string)$event['id'];
            // Check the advisory lock before replacing an expired lease. The
            // previous worker can still finish while it holds this lock.
            $lockName = 'litebbs:outbox:'.$id;
            $lockResult = (string)h('SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn();
            if ($lockResult === '0') {
                h('UPDATE outbox_events SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 5 SECOND) WHERE id=?', [$id]);
                $db->commit();
                continue;
            }
            if ($lockResult !== '1') throw new RuntimeException('Could not acquire outbox advisory lock.');
            $lockAcquired = true;
            $leaseToken = bin2hex(random_bytes(32));
            // Long enough for the worker timeout; consumers must still make side effects idempotent.
            h('UPDATE outbox_events SET attempts=attempts+1,lease_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 300 SECOND) WHERE id=?', [$leaseToken, $id]);
            $attempt = (int)$event['attempts'] + 1;
            $db->commit();
            try {
                $name = (string)$event['event_name'];
                $payload = json_decode((string)$event['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new RuntimeException('Outbox payload must be an object.');
                extension_validate_async_event_payload($name, $payload);
                $payload['_outbox_event_key'] = (string)$event['event_key'];
                extension_runtime_load(null, 'event', $name);
                $listeners = extension_registry()['events'][$name] ?? [];
                $checked = [];
                foreach ($listeners as $entry) {
                    $plugin = $entry['plugin'];
                    if (isset($checked[$plugin])) continue;
                    $checked[$plugin] = true;
                    if (!extension_plugin_degraded($plugin, true)) continue;
                    // Wait out the health cooldown before invoking any listener.
                    // A cooldown is not a delivery attempt.
                    h('UPDATE outbox_events SET attempts=attempts-1,available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', ['Plugin event '.$plugin.' deferred during health cooldown.', $id, $leaseToken]);
                    continue 2;
                }
                foreach ($listeners as $entry) {
                    try {
                        ($entry['listener'])($payload);
                    } catch (Throwable $listenerError) {
                        extension_plugin_failure($entry['plugin']);
                        throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $listenerError);
                    }
                }
                if (h("UPDATE outbox_events SET processed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error='' WHERE id=? AND lease_token=?", [$id, $leaseToken])->rowCount() === 1) $processed++;
            } catch (Throwable $error) {
                $message = text_slice($error->getMessage(), 0, 500);
                if ($attempt >= 10) {
                    $db->beginTransaction();
                    if (h('UPDATE outbox_events SET processed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, $id, $leaseToken])->rowCount() === 1) {
                        h('INSERT IGNORE INTO failed_outbox_events(event_id,event_name,event_key,payload_json,attempts,last_error,failed_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$id, $event['event_name'], $event['event_key'], $event['payload_json'], $attempt, $message]);
                    }
                    $db->commit();
                } else {
                    h('UPDATE outbox_events SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL LEAST(300,POW(2,attempts)) SECOND),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, $id, $leaseToken]);
                }
                error_log('outbox event delivery failed: '.$message);
            }
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } finally {
            if ($lockAcquired) h('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
    return $processed;
}
