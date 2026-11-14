<?php
declare(strict_types=1);

function handle_topic_reply(int $topicId, array $input, array $files): never {
    require_post();
    $user=current_user();
    if(!$user)redirect('/login');
    limit_action('reply',10,300,'user:'.$user['id']);
    enforce_post_interval((int)$user['id']);
    $body=trim((string)($input['body']??''));
    $replyTo=(int)($input['reply_to_post']??0);
    if($replyTo){
        $target=h('SELECT p.body_markdown,p.topic_id,u.username FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status="normal"',[$replyTo])->fetch();
        if(!$target||(int)$target['topic_id']!==$topicId)fail(400,'回复目标无效');
        $quoted=preg_split('/\R/u',(string)$target['body_markdown'])?:[];
        $body='回复 '.$target['username']."：\n> ".implode("\n> ",$quoted)."\n\n".$body;
    }
    if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $topic=h('SELECT t.id FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND t.is_locked=0 AND c.is_visible=1',[$topicId])->fetch();
        if(!$topic){ $pdo->rollBack();fail(404,'主题不存在、分区已隐藏或主题已锁定'); }
        h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$topic['id'],$user['id'],$body,render_md($body)]);
        $postId=(int)$pdo->lastInsertId();
        h('INSERT INTO notification_outbox(topic_id,post_id,actor_id,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$topicId,$postId,(int)$user['id']]);
        extension_event_sync('post.created', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id'], 'post'=>$input, 'files'=>$files, 'user'=>$user]);
        $updated=h('UPDATE topics SET reply_count=reply_count+1,last_post_at=UTC_TIMESTAMP(6) WHERE id=? AND status="normal" AND is_locked=0',[$topicId]);
        if($updated->rowCount()!==1){ $pdo->rollBack();fail(409,'主题状态已变化，请刷新后重试'); }
        enqueue_outbox_event('post.created.async', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id']]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    invalidate_topic_read_cache($topicId);
    redirect('/t/'.$topicId.'#p'.$postId);
}
