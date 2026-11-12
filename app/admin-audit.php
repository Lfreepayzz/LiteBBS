<?php
declare(strict_types=1);

if ($path !== '/admin/audit' || $method !== 'GET') return;
admin();
$before = max(0, (int)($_GET['before'] ?? 0));
$sql = 'SELECT id,actor_id,action,target_type,target_id,metadata_json,created_at FROM audit_logs'.($before ? ' WHERE id<?' : '').' ORDER BY id DESC LIMIT 100';
$rows = h($sql, $before ? [$before] : [])->fetchAll();
$body = '<p class="admin-intro">仅显示操作类型、对象和经过限制的安全元数据摘要。</p>';
foreach ($rows as $row) {
    $meta = json_decode((string)$row['metadata_json'], true);
    $summary = is_array($meta) ? implode(', ', array_map(static fn($k) => preg_match('/^[a-z_.-]{1,32}$/i', (string)$k) ? $k : 'field', array_slice(array_keys($meta), 0, 8))) : '';
    $body .= '<article class="adminrow"><strong>#'.(int)$row['id'].' '.e($row['action']).'</strong> · '.e($row['target_type']).' #'.(int)($row['target_id'] ?? 0).' · actor #'.(int)($row['actor_id'] ?? 0).' · '.e($row['created_at']).($summary !== '' ? ' · metadata: '.e($summary) : '').'</article>';
}
if (count($rows) === 100) $body .= '<p><a href="/admin/audit?before='.(int)end($rows)['id'].'">更早的记录</a></p>';
page('审计日志', $body, '', false, true);
exit;
