<?php
declare(strict_types=1);

function process_notification_outbox(int $limit = 1): int {
    $db=db();
    $processed=0;
    $limit=max(1,min(100,$limit));
    while($processed<$limit){
        $db->beginTransaction();
        try{
            $event=h('SELECT id,topic_id,post_id,actor_id FROM notification_outbox WHERE processed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if(!$event){$db->commit();break;}
            h('INSERT INTO notifications(user_id,actor_id,topic_id,post_id,created_at) SELECT recipients.user_id,?,?,?,UTC_TIMESTAMP(6) FROM (SELECT user_id FROM topic_subscriptions WHERE topic_id=? UNION SELECT user_id FROM topics WHERE id=? UNION SELECT user_id FROM posts WHERE id=?) recipients WHERE recipients.user_id<>?',[(int)$event['actor_id'],(int)$event['topic_id'],(int)$event['post_id'],(int)$event['topic_id'],(int)$event['topic_id'],(int)$event['post_id'],(int)$event['actor_id']]);
            h('UPDATE notification_outbox SET processed_at=UTC_TIMESTAMP(6) WHERE id=?',[(int)$event['id']]);
            $db->commit();
            $processed++;
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    return $processed;
}
