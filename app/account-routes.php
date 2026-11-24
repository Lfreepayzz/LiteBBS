<?php
declare(strict_types=1);

use LiteBBS\Application\AccountRegistrationController;
use LiteBBS\Application\DuplicateAccountIdentity;
use LiteBBS\Application\PasswordPolicy;

function handle_account_routes(\LiteBBS\Framework\Request $request): void
{
$query = $request->query;
$post = $request->post;
if ($request->path === '/logout' && $request->method === 'POST') {
    require_post();
    if ($user = current_user()) h('DELETE FROM sessions WHERE id=? AND user_id=?', [$_SESSION['sid'], $user['id']]);
    start_session();
    $_SESSION = [];
    session_destroy();
    clear_session_cookie();
    redirect('/');
}

if ($request->path === '/register' && in_array($request->method, ['GET', 'POST'], true)) {
    (new AccountRegistrationController())($request);
    return;
}

if ($request->path === '/login' && in_array($request->method, ['GET', 'POST'], true)) {
    if ($request->method === 'POST') {
        require_post();
        $identity = trim((string)($post['account'] ?? ''));
        $password = (string)($post['password'] ?? '');
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($identity, 'UTF-8') : strtolower($identity);
        limit_action('login-ip', 10, 900, hash('sha256', (string)($request->server['REMOTE_ADDR'] ?? '')));
        limit_action('login-account', 5, 900, hash('sha256', $normalized));
        $identityColumn = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = h('SELECT id,password_hash,status FROM users WHERE '.$identityColumn.'=? LIMIT 1', [$identity])->fetch();
        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) h('UPDATE users SET password_hash=? WHERE id=?', [password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), $user['id']]);
            login_user((int)$user['id']);
            redirect('/');
        }
        $error = '账号或密码不正确。';
    }
    page('登录', '<p class="error">'.e($error ?? '欢迎回来。').'</p>'.form('/login', '登录', input('account', '用户名或邮箱').input('password', '密码', 'password'), '登录'));
    return;
}

