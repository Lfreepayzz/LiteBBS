<?php
declare(strict_types=1);

function handle_admin_users_settings(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$post = $request->post;
if (!in_array($path, ['/admin/users', '/admin/settings', '/admin/content'], true) || !in_array($method, ['GET', 'POST'], true)) return;

if ($path === '/admin/content') {
    $contentAdmin = admin();
    if ($method !== 'POST') {
        $topics = h('SELECT id,title,is_pinned,is_locked FROM topics WHERE status="normal" ORDER BY id DESC LIMIT 100')->fetchAll();
        $body = '<div class="admin-list-toolbar"><p>管理最近发布的公开主题。</p><span>显示 '.count($topics).' 条主题</span></div>'
            .'<table class="admin-records admin-topic-list" role="table" aria-label="公开主题"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">标题</th><th scope="col" role="columnheader">状态</th><th scope="col" role="columnheader">编号</th></tr></thead><tbody role="rowgroup">';
        if (!$topics) $body .= '<tr role="row"><td role="cell" colspan="3" class="admin-record-empty">目前没有可管理的公开主题。</td></tr>';
        foreach ($topics as $topic) {
            $id = (int)$topic['id'];
            $state = ($topic['is_pinned'] ? '<span class="admin-record-state is-blue">置顶</span>' : '').($topic['is_locked'] ? '<span class="admin-record-state">已锁定</span>' : '<span class="admin-record-state">可回复</span>');
            $body .= '<tr role="row"><th scope="row" role="rowheader"><a class="admin-record-title" href="/t/'.$id.'">'.e($topic['title']).'</a><div class="admin-record-actions">';
            foreach ([['pin', $topic['is_pinned'] ? 0 : 1, $topic['is_pinned'] ? '取消置顶' : '置顶'], ['lock', $topic['is_locked'] ? 0 : 1, $topic['is_locked'] ? '解锁' : '锁定']] as [$action, $value, $label]) {
                $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="value" value="'.$value.'"><button type="submit">'.e($label).'</button></form>';
            }
            $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="delete"><button class="is-danger" type="submit">永久删除</button></form></div></th>'
                .'<td role="cell">'.$state.'</td><td role="cell" class="admin-record-meta">#'.$id.'</td></tr>';
        }
        $body .= '</tbody></table>';
        page('内容管理', $body);
        return;
    }
    require_post();
    $id = (int)($post['id'] ?? 0);
    $action = (string)($post['action'] ?? '');
    if ($action === 'delete') {
        if (empty($post['confirm_token'])) confirmation_page('确认永久删除','将永久删除主题、回复、修订和积分记录，此操作不可恢复。','topic.delete',['id'=>$id,'action'=>'delete'],(string)$id);
        require_confirmation('topic.delete',(string)$id,(string)$post['confirm_token']);
        permanently_delete_topic($id);
        redirect('/admin/content');
    }
    $columns = ['pin' => 'is_pinned', 'lock' => 'is_locked'];
    if (!$id || !isset($columns[$action])) fail(400, '操作无效');
    $column = $columns[$action];
    $value = filter_var($post['value'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
    if ($value === false || $value === null) fail(400, '状态值无效');
    moderate_topic($id, $column, $value, $action);
    redirect('/admin/content');
}

$actor = admin();
$config = load_config();

if ($method === 'POST') {
    require_post();

    if ($path === '/admin/users') {
        $id = (int)($post['id'] ?? 0);
        $role = (string)($post['role'] ?? 'member');
        $status = (string)($post['status'] ?? 'active');
        $developer = isset($post['is_developer']) ? 1 : 0;
        if (!$id || $id === (int)$actor['id'] || !in_array($role, ['member', 'moderator', 'admin'], true) || !in_array($status, ['active', 'disabled'], true)) fail(400, '用户操作无效');
        \LiteBBS\Application\transaction_manager()->run(static function () use ($id, $actor, $role, $status, $developer): void {
            $target = h('SELECT role,status FROM users WHERE id=? FOR UPDATE', [$id])->fetch();
            if (!$target) fail(404, '用户不存在');
            if (($role !== 'admin' || $status !== 'active') && $target['role'] === 'admin' && $target['status'] === 'active') {
                $activeAdmins = h('SELECT id FROM users WHERE role="admin" AND status="active" ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
                if (count($activeAdmins) <= 1) fail(409, '至少必须保留一名启用中的管理员');
            }
            h('UPDATE users SET role=?,status=?,is_developer=? WHERE id=?', [$role, $status, $developer, $id]);
            h('DELETE FROM sessions WHERE user_id=?', [$id]);
            audit('user.updated', 'user', $id, ['role' => $role, 'status' => $status, 'is_developer' => $developer]);
            extension_enqueue_async_event('user.updated', ['user_id'=>$id,'actor_id'=>(int)$actor['id'],'role'=>$role,'status'=>$status,'is_developer'=>$developer]);
        });
        redirect('/admin/users');
    }

    $site = trim((string)($post['site'] ?? ''));
    $registration = (string)($post['registration'] ?? '1');
    $interval = (int)($post['post_interval'] ?? 0);
    $topicRateMax = (int)($post['topic_rate_max'] ?? 0);
    $topicRateWindow = (int)($post['topic_rate_window'] ?? 300);
    $replyRateMax = (int)($post['reply_rate_max'] ?? 0);
    $replyRateWindow = (int)($post['reply_rate_window'] ?? 300);
    $locale = (string)($post['locale'] ?? 'zh-CN');
    $seoHomeDescription = trim((string)($post['seo_home_description'] ?? ''));
    $seoIndexing = (string)($post['seo_indexing'] ?? '1');
    $seoVerification = [];
    foreach (['google','bing','baidu','yandex','naver'] as $engine) $seoVerification[$engine] = trim((string)($post['seo_'.$engine.'_verification'] ?? ''));
    if (text_length($site) < 2 || text_length($site) > 100 || !in_array($registration, ['0', '1'], true) || $interval < 0 || $interval > 3600 || $topicRateMax < 0 || $topicRateMax > 1000 || $replyRateMax < 0 || $replyRateMax > 1000 || $topicRateWindow < 1 || $topicRateWindow > 86400 || $replyRateWindow < 1 || $replyRateWindow > 86400 || !in_array($locale, ['zh-CN', 'en-US'], true) || text_length($seoHomeDescription) > 220 || !in_array($seoIndexing, ['0', '1'], true)) fail(400, '设置值无效');
    foreach ($seoVerification as $value) if ($value !== '' && (!preg_match('/^[\x21-\x7E]{1,512}$/', $value) || strlen($value) > 512)) fail(400, '站长平台验证码格式无效');
    $config['site'] = $site;
    $config['locale'] = $locale;
    $config['seo'] = ['home_description'=>$seoHomeDescription, 'indexing'=>(int)$seoIndexing];
    foreach ($seoVerification as $engine=>$value) $config['seo'][$engine.'_verification'] = $value;
    try { write_config($config); } catch (Throwable $error) { fail(500, '配置写入失败'); }
    foreach (['registration'=>$registration,'post_interval'=>(string)$interval,'topic_rate_max'=>(string)$topicRateMax,'topic_rate_window'=>(string)$topicRateWindow,'reply_rate_max'=>(string)$replyRateMax,'reply_rate_window'=>(string)$replyRateWindow] as $key => $value) {
        h('INSERT INTO settings(setting_key,setting_value,updated_at) VALUES(?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)', [$key, $value]);
    }
    invalidate('settings');
    audit('settings.updated', 'settings', null);
    redirect('/admin/settings');
}

if ($path === '/admin/users') {
    $users = h('SELECT id,username,email,role,status,is_developer,points FROM users ORDER BY id DESC LIMIT 100')->fetchAll();
    $roles = ['member' => '成员', 'moderator' => '版主', 'admin' => '管理员'];
    $body = '<div class="admin-list-toolbar"><p>修改角色、状态或开发者权限后，该用户需要重新登录。</p><span>显示 '.count($users).' 位用户</span></div>'
        .'<table class="admin-records admin-user-list" role="table" aria-label="用户列表"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">用户名</th><th scope="col" role="columnheader">电子邮箱</th><th scope="col" role="columnheader">积分</th><th scope="col" role="columnheader">账户权限</th></tr></thead><tbody role="rowgroup">';
    if (!$users) $body .= '<tr role="row"><td role="cell" colspan="4" class="admin-record-empty">目前还没有注册用户。</td></tr>';
    foreach ($users as $row) {
        $id = (int)$row['id'];
        $self = $id === (int)$actor['id'];
        $body .= '<tr role="row"><th scope="row" role="rowheader"><div class="admin-user-identity"><span class="admin-user-avatar" aria-hidden="true">'.e(strtoupper(text_slice((string)$row['username'], 0, 1))).'</span><div><a class="admin-record-title" href="/u/'.rawurlencode((string)$row['username']).'">'.e($row['username']).'</a><span class="admin-record-meta">#'.$id.($self ? ' · 当前账户' : '').'</span></div></div></th>'
            .'<td role="cell" class="admin-user-email">'.e($row['email']).'</td><td role="cell" class="admin-user-points"><span class="admin-mobile-label">积分 </span>'.(int)$row['points'].'</td><td role="cell" class="admin-user-access">';
        if ($self) {
            $body .= '<span class="admin-record-state is-blue">管理员</span><span class="admin-record-state">正常</span>'.((int)$row['is_developer'] === 1 ? '<span class="admin-record-state">开发者</span>' : '');
        } else {
            $body .= '<form class="admin-user-form" method="post" aria-label="编辑用户 '.e($row['username']).'"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><label>角色<select class="admin-control" name="role">';
            foreach ($roles as $role => $label) $body .= '<option value="'.$role.'"'.($row['role'] === $role ? ' selected' : '').'>'.$label.'</option>';
            $body .= '</select></label><label>状态<select class="admin-control" name="status"><option value="active"'.($row['status'] === 'active' ? ' selected' : '').'>正常</option><option value="disabled"'.($row['status'] !== 'active' ? ' selected' : '').'>停用</option></select></label><label class="admin-user-developer"><input type="checkbox" name="is_developer" value="1"'.((int)$row['is_developer'] === 1 ? ' checked' : '').'>开发者</label><button class="admin-secondary" type="submit">保存</button></form>';
        }
        $body .= '</td></tr>';
    }
    $body .= '</tbody></table>';
    page('用户管理', $body);
    return;
}

$settings = [];
foreach (h('SELECT setting_key,setting_value FROM settings WHERE setting_key IN ("registration","post_interval","topic_rate_max","topic_rate_window","reply_rate_max","reply_rate_window")')->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];
$seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
$body = '<form class="admin-settings-form" method="post" action="/admin/settings"><input type="hidden" name="csrf" value="'.e(csrf()).'">'.
    '<section class="settings-section" id="site-general"><h2>社区信息</h2><div class="settings-grid">'.
    '<label>站点名称<input name="site" maxlength="100" value="'.e($config['site'] ?? 'LiteBBS').'" required></label>'.
    '<label>默认语言<select name="locale"><option value="zh-CN"'.(($config['locale'] ?? 'zh-CN') === 'zh-CN' ? ' selected' : '').'>简体中文</option><option value="en-US"'.(($config['locale'] ?? 'zh-CN') === 'en-US' ? ' selected' : '').'>English</option></select></label>'.
    '</div></section>'.
    '<section class="settings-section" id="site-community"><h2>注册与发帖</h2><div class="settings-grid">'.
    '<label>注册开关<select name="registration"><option value="1"'.(($settings['registration'] ?? '1') === '1' ? ' selected' : '').'>开放注册</option><option value="0"'.(($settings['registration'] ?? '1') === '0' ? ' selected' : '').'>暂停注册</option></select><small>关闭后，现有会员仍可正常登录和使用。</small></label>'.
    '<label>发帖间隔（秒）<input name="post_interval" type="number" min="0" max="3600" value="'.(int)($settings['post_interval'] ?? 0).'" inputmode="numeric"><small>设为 0 表示不额外限制；最大 3600 秒。</small></label>'.
    '<label>主题发布次数<input name="topic_rate_max" type="number" min="0" max="1000" value="'.(int)($settings['topic_rate_max'] ?? 0).'" inputmode="numeric"><small>设为 0 表示关闭主题次数限制。</small></label>'.
    '<label>主题统计周期（秒）<input name="topic_rate_window" type="number" min="1" max="86400" value="'.(int)($settings['topic_rate_window'] ?? 300).'" inputmode="numeric"><small>仅在主题发布次数大于 0 时生效。</small></label>'.
    '<label>回复发布次数<input name="reply_rate_max" type="number" min="0" max="1000" value="'.(int)($settings['reply_rate_max'] ?? 0).'" inputmode="numeric"><small>设为 0 表示关闭回复次数限制。</small></label>'.
    '<label>回复统计周期（秒）<input name="reply_rate_window" type="number" min="1" max="86400" value="'.(int)($settings['reply_rate_window'] ?? 300).'" inputmode="numeric"><small>仅在回复发布次数大于 0 时生效。</small></label>'.
    '</div></section>'.
    '<section class="settings-section" id="site-search"><h2>搜索展示</h2><div class="settings-grid">'.
    '<label>首页 SEO 描述<textarea name="seo_home_description" maxlength="220" rows="4" placeholder="用于首页搜索结果摘要与社交分享">'.e($seo['home_description'] ?? '').'</textarea><small>建议填写 50–160 个字符。</small></label>'.
    '<label>搜索引擎收录<select name="seo_indexing"><option value="1"'.((string)($seo['indexing'] ?? '1') !== '0' ? ' selected' : '').'>允许公开内容被收录</option><option value="0"'.((string)($seo['indexing'] ?? '1') === '0' ? ' selected' : '').'>全站暂不收录</option></select><small>暂停收录会在公开页输出 noindex，不会隐藏站内内容。</small></label>'.
    '</div></section>'.
    '<section class="settings-section" id="site-verification"><h2>站长平台验证</h2><p>选填各平台提供的验证码内容。</p><div class="settings-grid">'.
    '<label>Google Search Console<input name="seo_google_verification" maxlength="512" value="'.e($seo['google_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>Bing Webmaster Tools<input name="seo_bing_verification" maxlength="512" value="'.e($seo['bing_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>百度搜索资源平台<input name="seo_baidu_verification" maxlength="512" value="'.e($seo['baidu_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>Yandex Webmaster<input name="seo_yandex_verification" maxlength="512" value="'.e($seo['yandex_verification'] ?? '').'" autocomplete="off"></label>'.
    '<label>Naver Search Advisor<input name="seo_naver_verification" maxlength="512" value="'.e($seo['naver_verification'] ?? '').'" autocomplete="off"></label>'.
    '</div></section>'.
    '<footer class="settings-actions"><p><strong>安全说明</strong> 配置会写入私有目录；保存不会修改模板、插件配置或现有内容。</p><button class="primary" type="submit">保存更改</button></footer></form>';
page('站点设置', '<nav class="settings-sections" aria-label="设置分组"><a href="#site-general">社区信息</a><a href="#site-community">注册与发帖</a><a href="#site-search">搜索展示</a><a href="#site-verification">站长平台验证</a></nav>'.$body);
return;
}
