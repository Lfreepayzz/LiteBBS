<?php
declare(strict_types=1);

use LiteBBS\Framework\Request;
use LiteBBS\Framework\Response;

final class FriendLinksPlugin implements LiteBBSPlugin
{
    public function boot(PluginContext $context): void
    {
        $context->assets()->css('assets/style.css');
        $context->slots()->add('sidebar.sections', static fn(array $view): string => ($view['path'] ?? '') === '/' ? friend_links_render_sidebar() : '');
        $context->routes()->front('GET', '~^/friend-links/apply$~', static function (Request $request): Response {
            return friend_links_application_response($request);
        });
        $context->routes()->front('POST', '~^/friend-links/apply$~', static function (Request $request): Response {
            return friend_links_application_response($request);
        }, ['csrf'=>true]);
        $context->admin()->page('links', '友情链接管理', static function (Request $request): array {
            return friend_links_admin_page($request);
        }, [
            'default' => true,
            'navigation' => '友情链接',
        ]);
    }
}

function friend_links_render_sidebar(): string
{
    $cacheKey = 'plugin:friend-links:sidebar';
    $links = cached($cacheKey.':'.cache_version($cacheKey), 60, static fn(): array => h('SELECT title,url,description FROM plg_friend_links_links WHERE status="active" ORDER BY created_at DESC,id DESC LIMIT 20')->fetchAll());
    $html = '<div class="friend-links-sidebar"><div class="friend-links-heading"><h2>友情链接</h2><a href="/friend-links/apply">申请</a></div>';
    if (!$links) return $html.'<p class="friend-links-help">暂无友情链接。</p></div>';
    $html .= '<div class="friend-links-list">';
    foreach ($links as $link) {
        $html .= '<a href="'.e($link['url']).'" target="_blank" rel="nofollow noopener noreferrer"><span class="friend-links-item"><strong>'.e($link['title']).'</strong>';
        if ((string)$link['description'] !== '') $html .= '<small>'.e($link['description']).'</small>';
        $html .= '</span></a>';
    }
    return $html.'</div></div>';
}