if ($request->path === '/me' && in_array($request->method, ['GET', 'POST'], true)) {
    $user = current_user();
    if (!$user) redirect('/login');
    $tab = (string)($query['tab'] ?? 'overview');
    if ($request->method === 'POST') {
        require_post();
        $action = (string)($post['action'] ?? 'profile');
        if ($action === 'password') {
            $current = (string)($post['current_password'] ?? '');
            $new = $post['new_password'] ?? '';
            $hash = (string)h('SELECT password_hash FROM users WHERE id=?', [$user['id']])->fetchColumn();
            if (!password_verify($current, $hash) || !is_string($new) || !PasswordPolicy::accepts($new)) fail(400, '当前密码不正确，或新密码长度不符合要求');
            \LiteBBS\Application\transaction_manager()->run(static function () use ($new, $user): void {
                h('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), $user['id']]);
                h('DELETE FROM sessions WHERE user_id=?', [$user['id']]);
                audit('account.password.changed', 'user', (int)$user['id']);
            });
            login_user((int)$user['id']);
            redirect('/me?tab=security');
        }
        if ($action === 'profile') {
            $email = trim((string)($post['email'] ?? ''));
            $bio = trim((string)($post['bio'] ?? ''));
            $locale = (string)($post['locale'] ?? 'zh-CN');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($bio) > 500 || !in_array($locale, ['zh-CN', 'en-US'], true)) fail(400, '资料格式无效');
            try { h('UPDATE users SET email=?,bio=?,locale=? WHERE id=?', [$email, $bio, $locale, $user['id']]); }
            catch (PDOException $error) {
                if (!DuplicateAccountIdentity::matches($error)) throw $error;
                fail(409, '该邮箱已被使用');
            }
            extension_event_async('user.profile.updated', ['user_id'=>(int)$user['id']]);
            redirect('/me?tab=profile');
        }
        if ($action === 'notifications-read') {
            h('UPDATE notifications SET read_at=UTC_TIMESTAMP(6),coalesce_topic_id=NULL WHERE user_id=? AND read_at IS NULL', [$user['id']]);
            invalidate_user_cache((int)$user['id']);
            redirect('/me?tab=notifications');
        }
        if ($action === 'notifications-clear') {
            h('DELETE FROM notifications WHERE user_id=?', [$user['id']]);
            invalidate_user_cache((int)$user['id']);
            redirect('/me?tab=notifications');
        }
        fail(400, '操作无效');
    }
    $user = h('SELECT id,username,email,role,points,locale,bio,created_at FROM users WHERE id=?', [$user['id']])->fetch();
    $tabs = ['overview'=>'概览', 'profile'=>'个人资料', 'security'=>'账户安全', 'notifications'=>'通知', 'messages'=>'私信', 'bookmarks'=>'收藏', 'blocks'=>'屏蔽用户'];
    $body = '<section class="member-center"><header class="member-hero"><span class="member-avatar">'.e(text_slice($user['username'], 0, 1)).'</span><div><p class="eyebrow">ACCOUNT CENTER</p><h1>'.e($user['username']).'</h1><p>'.e($user['email']).' · 积分 '.(int)$user['points'].'</p></div><a href="/u/'.rawurlencode($user['username']).'">公开资料</a></header><nav class="member-tabs" aria-label="会员中心">';
    foreach ($tabs as $key=>$label) $body .= '<a href="'.($key === 'messages' ? '/messages' : '/me?tab='.$key).'"'.($tab === $key ? ' aria-current="page"' : '').'>'.$label.'</a>';
    $body .= '</nav><div class="member-panel">';
    if ($tab === 'profile') $body .= '<h2>个人资料</h2><form class="member-form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="profile"><label>用户名<input value="'.e($user['username']).'" disabled></label><label>邮箱<input type="email" name="email" maxlength="254" value="'.e($user['email']).'" required></label><label>个人介绍<textarea name="bio" maxlength="500" rows="5">'.e($user['bio']).'</textarea></label><label>界面语言<select name="locale"><option value="zh-CN"'.($user['locale'] === 'zh-CN' ? ' selected' : '').'>简体中文</option><option value="en-US"'.($user['locale'] === 'en-US' ? ' selected' : '').'>English</option></select></label><button class="primary">保存资料</button></form><form id="avatar-form" class="member-form avatar-form" method="post" action="/avatar/upload" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.e(csrf()).'"><label>头像<input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required></label><button>上传头像</button></form>';
    elseif ($tab === 'security') $body .= '<h2>修改密码</h2><form class="member-form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="password"><label>当前密码<input type="password" name="current_password" autocomplete="current-password" required></label><label>新密码<input type="password" name="new_password" minlength="'.PasswordPolicy::MIN_CHARACTERS.'" maxlength="'.PasswordPolicy::MAX_BYTES.'" autocomplete="new-password" required><small>至少 '.PasswordPolicy::MIN_CHARACTERS.' 个字符，最多 '.PasswordPolicy::MAX_BYTES.' 字节。保存后会退出其他设备。</small></label><button class="primary">更新密码</button></form>';
    elseif ($tab === 'notifications') {
        $rows = h('SELECT n.topic_id,n.post_id,n.kind,n.created_at,u.username,t.title FROM notifications n JOIN users u ON u.id=n.actor_id LEFT JOIN topics t ON t.id=n.topic_id LEFT JOIN categories c ON c.id=t.category_id WHERE n.user_id=? AND (n.kind="message" OR (t.status="normal" AND c.is_visible=1)) ORDER BY n.created_at DESC,n.id DESC LIMIT 30', [$user['id']])->fetchAll();
        $body .= '<h2>通知</h2><div class="member-actions"><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="notifications-read"><button>全部标记为已读</button></form><form class="member-clear-notifications" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="notifications-clear"><button>清空通知</button></form></div><div class="member-list">';
        foreach ($rows as $row) $body .= '<a class="member-row" href="'.e($row['kind'] === 'message' ? '/messages?to='.rawurlencode($row['username']) : '/t/'.(int)$row['topic_id'].'#p'.(int)$row['post_id']).'"><span><strong>'.e($row['username']).'</strong> '.e($row['kind'] === 'message' ? '给你发送了私信' : '回复了你的主题').'</span><span>'.e((string)($row['title'] ?? '')).'</span><time>'.e(substr((string)$row['created_at'], 0, 16)).'</time></a>';
        $body .= $rows ? '' : '<p class="empty-state">暂无通知</p>';
        $body .= '</div>';
    } elseif ($tab === 'bookmarks') {
        $rows = h('SELECT t.id,t.title,t.reply_count,t.last_post_at FROM bookmarks b JOIN topics t ON t.id=b.topic_id JOIN categories c ON c.id=t.category_id WHERE b.user_id=? AND t.status="normal" AND c.is_visible=1 ORDER BY b.created_at DESC LIMIT 50', [$user['id']])->fetchAll();
        $body .= '<h2>收藏的主题</h2><div class="member-list">';
        foreach ($rows as $row) $body .= '<a class="member-row" href="/t/'.(int)$row['id'].'"><strong>'.e($row['title']).'</strong><span>'.(int)$row['reply_count'].' 条回复</span><time>'.e(substr((string)$row['last_post_at'], 0, 16)).'</time></a>';
        $body .= $rows ? '' : '<p class="empty-state">还没有收藏主题</p>';
        $body .= '</div>';
    } elseif ($tab === 'blocks') {
        $rows = h('SELECT u.id,u.username FROM user_blocks b JOIN users u ON u.id=b.blocked_user_id WHERE b.user_id=? ORDER BY b.created_at DESC', [$user['id']])->fetchAll();
        $body .= '<h2>屏蔽用户</h2><div class="member-list">';
        foreach ($rows as $row) $body .= '<div class="member-row"><a href="/u/'.rawurlencode($row['username']).'">'.e($row['username']).'</a><form method="post" action="/user/'.(int)$row['id'].'/unblock"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>取消屏蔽</button></form></div>';
        $body .= $rows ? '' : '<p class="empty-state">没有屏蔽用户</p>';
        $body .= '</div>';
    } else {
        $posts = h('SELECT p.id,p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.user_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 ORDER BY p.id DESC LIMIT 20', [$user['id']])->fetchAll();
        [$unreadNotifications,$unreadMessages] = unread_counts((int)$user['id']);
        $bookmarks = (int)h('SELECT COUNT(*) FROM bookmarks WHERE user_id=?', [$user['id']])->fetchColumn();
        $body .= '<div class="member-stats"><a href="/me?tab=notifications"><strong>'.$unreadNotifications.'</strong><span>未读通知</span></a><a href="/me?tab=bookmarks"><strong>'.$bookmarks.'</strong><span>收藏主题</span></a><a href="/messages"><strong>'.$unreadMessages.'</strong><span>未读私信</span></a></div><h2>最近发帖</h2><div class="member-list">';
        foreach ($posts as $post) $body .= '<article class="member-row"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><span>'.e(text_slice($post['body_markdown'], 0, 100)).'</span><a href="/p/'.(int)$post['id'].'/edit">编辑</a></article>';
        $body .= $posts ? '' : '<p class="empty-state">还没有发布内容</p>';
        $body .= '</div>';
    }
    page('会员中心', $body.'</div></section>');
    return;
}

