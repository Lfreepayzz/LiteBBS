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
        return;
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
        $developer = isset($_POST['is_developer']) ? 1 : 0;
        if (!$id || $id === (int)$actor['id'] || !in_array($role, ['member', 'moderator', 'admin'], true) || !in_array($status, ['active', 'disabled'], true)) fail(400, '用户操作无效');
        $db = db();
        $db->beginTransaction();
        $target = h('SELECT role,status FROM users WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$target) {
            $db->rollBack();
            fail(404, '用户不存在');
        }
        if ($role !== 'admin' || $status !== 'active') {
            $activeAdmins = h('SELECT id FROM users WHERE role="admin" AND status="active" FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            if ($target['role'] === 'admin' && $target['status'] === 'active' && count($activeAdmins) <= 1) {
                $db->rollBack();
                fail(409, '至少必须保留一名启用中的管理员');
            }
        }
        h('UPDATE users SET role=?,status=?,is_developer=? WHERE id=?', [$role, $status, $developer, $id]);
        h('DELETE FROM sessions WHERE user_id=?', [$id]);
        if ($id === (int)($_SESSION['uid'] ?? 0)) { start_session(); $_SESSION = []; session_destroy(); }
        audit('user.updated', 'user', $id, ['role' => $role, 'status' => $status, 'is_developer' => $developer]);
        $db->commit();
        redirect('/admin/users');
    }

    $site = trim((string)($_POST['site'] ?? ''));
    $registration = (string)($_POST['registration'] ?? '1');
    $interval = (int)($_POST['post_interval'] ?? 0);
    $locale = (string)($_POST['locale'] ?? 'zh-CN');
    $seoHomeDescription = trim((string)($_POST['seo_home_description'] ?? ''));
    $seoIndexing = (string)($_POST['seo_indexing'] ?? '1');
    $seoVerification = [];
    foreach (['google','bing','baidu','yandex','naver'] as $engine) $seoVerification[$engine] = trim((string)($_POST['seo_'.$engine.'_verification'] ?? ''));
    if (text_length($site) < 2 || text_length($site) > 100 || !in_array($registration, ['0', '1'], true) || $interval < 0 || $interval > 3600 || !in_array($locale, ['zh-CN', 'en-US'], true) || text_length($seoHomeDescription) > 220 || !in_array($seoIndexing, ['0', '1'], true)) fail(400, '设置值无效');
    foreach ($seoVerification as $value) if ($value !== '' && (!preg_match('/^[\x21-\x7E]{1,512}$/', $value) || strlen($value) > 512)) fail(400, '站长平台验证码格式无效');
    $config['site'] = $site;
    $config['locale'] = $locale;
    $config['seo'] = ['home_description'=>$seoHomeDescription, 'indexing'=>(int)$seoIndexing];
    foreach ($seoVerification as $engine=>$value) $config['seo'][$engine.'_verification'] = $value;
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
    foreach (h('SELECT id,username,email,role,status,is_developer,points FROM users ORDER BY id DESC LIMIT 100')->fetchAll() as $row) {
        $body .= '<form class="adminrow" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'">'.e($row['username']).' · '.e($row['email']).' · '.(int)$row['points'].'<select name="role">';
        foreach (['member' => '成员', 'moderator' => '版主', 'admin' => '管理员'] as $role => $label) $body .= '<option value="'.$role.'"'.($row['role'] === $role ? ' selected' : '').'>'.$label.'</option>';
        $body .= '</select><select name="status"><option value="active"'.($row['status'] === 'active' ? ' selected' : '').'>正常</option><option value="disabled"'.($row['status'] !== 'active' ? ' selected' : '').'>停用</option></select><label><input type="checkbox" name="is_developer" value="1"'.((int)$row['is_developer'] === 1 ? ' checked' : '').'>开发者权限</label><button>保存并撤销会话</button></form>';
    }
    page('用户管理', $body);
    return;
}

