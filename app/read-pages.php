<?php
declare(strict_types=1);
require_once __DIR__.'/read-model.php';

if ($method !== 'GET') return;

if (preg_match('~^/t/(\d+)(?:-[^/]+)?$~', $path, $match)) {
    $id = (int)$match[1];
    if ($path !== '/t/'.$id) redirect('/t/'.$id, 301);

    $topic = h('SELECT t.*,c.id category_id,c.title category,c.slug category_slug,u.username,(SELECT MIN(p.id) FROM posts p WHERE p.topic_id=t.id AND p.status="normal") original_post_id FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1', [$id])->fetch();
    if (!$topic) fail(404, '主题不存在');

    $pageNo = max(1, (int)($_GET['page'] ?? 1));
    $afterId = max(0, (int)($_GET['after'] ?? 0));
    // New cursor links carry the last displayed floor, avoiding a growing
    // COUNT(*) scan for every subsequent page of a long topic. Keep the
    // count fallback for existing bookmarks and manually shared links.
    $afterFloor = $afterId > 0 ? min((int)$topic['reply_count'] + 1, max(0, (int)($_GET['floor'] ?? 0))) : 0;
    $pages = max(1, (int)ceil(((int)$topic['reply_count'] + 1) / 20));
    if ($pageNo > $pages) fail(404, '页面不存在');
    if ($pageNo > 1 && $afterId === 0) fail(400, '分页方式已更新，请从主题首页使用游标继续浏览');
    $user = current_user();
    $versions = cache_versions(['topic:'.$id,'extension-read-pages','categories','plugins']);
    $key = 'topic:v8:'.$id.':'.$pageNo.':'.$afterId.':'.(int)$versions['topic:'.$id].':'.(int)$versions['extension-read-pages'];
    $body = !$user && $afterId === 0 ? read_cache($key, 30) : null;

    if ($body === null) {
        if ($afterId > 0) {
            $rows = h('SELECT p.id,p.user_id,p.body_markdown,p.body_html,p.created_at,u.username FROM posts p JOIN users u ON u.id=p.user_id WHERE p.topic_id=? AND p.status="normal" AND p.id>? ORDER BY p.id LIMIT 21', [$id,$afterId])->fetchAll();
            $hasMore = count($rows) > 20;
            if ($hasMore) array_pop($rows);
        } else {
            $rows = h('SELECT p.id,p.user_id,p.body_markdown,p.body_html,p.created_at,u.username FROM posts p JOIN users u ON u.id=p.user_id WHERE p.topic_id=? AND p.status="normal" ORDER BY p.id LIMIT 20', [$id])->fetchAll();
            $hasMore = $pageNo < $pages;
        }
        extension_preload('post.after-body', ['posts'=>$rows, 'topic'=>$topic, 'viewer'=>$user]);
        $viewerState = topic_page_viewer_state($rows, $id, $user);
        $createdAt = (string)$topic['created_at'];
        $lastPostAt = (string)$topic['last_post_at'];
        $canEditTopic = !empty($topic['original_post_id']) && $user && ((int)$user['id'] === (int)$topic['user_id'] || $user['role'] === 'admin');
        $viewerId=(int)($user['id']??0);
        $bookmarked=$viewerState['bookmarked'];
        $subscribed=$viewerState['subscribed'];
        $body = '<header class="topic-page-heading"><div class="topic-heading-topline"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a><span class="topic-state'.(!empty($topic['is_locked'])?' is-locked':'').'">'.(!empty($topic['is_locked'])?'已锁定':'开放讨论').'</span></div><div class="topic-title-row"><h1>'.e($topic['title']).'</h1>'.($canEditTopic?'<a class="post-edit" href="/p/'.(int)$topic['original_post_id'].'/edit">编辑</a>':'').'</div><div class="topic-page-meta"><span class="topic-owner-avatar" aria-hidden="true">'.e(text_slice($topic['username'], 0, 1)).'</span><a href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time datetime="'.e(str_replace(' ', 'T', substr($createdAt, 0, 19))).'">发布于 '.e(substr($createdAt, 0, 16)).'</time><span class="topic-meta-count">'.(int)$topic['reply_count'].' 条回复</span>'.($topic['reply_count']?'<time datetime="'.e(str_replace(' ', 'T', substr($lastPostAt, 0, 19))).'">最近更新 '.e(substr($lastPostAt, 0, 16)).'</time>':'').'</div>'.($user?'<div class="topic-actions"><form method="post" action="/topic/'.$id.'/bookmark"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($bookmarked?'取消收藏':'收藏主题').'</button></form><form method="post" action="/topic/'.$id.'/subscribe"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($subscribed?'取消订阅':'订阅回复').'</button></form><details class="report-control"><summary>举报主题</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="topic"><input type="hidden" name="target_id" value="'.$id.'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details></div>':'').'</header><section class="topic-thread" id="replies">';
        $floor = $afterId > 0
            ? ($afterFloor > 0 ? $afterFloor : (int)h('SELECT COUNT(*) FROM posts WHERE topic_id=? AND status="normal" AND id<=?',[$id,$afterId])->fetchColumn())
            : ($pageNo - 1) * 20;
        foreach ($rows as $post) {
            $floor++;
            $kind = $floor === 1 ? 'post-original' : 'post-reply';
            if ($floor === 2 || ($pageNo > 1 && $floor === ($pageNo - 1) * 20 + 1)) $body .= '<h2 class="replies-heading">回复 <span>'.(int)$topic['reply_count'].'</span></h2>';
            $postCreatedAt = (string)$post['created_at'];
            $canEditPost = $user && ((int)$user['id'] === (int)$post['user_id'] || $user['role'] === 'admin');
            $liked=!empty($viewerState['liked'][(int)$post['id']]);
            $blocked=!empty($viewerState['blocked'][(int)$post['user_id']]);
            if($blocked)$post['body_html']='<p class="blocked-post">你已屏蔽该用户的内容。</p>';
            $body .= '<article class="post '.$kind.'" id="p'.(int)$post['id'].'"><div class="reply-author"><span class="reply-avatar" aria-hidden="true">'.e(text_slice($post['username'], 0, 1)).'</span><div class="reply-author-meta"><a href="/u/'.rawurlencode($post['username']).'">'.e($post['username']).'</a>'.($floor === 1 ? '<span class="author-role">楼主</span>' : '').'<time datetime="'.e(str_replace(' ', 'T', substr($postCreatedAt, 0, 19))).'">'.e(substr($postCreatedAt, 0, 16)).'</time></div>'.($floor > 1 && $canEditPost?'<a class="post-edit" href="/p/'.(int)$post['id'].'/edit">编辑</a>':'').'<button class="reply-floor" type="button" data-floor="'.(int)$floor.'" aria-label="回复 '.$floor.' 楼">回复</button></div><div class="body">'.$post['body_html'].'</div>';
            $body .= extension_slot('post.after-body', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user]);
            if($user){$likes=(int)($viewerState['likes'][(int)$post['id']]??0);$body.='<div class="post-tools"><form method="post" action="/post/'.(int)$post['id'].'/like"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($liked?'已点赞':'点赞').' · '.$likes.'</button></form><details class="report-control"><summary>举报</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="post"><input type="hidden" name="target_id" value="'.(int)$post['id'].'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details><form method="post" action="/user/'.(int)$post['user_id'].'/block"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>屏蔽用户</button></form></div>';}
            $body .= '</article>';
        }
        $body .= '</section>';
        if ($afterId > 0) {
            if ($hasMore && $rows) $body .= '<nav class="pagination" aria-label="回复分页"><a class="pagination-next" href="/t/'.$id.'?after='.(int)end($rows)['id'].'&floor='.$floor.'#replies">更多回复</a></nav>';
        } else {
            if ($pageNo === 1 && $pages > 1 && $rows) $body .= '<nav class="pagination" aria-label="回复游标分页"><a class="pagination-next" href="/t/'.$id.'?after='.(int)end($rows)['id'].'&floor='.$floor.'#replies">按游标继续浏览</a></nav>';
        }
        if (!empty($topic['is_locked'])) $body .= '<p class="locked-note">该主题已锁定，暂不能回复。</p>';
        elseif ($user) $body .= '<section class="reply-composer" id="reply-composer" aria-labelledby="reply-composer-title"><div class="reply-composer-heading"><div><p class="eyebrow">YOUR REPLY</p><h2 id="reply-composer-title">参与讨论</h2></div><span>以 '.e($user['username']).' 的身份回复</span></div><div class="reply-quote-note" hidden><strong></strong><blockquote></blockquote></div><form method="post" action="/t/'.$id.'/reply"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" id="reply-to-post" name="reply_to_post" value=""><label class="reply-editor" for="reply-body"><span>回复内容</span><textarea id="reply-body" name="body" maxlength="12000" rows="7" placeholder="写下清晰、友善且有帮助的回复…" required></textarea></label><div class="reply-composer-actions"><small>支持换行纯文本，最多 12,000 字</small><button class="primary">发布回复</button></div></form></section>';
        if (!$user && $afterId === 0) write_cache($key, [$body], 30);
    } else {
        $body = $body[0] ?? '';
    }

    count_topic_view($id);
    page($topic['title'], $body, '讨论主题：'.$topic['title'], true);
    return;
}

