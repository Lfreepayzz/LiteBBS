<?php
declare(strict_types=1);

function handle_topic_reply(\LiteBBS\Framework\Request $request, int $topicId): never {
    require_post();
    $input = $request->post;
    $files = $request->files;
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
    enforce_content_rate_limit('reply',(int)$user['id']);
    $topic=h('SELECT t.id,t.category_id,t.user_id FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND t.is_locked=0 AND c.is_visible=1',[$topicId])->fetch();
    if(!$topic)fail(404,'主题不存在、分区已隐藏或主题已锁定');
    $bodyHtml=extension_render_post_body($body, ['topic_id'=>$topicId,'actor_id'=>(int)$user['id'],'kind'=>'reply']);
    $hasLastPostPointer = topic_last_post_pointer_available();
    $hasParticipants = topic_participants_available();
    $uploads = extension_safe_uploads($files);
    [$topic, $postId, $topicAuthorId] = \LiteBBS\Application\transaction_manager()->run(static function (PDO $pdo) use ($topicId, $user, $body, $bodyHtml, $input, $files, $uploads, $hasLastPostPointer, $hasParticipants): array {
        enforce_post_interval((int)$user['id'], true);
        $topic=h('SELECT t.id,t.category_id,t.user_id FROM topics t WHERE t.id=? AND t.status="normal" AND t.is_locked=0',[$topicId])->fetch();
        if(!$topic)fail(404,'主题不存在、分区已隐藏或主题已锁定');
        if(!h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE',[(int)$topic['category_id']])->fetch()) fail(404,'主题不存在、分区已隐藏或主题已锁定');
        h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$topic['id'],$user['id'],$body,$bodyHtml]);
        $postId=(int)$pdo->lastInsertId();
        $topicAuthorId=(int)$topic['user_id'];
        if($topicAuthorId!==(int)$user['id'])h('INSERT INTO notifications(user_id,actor_id,topic_id,post_id,kind,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',[$topicAuthorId,(int)$user['id'],$topicId,$postId,'reply']);
        if ($hasParticipants) h('INSERT INTO topic_participants(topic_id,user_id,last_post_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_post_id=GREATEST(last_post_id,VALUES(last_post_id))', [$topicId,$user['id'],$postId]);
        h('INSERT INTO notification_outbox(topic_id,post_id,actor_id,pending_topic_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE actor_id=IF(VALUES(post_id)>post_id,VALUES(actor_id),actor_id),created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))',[$topicId,$postId,(int)$user['id'],$topicId]);
        $updated=$hasLastPostPointer
            ? h('UPDATE topics SET reply_count=reply_count+1,last_post_id=GREATEST(COALESCE(last_post_id,0),?),last_post_at=UTC_TIMESTAMP(6) WHERE id=? AND category_id=? AND status="normal" AND is_locked=0',[$postId,$topicId,(int)$topic['category_id']])
            : h('UPDATE topics SET reply_count=reply_count+1,last_post_at=UTC_TIMESTAMP(6) WHERE id=? AND category_id=? AND status="normal" AND is_locked=0',[$topicId,(int)$topic['category_id']]);
        if($updated->rowCount()!==1)fail(409,'主题状态已变化，请刷新后重试');
        extension_event_or_fail('post.created', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id'], 'post'=>$input, 'files'=>$files, 'user'=>$user]);
        extension_event_or_fail('post.created.v2', ['topic_id'=>$topicId,'post_id'=>$postId,'actor_id'=>(int)$user['id'],'body'=>$body,'uploads'=>$uploads]);
        extension_enqueue_async_event('post.created.async', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id']]);
        return [$topic, $postId, $topicAuthorId];
    });
    if($topicAuthorId!==(int)$user['id'])invalidate_user_cache($topicAuthorId);
    // Feed caches have a bounded TTL. Avoid invalidating every public listing
    // for each reply; only the changed topic page needs immediate freshness.
    invalidate_topic_read_cache($topicId, (int)$topic['category_id'], false, false);
    redirect('/t/'.$topicId.'#p'.$postId);
}
