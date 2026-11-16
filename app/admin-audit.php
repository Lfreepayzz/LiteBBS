<?php
declare(strict_types=1);

if ($path !== '/admin/audit' || !in_array($method, ['GET', 'POST'], true)) return;
$actor = admin();

if ($method === 'POST') {
    require_post();
    if ((string)($_POST['action'] ?? '') !== 'clear') fail(400, '操作无效');
    if (empty($_POST['confirm_token'])) confirmation_page('确认清空审计日志', '将永久删除全部历史审计记录，仅保留本次清理操作作为追溯凭据。', 'audit.clear', ['action' => 'clear'], 'all');
    require_confirmation('audit.clear', 'all', (string)$_POST['confirm_token']);
    $db = db();
    $db->beginTransaction();
    try {
        $deleted = h('DELETE FROM audit_logs')->rowCount();
        audit('audit.cleared', 'audit_log', null, ['deleted_count' => $deleted]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    redirect('/admin/audit');
}

$before = max(0, (int)($_GET['before'] ?? 0));
$stats = h('SELECT COUNT(*) AS total,MAX(created_at) AS latest_at FROM audit_logs')->fetch() ?: ['total' => 0, 'latest_at' => null];
$sql = 'SELECT id,actor_id,action,target_type,target_id,metadata_json,created_at FROM audit_logs'.($before ? ' WHERE id<?' : '').' ORDER BY id DESC LIMIT 100';
$rows = h($sql, $before ? [$before] : [])->fetchAll();

$body = '<section class="audit-hero"><div><p class="eyebrow">ACTIVITY TRACE</p><h2>审计日志</h2><p>保留后台关键操作的最小化追溯信息，便于检查管理行为与安全事件。</p></div><div class="audit-summary"><span><strong>'.number_format((int)$stats['total']).'</strong> 条记录</span><small>最近记录 '.e((string)($stats['latest_at'] ?? '暂无')).'</small></div></section>';
$body .= '<section class="audit-toolbar"><p>当前显示 '.count($rows).' 条'.($before ? '历史记录' : '最新记录').'，不展示敏感元数据内容。</p><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="clear"><button type="submit" class="audit-clear">清空全部日志</button></form></section>';
$body .= '<section class="audit-list">';
if (!$rows) $body .= '<p class="admin-empty">暂无审计记录。</p>';
foreach ($rows as $row) {
    $meta = json_decode((string)$row['metadata_json'], true);
    $summary = is_array($meta) ? implode('、', array_map(static fn($key) => preg_match('/^[a-z_.-]{1,32}$/i', (string)$key) ? (string)$key : 'field', array_slice(array_keys($meta), 0, 8))) : '';
    $target = e($row['target_type']).((int)($row['target_id'] ?? 0) > 0 ? ' #'.(int)$row['target_id'] : '');
    $body .= '<article class="audit-entry"><div class="audit-entry-id">#'.(int)$row['id'].'</div><div class="audit-entry-main"><strong>'.e($row['action']).'</strong><p>对象 '.$target.' <span>·</span> 操作人 #'.(int)($row['actor_id'] ?? 0).($summary !== '' ? ' <span>·</span> 字段 '.$summary : '').'</p></div><time datetime="'.e($row['created_at']).'">'.e($row['created_at']).'</time></article>';
}
$body .= '</section>';
if (count($rows) === 100) $body .= '<p class="audit-more"><a href="/admin/audit?before='.(int)end($rows)['id'].'">加载更早的记录</a></p>';
page('审计日志', $body, '', false, true);
return;