if (preg_match('~^/c/(\d+)$~', $path, $match)) {
    $id = (int)$match[1];
    $category = h('SELECT id,title,description FROM categories WHERE id=? AND is_visible=1', [$id])->fetch();
    if (!$category) fail(404, '分区不存在');

    $pageNo = max(1, (int)($_GET['page'] ?? 1));
    $categoryCursor = (string)($_GET['cursor'] ?? '');
    if (strlen($categoryCursor) > 256) fail(400, '分页游标无效');
    $categoryCursorData = null;
    if ($categoryCursor !== '') {
        $decoded = base64_decode(strtr($categoryCursor, '-_', '+/'), true);
        $candidate = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($candidate) || !isset($candidate['p'],$candidate['d'],$candidate['i']) || !in_array((int)$candidate['p'],[0,1],true) || !is_string($candidate['d']) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',$candidate['d']) || (int)$candidate['i']<1) fail(400,'分页游标无效');
        $categoryCursorData = ['p'=>(int)$candidate['p'],'d'=>$candidate['d'],'i'=>(int)$candidate['i']];
    }
    $versions = cache_versions(['topics','categories','category-feed:'.$id,'extension-read-pages','plugins']);
    $topicCount = cached_topic_count('category',$id);
    $pages = max(1, (int)ceil($topicCount / 30));
    if ($pageNo > $pages || $pageNo > 100000) fail(404, '页面不存在');
    if ($pageNo > 1 && $categoryCursor === '') fail(400, '分页方式已更新，请从分区首页使用游标继续浏览');
    $key = 'category:v3:'.$id.':'.$pageNo.':'.hash('sha256',$categoryCursor).':'.(int)$versions['category-feed:'.$id].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'];
    $loadRows = static function () use ($id, $categoryCursorData): array {
        $args=[$id];$where='t.category_id=? AND t.status="normal"';
        if($categoryCursorData){$where.=' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';array_push($args,$categoryCursorData['p'],$categoryCursorData['p'],$categoryCursorData['d'],$categoryCursorData['d'],$categoryCursorData['i']);$sql='SELECT t.id,t.title,t.reply_count,t.last_post_at,t.is_pinned,u.username FROM topics t JOIN users u ON u.id=t.user_id WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 31';}
        else{$sql='SELECT t.id,t.title,t.reply_count,t.last_post_at,t.is_pinned,u.username FROM topics t JOIN users u ON u.id=t.user_id WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 31';}
        return h($sql,$args)->fetchAll();
    };
    $rows = $categoryCursorData === null ? cached($key, 60, $loadRows) : $loadRows();
    $hasMore=count($rows)>30;if($hasMore)array_pop($rows);$nextCursor='';if($hasMore&&$rows){$last=end($rows);$payload=json_encode(['p'=>(int)$last['is_pinned'],'d'=>(string)$last['last_post_at'],'i'=>(int)$last['id']]);$nextCursor=rtrim(strtr(base64_encode((string)$payload),'+/','-_'),'=');}

    $body = '<p class="eyebrow">SPACE / '.e($category['title']).'</p><h1>'.e($category['title']).'</h1><p>'.e($category['description']).'</p>';
    foreach ($rows as $topic) $body .= '<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'"><span class="topic-avatar" aria-hidden="true">'.e(text_slice($topic['username'], 0, 1)).'</span><div class="topic-feed-main"><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><div class="topic-feed-meta">'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'<a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><span class="topic-activity">最后活跃</span><time>'.e(substr((string)$topic['last_post_at'],0,16)).'</time></div></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';
    if($nextCursor!=='')$body.='<nav class="pagination"><a class="pagination-next" href="/c/'.$id.'?cursor='.rawurlencode($nextCursor).'">'.($categoryCursor!==''?'加载更多':'继续浏览').'</a></nav>';
    page($category['title'], $body, $category['description'], true);
    return;
}
