<?php
declare(strict_types=1);

use LiteBBS\Framework\Request;
use LiteBBS\Framework\Response;

final class FriendLinksPlugin implements LiteBBSPlugin
{
    public function boot(PluginContext $context): void
    {
        $context->assets()->css('assets/style.css');
        $context->slots()->add('sidebar.sections', static fn(array $view): string => friend_links_render_sidebar());
        $context->routes()->front(['GET', 'POST'], '~^/friend-links/apply$~', static function (Request $request): Response {
            if ($request->method === 'POST') require_post();
            return friend_links_application_response($request);
        });
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
    $links = h('SELECT title,url,description FROM plg_friend_links_links WHERE status="active" ORDER BY created_at DESC,id DESC LIMIT 20')->fetchAll();
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
            audit('friend_links.added', 'plugin_friend_link', null, ['url' => $url]);
        } elseif (in_array($action, ['approve', 'toggle', 'delete'], true)) {
            $id = (int)($request->post['id'] ?? 0);
            $link = $id > 0 ? h('SELECT id,status,url FROM plg_friend_links_links WHERE id=?', [$id])->fetch() : false;
            if (!$link) fail(404, '友情链接不存在。');
            if ($action === 'approve') {
                if ((string)$link['status'] !== 'pending') fail(409, '该友情链接不是待审核状态。');
                h('UPDATE plg_friend_links_links SET status="active",verified_at=UTC_TIMESTAMP(6),last_checked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?', [$id]);
                audit('friend_links.confirmed', 'plugin_friend_link', $id, ['url' => $link['url']]);
            } elseif ($action === 'toggle') {
                $status = (string)$link['status'] === 'active' ? 'disabled' : 'active';
                h('UPDATE plg_friend_links_links SET status=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?', [$status, $id]);
                audit('friend_links.status_changed', 'plugin_friend_link', $id, ['status' => $status, 'url' => $link['url']]);
            } else {
                h('DELETE FROM plg_friend_links_links WHERE id=?', [$id]);
                audit('friend_links.deleted', 'plugin_friend_link', $id, ['url' => $link['url']]);
            }
        } else {
            fail(400, '友情链接管理操作无效。');
        }
        redirect('/admin/plugins?plugin=friend-links&page=links');
    }

    $links = h('SELECT id,title,url,description,source_url,status,verified_at,last_checked_at,created_at FROM plg_friend_links_links ORDER BY created_at DESC,id DESC')->fetchAll();
    $body = '<section class="friend-links-admin"><p class="admin-intro">申请会保留对方填写的本站链接所在页。请打开该页面确认已存在本站 HTTPS 链接，再点击“确认并启用”。这里也可以手工新增、停用或删除友情链接。</p><h2>新增友情链接</h2><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="add"><label>网站名称<input name="title" maxlength="100" required></label><label>网站地址<input type="url" name="url" maxlength="2048" placeholder="https://example.com/" required></label><label>网站简介（可选）<textarea name="description" maxlength="255" rows="3"></textarea></label><button class="primary" type="submit">新增友情链接</button></form><div class="friend-links-admin-list">';
    if (!$links) $body .= '<p class="friend-links-help">尚未添加友情链接。</p>';
    foreach ($links as $link) {
        $status = (string)$link['status'];
        $active = $status === 'active';
        $pending = $status === 'pending';
        $statusLabel = $active ? '已启用' : ($pending ? '待核验' : '已停用');
        $body .= '<article class="friend-links-admin-row"><div><h3>'.e($link['title']).'<span class="friend-links-status'.($active ? '' : ' is-disabled').'">'.$statusLabel.'</span></h3><p><a href="'.e($link['url']).'" target="_blank" rel="noopener noreferrer">'.e($link['url']).'</a></p>';
        if ((string)$link['description'] !== '') $body .= '<p>'.e($link['description']).'</p>';
        if ((string)($link['source_url'] ?? '') !== '') $body .= '<p class="friend-links-meta">互链验证页：<a href="'.e($link['source_url']).'" target="_blank" rel="noopener noreferrer">'.e($link['source_url']).'</a></p>';
        if ((string)($link['verified_at'] ?? '') !== '') $body .= '<p class="friend-links-meta">最近验证：'.e((string)$link['last_checked_at']).'</p>';
        $body .= '</div><div class="friend-links-admin-actions">'.($pending ? '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="'.(int)$link['id'].'"><button class="primary friend-links-action-approve" type="submit">确认并启用</button></form>' : '').'<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="'.(int)$link['id'].'"><button class="friend-links-action-toggle" type="submit">'.($active ? '停用' : '启用').'</button></form><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.(int)$link['id'].'"><button class="friend-links-action-delete" type="submit">删除</button></form></div></article>';
    }
    return ['title' => '友情链接管理', 'body' => $body.'</div></section>'];
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