function friend_links_application_response(Request $request): Response
{
    $message = '';
    if ($request->method === 'POST') {
        limit_action('friend-links-apply', 3, 3600, hash('sha256', (string)($request->server['REMOTE_ADDR'] ?? '')), '申请过于频繁，请一小时后再试。');
        $title = trim((string)($request->post['title'] ?? ''));
        $url = friend_links_normalize_url((string)($request->post['url'] ?? ''));
        $sourceUrl = friend_links_normalize_url((string)($request->post['source_url'] ?? ''));
        $description = trim((string)($request->post['description'] ?? ''));
        if (text_length($title) < 2 || text_length($title) > 100) fail(400, '网站名称应为 2 至 100 个字符。');
        if ($url === null || $sourceUrl === null) fail(400, '请填写有效的 HTTPS 网站地址和本站链接所在页。');
        if (text_length($description) > 255) fail(400, '网站简介不能超过 255 个字符。');
        try {
            h('INSERT INTO plg_friend_links_links(title,url,url_hash,description,source_url,status,created_at,updated_at) VALUES(?,?,?,?,?,"pending",UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$title, $url, hash('sha256', $url), $description, $sourceUrl]);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1062) fail(409, '该网站已经是友情链接。');
            throw $error;
        }
        audit('friend_links.applied', 'plugin_friend_link', null, ['url' => $url, 'source_url' => $sourceUrl]);
        friend_links_invalidate_sidebar();
        $message = '申请已提交，管理员确认对方页面包含本站链接后会启用友情链接。';
    }

    $body = '<section class="friend-links-application"><header class="friend-links-application-header"><span class="friend-links-eyebrow">LINK EXCHANGE</span><h1>申请友情链接</h1><p>先在贵站放置本站 HTTPS 链接，再提交申请。管理员将按您提供的页面进行确认，审核通过后会在侧边栏展示。</p></header><ol class="friend-links-steps"><li><span>01</span><div><strong>添加本站链接</strong><small>在贵站公开页面放置本站 HTTPS 链接。</small></div></li><li><span>02</span><div><strong>提交网站信息</strong><small>填写网站地址和本站链接所在的具体页面。</small></div></li><li><span>03</span><div><strong>等待确认</strong><small>管理员确认后，友情链接将自动启用。</small></div></li></ol>';
    if ($message !== '') $body .= '<p class="friend-links-message">'.e($message).'</p>';
    $body .= '<form class="form friend-links-form" method="post" action="/friend-links/apply"><input type="hidden" name="csrf" value="'.e(csrf()).'"><h2>网站信息</h2><label>网站名称<input name="title" maxlength="100" placeholder="例如：示例社区" required><small>用于侧边栏显示，2 至 100 个字符。</small></label><label>友情链接地址<input type="url" name="url" maxlength="2048" placeholder="https://example.com/" required><small>访客点击后将前往此地址。</small></label><label>本站链接所在页<input type="url" name="source_url" maxlength="2048" placeholder="https://example.com/links" required><small>请填写已经放置本站链接的具体公开页面。</small></label><label>网站简介 <em>可选</em><textarea name="description" maxlength="255" rows="3" placeholder="用一句话介绍您的网站内容与定位。"></textarea><small>最多 255 个字符。</small></label><footer class="friend-links-submit"><span>提交即表示您确认链接页面可公开访问。</span><button class="primary" type="submit">提交友情链接申请</button></footer></form></section>';
    ob_start();
    page('申请友情链接', $body);
    return new Response((string)ob_get_clean());
}

function friend_links_admin_page(Request $request): array
{
    if ($request->method === 'POST') {
        $action = (string)($request->post['action'] ?? '');
        if ($action === 'add') {
            $title = trim((string)($request->post['title'] ?? ''));
            $url = friend_links_normalize_url((string)($request->post['url'] ?? ''));
            $description = trim((string)($request->post['description'] ?? ''));
            if (text_length($title) < 2 || text_length($title) > 100 || $url === null || text_length($description) > 255) fail(400, '友情链接信息无效。');
            try {
                h('INSERT INTO plg_friend_links_links(title,url,url_hash,description,status,verified_at,last_checked_at,created_at,updated_at) VALUES(?,?,?,?,"active",UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$title, $url, hash('sha256', $url), $description]);
            } catch (PDOException $error) {
                if ((int)($error->errorInfo[1] ?? 0) === 1062) fail(409, '该网站已经是友情链接。');
                throw $error;
            }
            friend_links_invalidate_sidebar();
            audit('friend_links.added', 'plugin_friend_link', null, ['url' => $url]);
        } elseif (in_array($action, ['approve', 'toggle', 'delete'], true)) {
            $id = (int)($request->post['id'] ?? 0);
            $link = $id > 0 ? h('SELECT id,status,url FROM plg_friend_links_links WHERE id=?', [$id])->fetch() : false;
            if (!$link) fail(404, '友情链接不存在。');
            if ($action === 'approve') {
                $updated = h('UPDATE plg_friend_links_links SET status="active",verified_at=UTC_TIMESTAMP(6),last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status="pending"', [$id]);
                if ($updated->rowCount() !== 1) fail(409, '该友情链接不是待审核状态。');
                friend_links_invalidate_sidebar();
                audit('friend_links.confirmed', 'plugin_friend_link', $id, ['url' => $link['url']]);
            } elseif ($action === 'toggle') {
                $previous = (string)$link['status'];
                if ($previous === 'pending') fail(409, '请先核验互链，再确认并启用。');
                $status = $previous === 'active' ? 'disabled' : 'active';
                $updated = h('UPDATE plg_friend_links_links SET status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status=?', [$status, $id, $previous]);
                if ($updated->rowCount() !== 1) fail(409, '友情链接状态已变化，请刷新后重试。');
                friend_links_invalidate_sidebar();
                audit('friend_links.status_changed', 'plugin_friend_link', $id, ['status' => $status, 'url' => $link['url']]);
            } else {
                h('DELETE FROM plg_friend_links_links WHERE id=?', [$id]);
                friend_links_invalidate_sidebar();
                audit('friend_links.deleted', 'plugin_friend_link', $id, ['url' => $link['url']]);
            }
        } else {
            fail(400, '友情链接管理操作无效。');
        }
        redirect('/admin/plugins?plugin=friend-links&page=links');
    }

    $links = h('SELECT id,title,url,description,source_url,status,verified_at,last_checked_at,created_at FROM plg_friend_links_links ORDER BY created_at DESC,id DESC')->fetchAll();
    $counts = ['pending' => 0, 'active' => 0, 'disabled' => 0];
    foreach ($links as $link) $counts[(string)$link['status']]++;
    $body = '<section class="friend-links-admin"><header class="friend-links-admin-hero"><div><p class="friend-links-eyebrow">PARTNER DIRECTORY</p><h2>友情链接管理</h2><p>审核站点互链申请，维护已展示的合作伙伴链接。确认前请打开对方提供的验证页面，核验本站 HTTPS 链接。</p></div><div class="friend-links-admin-stats"><span><strong>'.(int)$counts['pending'].'</strong> 待核验</span><span><strong>'.(int)$counts['active'].'</strong> 已启用</span><span><strong>'.(int)$counts['disabled'].'</strong> 已停用</span></div></header><section class="friend-links-admin-create"><div class="friend-links-admin-section-heading"><span>+</span><div><h3>手工新增</h3><p>直接添加一个已确认的友情链接，保存后会立即在侧边栏展示。</p></div></div><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="add"><label>网站名称<input name="title" maxlength="100" placeholder="例如：示例社区" required></label><label>网站地址<input type="url" name="url" maxlength="2048" placeholder="https://example.com/" required></label><label class="friend-links-admin-description">网站简介 <em>可选</em><textarea name="description" maxlength="255" rows="3" placeholder="用一句话说明网站内容与定位。"></textarea></label><div class="friend-links-admin-create-footer"><span>新增链接默认启用，并记录为已核验。</span><button class="primary" type="submit">新增并启用</button></div></form></section><section class="friend-links-admin-directory"><div class="friend-links-admin-directory-heading"><div><h3>链接目录</h3><p>'.count($links).' 条记录</p></div></div><div class="friend-links-admin-list">';
    if (!$links) $body .= '<p class="friend-links-help">尚未添加友情链接。</p>';
    foreach ($links as $link) {
        $status = (string)$link['status'];
        $active = $status === 'active';
        $pending = $status === 'pending';
        $statusLabel = $active ? '已启用' : ($pending ? '待核验' : '已停用');
        $body .= '<article class="friend-links-admin-row is-'.e($status).'"><div class="friend-links-admin-row-main"><div class="friend-links-admin-row-title"><h3>'.e($link['title']).'</h3><span class="friend-links-status'.($active ? '' : ($pending ? ' is-pending' : ' is-disabled')).'">'.$statusLabel.'</span></div><p class="friend-links-admin-url"><a href="'.e($link['url']).'" target="_blank" rel="noopener noreferrer">'.e($link['url']).'</a></p>';
        if ((string)$link['description'] !== '') $body .= '<p>'.e($link['description']).'</p>';
        if ((string)($link['source_url'] ?? '') !== '') $body .= '<p class="friend-links-meta">互链验证页：<a href="'.e($link['source_url']).'" target="_blank" rel="noopener noreferrer">'.e($link['source_url']).'</a></p>';
        if ((string)($link['verified_at'] ?? '') !== '') $body .= '<p class="friend-links-meta">最近验证：'.e((string)$link['last_checked_at']).'</p>';
        $fields = '<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$link['id'].'">';
        $body .= '</div><div class="friend-links-admin-actions">';
        if ($pending) {
            $body .= '<form method="post">'.$fields.'<button class="primary friend-links-action-approve" name="action" value="approve" type="submit">确认并启用</button></form>';
        } else {
            $body .= '<form method="post">'.$fields.'<button class="friend-links-action-toggle" name="action" value="toggle" type="submit">'.($active ? '停用' : '启用').'</button></form>';
        }
        $body .= '<form method="post">'.$fields.'<button class="friend-links-action-delete" name="action" value="delete" type="submit">删除</button></form></div></article>';
    }
    return ['title' => '友情链接管理', 'body' => $body.'</div></section></section></section>'];
}

function friend_links_invalidate_sidebar(): void
{
    extension_invalidate('plugin:friend-links:sidebar');
}

function friend_links_normalize_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\\\\]/', $value)) return null;
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    $port = (int)($parts['port'] ?? 443);
    if ($host === '' || $port !== 443 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) return null;
    $path = (string)($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/') $path = '/'.$path;
    return 'https://'.$host.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
}
