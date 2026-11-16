<?php
declare(strict_types=1);

function handle_topic_reply(int $topicId, array $input, array $files): never {
    require_post();
    $user=current_user();
    if(!$user)redirect('/login');
    $body=trim((string)($input['body']??''));
    $replyTo=(int)($input['reply_to_post']??0);
    if($replyTo){
        $target=h('SELECT p.body_markdown,p.topic_id,u.username FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status="normal"',[$replyTo])->fetch();
        if(!$target||(int)$target['topic_id']!==$topicId)fail(400,'回复目标无效');
        $quoted=preg_split('/\R/u',(string)$target['body_markdown'])?:[];
        $body='回复 '.$target['username']."：\n> ".implode("\n> ",$quoted)."\n\n".$body;
    }
    if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');
    enforce_post_interval((int)$user['id']);
    enforce_content_rate_limit('reply',(int)$user['id']);
    $topic=h('SELECT t.id,t.category_id FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND t.is_locked=0 AND c.is_visible=1',[$topicId])->fetch();
    if(!$topic)fail(404,'主题不存在、分区已隐藏或主题已锁定');
    $bodyHtml=extension_render_post_body($body, ['topic_id'=>$topicId,'actor_id'=>(int)$user['id'],'kind'=>'reply']);
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $topic=h('SELECT t.id,t.category_id FROM topics t WHERE t.id=? AND t.status="normal" AND t.is_locked=0',[$topicId])->fetch();
        if(!$topic){ $pdo->rollBack();fail(404,'主题不存在、分区已隐藏或主题已锁定'); }
        if(!h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE',[(int)$topic['category_id']])->fetch()) { $pdo->rollBack();fail(404,'主题不存在、分区已隐藏或主题已锁定'); }
        h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$topic['id'],$user['id'],$body,$bodyHtml]);
        $postId=(int)$pdo->lastInsertId();
        h('INSERT INTO notification_outbox(topic_id,post_id,actor_id,pending_topic_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))',[$topicId,$postId,(int)$user['id'],$topicId]);
        extension_event_sync('post.created', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id'], 'post'=>$input, 'files'=>$files, 'user'=>$user]);
        extension_event_sync('post.created.v2', ['topic_id'=>$topicId,'post_id'=>$postId,'actor_id'=>(int)$user['id'],'body'=>$body,'uploads'=>extension_safe_uploads($files)]);
        extension_event_async('post.created.async', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id']]);
        $updated=h('UPDATE topics SET reply_count=reply_count+1,last_post_at=UTC_TIMESTAMP(6) WHERE id=? AND status="normal" AND is_locked=0',[$topicId]);
        if($updated->rowCount()!==1){ $pdo->rollBack();fail(409,'主题状态已变化，请刷新后重试'); }
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    invalidate_topic_read_cache($topicId, (int)$topic['category_id']);
    redirect('/t/'.$topicId.'#p'.$postId);
}
