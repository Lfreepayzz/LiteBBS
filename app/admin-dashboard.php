<?php
declare(strict_types=1);

if ($path !== '/admin' || !in_array($method, ['GET', 'POST'], true)) return;

$actor = admin();
if ($method === 'POST') {
    require_post();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'pin' || $action === 'unpin') {
        moderate_topic($id, 'is_pinned', $action === 'pin' ? 1 : 0, $action);
    } elseif ($action === 'lock' || $action === 'unlock') {
        moderate_topic($id, 'is_locked', $action === 'lock' ? 1 : 0, $action);
    } elseif ($action === 'delete') {
        if (empty($_POST['confirm_token'])) confirmation_page('确认永久删除','将永久删除主题、回复、修订和积分记录，此操作不可恢复。','topic.delete',['id'=>$id,'action'=>'delete'],(string)$id);
        require_confirmation('topic.delete',(string)$id,(string)$_POST['confirm_token']);
        permanently_delete_topic($id);
    } else {
        fail(400, '操作无效');
    }
    redirect('/admin');
}

$stats = h('SELECT (SELECT COUNT(*) FROM users) users,(SELECT COUNT(*) FROM topics WHERE status="normal") topics,(SELECT COALESCE(SUM(reply_count),0) FROM topics WHERE status="normal") replies,(SELECT COUNT(*) FROM market_submissions WHERE status="pending") pending_extensions')->fetch();
$recent = h('SELECT t.id,t.title,t.status,t.is_pinned,t.is_locked,t.last_post_at,u.username FROM topics t JOIN users u ON u.id=t.user_id WHERE t.status="normal" ORDER BY t.id DESC LIMIT 8')->fetchAll();
$cards = [
    '注册用户' => (int)$stats['users'],
    '公开主题' => (int)$stats['topics'],
    '公开回复' => (int)$stats['replies'],
    '待审扩展' => (int)$stats['pending_extensions'],
];
$body = '<section class="dashboard-stats">';
foreach ($cards as $label => $value) $body .= '<div class="dashboard-stat"><span>'.e($label).'</span><strong>'.number_format($value).'</strong></div>';
$body .= '</section><section class="dashboard-section"><div class="dashboard-section-heading"><h2>最近主题</h2><a href="/admin/content">查看内容管理</a></div>';
if (!$recent) $body .= '<p class="admin-intro">目前还没有主题。请先在前台发布主题，或前往分区管理确认至少有一个可见分区。</p>';
foreach ($recent as $topic) {
    $id = (int)$topic['id'];
    $status = $topic['status'] === 'normal' ? '公开' : '已删除';
    $body .= '<article class="dashboard-topic"><div><a href="/t/'.$id.'">'.e($topic['title']).'</a><p>'.e($topic['username']).' · '.e($topic['last_post_at']).' · '.$status.($topic['is_pinned'] ? ' · 置顶' : '').($topic['is_locked'] ? ' · 已锁定' : '').'</p></div><div class="dashboard-actions">';
    $actions = [
        [$topic['is_pinned'] ? 'unpin' : 'pin', $topic['is_pinned'] ? '取消置顶' : '置顶'],
        [$topic['is_locked'] ? 'unlock' : 'lock', $topic['is_locked'] ? '解锁' : '锁定'],
        ['delete', '永久删除'],
    ];
    foreach ($actions as [$action, $label]) {
        $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.e($action).'"><button>'.e($label).'</button></form>';
    }
    $body .= '</div></article>';
}
$body .= '</section><section class="dashboard-links"><a href="/admin/users">用户管理</a><a href="/admin/categories">分区管理</a><a href="/admin/settings">站点设置</a><a href="/admin/plugins">插件管理</a><a href="/admin/themes">模板管理</a><a href="/admin/market">扩展审核</a><a href="/admin/audit">审计日志</a></section>';
page('管理总览', $body);
exit;
