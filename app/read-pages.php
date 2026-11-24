<?php
declare(strict_types=1);
require_once __DIR__.'/read-model.php';
require_once __DIR__.'/topic-cursor.php';
require_once __DIR__.'/pagination-model.php';

function handle_read_pages(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$cookies = $request->cookies;
if ($method !== 'GET') return;

if (preg_match('~^/(?:t/(\d+)|topic-(\d+)(?:-(\d+))?)$~', $path, $match)) {
    $topicId = (string)($match[1] ?? '');
    $numericTopicId = (string)($match[2] ?? '');
    $numericPage = $numericTopicId !== '' ? (int)($match[3] ?? 1) : 1;
    $id = (int)($topicId !== '' ? $topicId : $numericTopicId);
    if ($topicId !== '' && $path !== '/t/'.$id) redirect('/t/'.$id, 301);

    if (array_key_exists('page', $query)) fail(400, '主题回复仅支持游标翻页。');
    $afterId = filter_var($numericPage > 1 ? \LiteBBS\Application\NumericPageCursor::topicAfter($id, $numericPage, theme_page_size((string)(load_config()['theme'] ?? 'default'), 'topic')) : ($query['after'] ?? '0'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($afterId === false) fail(400, '回复分页游标无效');
    $pageSize = theme_page_size((string)(load_config()['theme'] ?? 'default'), 'topic');
    $anonymous = empty($cookies[session_name()]);
    $config = load_config();
    $theme = (string)($config['theme'] ?? 'default');
    $versions = cache_versions(['topic:'.$id,'topic-floor:'.$id,'extension-read-pages','categories','plugins','theme-settings:'.$theme]);
    $floorRevision = $versions['topic-floor:'.$id];
    $topicCursor = new \LiteBBS\Application\TopicCursor((string)($config['key'] ?? ''));
    $shellVersion = hash('sha256', implode("\0", [
        (string)($config['site'] ?? ''),
        (string)($config['url'] ?? ''),
        (string)($config['theme'] ?? 'default'),
        (string)($config['locale'] ?? 'zh-CN'),
        json_encode($config['seo'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
    ]));
    $pageCacheKey = 'topic-page:v5:'.$pageSize.':'.$id.':'.$shellVersion.':'.(int)$versions['topic:'.$id].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'].':'.(int)$versions['plugins'].':'.(int)$versions['theme-settings:'.$theme];
    if ($anonymous && $afterId === 0 && ($cachedPage = page_cache_read($pageCacheKey, 120)) !== null) {
        echo (string)($cachedPage[0] ?? '');
        return;
    }

    $topic = h('SELECT t.*,c.id category_id,c.title category,c.slug category_slug,u.username,(SELECT MIN(p.id) FROM posts p WHERE p.topic_id=t.id AND p.status="normal") original_post_id FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1', [$id])->fetch();
    if (!$topic) fail(404, '主题不存在');

    if ($afterId > 0 && $numericPage === 1 && (!isset($query['floor']) || !is_string($query['floor']) || !ctype_digit($query['floor']))) fail(400, '回复分页游标无效');
    $afterFloor = 0;
    if ($afterId > 0) {
        $cursorPost = h('SELECT id FROM posts WHERE id=? AND topic_id=? AND status="normal"', [$afterId, $id])->fetchColumn();
        if (!$cursorPost) fail(400, '回复分页游标无效');
        $claimedFloor = $numericPage > 1 ? ($numericPage - 1) * $pageSize : (int)$query['floor'];
        if ($numericPage > 1 || $topicCursor->accepts($query['sig'] ?? null, $id, $afterId, $claimedFloor, $floorRevision, (int)$topic['reply_count'])) {
            $afterFloor = $claimedFloor;
        } else {
            $afterFloor = (int)h('SELECT COUNT(*) FROM posts WHERE topic_id=? AND status="normal" AND id<=?', [$id, $afterId])->fetchColumn();
        }
    }
    $user = current_user();
    // Core renders stored HTML. Keep the Markdown field for plugin hook payloads.
    $fields = 'p.id,p.user_id,p.body_html,p.created_at,u.username';
    if (extension_enabled_plugins()) $fields .= ',p.body_markdown';
    $where = 'p.topic_id=? AND p.status="normal"';
    $args = [$id];
    if ($afterId > 0) {
        $where .= ' AND p.id>?';
        $args[] = $afterId;
    }
    $rows = h('SELECT '.$fields.' FROM posts p JOIN users u ON u.id=p.user_id WHERE '.$where.' ORDER BY p.id LIMIT '.($pageSize+1), $args)->fetchAll();
    $hasMore = count($rows) > $pageSize;
    if ($hasMore) array_pop($rows);
        extension_preload('post.after-body', ['posts'=>$rows, 'topic'=>$topic, 'viewer'=>$user]);
        extension_preload('post.after', ['posts'=>$rows, 'topic'=>$topic, 'viewer'=>$user]);
        $viewerState = topic_page_viewer_state($rows, $id, $user);
        $createdAt = (string)$topic['created_at'];
        $lastPostAt = (string)$topic['last_post_at'];
        $canEditTopic = !empty($topic['original_post_id']) && $user && ((int)$user['id'] === (int)$topic['user_id'] || $user['role'] === 'admin');
        $bookmarked=$viewerState['bookmarked'];
        $subscribed=$viewerState['subscribed'];
        $threadView=['topic'=>$topic,'posts'=>$rows,'viewer'=>$user,'after_id'=>$afterId];
        $body = '<header class="topic-page-heading"><div class="topic-heading-topline"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a><span class="topic-state'.(!empty($topic['is_locked'])?' is-locked':'').'">'.(!empty($topic['is_locked'])?'已锁定':'开放讨论').'</span></div><div class="topic-title-row"><h1>'.e($topic['title']).'</h1>'.($canEditTopic?'<a class="post-edit" href="/p/'.(int)$topic['original_post_id'].'/edit">编辑</a>':'').'</div><div class="topic-page-meta"><span class="topic-owner-avatar" aria-hidden="true">'.e(text_slice($topic['username'], 0, 1)).'</span><a href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time datetime="'.e(str_replace(' ', 'T', substr($createdAt, 0, 19))).'">发布于 '.e(substr($createdAt, 0, 16)).'</time><span class="topic-views" aria-label="'.(int)$topic['view_count'].' 次浏览"><i aria-hidden="true"></i>'.(int)$topic['view_count'].'</span><span class="topic-meta-count">'.(int)$topic['reply_count'].' 条回复</span>'.($topic['reply_count']?'<time datetime="'.e(str_replace(' ', 'T', substr($lastPostAt, 0, 19))).'">最近更新 '.e(substr($lastPostAt, 0, 16)).'</time>':'').'</div>'.extension_slot('topic.header.after', ['topic'=>$topic, 'viewer'=>$user]).($user?'<div class="topic-actions"><form method="post" action="/topic/'.$id.'/bookmark"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($bookmarked?'取消收藏':'收藏主题').'</button></form><form method="post" action="/topic/'.$id.'/subscribe"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($subscribed?'取消订阅':'订阅回复').'</button></form><details class="report-control"><summary>举报主题</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="topic"><input type="hidden" name="target_id" value="'.$id.'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details></div>'.extension_slot('topic.actions.after', ['topic'=>$topic, 'viewer'=>$user]):'').'</header>'.extension_slot('topic.thread.before',$threadView).'<section class="topic-thread" id="replies">';
        $floor = $afterFloor;
        foreach ($rows as $post) {
            $floor++;
            $kind = $floor === 1 ? 'post-original' : 'post-reply';
            if ($floor === 2) $body .= '<h2 class="replies-heading">回复 <span>'.(int)$topic['reply_count'].'</span></h2>';
            $postCreatedAt = (string)$post['created_at'];
            $canEditPost = $user && ((int)$user['id'] === (int)$post['user_id'] || $user['role'] === 'admin');
            $liked=!empty($viewerState['liked'][(int)$post['id']]);
            $blocked=!empty($viewerState['blocked'][(int)$post['user_id']]);
            if($blocked)$post['body_html']='<p class="blocked-post">你已屏蔽该用户的内容。</p>';
            $canDeletePost = $floor > 1 && $canEditPost;
            $body .= extension_slot('post.before', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user, 'floor'=>$floor]);
            $body .= '<article class="post '.$kind.'" id="p'.(int)$post['id'].'">';
            if ($floor > 1) $body .= '<div class="reply-author"><span class="reply-avatar" aria-hidden="true">'.e(text_slice($post['username'], 0, 1)).'</span><div class="reply-author-meta"><a href="/u/'.rawurlencode($post['username']).'">'.e($post['username']).'</a><time datetime="'.e(str_replace(' ', 'T', substr($postCreatedAt, 0, 19))).'">'.e(substr($postCreatedAt, 0, 16)).'</time></div>'.($canEditPost?'<a class="post-edit" href="/p/'.(int)$post['id'].'/edit">编辑</a>':'').($canDeletePost?'<form class="post-delete" method="post" action="/post/'.(int)$post['id'].'/delete"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button type="submit">删除</button></form>':'').'<button class="reply-floor" type="button" data-floor="'.(int)$floor.'" aria-label="回复 '.$floor.' 楼">回复</button></div>';
            $body .= '<div class="body">'.$post['body_html'].'</div>';
            $body .= extension_slot('post.after-body', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user]);
            if($user && $floor > 1){$likes=(int)($viewerState['likes'][(int)$post['id']]??0);$body.='<div class="post-tools"><form method="post" action="/post/'.(int)$post['id'].'/like"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>'.($liked?'已点赞':'点赞').' · '.$likes.'</button></form><details class="report-control"><summary>举报</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="post"><input type="hidden" name="target_id" value="'.(int)$post['id'].'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details><form method="post" action="/user/'.(int)$post['user_id'].'/block"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>屏蔽用户</button></form></div>';}
            $body .= '</article>';
            $body .= extension_slot('post.after', ['post'=>$post, 'topic'=>$topic, 'viewer'=>$user, 'floor'=>$floor]);
        }
        $body .= '</section>'.extension_slot('topic.thread.after',$threadView);
        $nextAfter = $hasMore && $rows ? (int)end($rows)['id'] : 0;
        $currentPage = (int)ceil(max(1, $afterFloor + 1) / $pageSize);
        $nextUrl = '';
        if ($nextAfter > 0) {
            $signature = $topicCursor->signature($id, $nextAfter, $floor, $floorRevision, (int)$topic['reply_count']);
            $nextUrl = '/topic-'.$id.'-'.($currentPage + 1);
        }
        $pagination = ['context'=>'topic','page_size'=>$pageSize,'first_url'=>'/t/'.$id,'current_cursor'=>$afterId > 0 ? (string)$afterId : '','next_cursor'=>$nextAfter > 0 ? (string)$nextAfter : '','has_more'=>$nextAfter > 0,'next_url'=>$nextUrl];
        $pagination += \LiteBBS\Application\Pagination::forView($currentPage, (int)$topic['reply_count'] + 1, $pageSize, '/t/'.$id, '/topic-'.$id);
        $body .= theme_render_standard('pagination', ['pagination'=>$pagination], __DIR__.'/pagination.php');
        if (!empty($topic['is_locked'])) $body .= '<p class="locked-note">该主题已锁定，暂不能回复。</p>';
        elseif ($user) $body .= '<section class="reply-composer" id="reply-composer" aria-labelledby="reply-composer-title"><div class="reply-composer-heading"><div><p class="eyebrow">YOUR REPLY</p><h2 id="reply-composer-title">参与讨论</h2></div><span>以 '.e($user['username']).' 的身份回复</span></div><div class="reply-quote-note" hidden><strong></strong><blockquote></blockquote></div><form method="post" action="/t/'.$id.'/reply"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" id="reply-to-post" name="reply_to_post" value=""><label class="reply-editor" for="reply-body"><span>回复内容</span><textarea id="reply-body" name="body" maxlength="12000" rows="7" placeholder="写下清晰、友善且有帮助的回复…" required></textarea></label>'.extension_slot('reply.create.fields', ['topic'=>$topic, 'user'=>$user]).'<div class="reply-composer-actions"><small>支持换行纯文本，最多 12,000 字</small><button class="primary">发布回复</button></div>'.extension_slot('reply.form.after', ['topic'=>$topic, 'user'=>$user]).'</form></section>';

    if ($anonymous && $afterId === 0) {
        ob_start();
        page($topic['title'], $body, '讨论主题：'.$topic['title'], true);
        $html = (string)ob_get_clean();
        page_cache_write($pageCacheKey, [$html], 120);
        echo $html;
    } else {
        page($topic['title'], $body, '讨论主题：'.$topic['title'], true);
    }
    return;
}

if (preg_match('~^/(?:c/(\d+)|category-(\d+)(?:-(\d+))?)$~', $path, $match)) {
    $categoryId = (string)($match[1] ?? '');
    $numericCategoryId = (string)($match[2] ?? '');
    $numericPage = $numericCategoryId !== '' ? (int)($match[3] ?? 1) : 1;
    $id = (int)($categoryId !== '' ? $categoryId : $numericCategoryId);
    if (array_key_exists('page', $query)) fail(400, '分区列表仅支持游标翻页。');
    $categoryCursor = $numericPage > 1 ? \LiteBBS\Application\NumericPageCursor::feed($numericPage, theme_page_size((string)(load_config()['theme'] ?? 'default'), 'category'), $id) : ($query['cursor'] ?? '');
    if ($numericPage > 1 && $categoryCursor === '') fail(404, '页面不存在');
    try {
        $categoryCursorData = $categoryCursor === '' ? null : \LiteBBS\Application\FeedCursor::decode($categoryCursor);
    } catch (InvalidArgumentException) {
        fail(400, '分页游标无效');
    }
    $pageSize = theme_page_size((string)(load_config()['theme'] ?? 'default'), 'category');
    $anonymous = empty($cookies[session_name()]);
    $config = load_config();
    $theme = (string)($config['theme'] ?? 'default');
    $versions = cache_versions(['topics','categories','category-feed:'.$id,'extension-read-pages','plugins','theme-settings:'.$theme]);
    $shellVersion = hash('sha256', implode("\0", [
        (string)($config['site'] ?? ''),
        (string)($config['url'] ?? ''),
        (string)($config['theme'] ?? 'default'),
        (string)($config['locale'] ?? 'zh-CN'),
        json_encode($config['seo'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
    ]));
    $pageCacheKey = 'category-page:v6:'.$pageSize.':'.$id.':'.hash('sha256',$categoryCursor).':'.$shellVersion.':'.(int)$versions['categories'].':'.(int)$versions['category-feed:'.$id].':'.(int)$versions['extension-read-pages'].':'.(int)$versions['plugins'].':'.(int)$versions['theme-settings:'.$theme];
    if ($anonymous && ($cachedPage = page_cache_read($pageCacheKey, 120)) !== null) {
        echo (string)($cachedPage[0] ?? '');
        return;
    }
    $category = h('SELECT id,title,description FROM categories WHERE id=? AND is_visible=1', [$id])->fetch();
    if (!$category) fail(404, '分区不存在');
    $key = 'category:v7:'.$pageSize.':'.$id.':'.hash('sha256',$categoryCursor).':'.(int)$versions['category-feed:'.$id].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'];
    $loadRows = static function () use ($id, $categoryCursorData, $pageSize): array {
        $args=[$id];$where='t.category_id=? AND t.status="normal"';
        $fields='t.id,t.title,t.reply_count,t.view_count,t.created_at,t.last_post_at,t.is_pinned,u.username,ru.username reply_username,rp.created_at reply_created_at FROM topics t JOIN users u ON u.id=t.user_id '.topic_last_post_join_sql().' LEFT JOIN users ru ON ru.id=rp.user_id';
        if($categoryCursorData){$where.=' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';array_push($args,$categoryCursorData['p'],$categoryCursorData['p'],$categoryCursorData['d'],$categoryCursorData['d'],$categoryCursorData['i']);}$sql='SELECT '.$fields.' WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT '.($pageSize+1);
        return h($sql,$args)->fetchAll();
    };
    $rows = $categoryCursorData === null ? cached($key, 120, $loadRows) : $loadRows();
    $nextCursor = '';
    if (count($rows) > $pageSize) {
        array_pop($rows);
        $nextCursor = \LiteBBS\Application\FeedCursor::fromTopic(end($rows), ($categoryCursorData['n'] ?? 1) + 1);
    }

    $viewer = $anonymous ? null : current_user();
    $categoryView=['category'=>$category,'topics'=>$rows,'viewer'=>$viewer,'next_cursor'=>$nextCursor];
    $body = '<p class="eyebrow">SPACE / '.e($category['title']).'</p><h1>'.e($category['title']).'</h1><p>'.e($category['description']).'</p>'.extension_slot('category.header.after',$categoryView).extension_slot('category.feed.before',$categoryView);
    foreach ($rows as $topic) {$created=(string)$topic['created_at'];$replied=(string)$topic['reply_created_at'];$body .= '<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'"><span class="topic-avatar" aria-hidden="true">'.e(text_slice($topic['username'], 0, 1)).'</span><div class="topic-feed-main"><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><div class="topic-feed-meta">'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'<a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><span class="topic-views" aria-label="'.(int)$topic['view_count'].' 次浏览"><i aria-hidden="true"></i>'.(int)$topic['view_count'].'</span><time class="topic-created" datetime="'.e(str_replace(' ','T',substr($created,0,19))).'">'.e(substr($created,0,16)).'</time>'.((int)$topic['reply_count']>0?'<a class="topic-reply-author" href="/u/'.rawurlencode((string)$topic['reply_username']).'">'.e((string)$topic['reply_username']).'</a><span class="topic-activity">回复</span><time class="topic-replied" datetime="'.e(str_replace(' ','T',substr($replied,0,19))).'">'.e(substr($replied,0,16)).'</time>':'').'</div></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';}
    $nextUrl = $nextCursor !== '' ? '/category-'.$id.'-'.(($categoryCursorData['n'] ?? 1) + 1) : '';
    $pagination = ['context'=>'category','page_size'=>$pageSize,'first_url'=>'/c/'.$id,'current_cursor'=>$categoryCursor,'next_cursor'=>$nextCursor,'has_more'=>$nextCursor!=='','next_url'=>$nextUrl];
    $pagination += \LiteBBS\Application\Pagination::forView($categoryCursorData['n'] ?? 1, cached_topic_count('category', $id), $pageSize, '/c/'.$id, '/category-'.$id);
    $body.=extension_slot('category.feed.after',$categoryView).theme_render_standard('pagination',['pagination'=>$pagination],__DIR__.'/pagination.php');
    if ($anonymous) ob_start();
    page($category['title'], $body, $category['description'], true);
    if ($anonymous) {
        $html = (string)ob_get_clean();
        page_cache_write($pageCacheKey, [$html], 120);
        echo $html;
    }
    return;
}
}
