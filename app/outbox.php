<?php
declare(strict_types=1);

function enqueue_outbox_event(string $name, array $payload, int $delaySeconds = 0): void
{
    if (!preg_match('/^[a-z][a-z0-9._-]{1,95}$/', $name)) throw new InvalidArgumentException('Invalid outbox event name.');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $key = hash('sha256', $name.'|'.$json);
    h('INSERT IGNORE INTO outbox_events(event_name,event_key,payload_json,available_at,created_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),UTC_TIMESTAMP(6))', [$name, $key, $json, $delaySeconds]);
}

function process_outbox_events(int $limit = 100): int
{
    $processed = 0;
    $db = db();
    for ($i = 0; $i < $limit; $i++) {
        $db->beginTransaction();
        try {
            $event = h('SELECT id,event_name,event_key,payload_json,attempts FROM outbox_events WHERE processed_at IS NULL AND available_at<=UTC_TIMESTAMP(6) AND (lease_until IS NULL OR lease_until<=UTC_TIMESTAMP(6)) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$event) { $db->rollBack(); break; }
            $leaseToken = bin2hex(random_bytes(32));
            // Long enough for the worker timeout; consumers must still make side effects idempotent.
            h('UPDATE outbox_events SET attempts=attempts+1,lease_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 300 SECOND) WHERE id=?', [$leaseToken, (int)$event['id']]);
            $event['attempts'] = (int)$event['attempts'] + 1;
            $db->commit();
            try {
                $payload=json_decode((string)$event['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) throw new RuntimeException('Outbox payload must be an object.');
                $payload['_outbox_event_key']=(string)$event['event_key'];
                extension_event_or_fail((string)$event['event_name'], $payload);
                if (h('UPDATE outbox_events SET processed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL WHERE id=? AND lease_token=?', [(int)$event['id'], $leaseToken])->rowCount() === 1) $processed++;
            } catch (Throwable $error) {
                $message = text_slice($error->getMessage(), 0, 500);
                if ((int)$event['attempts'] >= 10) {
                    h('INSERT IGNORE INTO failed_outbox_events(event_id,event_name,event_key,payload_json,attempts,last_error,failed_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [(int)$event['id'], (string)$event['event_name'], (string)$event['event_key'], (string)$event['payload_json'], (int)$event['attempts'], $message]);
                    h('UPDATE outbox_events SET processed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, (int)$event['id'], $leaseToken]);
                } else h('UPDATE outbox_events SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL LEAST(300,POW(2,attempts)) SECOND),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, (int)$event['id'], $leaseToken]);
                error_log('outbox event deferred: '.$error->getMessage());
            }
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    return $processed;
}
