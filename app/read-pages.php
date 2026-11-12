<?php
declare(strict_types=1);

if ($method !== 'GET') return;

if (preg_match('~^/t/(\d+)(?:-[^/]+)?$~', $path, $match)) {
    $id = (int)$match[1];
    if ($path !== '/t/'.$id) redirect('/t/'.$id, 301);

    $topic = h('SELECT t.*,c.id category_id,c.title category,c.slug category_slug,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1', [$id])->fetch();
    if (!$topic) fail(404, '主题不存在');

    $pageNo = max(1, (int)($_GET['page'] ?? 1));
    $pages = max(1, (int)ceil(((int)$topic['reply_count'] + 1) / 20));
    if ($pageNo > $pages) fail(404, '页面不存在');
    $user = current_user();
    $version = cache_version('topics');
    $key = 'topic:v2:'.$id.':'.$pageNo.':'.$version;
    $body = !$user ? read_cache($key, 30) : null;

    if ($body === null) {
        $rows = h('SELECT p.id,p.user_id,p.body_html,p.created_at,u.username FROM posts p JOIN users u ON u.id=p.user_id WHERE p.topic_id=? AND p.status="normal" ORDER BY p.id LIMIT 20 OFFSET '.(($pageNo - 1) * 20), [$id])->fetchAll();
        $createdAt = (string)$topic['created_at'];
        $lastPostAt = (string)$topic['last_post_at'];
        $body = '<header class="topic-page-heading"><div class="topic-heading-topline"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a><span>'.(!empty($topic['is_locked'])?'已锁定':'开放讨论').'</span></div><h1>'.e($topic['title']).'</h1><div class="topic-page-meta"><a href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time datetime="'.e(str_replace(' ', 'T', substr($createdAt, 0, 19))).'">发布于 '.e(substr($createdAt, 0, 16)).'</time><span class="topic-meta-count">'.(int)$topic['reply_count'].' 条回复</span>'.($topic['reply_count']?'<time datetime="'.e(str_replace(' ', 'T', substr($lastPostAt, 0, 19))).'">最近更新 '.e(substr($lastPostAt, 0, 16)).'</time>':'').'</div></header><section class="topic-thread" id="replies">';
        $floor = ($pageNo - 1) * 20;
        foreach ($rows as $post) {
            $floor++;
            $kind = $floor === 1 ? 'post-original' : 'post-reply';
            if ($floor === 2 || ($pageNo > 1 && $floor === ($pageNo - 1) * 20 + 1)) $body .= '<h2 class="replies-heading">回复 <span>'.(int)$topic['reply_count'].'</span></h2>';
            $postCreatedAt = (string)$post['created_at'];
            $body .= '<article class="post '.$kind.'" id="p'.(int)$post['id'].'"><div class="reply-author"><span class="reply-avatar" aria-hidden="true">'.e(text_slice($post['username'], 0, 1)).'</span><div class="reply-author-meta"><a href="/u/'.rawurlencode($post['username']).'">'.e($post['username']).'</a>'.($floor === 1 ? '<span class="author-role">楼主</span>' : '').'<time datetime="'.e(str_replace(' ', 'T', substr($postCreatedAt, 0, 19))).'">'.e(substr($postCreatedAt, 0, 16)).'</time></div><a class="reply-floor" href="#p'.(int)$post['id'].'" aria-label="第 '.$floor.' 楼">#'.$floor.'</a></div><div class="body">'.$post['body_html'].'</div>';
            if ($user && ((int)$user['id'] === (int)$post['user_id'] || $user['role'] === 'admin')) $body .= '<div class="reply-actions"><a href="/p/'.(int)$post['id'].'/edit">编辑</a></div>';
            $body .= '</article>';
        }
        $body .= '</section>';
        $body .= pagination($pageNo, $pages);
        if (!empty($topic['is_locked'])) $body .= '<p class="locked-note">该主题已锁定，暂不能回复。</p>';
        elseif ($user) $body .= '<section class="reply-composer" aria-labelledby="reply-composer-title"><div class="reply-composer-heading"><div><p class="eyebrow">YOUR REPLY</p><h2 id="reply-composer-title">参与讨论</h2></div><span>以 '.e($user['username']).' 的身份回复</span></div><form method="post" action="/t/'.$id.'/reply"><input type="hidden" name="csrf" value="'.e(csrf()).'"><label class="reply-editor" for="reply-body"><span>回复内容</span><textarea id="reply-body" name="body" maxlength="12000" rows="7" placeholder="写下清晰、友善且有帮助的回复…" required></textarea></label><div class="reply-composer-actions"><small>支持换行纯文本，最多 12,000 字</small><button class="primary">发布回复</button></div></form></section>';
        if (!$user) write_cache($key, [$body]);
    } else {
        $body = $body[0] ?? '';
    }

    count_topic_view($id);
    page($topic['title'], $body, '讨论主题：'.$topic['title'], true);
    exit;
}

if (preg_match('~^/c/(\d+)$~', $path, $match)) {
    $id = (int)$match[1];
    $category = h('SELECT id,title,description FROM categories WHERE id=? AND is_visible=1', [$id])->fetch();
    if (!$category) fail(404, '分区不存在');

    $pageNo = max(1, (int)($_GET['page'] ?? 1));
    $topicCount = cached_topic_count('category',$id);
    $pages = max(1, (int)ceil($topicCount / 30));
    if ($pageNo > $pages || $pageNo > 100000) fail(404, '页面不存在');
    $key = 'category:v1:'.$id.':'.$pageNo.':'.cache_version('topics').':'.cache_version('categories');
    $rows = cached($key, 60, static function () use ($id, $pageNo): array {
        return h('SELECT t.id,t.title,t.reply_count,t.last_post_at,t.is_pinned,u.username FROM topics t JOIN users u ON u.id=t.user_id WHERE t.category_id=? AND t.status="normal" ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 30 OFFSET '.(($pageNo - 1) * 30), [$id])->fetchAll();
    });

    $body = '<p class="eyebrow">SPACE / '.e($category['title']).'</p><h1>'.e($category['title']).'</h1><p>'.e($category['description']).'</p>';
    foreach ($rows as $topic) $body .= '<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'"><div class="topic-feed-main"><div class="topic-feed-meta">'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'</div><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><p><a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time>'.e(substr((string)$topic['last_post_at'],0,16)).'</time></p></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';
    $body .= pagination($pageNo, $pages);
    page($category['title'], $body, $category['description'], true);
    exit;
}