$settings = [];
foreach (h('SELECT setting_key,setting_value FROM settings WHERE setting_key IN ("registration","post_interval")')->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];
$seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
$body = '<form class="admin-settings-form" method="post" action="/admin/settings"><input type="hidden" name="csrf" value="'.e(csrf()).'">'.
    '<section class="settings-section"><div class="settings-section-heading"><span>01</span><div><h3>社区信息</h3><p>用于浏览器标题、站点标识和默认界面语言。</p></div></div><div class="settings-grid settings-grid-compact">'.
    '<label>站点名称<input name="site" maxlength="100" value="'.e($config['site'] ?? 'LiteBBS').'" required></label>'.
    '<label>默认语言<select name="locale"><option value="zh-CN"'.(($config['locale'] ?? 'zh-CN') === 'zh-CN' ? ' selected' : '').'>简体中文</option><option value="en-US"'.(($config['locale'] ?? 'zh-CN') === 'en-US' ? ' selected' : '').'>English</option></select></label>'.
    '</div></section>'.
    '<section class="settings-section"><div class="settings-section-heading"><span>02</span><div><h3>社区行为</h3><p>控制新成员进入与公开内容发布的节奏。</p></div></div><div class="settings-grid settings-grid-compact">'.
    '<label>注册开关<select name="registration"><option value="1"'.(($settings['registration'] ?? '1') === '1' ? ' selected' : '').'>开放注册</option><option value="0"'.(($settings['registration'] ?? '1') === '0' ? ' selected' : '').'>暂停注册</option></select><small>关闭后，现有会员仍可正常登录和使用。</small></label>'.
    '<label>发帖间隔（秒）<input name="post_interval" type="number" min="0" max="3600" value="'.(int)($settings['post_interval'] ?? 0).'" inputmode="numeric"><small>设为 0 表示不额外限制；最大 3600 秒。</small></label>'.
    '</div></section>'.
    '<section class="settings-section"><div class="settings-section-heading"><span>03</span><div><h3>搜索展示</h3><p>设置首页摘要和搜索引擎公开收录策略。</p></div></div><div class="settings-grid">'.
    '<label class="settings-wide">首页 SEO 描述<textarea name="seo_home_description" maxlength="220" rows="4" placeholder="用于首页搜索结果摘要与社交分享">'.e($seo['home_description'] ?? '').'</textarea><small>建议 50-160 个字符；主题和分区页仍使用各自内容摘要。</small></label>'.
    '<label>搜索引擎收录<select name="seo_indexing"><option value="1"'.((string)($seo['indexing'] ?? '1') !== '0' ? ' selected' : '').'>允许公开内容被收录</option><option value="0"'.((string)($seo['indexing'] ?? '1') === '0' ? ' selected' : '').'>全站暂不收录</option></select><small>暂停收录会在公开页输出 noindex，不会隐藏站内内容。</small></label>'.
    '</div></section>'.
    '<section class="settings-section settings-verification"><div class="settings-section-heading"><span>04</span><div><h3>站长平台验证</h3><p>可选。只填写各平台提供的验证码内容，不要粘贴完整 meta 标签。</p></div></div><div class="settings-grid">'.
    '<label>Google Search Console<input name="seo_google_verification" maxlength="512" value="'.e($seo['google_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>Bing Webmaster Tools<input name="seo_bing_verification" maxlength="512" value="'.e($seo['bing_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>百度搜索资源平台<input name="seo_baidu_verification" maxlength="512" value="'.e($seo['baidu_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>Yandex Webmaster<input name="seo_yandex_verification" maxlength="512" value="'.e($seo['yandex_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label class="settings-wide">Naver Search Advisor<input name="seo_naver_verification" maxlength="512" value="'.e($seo['naver_verification'] ?? '').'" autocomplete="off"></label>'.
    '</div></section>'.
    '<footer class="settings-actions"><p><strong>安全说明</strong> 配置会写入私有目录；保存不会修改模板、插件配置或现有内容。</p><button class="primary" type="submit">保存更改</button></footer></form>';
page('站点设置', $body);
return;
