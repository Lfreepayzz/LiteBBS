<?php
declare(strict_types=1);

function handle_post_edit(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$postInput = $request->post;
if (!preg_match('~^/p/(\d+)/edit$~', $path, $match) || !in_array($method, ['GET', 'POST'], true)) return;

$user = current_user();
if (!$user) redirect('/login');

$post = h(
    'SELECT p.*,t.status topic_status,t.title topic_title,t.category_id,c.is_visible,(SELECT MIN(fp.id) FROM posts fp WHERE fp.topic_id=p.topic_id AND fp.status="normal") original_post_id FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.id=? AND p.status="normal"',
    [(int)$match[1]]
)->fetch();
if (!$post || $post['topic_status'] !== 'normal' || (!(int)$post['is_visible'] && $user['role'] !== 'admin')) fail(404, '帖子不存在');
if ((int)$post['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') fail(403, '无权编辑');

$isOriginalPost = (int)$post['id'] === (int)$post['original_post_id'];
$categories = $isOriginalPost
    ? h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll()
    : [];

if ($method === 'POST') {
    require_post();
    $body = trim((string)($postInput['body'] ?? ''));
    $revision = (int)($postInput['revision'] ?? 0);
    if (text_length($body) < 1 || text_length($body) > 12000 || $revision < 1) fail(400, '回复内容或版本无效');

    $title = (string)$post['topic_title'];
    $categoryId = (int)$post['category_id'];
    if ($isOriginalPost) {
        $title = trim((string)($postInput['title'] ?? ''));
        $categoryId = (int)($postInput['category'] ?? 0);
        $categoryIds = array_map('intval', array_column($categories, 'id'));
        if (text_length($title) < 4 || text_length($title) > 120 || !in_array($categoryId, $categoryIds, true)) fail(400, '标题或分区无效');
    }

    $oldCategoryId = (int)$post['category_id'];
    $bodyHtml = extension_render_post_body($body, ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'kind'=>'edit']);
    $eventPost = $postInput;
    $eventPost['body'] = $body;
    \LiteBBS\Application\transaction_manager()->run(static function () use ($post, $user, $isOriginalPost, $categoryId, $oldCategoryId, $title, $body, $bodyHtml, $eventPost, $revision): void {
        $topic = h('SELECT category_id FROM topics WHERE id=? AND status="normal" FOR UPDATE', [$post['topic_id']])->fetch();
        if (!$topic) fail(404, '帖子不存在');
        if ((int)$topic['category_id'] !== $oldCategoryId) fail(409, '主题分区已变化，请刷新后重试');
        if ($user['role'] !== 'admin' && !h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE', [$oldCategoryId])->fetchColumn()) {
            fail(404, '帖子不存在');
        }
        if ($isOriginalPost) {
            if (!h('SELECT id FROM categories WHERE id=? AND is_visible=1 LOCK IN SHARE MODE', [$categoryId])->fetch()) fail(400, '分区已隐藏或无效');
            h('UPDATE topics SET title=?,slug=?,category_id=? WHERE id=? AND status="normal"', [$title,slug($title),$categoryId,$post['topic_id']]);
        }
        h('INSERT INTO post_revisions(post_id,editor_id,body_markdown,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$post['id'],$user['id'],$post['body_markdown']]);
        $updated = h(
            'UPDATE posts SET body_markdown=?,body_html=?,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND revision=? AND status="normal"',
            [$body,$bodyHtml,$post['id'],$revision]
        );
        if ($updated->rowCount() !== 1) {
            fail(409, '帖子已被其他人修改，请刷新后重试');
        }
        audit('post.edited', 'post', (int)$post['id']);
        if ($isOriginalPost && ($title !== (string)$post['topic_title'] || $categoryId !== $oldCategoryId)) {
            audit('topic.edited', 'topic', (int)$post['topic_id'], ['old_category_id'=>$oldCategoryId,'category_id'=>$categoryId]);
            $topicUpdatedEvent = [
                'topic_id'=>(int)$post['topic_id'],
                'actor_id'=>(int)$user['id'],
                'title'=>$title,
                'category_id'=>$categoryId,
                'previous_title'=>(string)$post['topic_title'],
                'previous_category_id'=>$oldCategoryId,
            ];
            extension_enqueue_async_event('topic.updated.async', $topicUpdatedEvent);
        }
        extension_event_or_fail('post.updated', ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'post'=>$eventPost,'previous_body_markdown'=>(string)$post['body_markdown'],'user'=>$user]);
        extension_event_or_fail('post.updated.v2', ['topic_id'=>(int)$post['topic_id'],'post_id'=>(int)$post['id'],'actor_id'=>(int)$user['id'],'body'=>$body,'previous_body_markdown'=>(string)$post['body_markdown']]);
    });
    $categoryChanged = $isOriginalPost && $categoryId !== $oldCategoryId;
    $titleChanged = $isOriginalPost && $title !== (string)$post['topic_title'];
    invalidate_topic_read_cache((int)$post['topic_id'], $categoryId, $categoryChanged || $titleChanged);
    if ($categoryChanged) {
        invalidate_topic_listing_cache($oldCategoryId, true);
        invalidate_topic_listing_cache($categoryId, true);
    }
    redirect('/t/'.(int)$post['topic_id'].'#p'.(int)$post['id']);
}

$fields = '<input type="hidden" name="revision" value="'.(int)$post['revision'].'">';
if ($isOriginalPost) {
    $options = '';
    foreach ($categories as $category) {
        $selected = (int)$category['id'] === (int)$post['category_id'] ? ' selected' : '';
        $options .= '<option value="'.(int)$category['id'].'"'.$selected.'>'.e($category['title']).'</option>';
    }
    $fields .= '<label>标题<input name="title" minlength="4" maxlength="120" value="'.e($post['topic_title']).'" required></label>'.
        '<label>分区<select name="category" required>'.$options.'</select></label>';
}
$fields .= '<label>正文<textarea name="body" rows="12" maxlength="12000" required>'.e($post['body_markdown']).'</textarea></label>'.
    extension_slot('post.edit.fields', ['post'=>$post,'user'=>$user]).
    extension_slot('post.edit.form.after', ['post'=>$post,'user'=>$user]);
page('编辑帖子', form('/p/'.(int)$post['id'].'/edit', '编辑帖子', $fields));
return;
}
