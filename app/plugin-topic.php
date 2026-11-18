<?php
declare(strict_types=1);

if ($path !== '/new' || !in_array($method, ['GET', 'POST'], true)) return;
$user = current_user();
if (!$user) redirect('/login');
$categories = h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll();
if ($method === 'POST') {
    require_post();
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $category = (int)($_POST['category'] ?? 0);
    $categoryIds = array_map('intval', array_column($categories, 'id'));
    if (text_length($title) < 4 || text_length($title) > 120 || text_length($body) < 5 || text_length($body) > 12000 || !in_array($category, $categoryIds, true)) fail(400, '标题、正文或分区无效');
    // Upload inspection runs before the transaction so extension file work does
    // not extend category and quota locks during topic creation.
    extension_event_sync('topic.create.preflight', ['post'=>$_POST, 'files'=>$_FILES, 'user'=>$user]);
    extension_event_sync('topic.create.preflight.v2', ['actor_id'=>(int)$user['id'],'title'=>$title,'body'=>$body,'category_id'=>$category,'uploads'=>extension_safe_uploads($_FILES)]);
    enforce_post_interval((int)$user['id']);
    enforce_content_rate_limit('topic', (int)$user['id']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // A shared lock prevents a concurrent hide without serializing topic
        // creators in the same visible category.
        $categoryRow = h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE', [$category])->fetch();
        if (!$categoryRow) {
            $pdo->rollBack();
            fail(400, '分区已隐藏或无效');
        }
        h('INSERT INTO topics(category_id,user_id,slug,title,created_at,last_post_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$category,$user['id'],slug($title),$title]);
        $topicId = (int)$pdo->lastInsertId();
        h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))', [$topicId,$user['id'],$body,extension_render_post_body($body, ['topic_id'=>$topicId,'actor_id'=>(int)$user['id'],'kind'=>'topic'])]);
        $postId = (int)$pdo->lastInsertId();
        if (topic_last_post_pointer_available()) h('UPDATE topics SET last_post_id=? WHERE id=?', [$postId,$topicId]);
        if (topic_participants_available()) h('INSERT INTO topic_participants(topic_id,user_id,last_post_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_post_id=GREATEST(last_post_id,VALUES(last_post_id))', [$topicId,$user['id'],$postId]);
        h('INSERT INTO notification_outbox(topic_id,post_id,actor_id,pending_topic_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE created_at=IF(VALUES(post_id)>post_id,VALUES(created_at),created_at),post_id=GREATEST(post_id,VALUES(post_id))', [$topicId,$postId,(int)$user['id'],$topicId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    extension_event('topic.created', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id'], 'post'=>$_POST, 'files'=>$_FILES, 'user'=>$user]);
    extension_event('topic.created.v2', ['topic_id'=>$topicId,'post_id'=>$postId,'actor_id'=>(int)$user['id'],'title'=>$title,'body'=>$body,'category_id'=>$category,'uploads'=>extension_safe_uploads($_FILES)]);
    extension_event_async('topic.created.async', ['topic_id'=>$topicId, 'post_id'=>$postId, 'actor_id'=>(int)$user['id']]);
    invalidate('topic:'.$topicId);
    invalidate_topic_listing_cache($category, true);
    redirect('/t/'.$topicId);
}
$options = '';
foreach ($categories as $category) $options .= '<option value="'.(int)$category['id'].'">'.e($category['title']).'</option>';
    page('发布主题','<form class="form" method="post" action="/new" enctype="multipart/form-data"><h1>发布一个主题</h1><input type="hidden" name="csrf" value="'.e(csrf()).'"><label>标题<input name="title" minlength="4" maxlength="120" required></label><label>分区<select name="category">'.$options.'</select></label><label>正文<textarea name="body" rows="12" maxlength="12000" required></textarea></label>'.extension_slot('topic.create.fields', ['user'=>$user]).'<button class="primary">发布主题</button></form>');
return;
