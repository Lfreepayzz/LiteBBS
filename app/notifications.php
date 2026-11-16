<?php
declare(strict_types=1);

function process_notification_outbox(int $limit = 1): int {
    $db=db();
    $batches=0;
    $limit=max(1,min(100,$limit));
    while($batches<$limit){
        $db->beginTransaction();
        try{
            $event=h('SELECT id,topic_id,post_id,actor_id,fanout_cursor FROM notification_outbox WHERE processed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if(!$event){$db->commit();break;}
            h('UPDATE notification_outbox SET pending_topic_id=NULL WHERE id=?',[(int)$event['id']]);
            // Fan out to subscribers, the topic author, and every prior participant.
            // The cursor is a user ID so batches remain deterministic across workers.
            $recipients=h('SELECT user_id FROM (SELECT user_id FROM topic_subscriptions WHERE topic_id=? AND user_id>? UNION SELECT user_id FROM topics WHERE id=? AND user_id>? UNION SELECT user_id FROM posts WHERE topic_id=? AND status="normal" AND user_id>?) candidates WHERE user_id<>? ORDER BY user_id LIMIT 501',[(int)$event['topic_id'],(int)$event['fanout_cursor'],(int)$event['topic_id'],(int)$event['fanout_cursor'],(int)$event['topic_id'],(int)$event['fanout_cursor'],(int)$event['actor_id']])->fetchAll(PDO::FETCH_COLUMN);
            $hasMore=count($recipients)>500;
            if($hasMore)array_pop($recipients);
            if($recipients){
                $tuples=implode(',',array_fill(0,count($recipients),'(?,?,?,?,?,UTC_TIMESTAMP(6))'));
                $parameters=[];
                foreach($recipients as $userId)array_push($parameters,(int)$userId,(int)$event['actor_id'],(int)$event['topic_id'],(int)$event['topic_id'],(int)$event['post_id']);
                h('INSERT INTO notifications(user_id,actor_id,topic_id,coalesce_topic_id,post_id,created_at) VALUES '.$tuples.' ON DUPLICATE KEY UPDATE actor_id=IF(VALUES(post_id)>post_id,VALUES(actor_id),actor_id),created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))',$parameters);
                $nextCursor=(int)end($recipients);
                if($hasMore)h('UPDATE notification_outbox SET fanout_cursor=? WHERE id=?',[$nextCursor,(int)$event['id']]);
            }
            if(!$hasMore)h('UPDATE notification_outbox SET processed_at=UTC_TIMESTAMP(6) WHERE id=?',[(int)$event['id']]);
            $db->commit();
            $batches++;
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    return $batches;
}
