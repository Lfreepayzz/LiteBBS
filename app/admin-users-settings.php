<?php
declare(strict_types=1);

if (!in_array($path, ['/admin/users', '/admin/settings', '/admin/content'], true) || !in_array($method, ['GET', 'POST'], true)) return;

if ($path === '/admin/content') {
    $contentAdmin = admin();
    if ($method !== 'POST') {
        $body = '';
        foreach (h('SELECT id,title,is_pinned,is_locked FROM topics WHERE status="normal" ORDER BY id DESC LIMIT 100')->fetchAll() as $topic) {
            $id = (int)$topic['id'];
            $body .= '<article class="adminrow"><a href="/t/'.$id.'">'.e($topic['title']).'</a><div class="dashboard-actions">';
            foreach ([['pin', $topic['is_pinned'] ? 0 : 1, $topic['is_pinned'] ? '取消置顶' : '置顶'], ['lock', $topic['is_locked'] ? 0 : 1, $topic['is_locked'] ? '解锁' : '锁定']] as [$action, $value, $label]) {
                $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="value" value="'.$value.'"><button>'.e($label).'</button></form>';
            }
            $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="delete"><button>永久删除</button></form></div></article>';
        }
        page('内容管理', $body);
        exit;
    }
    require_post();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        if (empty($_POST['confirm_token'])) confirmation_page('确认永久删除','将永久删除主题、回复、修订和积分记录，此操作不可恢复。','topic.delete',['id'=>$id,'action'=>'delete'],(string)$id);
        require_confirmation('topic.delete',(string)$id,(string)$_POST['confirm_token']);
        permanently_delete_topic($id);
        redirect('/admin/content');
    }
    $columns = ['pin' => 'is_pinned', 'lock' => 'is_locked'];
    if (!$id || !isset($columns[$action])) fail(400, '操作无效');
    $column = $columns[$action];
    $value = filter_var($_POST['value'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
    if ($value === false || $value === null) fail(400, '状态值无效');
    moderate_topic($id, $column, $value, $action);
    redirect('/admin/content');
}

$actor = admin();
$config = load_config();

if ($method === 'POST') {
    require_post();

    if ($path === '/admin/users') {
        $id = (int)($_POST['id'] ?? 0);
        $role = (string)($_POST['role'] ?? 'member');
        $status = (string)($_POST['status'] ?? 'active');
        if (!$id || $id === (int)$actor['id'] || !in_array($role, ['member', 'moderator', 'admin'], true) || !in_array($status, ['active', 'disabled'], true)) fail(400, '用户操作无效');
        $pdo->beginTransaction();
        if ($role !== 'admin' || $status !== 'active') {
            $activeAdmins = h('SELECT id FROM users WHERE role="admin" AND status="active" FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $target = h('SELECT role,status FROM users WHERE id=? FOR UPDATE', [$id])->fetch();
            if (!$target || ($target['role'] === 'admin' && $target['status'] === 'active' && count($activeAdmins) <= 1)) {
                $pdo->rollBack();
                fail(409, '至少必须保留一名启用中的管理员');
            }
        }
        h('UPDATE users SET role=?,status=? WHERE id=?', [$role, $status, $id]);
        h('DELETE FROM sessions WHERE user_id=?', [$id]);
        if ($id === (int)($_SESSION['uid'] ?? 0)) { $_SESSION = []; session_destroy(); }
        audit('user.updated', 'user', $id, ['role' => $role, 'status' => $status]);
        $pdo->commit();
        redirect('/admin/users');
    }

    $site = trim((string)($_POST['site'] ?? ''));
    $registration = (string)($_POST['registration'] ?? '1');
    $interval = (int)($_POST['post_interval'] ?? 0);
    $locale = (string)($_POST['locale'] ?? 'zh-CN');
    if (text_length($site) < 2 || text_length($site) > 100 || !in_array($registration, ['0', '1'], true) || $interval < 0 || $interval > 3600 || !in_array($locale, ['zh-CN', 'en-US'], true)) fail(400, '设置值无效');
    $config['site'] = $site;
    $config['locale'] = $locale;
    try { write_config($config); } catch (Throwable $error) { fail(500, '配置写入失败'); }
    foreach (['registration' => $registration, 'post_interval' => (string)$interval] as $key => $value) {
        h('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)', [$key, $value]);
    }
    invalidate('settings');
    audit('settings.updated', 'settings', null);
    redirect('/admin/settings');
}

if ($path === '/admin/users') {
    $body = '';
    foreach (h('SELECT id,username,email,role,status,points FROM users ORDER BY id DESC LIMIT 100')->fetchAll() as $row) {
        $body .= '<form class="adminrow" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'">'.e($row['username']).' · '.e($row['email']).' · '.(int)$row['points'].'<select name="role">';
        foreach (['member' => '成员', 'moderator' => '版主', 'admin' => '管理员'] as $role => $label) $body .= '<option value="'.$role.'"'.($row['role'] === $role ? ' selected' : '').'>'.$label.'</option>';
        $body .= '</select><select name="status"><option value="active"'.($row['status'] === 'active' ? ' selected' : '').'>正常</option><option value="disabled"'.($row['status'] !== 'active' ? ' selected' : '').'>停用</option></select><button>保存并撤销会话</button></form>';
    }
    page('用户管理', $body);
    exit;
}

$settings = [];
foreach (h('SELECT setting_key,setting_value FROM settings WHERE setting_key IN ("registration","post_interval")')->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];
$body = form('/admin/settings', '保存设置',
    '<label>站点名称<input name="site" maxlength="100" value="'.e($config['site'] ?? 'LiteBBS').'" required></label>'.
    '<label>注册开关<select name="registration"><option value="1"'.(($settings['registration'] ?? '1') === '1' ? ' selected' : '').'>开放</option><option value="0"'.(($settings['registration'] ?? '1') === '0' ? ' selected' : '').'>关闭</option></select></label>'.
    '<label>发帖间隔（秒）<input name="post_interval" type="number" min="0" max="3600" value="'.(int)($settings['post_interval'] ?? 0).'"></label>'.
    '<label>默认语言<select name="locale"><option'.(($config['locale'] ?? 'zh-CN') === 'zh-CN' ? ' selected' : '').'>zh-CN</option><option'.(($config['locale'] ?? 'zh-CN') === 'en-US' ? ' selected' : '').'>en-US</option></select></label>');
page('站点设置', $body);
exit;
