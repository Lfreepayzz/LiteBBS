<?php
declare(strict_types=1);

if ($path !== '/admin/reports' || !in_array($method, ['GET', 'POST'], true)) return;
$actor = admin();
if ($method === 'POST') {
    require_post();
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $report = h('SELECT target_type,target_id FROM reports WHERE id=?', [$id])->fetch();
    if (!$report || !in_array($status, ['reviewed', 'dismissed', 'remove_target'], true)) fail(400, '举报状态无效');
    if ($status === 'remove_target') {
        if ($report['target_type'] === 'topic') { h('UPDATE topics SET status="deleted" WHERE id=?', [(int)$report['target_id']]); invalidate_topic_read_cache((int)$report['target_id']); }
        elseif ($report['target_type'] === 'post') {
            $post = h('SELECT p.topic_id,p.status,(SELECT id FROM posts WHERE topic_id=p.topic_id ORDER BY id LIMIT 1) AS first_post_id FROM posts p WHERE p.id=?', [(int)$report['target_id']])->fetch();
            $topicId = (int)($post['topic_id'] ?? 0);
            if ($post && h('UPDATE posts SET status="deleted" WHERE id=? AND status="normal"', [(int)$report['target_id']])->rowCount() === 1 && $topicId > 0 && (int)$post['first_post_id'] !== (int)$report['target_id']) h('UPDATE topics SET reply_count=GREATEST(reply_count-1,0) WHERE id=?', [$topicId]);
            if ($topicId > 0) invalidate_topic_read_cache($topicId);
        }
        elseif ($report['target_type'] === 'user') h('UPDATE users SET status="banned" WHERE id=?', [(int)$report['target_id']]);
        else fail(400, '举报目标无效');
        $status = 'reviewed';
    }
    h('UPDATE reports SET status=?,reviewer_id=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?', [$status, $actor['id'], $id]);
    audit('report.'.$status, 'report', $id);
    redirect('/admin/reports');
}
$rows = h('SELECT r.*,u.username reporter FROM reports r JOIN users u ON u.id=r.reporter_id ORDER BY FIELD(r.status,"open","reviewed","dismissed"),r.id DESC LIMIT 100')->fetchAll();
$openCount = (int)h('SELECT COUNT(*) FROM reports WHERE status="open"')->fetchColumn();
$body = '<p class="admin-intro">处理用户提交的主题、回复、用户和附件举报。当前有 <strong>'.$openCount.'</strong> 条待处理举报。</p>';
if (!$rows) $body .= '<p class="admin-empty">暂无举报记录。</p>';
foreach ($rows as $row) {
    $targetPath=match($row['target_type']){'topic'=>'/t/'.(int)$row['target_id'],'post'=>(($topicId=(int)h('SELECT topic_id FROM posts WHERE id=?',[$row['target_id']])->fetchColumn())?'/t/'.$topicId.'#p'.(int)$row['target_id']:''),'user'=>(($name=h('SELECT username FROM users WHERE id=?',[$row['target_id']])->fetchColumn())?'/u/'.rawurlencode((string)$name):''),default=>''};
    $targetLink=$targetPath!==''?' <a href="'.e($targetPath).'">查看目标</a>':'';
    $body .= '<article class="adminrow"><strong>#'.(int)$row['id'].' '.e($row['target_type']).' #'.(int)$row['target_id'].'</strong><p>'.e($row['reason']).' · 举报人 '.e($row['reporter']).' · '.e($row['created_at']).' · 状态 '.e($row['status']).$targetLink.'</p><div class="dashboard-actions">';
    if ($row['status'] === 'open') foreach (['reviewed'=>'标记已处理','dismissed'=>'驳回','remove_target'=>'移除违规目标'] as $status=>$label) $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="status" value="'.$status.'"><button>'.e($label).'</button></form>';
    $body .= '</div></article>';
}
page('举报审核', $body, '', false, true);
return;