if (preg_match('~^/u/([^/]+)$~', $request->path, $match) && $request->method === 'GET') {
    $name = rawurldecode($match[1]);
    $profile = h('SELECT id,username,points,bio,avatar_path,created_at FROM users WHERE username=? AND status="active"', [$name])->fetch();
    if (!$profile) fail(404, '用户不存在');
    $viewer = current_user();
    $posts = h('SELECT p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.user_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 ORDER BY p.id DESC LIMIT 20', [$profile['id']])->fetchAll();
    $avatar = !empty($profile['avatar_path']) ? '<img src="/avatar/'.(int)$profile['id'].'?v='.rawurlencode((string)$profile['avatar_path']).'" alt="">' : e(text_slice($profile['username'], 0, 1));
    $body = '<section class="member-center"><header class="member-hero"><span class="member-avatar">'.$avatar.'</span><div><h1>'.e($profile['username']).'</h1><p>积分 '.(int)$profile['points'].' · 加入于 '.e(substr((string)$profile['created_at'], 0, 10)).'</p></div></header><p class="public-bio">'.nl2br(e((string)$profile['bio'])).'</p>';
    if ($viewer && (int)$viewer['id'] !== (int)$profile['id']) $body .= '<div class="topic-actions"><a href="/messages?to='.rawurlencode($profile['username']).'">发送私信</a><form method="post" action="/user/'.(int)$profile['id'].'/block"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>屏蔽用户</button></form><details class="report-control"><summary>举报用户</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="user"><input type="hidden" name="target_id" value="'.(int)$profile['id'].'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details></div>';
    $body .= '<h2>最近发布</h2>';
    foreach ($posts as $post) $body .= '<article class="member-post"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><p>'.e(text_slice($post['body_markdown'], 0, 180)).'</p></article>';
    page($profile['username'], $body.'</section>');
    return;
}

if ($request->path === '/search' && $request->method === 'GET') {
    $term = trim((string)($query['q'] ?? ''));
    if (text_length($term) > 60) fail(400, '搜索词不能超过 60 个字符');
    $body = '<form class="search"><label>搜索主题标题<input name="q" maxlength="60" value="'.e($term).'"></label><button>搜索</button></form>';
    if ($term !== '') {
        if (text_length($term) < 3) $body .= '<p>请输入至少三个字符。</p>';
        else {
            $viewer = current_user();
            limit_action('search', 10, 300, $viewer ? 'user:'.$viewer['id'] : 'ip:'.($request->server['REMOTE_ADDR'] ?? ''));
            $ascii = preg_match('/^[\x00-\x7F]+$/', $term) === 1;
            $sql = $ascii ? 'MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE)' : 't.title LIKE ?';
            $arg = $ascii ? $term : $term.'%';
            $versions = cache_versions(['topics', 'categories']);
            $rows = cached('search:v2:'.hash('sha256', ($ascii ? 'fulltext:' : 'prefix:').$term).':'.(int)$versions['topics'].':'.(int)$versions['categories'], 30, static function () use ($sql, $arg): array {
                return h('SELECT t.id,t.title,t.reply_count,t.last_post_at,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1 AND '.$sql.' ORDER BY t.last_post_at DESC LIMIT 50', [$arg])->fetchAll();
            });
            foreach ($rows as $row) $body .= '<article class="topic"><a href="/c/'.(int)$row['category_id'].'">'.e($row['category']).'</a><h2><a href="/t/'.(int)$row['id'].'">'.e($row['title']).'</a></h2></article>';
            if (!$rows) $body .= '<p>没有找到匹配的主题。</p>';
        }
    }
    page('搜索讨论', $body, '仅搜索公开主题标题；中文搜索匹配标题前缀。');
}
}
