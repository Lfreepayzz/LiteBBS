<?php
declare(strict_types=1);

function handle_admin_reports(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
if ($path !== '/admin/reports' || !in_array($method, ['GET', 'POST'], true)) return;
$actor = admin();
if ($method === 'POST') {
    require_post();
    $postInput = $request->post;
    $id = (int)($postInput['id'] ?? 0);
    $status = (string)($postInput['status'] ?? '');
    if (!in_array($status, ['reviewed', 'dismissed', 'remove_target'], true)) fail(400, '举报状态无效');
    $invalidate = \LiteBBS\Application\transaction_manager()->run(static function () use ($id, $status, $actor): array {
    $invalidate = [];
    $events = [];
    $report = h('SELECT target_type,target_id,status FROM reports WHERE id=? FOR UPDATE', [$id])->fetch();
    if (!$report || $report['status'] !== 'open') fail(409, '举报已被处理');
    if ($status === 'remove_target') {
        if ($report['target_type'] === 'topic') {
            $topic = h('SELECT category_id FROM topics WHERE id=? AND status="normal" FOR UPDATE', [(int)$report['target_id']])->fetch();
            if ($topic && h('UPDATE topics SET status="deleted" WHERE id=? AND status="normal"', [(int)$report['target_id']])->rowCount() === 1) {
                h('DELETE FROM notifications WHERE topic_id=?', [(int)$report['target_id']]);
                $events[] = ['topic.deleted', ['topic_id'=>(int)$report['target_id'],'category_id'=>(int)$topic['category_id'],'post_ids'=>[],'actor_id'=>(int)$actor['id'],'reason'=>'report']];
                $topicId = (int)$report['target_id'];
                $categoryId = (int)$topic['category_id'];
                $invalidate[] = static function () use ($topicId, $categoryId): void {
                    invalidate_topic_read_cache($topicId, $categoryId, false, true);
                    invalidate_topic_listing_cache($categoryId, true);
                };
            }
        }
        elseif ($report['target_type'] === 'post') {
            h('SELECT id FROM notification_outbox WHERE post_id=? AND processed_at IS NULL FOR UPDATE', [(int)$report['target_id']])->fetchAll();
            $post = h('SELECT p.topic_id,t.category_id FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal" FOR UPDATE', [(int)$report['target_id']])->fetch();
            if ($post) {
                $postId = (int)$report['target_id'];
                $topicId = (int)$post['topic_id'];
                $categoryId = (int)$post['category_id'];
                h('UPDATE posts SET status="deleted" WHERE id=? AND status="normal"', [$postId]);
                h('DELETE FROM notification_outbox WHERE post_id=? AND processed_at IS NULL', [$postId]);
                h('DELETE FROM notifications WHERE post_id=?', [$postId]);
                $events[] = ['post.deleted', ['post_id'=>$postId, 'topic_id'=>$topicId, 'actor_id'=>(int)$actor['id'], 'reason'=>'report']];
                if (topic_last_post_pointer_available()) {
                    h('UPDATE topics SET reply_count=IF(reply_count>0,reply_count-1,0),last_post_id=(SELECT id FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),last_post_at=COALESCE((SELECT created_at FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),created_at) WHERE id=?', [$topicId, $topicId, $topicId]);
                } else {
                    h('UPDATE topics SET reply_count=IF(reply_count>0,reply_count-1,0) WHERE id=?', [$topicId]);
                }
                $invalidate[] = static function () use ($topicId, $categoryId): void {
                    invalidate('topic-floor:'.$topicId);
                    invalidate_topic_read_cache($topicId, $categoryId, true, false);
                };
            }
        }
        elseif ($report['target_type'] === 'user') {
            $targetId = (int)$report['target_id'];
            if ($targetId === (int)$actor['id']) fail(400, '不能停用当前管理员账号');
            $target = h('SELECT role,status FROM users WHERE id=? FOR UPDATE', [$targetId])->fetch();
            if ($target && $target['role'] === 'admin' && $target['status'] === 'active') {
                $activeAdmins = h('SELECT id FROM users WHERE role="admin" AND status="active" ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
                if (count($activeAdmins) <= 1) fail(409, '至少必须保留一名启用中的管理员');
            }
            h('UPDATE users SET status="disabled" WHERE id=?', [$targetId]);
            h('DELETE FROM sessions WHERE user_id=?', [$targetId]);
        }
        else fail(400, '举报目标无效');
        $status = 'reviewed';
    }
    h('UPDATE reports SET status=?,reviewer_id=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=? AND status="open"', [$status, $actor['id'], $id]);
    audit('report.'.$status, 'report', $id);
    foreach ($events as [$event, $payload]) extension_enqueue_async_event($event, $payload);
    extension_enqueue_async_event('report.reviewed', ['report_id'=>$id,'target_type'=>(string)$report['target_type'],'target_id'=>(int)$report['target_id'],'status'=>$status,'actor_id'=>(int)$actor['id']]);
    return $invalidate;
    });
    foreach ($invalidate as $invalidateCache) $invalidateCache();
    redirect('/admin/reports');
}
$queues = ['open'=>'待处理','reviewed'=>'已处理','dismissed'=>'已驳回'];
$queue = $request->query['status'] ?? 'open';
if (!is_string($queue) || !isset($queues[$queue])) fail(400, '举报状态无效');
$before = $request->query['before'] ?? null;
if ($before !== null && (!is_string($before) || !preg_match('/^[1-9][0-9]{0,19}$/D', $before) || (strlen($before) === 20 && strcmp($before, '18446744073709551615') > 0))) fail(400, '分页游标无效');
$rows = h('SELECT r.*,u.username reporter,p.topic_id target_topic_id,target_user.username target_username
    FROM (SELECT id FROM reports WHERE status=?'.($before === null ? '' : ' AND id<CAST(? AS UNSIGNED)').' ORDER BY id DESC LIMIT 101) batch
    JOIN reports r ON r.id=batch.id
    JOIN users u ON u.id=r.reporter_id
    LEFT JOIN posts p ON r.target_type="post" AND p.id=r.target_id
    LEFT JOIN users target_user ON r.target_type="user" AND target_user.id=r.target_id
    ORDER BY batch.id DESC', $before === null ? [$queue] : [$queue, $before])->fetchAll();
$hasMore = count($rows) > 100;
if ($hasMore) array_pop($rows);
$body = '<p class="admin-intro">处理用户提交的主题、回复和用户举报。</p><nav class="dashboard-actions" aria-label="举报状态">';
foreach ($queues as $key=>$label) $body .= '<a href="/admin/reports?status='.$key.'"'.($key === $queue ? ' aria-current="page"' : '').'>'.e($label).'</a> ';
$body .= '</nav>';
if (!$rows) $body .= '<p class="admin-empty">暂无举报记录。</p>';
foreach ($rows as $row) {
    $targetPath=match($row['target_type']){'topic'=>'/t/'.(int)$row['target_id'],'post'=>((int)($row['target_topic_id']??0)?'/t/'.(int)$row['target_topic_id'].'#p'.(int)$row['target_id']:''),'user'=>((string)($row['target_username']??'')!==''?'/u/'.rawurlencode((string)$row['target_username']):''),default=>''};
    $targetLink=$targetPath!==''?' <a href="'.e($targetPath).'">查看目标</a>':'';
    $body .= '<article class="adminrow"><strong>#'.(int)$row['id'].' '.e($row['target_type']).' #'.(int)$row['target_id'].'</strong><p>'.e($row['reason']).' · 举报人 '.e($row['reporter']).' · '.e($row['created_at']).' · 状态 '.e($row['status']).$targetLink.'</p><div class="dashboard-actions">';
    if ($row['status'] === 'open') foreach (['reviewed'=>'标记已处理','dismissed'=>'驳回','remove_target'=>'移除违规目标'] as $status=>$label) $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="status" value="'.$status.'"><button>'.e($label).'</button></form>';
    $body .= '</div></article>';
}
if ($hasMore) $body .= '<p><a rel="next" href="/admin/reports?status='.$queue.'&amp;before='.e((string)end($rows)['id']).'">加载更早的记录</a></p>';
page('举报审核', $body, '', false, true);
return;
}
