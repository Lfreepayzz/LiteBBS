<?php
declare(strict_types=1);

/** Single admin file: layout, routes, and handlers. */
function admin_page(string $title, string $body): void
{
    $config = load_config();
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH) ?: '/admin';
    $user = current_user();
    if (!is_array($user) || ($user['role'] ?? '') !== 'admin') fail(403, '无权访问');
    header('Cache-Control: private, no-store');
    render_admin_layout([
        'title'=>$title,
        'body'=>$body,
        'requestPath'=>$requestPath,
        'user'=>$user,
        'c'=>[
            'site'=>(string)($config['site'] ?? 'LiteBBS'),
            'locale'=>(string)($config['locale'] ?? 'zh-CN'),
        ],
    ]);
}

function confirmation_page(string $title, string $message, string $action, array $fields, string $subject): never
{
    $token = confirmation_token($action, $subject);
    $inputs = '<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="confirm_token" value="'.e($token).'">';
    foreach ($fields as $name=>$value) $inputs .= '<input type="hidden" name="'.e((string)$name).'" value="'.e((string)$value).'">';
    $details = ['topic.delete'=>['/admin/content','确认永久删除'],'theme.delete'=>['/admin/themes','确认删除模板'],'plugin.purge'=>['/admin/plugins','确认删除插件'],'audit.clear'=>['/admin/audit','确认清空日志']];
    [$cancel, $buttonLabel] = $details[$action] ?? ['/admin','确认执行'];
    $body = '<section class="destructive-confirm"><div class="destructive-confirm-copy"><p>'.e($message).'</p><small>此操作不可撤销。</small></div><form method="post">'.$inputs.'<div class="destructive-confirm-actions"><a href="'.e($cancel).'">取消</a><button type="submit">'.e($buttonLabel).'</button></div></form></section>';
    ob_start();
    admin_page($title, $body);
    throw new \LiteBBS\Framework\ResponseHalt(new \LiteBBS\Framework\Response((string)ob_get_clean()));
}

function admin_schema_definitions(): array
{
    static $definitions;
    if ($definitions !== null) return $definitions;
    $schema = @file_get_contents(dirname(__DIR__).'/database/schema.sql');
    if (!is_string($schema)) throw new RuntimeException('Database schema is unavailable.');
    $definitions = [];
    foreach (preg_split('/\R/', $schema) ?: [] as $statement) {
        $statement = trim($statement);
        if (!preg_match('/^CREATE TABLE ([a-z][a-z0-9_]{0,63})\s*\((.*)\)\s*ENGINE=/i', $statement, $match)) continue;
        $table = strtolower($match[1]);
        $columns = [];
        $indexes = [];
        foreach (admin_schema_definition_items($match[2]) as $item) {
            if (preg_match('/^([a-z][a-z0-9_]*)\b/i', $item, $column) && !in_array(strtoupper($column[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'FULLTEXT', 'FOREIGN', 'CONSTRAINT', 'CHECK'], true)) $columns[strtolower($column[1])] = $item;
            elseif (preg_match('/^(?:UNIQUE |FULLTEXT )?KEY\s+([a-z][a-z0-9_]*)\b/i', $item, $index)) $indexes[strtolower($index[1])] = $item;
        }
        if (!$columns) throw new RuntimeException('Invalid schema table definition: '.$table);
        $definitions[$table] = ['create'=>substr($statement, 0, -1), 'columns'=>$columns, 'indexes'=>$indexes];
    }
    if (!$definitions) throw new RuntimeException('Database schema has no table definitions.');
    return $definitions;
}

function admin_schema_definition_items(string $definition): array
{
    $items = [];
    $item = '';
    $depth = 0;
    $quoted = false;
    for ($offset = 0, $length = strlen($definition); $offset < $length; ++$offset) {
        $character = $definition[$offset];
        if ($character === '`') $quoted = !$quoted;
        if (!$quoted && $character === '(') ++$depth;
        elseif (!$quoted && $character === ')') --$depth;
        if (!$quoted && $depth === 0 && $character === ',') {
            $items[] = trim($item);
            $item = '';
            continue;
        }
        $item .= $character;
    }
    if ($quoted || $depth !== 0 || trim($item) === '') throw new RuntimeException('Database schema definition is malformed.');
    $items[] = trim($item);
    return $items;
}

function admin_schema_sync_report(?PDO $pdo=null): array
{
    $pdo = $pdo ?? db();
    $definitions = admin_schema_definitions();
    $tables = array_fill_keys(array_map('strtolower', $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN)), true);
    $columns = [];
    foreach ($pdo->query('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE()')->fetchAll() as $row) $columns[strtolower((string)$row['table_name'])][strtolower((string)$row['column_name'])] = true;
    $indexes = [];
    foreach ($pdo->query('SELECT table_name,index_name FROM information_schema.statistics WHERE table_schema=DATABASE()')->fetchAll() as $row) $indexes[strtolower((string)$row['table_name'])][strtolower((string)$row['index_name'])] = true;
    $missing = ['tables'=>[], 'columns'=>[], 'indexes'=>[]];
    foreach ($definitions as $table=>$definition) {
        if (!isset($tables[$table])) { $missing['tables'][] = $table; continue; }
        foreach ($definition['columns'] as $column=>$sql) if (empty($columns[$table][$column])) $missing['columns'][$table][$column] = $sql;
        foreach ($definition['indexes'] as $index=>$sql) if (empty($indexes[$table][$index])) $missing['indexes'][$table][$index] = $sql;
    }
    return ['ready'=>!$missing['tables'] && !$missing['columns'] && !$missing['indexes'], 'missing'=>$missing, 'signature'=>hash('sha256', serialize($definitions))];
}

function admin_schema_sync(?PDO $pdo=null): array
{
    $pdo = $pdo ?? db();
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $lock->execute(['litebbs:schema-sync']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库结构升级正在由另一项任务执行，请稍后重试。');
    try {
        $report = admin_schema_sync_report($pdo);
        $definitions = admin_schema_definitions();
        $missing = $report['missing'];
        $changed = ['tables'=>[], 'columns'=>[], 'indexes'=>[]];
        foreach ($missing['tables'] as $table) {
            $pdo->exec($definitions[$table]['create']);
            $changed['tables'][] = $table;
        }
        foreach ($missing['columns'] as $table=>$columns) foreach ($columns as $column=>$sql) {
            $pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN '.$sql);
            $changed['columns'][] = $table.'.'.$column;
        }
        foreach ($missing['indexes'] as $table=>$indexes) foreach ($indexes as $index=>$sql) {
            $pdo->exec('ALTER TABLE `'.$table.'` ADD '.$sql);
            $changed['indexes'][] = $table.'.'.$index;
        }
        if ($changed['tables'] || $changed['columns'] || $changed['indexes']) $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['schema-sync-'.substr($report['signature'], 0, 48)]);
        return $changed;
    } finally {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['litebbs:schema-sync']); } catch (Throwable $error) { cache_error('database schema lock release failed: '.$error->getMessage()); }
    }
}

function admin_database_maintenance_report(?PDO $pdo=null): array
{
    $report = database_maintenance_report($pdo);
    $schema = admin_schema_sync_report($pdo);
    $report['checks'] = ['schema.sql 增量结构'=>$schema['ready']] + $report['checks'];
    $report['ready'] = $schema['ready'] && $report['ready'];
    return $report;
}

function admin_database_upgrade_handle(\LiteBBS\Framework\Request $request): bool
{
    if ($request->path !== '/admin/extensions' || $request->method !== 'POST') return false;
    $action = (string)($request->post['action'] ?? '');
    if (!in_array($action, ['migrate_database', 'cleanup_legacy_database'], true)) return false;
    admin();
    require_post();
    if ($action === 'cleanup_legacy_database') {
        if (empty($request->post['confirm_token'])) confirmation_page('确认清理残留数据库', '仅删除已完成迁移且新表数据不少于旧表的旧附件表。此操作不可恢复。', 'database.legacy.cleanup', ['action'=>'cleanup_legacy_database'], 'legacy-attachments');
        require_confirmation('database.legacy.cleanup', 'legacy-attachments', (string)($request->post['confirm_token'] ?? ''));
        try {
            $removed = cleanup_legacy_database_tables();
            audit('database.legacy.cleaned', 'schema', null, ['tables'=>$removed]);
            redirect('/admin/extensions?database_result='.rawurlencode($removed ? '已清理旧表：'.implode('、', $removed) : '没有可清理的旧表。'));
        } catch (\LiteBBS\Framework\ResponseHalt $halt) {
            throw $halt;
        } catch (Throwable $error) { fail(503, '数据库维护失败：'.$error->getMessage()); }
    }
    try {
        if (admin_database_maintenance_report()['ready']) redirect('/admin/extensions');
        @set_time_limit(0);
        // Historical migrations can populate newly introduced tables. Run them
        // first so the generic schema synchronizer never creates the same table.
        $changed = ['legacy'=>migrate_performance_indexes(), 'schema_sql'=>admin_schema_sync()];
        audit('database.migrated', 'schema', null, ['migration'=>'incremental-schema-sync', 'changed'=>$changed]);
        redirect('/admin/extensions');
    } catch (\LiteBBS\Framework\ResponseHalt $halt) {
        throw $halt;
    } catch (Throwable $error) { fail(503, '数据库差异升级失败：'.$error->getMessage()); }
    return true;
}

function admin_database_upgrade_panel(array $query): string
{
    $report = admin_database_maintenance_report();
    $result = isset($query['database_result']) && is_string($query['database_result']) ? '<p class="program-update-notice" data-state="success">'.e($query['database_result']).'</p>' : '';
    $checks = '';
    foreach ($report['checks'] as $label=>$ready) $checks .= '<li>'.e($label).'：'.($ready ? '已完成' : '待处理').'</li>';
    $csrf = e(csrf());
    $content = $result.($report['ready'] ? '<p class="program-update-notice" data-state="success"><strong>已完成</strong> · 数据库结构与当前 schema.sql 一致。</p>' : '<p class="program-update-notice" data-state="warning"><strong>待升级</strong> · 点击下方按钮自动检查 schema.sql 差异，并补齐缺失的表、字段和索引。</p><p>不会删除数据、字段或索引，也不会修改字段类型或重建现有表。</p>').'<ul class="database-maintenance-checks">'.$checks.'</ul><form method="post" class="database-upgrade-form"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="migrate_database"><button class="primary" type="submit"'.($report['ready'] ? ' disabled' : '').'>检查并升级数据库结构</button></form>';
    if ($report['legacy_tables']) $content .= '<p>检测到已迁移的旧附件表：'.e(implode('、', $report['legacy_tables'])).'。</p><form method="post"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="cleanup_legacy_database"><button type="submit">清理残留数据库表</button></form>';
    return '<section class="program-update-panel" aria-labelledby="database-upgrade-heading"><h2 id="database-upgrade-heading">数据库状态</h2><div class="program-update-panel-body">'.$content.'</div></section>';
}

function admin_navigation_icon(string $name): string
{
    $paths = [
        'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'content'=>'<path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'reports'=>'<path d="M12 3 3 20h18L12 3Z"/><path d="M12 9v4M12 17h.01"/>',
        'theme'=>'<path d="M12 3a9 9 0 1 0 0 18h1.5a2.5 2.5 0 0 0 0-5H15a3 3 0 0 0 3-3 10 10 0 0 0-6-10Z"/><path d="M7.5 11h.01M9 7.5h.01M13 7h.01M16.5 10h.01"/>',
        'plugin'=>'<path d="M9 3v4M15 3v4M7 9H3v6h4v4h10v-4h4V9h-4V7H7v2Z"/><path d="M9 12h.01M15 12h.01"/>',
        'market'=>'<path d="M4 9h16l-1 11H5L4 9Z"/><path d="m8 9 1-5h6l1 5M8 13h8"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20c.7-3.3 2.7-5 6-5s5.3 1.7 6 5M17 8h4M19 6v4"/>',
        'audit'=>'<path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l-2-.6a6 6 0 0 0-.7-1.6l1-1.8-2.1-2.1-1.8 1a6 6 0 0 0-1.6-.7L11.5 3h-3l-.6 2a6 6 0 0 0-1.6.7l-1.8-1-2.1 2.1 1 1.8a6 6 0 0 0-.7 1.6l-2 .6v3l2 .6a6 6 0 0 0 .7 1.6l-1 1.8 2.1 2.1 1.8-1a6 6 0 0 0 1.6.7l.6 2h3l.6-2a6 6 0 0 0 1.6-.7l1.8 1 2.1-2.1-1-1.8a6 6 0 0 0 .7-1.6l2-.6Z"/>',
        'extension'=>'<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/>',
        'default'=>'<path d="M4 12h16M12 4v16"/>',
    ];
    $path = $paths[$name] ?? $paths['default'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'.$path.'</svg>';
}

final class AdminLoginController
{
    public function handle(\LiteBBS\Framework\Request $request): \LiteBBS\Framework\Response
    {
        if (!in_array($request->method, ['GET', 'POST'], true)) return (new \LiteBBS\Framework\Response('', 405))->header('Allow', 'GET, POST');
        $message = '请使用管理员账号登录。';
        if ($request->method === 'POST') {
            \require_post();
            $user = (new \LiteBBS\Application\AccountAuthenticator())->verify($request);
            if (is_array($user) && ($user['role'] ?? '') === 'admin') {
                \login_user((int)$user['id']);
                \redirect('/admin');
            }
            $message = '账号、密码或管理权限不正确。';
        }
        $site = (string)(\load_config()['site'] ?? 'LiteBBS');
        $css = \asset_version(__DIR__.'/../assets/css/admin.css');
        $body = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>后台登录 · '.\e($site).'</title><link rel="stylesheet" href="/assets/css/admin.css?v='.$css.'"></head><body class="admin-login-body"><main class="admin-login-panel"><p class="admin-login-kicker">LITEBBS ADMIN</p><h1>'.\e($site).'</h1><p class="admin-login-message">'.\e($message).'</p><form method="post" action="/admin" class="admin-login-form"><input type="hidden" name="csrf" value="'.\e(\csrf()).'"><label>用户名或邮箱<input name="account" autocomplete="username" required autofocus></label><label>密码<input type="password" name="password" autocomplete="current-password" required></label><button class="primary" type="submit">登录后台</button></form><a class="admin-login-back" href="/">返回社区</a></main></body></html>';
        return new \LiteBBS\Framework\Response($body);
    }
}

function render_admin_layout(array $variables): void { extract($variables, EXTR_SKIP); ?>
<?php
$requestPath = is_string($requestPath ?? null) ? $requestPath : '/admin';
$pluginId = $_GET['plugin'] ?? null;
$pluginPageName = $_GET['page'] ?? null;
$pluginManagementId = $requestPath === '/admin/plugins' && is_string($pluginId) && preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $pluginId) ? $pluginId : null;
$pluginManagementName = $pluginManagementId !== null && is_string($pluginPageName) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pluginPageName) ? $pluginPageName : null;
extension_runtime_load($pluginManagementId);
$pluginManagementPage = $pluginManagementId !== null && $pluginManagementName !== null ? extension_plugin_management_page($pluginManagementId, $pluginManagementName) : null;
$menu = [
    '/admin' => ['后台总览', 'dashboard'],
    '/admin/content' => ['内容管理', 'content'],
    '/admin/categories' => ['论坛分区', 'content'],
    '/admin/themes' => ['模板管理', 'theme'],
    '/admin/plugins' => ['插件管理', 'plugin'],
    '/admin/market' => ['扩展审核', 'market'],
    '/admin/users' => ['用户管理', 'users'],
    '/admin/reports' => ['举报审核', 'reports'],
    '/admin/audit' => ['审计日志', 'audit'],
    '/admin/settings' => ['站点设置', 'settings'],
    '/admin/extensions' => ['程序更新', 'extension'],
];
if (setting('security_scanner_enabled', '0') === '1') $menu = array_slice($menu, 0, -1, true) + ['/admin/scanner' => ['病毒扫描', 'audit']] + array_slice($menu, -1, null, true);
$showAdminSlots = $pluginManagementPage === null;
$breadcrumbs = $pluginManagementPage['breadcrumbs'] ?? [];
if ($showAdminSlots) {
    foreach (extension_registry()['admin_pages'] as $registeredPage) {
        if (!empty($registeredPage['plugin_management'])) continue;
        $menu[$registeredPage['path']] ??= [$registeredPage['navigation'], 'extension'];
        if ($registeredPage['path'] === $requestPath) $breadcrumbs = $registeredPage['breadcrumbs'];
    }
}
$adminExtensionView = ['path'=>$requestPath, 'user'=>$user, 'plugin_management'=>!$showAdminSlots, 'plugin'=>$pluginManagementPage['plugin'] ?? null];
$adminContentView = $adminExtensionView + ['title'=>$title, 'is_admin'=>true];
$siteName = $c['site'] ?? 'LiteBBS';
?><!doctype html>
<html lang="<?=e($c['locale'] ?? 'zh-CN')?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · <?=e($siteName)?></title>
<meta name="robots" content="noindex,follow">
<link rel="stylesheet" href="/assets/css/admin.css?v=<?=asset_version(__DIR__.'/../assets/css/admin.css')?>">
<link rel="stylesheet" href="/assets/css/admin-settings.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-settings.css')?>">
<link rel="stylesheet" href="/assets/css/admin-management.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-management.css')?>">
<link rel="stylesheet" href="/assets/css/admin-catalog.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-catalog.css')?>">
<link rel="stylesheet" href="/assets/css/admin-audit.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-audit.css')?>">
<link rel="stylesheet" href="/assets/css/admin-confirm.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-confirm.css')?>">
<link rel="stylesheet" href="/assets/css/admin-extensions.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-extensions.css')?>">
<link rel="stylesheet" href="/assets/css/admin-scanner.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-scanner.css')?>">
<?=extension_stylesheets($requestPath, $pluginManagementId)?>
<?=$showAdminSlots ? extension_slot('admin.head', $adminExtensionView) : ''?>
</head>
<body class="admin-body">
<a class="admin-skip" href="#admin-main">跳到主要内容</a>
<header class="admin-toolbar">
    <button class="admin-menu-toggle" type="button" aria-controls="admin-menu" aria-expanded="true" aria-label="收起管理菜单" hidden><span aria-hidden="true">☰</span></button>
    <a class="admin-brand" href="/admin"><?=e($siteName)?></a>
    <a class="admin-back" href="/">访问主页</a>
    <?php if (($user['role'] ?? null) === 'admin'): ?>
        <details class="admin-account">
            <summary><span>你好，<?=e($user['username'])?></span></summary>
            <div class="admin-account-menu">
                <a href="/me">个人中心</a>
                <form method="post" action="/logout">
                    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
                    <button type="submit">退出登录</button>
                </form>
            </div>
        </details>
    <?php endif; ?>
</header>
<div class="admin-page admin-shell">
    <div class="admin-menu-backdrop" aria-hidden="true" hidden></div>
    <nav class="admin-sidebar" id="admin-menu" aria-label="后台导航" tabindex="-1">
        <?php foreach ($menu as $url => $item):
            [$label, $icon] = $item;
            $children = $item[2] ?? [];
            $current = $requestPath === $url;
            $active = $current || isset($children[$requestPath]);
        ?>
            <a class="admin-link<?=$active ? ' active' : ''?>" href="<?=e($url)?>"<?=$current ? ' aria-current="'.($pluginManagementPage !== null ? 'location' : 'page').'"' : ''?>>
                <span class="admin-menu-icon"><?=admin_navigation_icon($icon)?></span><?=e($label)?>
            </a>
            <?php if ($children): ?>
                <div class="admin-submenu">
                    <?php foreach ($children as $childUrl => [$childLabel, $childIcon]): ?>
                        <a class="admin-link" href="<?=e($childUrl)?>"<?=$requestPath === $childUrl ? ' aria-current="page"' : ''?>><span class="admin-menu-icon"><?=admin_navigation_icon($childIcon)?></span><?=e($childLabel)?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?=$showAdminSlots ? extension_slot('admin.navigation', $adminExtensionView) : extension_render_links('admin.navigation', $user)?>
    </nav>
    <main class="admin-workspace" id="admin-main" tabindex="-1">
        <header class="admin-top">
            <?php if ($breadcrumbs): ?>
                <nav class="admin-breadcrumbs" aria-label="面包屑">
                    <?php foreach ($breadcrumbs as $label => $url): ?>
                        <?php if (is_string($url)): ?>
                            <a href="<?=e($url)?>"><?=e($label)?></a>
                        <?php else: ?>
                            <span aria-current="page"><?=e($label)?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <h1><?=e($title)?></h1>
        </header>
        <div class="admin-content">
            <?=$showAdminSlots ? extension_slot('admin.content.before', $adminContentView) : ''?>
            <?=$body?>
            <?=$showAdminSlots ? extension_slot('admin.content.after', $adminContentView) : ''?>
        </div>
        <footer class="admin-footer"><span>LiteBBS <?=e(LITEBBS_VERSION)?></span></footer>
    </main>
</div>
<script src="/assets/js/admin-menu.js?v=<?=filemtime(__DIR__.'/../assets/js/admin-menu.js')?>" defer></script>
<script src="/assets/js/admin-forum-share.js?v=<?=asset_version(__DIR__.'/../assets/js/admin-forum-share.js')?>" defer></script>
<?=extension_javascripts($requestPath, $pluginManagementId)?>
</body></html>

<?php }

// ===== admin-dashboard.php =====
function handle_admin_dashboard(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
if ($path !== '/admin' || !in_array($method, ['GET', 'POST'], true)) return;

$actor = admin();
if ($method === 'POST') {
    require_post();
    $action = (string)($post['action'] ?? '');
    $id = (int)($post['id'] ?? 0);
    if ($action === 'pin' || $action === 'unpin') {
        moderate_topic($id, 'is_pinned', $action === 'pin' ? 1 : 0, $action);
    } elseif ($action === 'lock' || $action === 'unlock') {
        moderate_topic($id, 'is_locked', $action === 'lock' ? 1 : 0, $action);
    } elseif ($action === 'delete') {
        if (empty($post['confirm_token'])) confirmation_page('确认永久删除','将永久删除主题、回复、修订和积分记录，此操作不可恢复。','topic.delete',['id'=>$id,'action'=>'delete'],(string)$id);
        require_confirmation('topic.delete',(string)$id,(string)($post['confirm_token'] ?? ''));
        permanently_delete_topic($id);
    } else {
        fail(400, '操作无效');
    }
    redirect('/admin');
}

$maintenance = dirname(__DIR__).'/cache/cleanup-cache-locks.lock';
if (is_dir(dirname($maintenance)) && (!is_file($maintenance) || filemtime($maintenance) < time() - 86400)) {
    $handle = @fopen($maintenance, 'c');
    if ($handle !== false) {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            clearstatcache(true, $maintenance);
            if (filemtime($maintenance) < time() - 86400) {
                clean_old_cache_locks();
                @touch($maintenance);
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

$stats = cached('admin:dashboard:stats:v1', 30, static function (): array {
    return h('SELECT (SELECT COUNT(*) FROM users) users,(SELECT COUNT(*) FROM topics WHERE status="normal") topics,(SELECT COALESCE(SUM(reply_count),0) FROM topics WHERE status="normal") replies,(SELECT COUNT(*) FROM market_submissions WHERE status="pending") pending_extensions')->fetch() ?: [];
});
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
$body .= '</section>';
admin_page('管理总览', $body);
return;
}


// ===== admin-categories.php =====
function handle_admin_categories(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$post = $request->post;
if ($path !== '/admin/categories' || !in_array($method, ['GET','POST'], true)) return;
$actor=admin();
if ($method==='POST') {
 require_post();
    $id = (int)($post['id'] ?? 0);
    $action = $post['action'] ?? 'save';
    if (!in_array($action, ['save', 'delete', 'restore'], true)) fail(400, '操作无效');
    $title = trim((string)($post['title'] ?? ''));
    $description = trim((string)($post['description'] ?? ''));
    if ($action === 'save' && (text_length($title) < 1 || text_length($title) > 100 || text_length($description) > 500)) {
        fail(400, '分区名称或说明长度无效');
    }
    $position = (int)($post['position'] ?? 0);
    $visible = $action === 'save' ? isset($post['is_visible']) : $action === 'restore';
    try {
        [$id, $countChanged] = \LiteBBS\Application\transaction_manager()->run(static function (PDO $pdo) use ($id, $action, $actor, $title, $description, $position, $visible): array {
            $existing = null;
            if ($id || $action !== 'save') {
                $existing = h('SELECT is_visible FROM categories WHERE id=? FOR UPDATE', [$id])->fetch();
                if (!$existing) fail(404, '分区不存在');
            }
            if ($action !== 'save') {
                h('UPDATE categories SET is_visible=? WHERE id=?', [(int)$visible, $id]);
                audit('category.'.$action, 'category', $id);
                extension_enqueue_async_event('category.visibility.updated', ['category_id'=>$id, 'visible'=>$visible, 'actor_id'=>(int)$actor['id']]);
            } else {
                $values = [slug($title), $title, $description, $position, (int)$visible];
                if ($existing) {
                    h('UPDATE categories SET slug=?,title=?,description=?,position=?,is_visible=? WHERE id=?', [...$values, $id]);
                } else {
                    h('INSERT INTO categories(slug,title,description,position,is_visible) VALUES(?,?,?,?,?)', $values);
                    $id = (int)$pdo->lastInsertId();
                }
                audit('category.saved', 'category', $id);
                extension_enqueue_async_event($existing ? 'category.updated' : 'category.created', ['category_id'=>$id, 'actor_id'=>(int)$actor['id']]);
            }
            return [$id, !$existing || (bool)$existing['is_visible'] !== $visible];
        });
    } catch (PDOException $error) {
        if ($action === 'save' && (int)($error->errorInfo[1] ?? 0) === 1062) fail(409, '分区名称生成的 slug 已存在，请使用不同名称');
        throw $error;
    }
    invalidate('categories');
    invalidate_topic_listing_cache($id, $countChanged);
    redirect('/admin/categories');
}
$rows=h('SELECT id,title,description,position,is_visible FROM categories ORDER BY position,id')->fetchAll();
$body='<p class="admin-intro">创建分区、修改名称与说明、调整顺序，或隐藏不再使用的分区。隐藏不会删除已有主题。</p>';
$body.='<form class="form category-create" method="post"><h2>添加分区</h2><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="save"><label>名称<input name="title" maxlength="100" required></label><label>说明<input name="description" maxlength="500"></label><label>排序<input name="position" type="number" value="0"></label><label><input type="checkbox" name="is_visible" checked> 前台显示</label><button class="primary">创建分区</button></form><h2>现有分区 <small>（'.count($rows).'）</small></h2>';
foreach($rows as$r){$id=(int)$r['id'];$body.='<section class="category-admin"><h3>分区 #'.$id.' · '.e($r['title']).($r['is_visible']?'':' <span>已隐藏</span>').'</h3><form method="post" class="category-edit"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.$id.'"><label>名称<input name="title" maxlength="100" value="'.e($r['title']).'" required></label><label>说明<input name="description" maxlength="500" value="'.e($r['description']).'"></label><label>排序<input name="position" type="number" value="'.(int)$r['position'].'"></label><label class="check-label"><input type="checkbox" name="is_visible"'.($r['is_visible']?' checked':'').'> 前台显示</label><button class="primary">保存修改</button></form><form method="post" class="category-delete"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.($r['is_visible']?'delete':'restore').'"><button>'.($r['is_visible']?'删除分区（隐藏）':'恢复分区').'</button></form></section>';}
 admin_page('分区管理', $body); return;
}


// ===== admin-audit.php =====
function handle_admin_audit(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
if ($path !== '/admin/audit' || !in_array($method, ['GET', 'POST'], true)) return;
$actor = admin();

if ($method === 'POST') {
    require_post();
    if ((string)($post['action'] ?? '') !== 'clear') fail(400, '操作无效');
    if (empty($post['confirm_token'])) confirmation_page('确认清空审计日志', '将永久删除全部历史审计记录，仅保留本次清理操作作为追溯凭据。', 'audit.clear', ['action' => 'clear'], 'all');
    require_confirmation('audit.clear', 'all', (string)($post['confirm_token'] ?? ''));
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

$before = max(0, (int)($query['before'] ?? 0));
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
admin_page('审计日志', $body);
return;
}


// ===== admin-reports.php =====
function handle_admin_reports(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
if ($path !== '/admin/reports' || !in_array($method, ['GET', 'POST'], true)) return;
$actor = admin();
if ($method === 'POST') {
    require_post();
    $postInput = $request->post;
    $id = (int)($postInput['id'] ?? 0);
    $status = (string)($postInput['status'] ?? '');
    if (!in_array($status, ['reviewed', 'dismissed', 'remove_target'], true)) fail(400, '举报状态无效');
    $invalidate = \LiteBBS\Application\transaction_manager()->run(static function () use ($id, $status, $actor): array {
    $invalidate = [];
    $events = [];
    $report = h('SELECT target_type,target_id,status FROM reports WHERE id=? FOR UPDATE', [$id])->fetch();
    if (!$report || $report['status'] !== 'open') fail(409, '举报已被处理');
    if ($status === 'remove_target') {
        if ($report['target_type'] === 'topic') {
            $topic = h('SELECT category_id FROM topics WHERE id=? AND status="normal" FOR UPDATE', [(int)$report['target_id']])->fetch();
            if ($topic && h('UPDATE topics SET status="deleted" WHERE id=? AND status="normal"', [(int)$report['target_id']])->rowCount() === 1) {
                h('DELETE FROM notifications WHERE topic_id=?', [(int)$report['target_id']]);
                $events[] = ['topic.deleted', ['topic_id'=>(int)$report['target_id'],'category_id'=>(int)$topic['category_id'],'post_ids'=>[],'actor_id'=>(int)$actor['id'],'reason'=>'report']];
                $topicId = (int)$report['target_id'];
                $categoryId = (int)$topic['category_id'];
                $invalidate[] = static function () use ($topicId, $categoryId): void {
                    invalidate_topic_read_cache($topicId, $categoryId, false, true);
                    invalidate_topic_listing_cache($categoryId, true);
                };
            }
        }
        elseif ($report['target_type'] === 'post') {
            h('SELECT id FROM notification_outbox WHERE post_id=? AND processed_at IS NULL FOR UPDATE', [(int)$report['target_id']])->fetchAll();
            $post = h('SELECT p.topic_id,t.category_id FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal" FOR UPDATE', [(int)$report['target_id']])->fetch();
            if ($post) {
                $postId = (int)$report['target_id'];
                $topicId = (int)$post['topic_id'];
                $categoryId = (int)$post['category_id'];
                h('UPDATE posts SET status="deleted" WHERE id=? AND status="normal"', [$postId]);
                h('DELETE FROM notification_outbox WHERE post_id=? AND processed_at IS NULL', [$postId]);
                h('DELETE FROM notifications WHERE post_id=?', [$postId]);
                $events[] = ['post.deleted', ['post_id'=>$postId, 'topic_id'=>$topicId, 'actor_id'=>(int)$actor['id'], 'reason'=>'report']];
                if (topic_last_post_pointer_available()) {
                    h('UPDATE topics SET reply_count=IF(reply_count>0,reply_count-1,0),last_post_id=(SELECT id FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),last_post_at=COALESCE((SELECT created_at FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),created_at) WHERE id=?', [$topicId, $topicId, $topicId]);
                } else {
                    h('UPDATE topics SET reply_count=IF(reply_count>0,reply_count-1,0) WHERE id=?', [$topicId]);
                }
                $invalidate[] = static function () use ($topicId, $categoryId): void {
                    invalidate('topic-floor:'.$topicId);
                    invalidate_topic_read_cache($topicId, $categoryId, true, false);
                };
            }
        }
        elseif ($report['target_type'] === 'user') {
            $targetId = (int)$report['target_id'];
            if ($targetId === (int)$actor['id']) fail(400, '不能停用当前管理员账号');
            $target = h('SELECT role,status FROM users WHERE id=? FOR UPDATE', [$targetId])->fetch();
            if ($target && $target['role'] === 'admin' && $target['status'] === 'active') {
                $activeAdmins = h('SELECT id FROM users WHERE role="admin" AND status="active" ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
                if (count($activeAdmins) <= 1) fail(409, '至少必须保留一名启用中的管理员');
            }
            h('UPDATE users SET status="disabled" WHERE id=?', [$targetId]);
            h('DELETE FROM sessions WHERE user_id=?', [$targetId]);
        }
        else fail(400, '举报目标无效');
        $status = 'reviewed';
    }
    h('UPDATE reports SET status=?,reviewer_id=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=? AND status="open"', [$status, $actor['id'], $id]);
    audit('report.'.$status, 'report', $id);
    foreach ($events as [$event, $payload]) extension_enqueue_async_event($event, $payload);
    extension_enqueue_async_event('report.reviewed', ['report_id'=>$id,'target_type'=>(string)$report['target_type'],'target_id'=>(int)$report['target_id'],'status'=>$status,'actor_id'=>(int)$actor['id']]);
    return $invalidate;
    });
    foreach ($invalidate as $invalidateCache) $invalidateCache();
    redirect('/admin/reports');
}
$queues = ['open'=>'待处理','reviewed'=>'已处理','dismissed'=>'已驳回'];
$queue = $request->query['status'] ?? 'open';
if (!is_string($queue) || !isset($queues[$queue])) fail(400, '举报状态无效');
$before = $request->query['before'] ?? null;
if ($before !== null && (!is_string($before) || !preg_match('/^[1-9][0-9]{0,19}$/D', $before) || (strlen($before) === 20 && strcmp($before, '18446744073709551615') > 0))) fail(400, '分页游标无效');
$rows = h('SELECT r.*,u.username reporter,p.topic_id target_topic_id,target_user.username target_username
    FROM (SELECT id FROM reports WHERE status=?'.($before === null ? '' : ' AND id<CAST(? AS UNSIGNED)').' ORDER BY id DESC LIMIT 101) batch
    JOIN reports r ON r.id=batch.id
    JOIN users u ON u.id=r.reporter_id
    LEFT JOIN posts p ON r.target_type="post" AND p.id=r.target_id
    LEFT JOIN users target_user ON r.target_type="user" AND target_user.id=r.target_id
    ORDER BY batch.id DESC', $before === null ? [$queue] : [$queue, $before])->fetchAll();
$hasMore = count($rows) > 100;
if ($hasMore) array_pop($rows);
$body = '<p class="admin-intro">处理用户提交的主题、回复和用户举报。</p><nav class="dashboard-actions" aria-label="举报状态">';
foreach ($queues as $key=>$label) $body .= '<a href="/admin/reports?status='.$key.'"'.($key === $queue ? ' aria-current="page"' : '').'>'.e($label).'</a> ';
$body .= '</nav>';
if (!$rows) $body .= '<p class="admin-empty">暂无举报记录。</p>';
foreach ($rows as $row) {
    $targetPath=match($row['target_type']){'topic'=>'/t/'.(int)$row['target_id'],'post'=>((int)($row['target_topic_id']??0)?'/t/'.(int)$row['target_topic_id'].'#p'.(int)$row['target_id']:''),'user'=>((string)($row['target_username']??'')!==''?'/u/'.rawurlencode((string)$row['target_username']):''),default=>''};
    $targetLink=$targetPath!==''?' <a href="'.e($targetPath).'">查看目标</a>':'';
    $body .= '<article class="adminrow"><strong>#'.(int)$row['id'].' '.e($row['target_type']).' #'.(int)$row['target_id'].'</strong><p>'.e($row['reason']).' · 举报人 '.e($row['reporter']).' · '.e($row['created_at']).' · 状态 '.e($row['status']).$targetLink.'</p><div class="dashboard-actions">';
    if ($row['status'] === 'open') foreach (['reviewed'=>'标记已处理','dismissed'=>'驳回','remove_target'=>'移除违规目标'] as $status=>$label) $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="status" value="'.$status.'"><button>'.e($label).'</button></form>';
    $body .= '</div></article>';
}
if ($hasMore) $body .= '<p><a rel="next" href="/admin/reports?status='.$queue.'&amp;before='.e((string)end($rows)['id']).'">加载更早的记录</a></p>';
admin_page('举报审核', $body);
return;
}


function admin_market_manifests_match(string $reviewed, string $stored): bool
{
    // The database may reserialize JSON objects. Preserve list order and value types.
    $canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);
            return (object)array_map($canonicalize, $members);
        }
        return is_array($value) ? array_map($canonicalize, $value) : $value;
    };
    $left = json_decode($reviewed, false, 32, JSON_THROW_ON_ERROR);
    $right = json_decode($stored, false, 32, JSON_THROW_ON_ERROR);
    return $left instanceof stdClass && $right instanceof stdClass
        && json_encode($canonicalize($left), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($canonicalize($right), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
}

function handle_admin_market_review(\LiteBBS\Framework\Request $request): void
{
    $method = $request->method;
    $query = $request->query;
    $post = $request->post;
    if (!in_array($method, ['GET', 'POST'], true)) return;

    $actor = admin();
    if ($method === 'POST') {
        require_post(); $id = (int)($post['id'] ?? 0); $status = (string)($post['status'] ?? ''); $note = text_slice(trim((string)($post['review_note'] ?? '')), 0, 500);
        if (!$id || !in_array($status, ['approved', 'rejected', 'revoked'], true)) fail(400, '审核操作无效');
        $db = db(); $db->beginTransaction();
        try {
            $row = h('SELECT * FROM market_submissions WHERE id=? FOR UPDATE', [$id])->fetch(); $allowed = ['pending'=>['approved', 'rejected'], 'approved'=>['revoked']];
            if (!$row || !in_array($status, $allowed[$row['status']] ?? [], true)) throw new RuntimeException('状态转换无效');
            $package = __DIR__.'/../market-packages/'.$row['package_file'];
            if ($status === 'approved') {
                $hash = preg_match('/\A[a-f0-9]{48}\.zip\z/', $row['package_file']) && is_file($package) ? hash_file('sha256', $package) : false;
                if (!is_string($hash) || !hash_equals($row['sha256'], $hash)) throw new RuntimeException('扩展包缺失或哈希不一致');
                $tmp = dirname(__DIR__).'/cache/inspect-'.bin2hex(random_bytes(8));
                try { $root = market_unpack($package, $tmp); $metadata = extension_market_manifest($root, $row['kind'], $row['package_name']); }
                finally { remove_tree($tmp); }
                if ($metadata['version'] !== $row['version'] || !admin_market_manifests_match($metadata['manifest_json'], $row['manifest_json'])) throw new RuntimeException('扩展清单已变化');
            }
            h('UPDATE market_submissions SET status=?,reviewer_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?', [$status, $actor['id'], $note, $id]);
            audit('market.reviewed', 'market', $id, ['status'=>$status, 'reviewer_id'=>(int)$actor['id']]); $db->commit(); invalidate('market');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            fail(400, $e->getMessage());
        }
        redirect('/admin/market');
    }
    $queues = ['pending'=>'待审核', 'approved'=>'已通过', 'rejected'=>'已拒绝', 'revoked'=>'已撤销'];
    $queue = $query['status'] ?? 'pending';
    if (!is_string($queue) || !isset($queues[$queue])) fail(400, '审核状态无效');
    $before = $query['before'] ?? null;
    if ($before !== null && (!is_string($before) || !preg_match('/^[1-9][0-9]{0,19}$/D', $before) || (strlen($before) === 20 && strcmp($before, '18446744073709551615') > 0))) fail(400, '分页游标无效');
    $rows = h('SELECT m.id,m.title,m.kind,m.package_name,m.version,m.summary,m.manifest_json,m.sha256,
        m.status,m.review_note,u.username,r.username AS reviewer_name
        FROM (SELECT id FROM market_submissions WHERE status=?'.($before === null ? '' : ' AND id<CAST(? AS UNSIGNED)').' ORDER BY id DESC LIMIT 101) batch
        JOIN market_submissions m ON m.id=batch.id
        JOIN users u ON u.id=m.user_id
        LEFT JOIN users r ON r.id=m.reviewer_id
        ORDER BY batch.id DESC', $before === null ? [$queue] : [$queue, $before])->fetchAll();
    $hasMore = count($rows) > 100;
    if ($hasMore) array_pop($rows);
    $body = '<p class="admin-intro">审核仅代表本站信任该代码。获批扩展在安装站点以 PHP 进程权限运行，并非沙箱。</p><nav class="dashboard-actions" aria-label="审核状态">';
    foreach ($queues as $key=>$label) $body .= '<a href="/admin/market?status='.$key.'"'.($key === $queue ? ' aria-current="page"' : '').'>'.e($label).'</a> ';
    $body .= '</nav>';
    if (!$rows) $body .= '<p class="admin-empty">暂无提交记录。</p>';
    foreach ($rows as $r) {
        $manifest = json_decode((string)$r['manifest_json'], true);
        $basics = is_array($manifest) ? ' · 清单版本 '.e((string)($manifest['version'] ?? '')).' · 清单 ID '.e((string)($manifest['id'] ?? $manifest['name'] ?? '')) : '';
        $body .= '<section class="market-review"><h2>'.e($r['title']).' <small>'.e($r['kind']).' · '.e($r['package_name']).' · '.e($r['version']).'</small></h2><p>'.e($r['summary']).$basics.' · 提交者 '.e($r['username']).' · SHA-256 <code>'.e($r['sha256']).'</code></p><p>状态：'.e($r['status']).' · 审核人：'.e((string)($r['reviewer_name'] ?? '')).' '.e($r['review_note']).'</p>';
        foreach ((['pending'=>['approved'=>'审核通过', 'rejected'=>'拒绝'], 'approved'=>['revoked'=>'撤销公开']][$r['status']] ?? []) as $st=>$label) $body .= '<form method="post" class="inline"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="status" value="'.$st.'"><input name="review_note" maxlength="500" placeholder="审核说明"><button>'.$label.'</button></form>';
        $body .= '</section>';
    }
    if ($hasMore) $body .= '<p><a rel="next" href="/admin/market?status='.$queue.'&amp;before='.e((string)end($rows)['id']).'">加载更早的记录</a></p>';
    admin_page('扩展市场审核', $body);
}

// ===== admin-extensions-manage.php =====


function handle_admin_extensions_manage(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
if (!in_array($path, ['/admin/themes', '/admin/plugins'], true) || !in_array($method, ['GET', 'POST'], true)) return;

$actor = admin();
$section = $path === '/admin/themes' ? 'themes' : 'plugins';
$root = dirname(__DIR__);

if ($section === 'plugins' && array_key_exists('plugin', $query)) {
    $pluginId = $query['plugin'];
    $pageName = $query['page'] ?? null;
    if (!is_string($pluginId) || !is_string($pageName)
        || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $pluginId)
        || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pageName)) fail(400, '插件管理页面无效');
    $record = h('SELECT enabled FROM plugins WHERE name=?', [$pluginId])->fetch();
    if (!$record || !(int)$record['enabled']) fail(404, '插件未启用或不存在');
    $adminPage = extension_plugin_management_page($pluginId, $pageName);
    if ($adminPage === null) fail(404, '插件管理页面不存在');
    if ($method === 'POST') require_post();
    try {
        $result = ($adminPage['handler'])($request);
        if (!is_array($result) || !isset($result['body'])) throw new RuntimeException('Admin page handler must return a body array.');
        if ($method === 'POST' && $adminPage['audit'] !== '') audit($adminPage['audit'], 'plugin', null, ['name'=>$pluginId]);
    } catch (\LiteBBS\Framework\ResponseHalt $halt) {
        throw $halt;
    } catch (Throwable $error) {
        error_log('plugin admin '.$pluginId.' failed: '.$error->getMessage());
        fail(503, '插件管理页面暂时不可用');
    }
    $pages = [];
    foreach (extension_registry()['admin_pages'] as $registeredPage) {
        if (($registeredPage['plugin'] ?? '') !== $pluginId || empty($registeredPage['plugin_management'])) continue;
        $url = '/admin/plugins?plugin='.rawurlencode($pluginId).'&page='.rawurlencode((string)$registeredPage['management_page']);
        $pages[] = '<a'.($registeredPage['management_page'] === $pageName ? ' class="active" aria-current="page"' : '').' href="'.e($url).'">'.e($registeredPage['navigation']).'</a>';
    }
    $body = '<nav class="plugin-management-tabs catalog-nav" aria-label="插件管理页面"><a href="/admin/plugins">返回插件列表</a>'.implode('', $pages).'</nav>'.(string)$result['body'];
    admin_page((string)($result['title'] ?? $adminPage['title']), $body);
    return;
}

if ($section === 'themes' && $method === 'GET' && array_key_exists('theme', $query)) {
    $themeId = $query['theme'];
    if (!is_string($themeId) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $themeId)) fail(400, '模板管理页面无效');
    $package = theme_runtime_package($themeId);
    if ($package === null) fail(404, '模板不可用');
    $manifest = $package['manifest'];
    $definitions = $package['settings'];
    if (!$definitions) fail(404, '该模板未声明可管理设置。');
    $record = h('SELECT config_json FROM themes WHERE name=?', [$themeId])->fetch();
    if (!$record) fail(404, '模板尚未启用过，无法保存设置。');
    $values = theme_settings($themeId);
    $fields = '';
    foreach ($definitions as $key=>$definition) {
        $name = 'theme_setting_'.$key; $value = $values[$key] ?? $definition['default'];
        $label = e($definition['label']);
        if ($definition['type'] === 'textarea') $control = '<textarea class="admin-control" name="'.e($name).'" maxlength="'.(int)$definition['max'].'" rows="5">'.e((string)$value).'</textarea>';
        elseif ($definition['type'] === 'select') { $control = '<select class="admin-control" name="'.e($name).'">'; foreach ($definition['options'] as $optionValue=>$optionLabel) $control .= '<option value="'.e($optionValue).'"'.($value === $optionValue ? ' selected' : '').'>'.e($optionLabel).'</option>'; $control .= '</select>'; }
        elseif ($definition['type'] === 'checkbox') $control = '<input type="hidden" name="'.e($name).'" value="0"><label class="check-label"><input type="checkbox" name="'.e($name).'" value="1"'.($value ? ' checked' : '').'> '. $label.'</label>';
        else $control = '<input class="admin-control" type="'.($definition['type'] === 'color' ? 'color' : 'text').'" name="'.e($name).'" maxlength="'.(int)($definition['max'] ?? 7).'" value="'.e((string)$value).'">';
        $fields .= $definition['type'] === 'checkbox' ? '<div class="theme-setting">'.$control.'</div>' : '<label class="theme-setting">'.$label.$control.'</label>';
    }
    $body = '<nav class="plugin-management-tabs catalog-nav" aria-label="模板管理页面"><a href="/admin/themes">返回模板列表</a></nav>'
        .'<section class="theme-settings catalog-settings" aria-labelledby="catalog-settings-title"><header><h2 id="catalog-settings-title">'.e($manifest['title'] ?? $themeId).'</h2><p>显示设置</p></header>'
        .'<form class="form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($themeId).'"><input type="hidden" name="action" value="save-settings"><div class="catalog-settings-fields">'.$fields.'</div><footer><button class="primary" type="submit">保存设置</button></footer></form></section>';
    admin_page('模板设置', $body);
    return;
}

if ($method === 'POST') {
    require_post();
    $name = (string)($post['name'] ?? '');
    $action = (string)($post['action'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) fail(400, '扩展 ID 无效');
    if ($section === 'themes') {
        $useDefault = $action === 'use-default' && $name === 'default';
        if (!$useDefault && !in_array($action, ['enable','save-settings','delete'], true)) fail(400, '模板操作无效');
        $package = theme_runtime_package($name);
        if ($package === null && !$useDefault) fail(400, '模板预检失败：目录缺失或未通过检查');

        if ($action === 'save-settings') {
            $definitions = $package['settings'];
            if (!$definitions) fail(400, '该模板未声明可管理设置。');
            try { $values = theme_settings_from_input($post, $definitions); }
            catch (Throwable $error) { fail(400, '模板设置无效。'); }
            h('INSERT INTO themes(name,version,enabled,config_json) VALUES(?,?,0,?) ON DUPLICATE KEY UPDATE version=VALUES(version),config_json=VALUES(config_json)', [$name,$package['manifest']['version'],json_encode($values, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            invalidate('theme-settings:'.$name);
            audit('theme.settings.updated', 'theme', null, ['name'=>$name]);
            redirect('/admin/themes?theme='.rawurlencode($name));
        }

        if ($action === 'delete') {
            if (empty($post['confirm_token'])) confirmation_page('确认删除模板','将永久删除模板目录，此操作不可恢复。','theme.delete',['name'=>$name,'action'=>'delete'],$name);
            require_confirmation('theme.delete',$name,(string)($post['confirm_token'] ?? ''));
            if ($name === 'default') fail(400, '默认模板不能删除');
            $config=load_config();
            if (($config['theme'] ?? 'default') === $name) fail(409, '当前模板正在使用，请先启用其他模板');
            h('DELETE FROM themes WHERE name=?',[$name]);
            remove_tree($package['directory']);
            audit('theme.deleted','theme',null,['name'=>$name]);
            redirect('/admin/themes');
        }

        $previousConfig = load_config();
        $nextConfig = $previousConfig;
        $nextConfig['theme'] = $name;
        $configWritten = false;
        try {
            \LiteBBS\Application\transaction_manager()->run(static function () use ($name, $package, $nextConfig, &$configWritten): void {
                h('UPDATE themes SET enabled=0 WHERE enabled<>0');
                if ($package !== null) {
                    h('INSERT INTO themes(name,version,enabled,config_json) VALUES(?,?,1,JSON_OBJECT()) ON DUPLICATE KEY UPDATE enabled=1,version=VALUES(version)', [$name, $package['manifest']['version']]);
                }
                write_config($nextConfig);
                $configWritten = true;
            });
        } catch (Throwable $error) {
            error_log('theme activation failed: '.$error->getMessage());
            if ($configWritten) {
                try { write_config($previousConfig); }
                catch (Throwable $restoreError) { error_log('theme config restore failed: '.$restoreError->getMessage()); }
            }
            fail(500, '模板设置保存失败');
        }
        audit($useDefault ? 'theme.default.activated' : 'theme.activated', 'theme', null, $useDefault ? [] : ['name' => $name]);
        redirect('/admin/themes');
    }

    $directory = $root.'/plugins/'.$name;
    if (!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $name)) fail(400, '插件 ID 无效');
    $manifest = extension_plugin_manifest($name);
    if ($action === 'purge') {
        if (empty($post['confirm_token'])) {
            $installed = (bool)h('SELECT 1 FROM plugins WHERE name=?', [$name])->fetchColumn();
            $message = $installed ? '将永久删除插件目录、登记、迁移记录、任务、用户字段、搜索文档和已确认归属的数据表。无法确认归属时会阻止删除。' : '该插件尚未安装，将仅永久删除插件目录及其文件。';
            confirmation_page('确认永久删除插件',$message,'plugin.purge',['name'=>$name,'action'=>'purge'],$name);
            return;
        }
        require_confirmation('plugin.purge',$name,(string)($post['confirm_token'] ?? ''));
        try { extension_purge_plugin($name, requireDisabled: true); }
        catch(Throwable $error){extension_invalidate('plugins');fail(409,'插件永久删除失败：'.$error->getMessage());}
        extension_invalidate('plugins');
        redirect('/admin/plugins');
    }
    if (!in_array($action, ['install','upgrade','enable','disable','uninstall'], true)) fail(400, '插件操作无效');
    if ($action === 'uninstall' && empty($post['confirm_token'])) {
        $orphaned=!is_dir($directory);
        $token=confirmation_token('plugin.uninstall',$name);
        $pluginTitle=is_array($manifest)?(string)($manifest['title']??$name):$name;
        $fields='<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="confirm_token" value="'.e($token).'">'.'<input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="uninstall">'.($orphaned?'':'<label class="plugin-confirm-option"><input type="checkbox" name="preserve_data" value="1" checked><span><strong>保留插件数据</strong><small>以后重新安装时，可以继续使用现有数据。</small></span></label><div class="plugin-data-result"><span>数据处理</span><strong class="when-kept">数据表与用户字段值将保留</strong><strong class="when-removed">数据表与用户字段值将永久删除</strong></div>').'<div class="plugin-confirm-actions"><a href="/admin/plugins">取消</a><button type="submit" class="plugin-confirm-danger">'.($orphaned?'强制永久删除':'确认卸载').'</button></div>';
        $message=$orphaned?'插件目录已经缺失。若发现无法确认归属的数据表，将阻止删除。否则将永久删除插件登记及其关联记录。':'保留数据时会保留迁移记录，重新安装可复用原有数据。取消保留数据会永久删除已确认归属的插件数据，归属不明时将阻止删除。';
        admin_page($orphaned?'确认强制删除':'确认卸载插件','<section class="plugin-confirm'.($orphaned?' is-orphaned':'').'"><header><span class="plugin-confirm-mark" aria-hidden="true">!</span><div class="plugin-confirm-heading"><p class="eyebrow">'.($orphaned?'插件记录异常':'卸载插件').'</p><h2>'.e($pluginTitle).'</h2><code>'.e($name).'</code></div><span class="plugin-confirm-risk">'.($orphaned?'不可恢复':'谨慎操作').'</span></header><div class="plugin-confirm-body"><p class="plugin-confirm-message">'.e($message).'</p><div class="plugin-confirm-impact"><span>卸载后</span><strong>'.($orphaned?'插件及关联数据将被永久删除':'插件将从后台列表中移除').'</strong></div><form method="post">'.$fields.'</form></div></section>');
        return;
    }
    if ($action === 'uninstall') {
        require_confirmation('plugin.uninstall',$name,(string)($post['confirm_token'] ?? ''));
    }
    try { extension_plugin_lifecycle($name,$action,is_dir($directory) && !empty($post['preserve_data']), approvedByAdmin: true); }
    catch(Throwable $error){extension_invalidate('plugins');fail(409,'插件操作失败：'.$error->getMessage());}
    extension_invalidate('plugins');
    redirect('/admin/plugins');
}

$actionForm = static function (string $name, string $action, string $label, string $class): string {
    return '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'">'
        .'<input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="'.e($action).'">'
        .'<button type="submit" class="'.e($class).'">'.e($label).'</button></form>';
};
$forumShare = static function (string $kind, array $manifest, string $name): string {
    return '<button type="button" class="catalog-share" data-forum-share data-forum-kind="'.e($kind).'" data-forum-name="'.e($name).'" data-forum-csrf="'.e(csrf()).'">分享</button>';
};
$body = '<div class="extension-catalog">';
$invalidExtensions = [];
if ($section === 'themes') {
    $body .= '<div class="admin-list-toolbar catalog-toolbar"><p class="admin-intro">启用前会检查模板结构与兼容性，模板代码仍需人工审查。</p></div>';
    $configured = (string)(load_config()['theme'] ?? 'default');
    $active = theme_effective_package($configured)['name'] ?? null;
    $cards = [];
    $settingsRecords = array_column(h('SELECT name FROM themes')->fetchAll(), 'name', 'name');
    foreach (is_dir($root.'/templates') ? new DirectoryIterator($root.'/templates') : [] as $dir) {
        $folder = $dir->getFilename();
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $folder)) continue;
        $manifest = theme_read_manifest($dir->getPathname());
        $name = $manifest['name'] ?? $folder;
        $package = is_string($name) ? theme_runtime_package($name) : null;
        if ($package === null || realpath($package['directory']) !== $dir->getRealPath()) {
            $invalidExtensions[$folder] = '目录或清单无效，或模板 ID 冲突。';
            continue;
        }
        $manifest = $package['manifest'];
        $isActive = $name === $active;
        $preview = '<span class="catalog-preview-placeholder" aria-hidden="true"><i></i><i></i><i></i></span>';
        // Only look at fixed raster filenames on the existing public theme asset path.
        $previewTypes = ['screenshot.webp'=>IMAGETYPE_WEBP, 'screenshot.png'=>IMAGETYPE_PNG, 'screenshot.jpg'=>IMAGETYPE_JPEG, 'screenshot.jpeg'=>IMAGETYPE_JPEG];
        if ($name === 'default') $previewTypes['banner.webp'] = IMAGETYPE_WEBP;
        foreach ($previewTypes as $asset => $type) {
            $file = extension_secure_runtime_file($root, 'templates/'.$folder.'/assets/'.$asset);
            if ($file === null || (@getimagesize($file)[2] ?? null) !== $type) continue;
            $preview .= '<img src="/templates/'.rawurlencode($folder).'/assets/'.rawurlencode($asset).'" alt="" loading="lazy" decoding="async">';
            break;
        }

        $card = '<article class="catalog-theme'.($isActive ? ' is-active' : '').'">'
            .'<div class="catalog-preview">'.$preview.'</div><div class="catalog-theme-details">'
            .($isActive ? '<span class="catalog-status is-enabled">当前使用</span>' : '')
            .'<h2>'.e($manifest['title'] ?? $name).'</h2><p class="catalog-meta">'.e($name).' · 版本 '.e($manifest['version'] ?? '未知').' · '.e($manifest['author'] ?? '未知作者').'</p>';
        $card .= '</div><div class="catalog-theme-actions">';
        if (!$isActive || $configured !== $name) $card .= $actionForm($name, 'enable', '启用', 'primary');
        if ($package['settings'] && isset($settingsRecords[$name])) $card .= '<a class="catalog-button" href="/admin/themes?theme='.rawurlencode($name).'">管理设置</a>';
        $card .= $forumShare('theme', $manifest, $name);
        if ($name !== 'default' && $configured !== $name) $card .= $actionForm($name, 'delete', '删除模板', 'catalog-button is-danger');
        $cards[$name] = $card.'</div></article>';
    }
    if ($active !== $configured && $configured !== 'default') {
        $body .= '<section class="catalog-notice"><h2>配置的模板不可用</h2><p>'.e($configured).'：目录缺失或未通过检查。</p>'
            .$actionForm('default', 'use-default', '使用默认模板', 'catalog-button').'</section>';
    }
    if ($active === null) {
        $body .= '<section class="catalog-notice"><p>当前使用核心布局。</p></section>';
    }
    if ($cards) {
        $activeCard = $active === null ? '' : ($cards[$active] ?? '');
        if ($active !== null) unset($cards[$active]);
        $body .= '<div class="catalog-theme-grid">'.$activeCard.implode('', $cards).'</div>';
    } else {
        $body .= '<p class="catalog-empty">没有通过检查的模板。</p>';
    }
} else {
    $body .= '<div class="admin-list-toolbar catalog-toolbar"><p class="admin-intro">插件会执行 PHP 代码，请在启用前人工审查。未应用的插件迁移须由运维人员通过 CLI 执行。状态变更前会检查依赖、冲突和核心能力，卸载时可选择保留数据。</p></div>';
    $pluginRow = static function (string $title, string $name, string $actions, string $description, string $meta, string $state, string $stateClass): string {
        return '<tr role="row"><th scope="row" role="rowheader"><strong class="catalog-plugin-title">'.e($title).'</strong>'
            .($title !== $name ? '<span class="catalog-meta">'.e($name).'</span>' : '')
            .'<div class="catalog-row-actions">'.$actions.'</div></th>'
            .'<td role="cell" class="catalog-plugin-description"><p>'.e($description).'</p><p class="catalog-meta">'.e($meta).'</p></td>'
            .'<td role="cell" class="catalog-plugin-status"><span class="catalog-status '.e($stateClass).'">'.e($state).'</span></td></tr>';
    };
    $rows = '';
    $installed = [];
    foreach (h('SELECT name,version,enabled FROM plugins')->fetchAll() as $row) $installed[$row['name']] = $row;
    $runnable = extension_runtime_plugins();
    foreach (is_dir($root.'/plugins') ? new DirectoryIterator($root.'/plugins') : [] as $dir) {
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dir->getFilename())) continue;
        $name = $dir->getFilename();
        $manifest = extension_plugin_manifest($name);
        try {
            if ($manifest === null) throw new RuntimeException('插件清单缺失、无效或为链接。');
            extension_validate_directory($dir->getPathname(), 'plugin', $name);
        } catch (Throwable $error) {
            $invalidExtensions[$name] = $error->getMessage();
            $record = $installed[$name] ?? null;
            $enabled = (int)($record['enabled'] ?? 0);
            $actions = $actionForm($name, 'purge', '永久删除并清理数据', 'catalog-row-action is-danger');
            $meta = $record ? '已登记版本 '.$record['version'] : '未安装';
            $state = $enabled ? '运行已阻止' : '不可用';
            $rows .= $pluginRow($name, $name, $actions, '插件未通过检查：'.$error->getMessage(), $meta, $state, 'is-warning');
            unset($installed[$name]);
            continue;
        }
        $record = $installed[$name] ?? null;
        $enabled = (int)($record['enabled'] ?? 0);
        $validationError = null;
        try {
            $adminPath = is_array($manifest) ? extension_manifest_admin_path($manifest, $name) : null;
            if (isset($runnable[$name]) && $adminPath !== null) $adminPath = extension_plugin_management_path($name, $adminPath);
        } catch (Throwable $error) { $validationError = $error->getMessage(); $adminPath = null; }
        $actions = '';
        $version = (string)($manifest['version'] ?? '未知');
        $meta = '版本 '.$version;
        if (isset($runnable[$name]) && $adminPath !== null) $actions .= '<a class="catalog-row-action" href="'.e($adminPath).'">管理</a>';
        $actions .= $forumShare('plugin', $manifest, $name);
        if (!$record) {
            $actions .= $actionForm($name, 'install', '安装', 'catalog-row-action');
            $actions .= $actionForm($name, 'purge', '删除插件文件', 'catalog-row-action is-danger');
        } else {
            if (version_compare((string)($manifest['version'] ?? '0.0.0'), (string)$record['version'], '>')) {
                $actions .= $actionForm($name, 'upgrade', '升级', 'catalog-row-action');
                $meta = '已安装 '.$record['version'].' · 可升级至 '.$version;
            }
            if ($enabled || $version === $record['version']) $actions .= $actionForm($name, $enabled ? 'disable' : 'enable', $enabled ? '停用' : '启用', 'catalog-row-action');
            if (!$enabled) {
                $actions .= $actionForm($name, 'uninstall', '卸载', 'catalog-row-action is-danger');
                $actions .= $actionForm($name, 'purge', '永久删除', 'catalog-row-action is-danger');
            }
        }
        if ($record && $version !== $record['version']) $meta = '已安装 '.$record['version'].' · 目录版本 '.$version;
        $state = $validationError !== null ? ($enabled ? '运行已阻止' : '不可用') : ($record ? ($enabled ? (isset($runnable[$name]) ? '已启用' : '运行已阻止') : '已停用') : '未安装');
        $description = $validationError === null ? (string)($manifest['description'] ?? '暂无说明') : '插件未通过检查：'.$validationError;
        $stateClass = $validationError !== null ? 'is-warning' : ($enabled ? (isset($runnable[$name]) ? 'is-enabled' : 'is-warning') : '');
        $rows .= $pluginRow((string)($manifest['title'] ?? $name), $name, $actions, $description, $meta, $state, $stateClass);
        unset($installed[$name]);
    }
    foreach ($installed as $name => $record) {
        $enabled = (int)$record['enabled'];
        $actions = $enabled ? $actionForm($name, 'disable', '停用', 'catalog-row-action') : '';
        $actions .= $actionForm($name, 'uninstall', '清理登记及关联数据', 'catalog-row-action is-danger');
        if (!$enabled) $actions .= $actionForm($name, 'purge', '永久删除目录和数据', 'catalog-row-action is-danger');
        $rows .= $pluginRow($name, $name, $actions, '插件目录缺失。', '已登记版本 '.$record['version'], $enabled ? '运行已阻止' : '不可用', 'is-warning');
    }
    $body .= '<p class="admin-intro">安装、升级或启用前，请审核插件的 PHP 代码。启用的插件拥有与应用相同的 PHP 进程权限。</p>'
        .'<div class="catalog-plugin-list"><table class="catalog-plugin-table" role="table" aria-label="插件列表">'
        .'<thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">插件</th><th scope="col" role="columnheader">说明</th><th scope="col" role="columnheader">状态</th></tr></thead><tbody role="rowgroup">'
        .($rows !== '' ? $rows : '<tr role="row"><td role="cell" colspan="3" class="catalog-empty">暂无可管理的插件。</td></tr>')
        .'</tbody></table></div>';
}
if ($invalidExtensions && $section === 'themes') {
    $body .= '<section class="catalog-notice"><h2>未通过检查的'.($section === 'themes' ? '模板' : '插件').'</h2><ul>';
    foreach ($invalidExtensions as $name => $reason) {
        $body .= '<li><strong>'.e($name).'</strong><p>'.e($reason).'</p>';
        if ($section === 'plugins') $body .= $actionForm($name, 'purge', '永久删除并清理数据', 'catalog-button is-danger');
        $body .= '</li>';
    }
    $body .= '</ul></section>';
}
if ($section === 'plugins') {
    $rendererDiagnostics = extension_markdown_renderer_diagnostics();
    if ($rendererDiagnostics['conflict']) {
        $body .= '<section class="catalog-notice"><h2>Markdown 主渲染器冲突</h2><p>当前选择：'.e($rendererDiagnostics['active']).'。候选项按优先级排列：</p><ul>';
        foreach ($rendererDiagnostics['renderers'] as $renderer) {
            $body .= '<li><strong>'.e($renderer['plugin']).'</strong><p>优先级 '.(int)$renderer['priority'].' · '.e($renderer['reason']).'</p></li>';
        }
        $body .= '</ul></section>';
    }
}
admin_page($section === 'themes' ? '模板管理' : '插件管理', $body.'</div>');
return;
}


// ===== admin-users-settings.php =====
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
        admin_page('内容管理', $body);
        return;
    }
    require_post();
    $id = (int)($post['id'] ?? 0);
    $action = (string)($post['action'] ?? '');
    if ($action === 'delete') {
        if (empty($post['confirm_token'])) confirmation_page('确认永久删除','将永久删除主题、回复、修订和积分记录，此操作不可恢复。','topic.delete',['id'=>$id,'action'=>'delete'],(string)$id);
        require_confirmation('topic.delete',(string)$id,(string)($post['confirm_token'] ?? ''));
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
    $securityScannerEnabled = (string)($post['security_scanner_enabled'] ?? '0');
    $virusTotalKey = trim((string)($post['virustotal_api_key'] ?? ''));
    $malwareBazaarKey = trim((string)($post['malwarebazaar_api_key'] ?? ''));
    $virusTotalUpload = (string)($post['virustotal_upload_unknown'] ?? '0');
    $securityScannerProvider = (string)($post['security_scanner_provider'] ?? 'virustotal');
    $topicRateMax = (int)($post['topic_rate_max'] ?? 0);
    $topicRateWindow = (int)($post['topic_rate_window'] ?? 300);
    $replyRateMax = (int)($post['reply_rate_max'] ?? 0);
    $replyRateWindow = (int)($post['reply_rate_window'] ?? 300);
    $locale = (string)($post['locale'] ?? 'zh-CN');
    $seoHomeDescription = trim((string)($post['seo_home_description'] ?? ''));
    $seoIndexing = (string)($post['seo_indexing'] ?? '1');
    $seoVerification = [];
    foreach (['google','bing','baidu','yandex','naver'] as $engine) $seoVerification[$engine] = trim((string)($post['seo_'.$engine.'_verification'] ?? ''));
    if (text_length($site) < 2 || text_length($site) > 100 || !in_array($registration, ['0', '1'], true) || !in_array($securityScannerEnabled, ['0', '1'], true) || !in_array($securityScannerProvider, ['virustotal', 'malwarebazaar'], true) || !in_array($virusTotalUpload, ['0', '1'], true) || ($virusTotalKey !== '' && !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $virusTotalKey)) || ($malwareBazaarKey !== '' && (!preg_match('/^[A-Za-z0-9_-]{16,256}$/D', $malwareBazaarKey))) || $interval < 0 || $interval > 3600 || $topicRateMax < 0 || $topicRateMax > 1000 || $replyRateMax < 0 || $replyRateMax > 1000 || $topicRateWindow < 1 || $topicRateWindow > 86400 || $replyRateWindow < 1 || $replyRateWindow > 86400 || !in_array($locale, ['zh-CN', 'en-US'], true) || text_length($seoHomeDescription) > 220 || !in_array($seoIndexing, ['0', '1'], true)) fail(400, '设置值无效');
    foreach ($seoVerification as $value) if ($value !== '' && (!preg_match('/^[\x21-\x7E]{1,512}$/', $value) || strlen($value) > 512)) fail(400, '站长平台验证码格式无效');
    $config['site'] = $site;
    $config['locale'] = $locale;
    $config['seo'] = ['home_description'=>$seoHomeDescription, 'indexing'=>(int)$seoIndexing];
    $config['security_scanner_provider'] = $securityScannerProvider;
    if ($virusTotalKey !== '') $config['virustotal_api_key'] = $virusTotalKey;
    if ($malwareBazaarKey !== '') $config['malwarebazaar_api_key'] = $malwareBazaarKey;
    $config['virustotal_upload_unknown'] = $virusTotalUpload === '1';
    foreach ($seoVerification as $engine=>$value) $config['seo'][$engine.'_verification'] = $value;
    try { write_config($config); } catch (Throwable $error) { fail(500, '配置写入失败'); }
    foreach (['registration'=>$registration,'security_scanner_enabled'=>$securityScannerEnabled,'post_interval'=>(string)$interval,'topic_rate_max'=>(string)$topicRateMax,'topic_rate_window'=>(string)$topicRateWindow,'reply_rate_max'=>(string)$replyRateMax,'reply_rate_window'=>(string)$replyRateWindow] as $key => $value) {
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
    admin_page('用户管理', $body);
    return;
}

$settings = [];
foreach (h('SELECT setting_key,setting_value FROM settings WHERE setting_key IN ("registration","security_scanner_enabled","post_interval","topic_rate_max","topic_rate_window","reply_rate_max","reply_rate_window")')->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];
$seo = is_array($config['seo'] ?? null) ? $config['seo'] : [];
$body = '<form class="admin-settings-form" method="post" action="/admin/settings"><input type="hidden" name="csrf" value="'.e(csrf()).'">'.
    '<section class="settings-section" id="site-general"><h2>社区信息</h2><div class="settings-grid">'.
    '<label>站点名称<input name="site" maxlength="100" value="'.e($config['site'] ?? 'LiteBBS').'" required></label>'.
    '<label>默认语言<select name="locale"><option value="zh-CN"'.(($config['locale'] ?? 'zh-CN') === 'zh-CN' ? ' selected' : '').'>简体中文</option><option value="en-US"'.(($config['locale'] ?? 'zh-CN') === 'en-US' ? ' selected' : '').'>English</option></select></label>'.
    '</div></section>'.
    '<section class="settings-section" id="site-security"><h2>安全工具</h2><div class="settings-grid">'.
    '<label>插件和模板病毒扫描<select name="security_scanner_enabled"><option value="0"'.(($settings['security_scanner_enabled'] ?? '0') !== '1' ? ' selected' : '').'>关闭</option><option value="1"'.(($settings['security_scanner_enabled'] ?? '0') === '1' ? ' selected' : '').'>开启</option></select><small>开启后，后台侧边栏会显示病毒扫描入口。扫描仅分析插件和模板文件，不会执行它们。</small></label>'.
    '<label>在线扫描情报源<select name="security_scanner_provider"><option value="virustotal"'.(($config['security_scanner_provider'] ?? 'virustotal') === 'virustotal' ? ' selected' : '').'>VirusTotal 多引擎</option><option value="malwarebazaar"'.(($config['security_scanner_provider'] ?? '') === 'malwarebazaar' ? ' selected' : '').'>MalwareBazaar 已知恶意样本</option></select><small>MalwareBazaar 仅用 SHA-256 查询已知恶意样本，不上传文件；VirusTotal 支持多引擎查询及可选未知文件上传。</small></label>'.
    '<label>MalwareBazaar API 密钥<input name="malwarebazaar_api_key" type="password" maxlength="256" placeholder="'.(!empty($config['malwarebazaar_api_key']) ? '已配置，留空则保持不变' : '选择 MalwareBazaar 时填写 Auth-Key').'" autocomplete="off"><small>MalwareBazaar 当前接口需要 Auth-Key。密钥仅保存到私有配置文件，查询只发送文件 SHA-256。<a href="https://bazaar.abuse.ch/api/" target="_blank" rel="noopener noreferrer">查看官方 API 文档与申请说明</a></small></label>'.
    '<label>VirusTotal API 密钥<input name="virustotal_api_key" type="password" maxlength="128" placeholder="'.(!empty($config['virustotal_api_key']) ? '已配置，留空则保持不变' : '填写 VirusTotal v3 API Key').'" autocomplete="off"><small>密钥保存在私有配置文件，不会显示在页面或扫描报告中。默认仅查询文件 SHA-256。</small></label>'.
    '<label>上传未知文件到 VirusTotal<select name="virustotal_upload_unknown"><option value="0"'.(empty($config['virustotal_upload_unknown']) ? ' selected' : '').'>关闭</option><option value="1"'.(!empty($config['virustotal_upload_unknown']) ? ' selected' : '').'>开启</option></select><small>开启会将 VirusTotal 未收录的插件或模板文件上传到第三方分析，可能泄露私有源码。</small></label>'.
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
admin_page('站点设置', '<nav class="settings-sections" aria-label="设置分组"><a href="#site-general">社区信息</a><a href="#site-security">安全工具</a><a href="#site-community">注册与发帖</a><a href="#site-search">搜索展示</a><a href="#site-verification">站长平台验证</a></nav>'.$body);
return;
}


/**
 * The only administrative routing boundary.  Feature implementations remain
 * lazy so ordinary public requests do not parse administration code.
 */
function handle_backend_routes(\LiteBBS\Framework\Request $request): void
{
    if ($request->path === '/admin/extension-share') { handle_extension_share_create($request); return; }
    if (admin_database_upgrade_handle($request)) return;
    $file = match ($request->path) {
        '/admin' => 'admin-dashboard.php',
        '/admin/categories' => 'admin-categories.php',
        '/admin/audit' => 'admin-audit.php',
        '/admin/reports' => 'admin-reports.php',
        '/admin/themes', '/admin/plugins' => 'admin-extensions-manage.php',
        '/admin/scanner' => 'admin-scanner.php',
        '/admin/users', '/admin/settings', '/admin/content' => 'admin-users-settings.php',
        '/admin/market' => 'admin-market-review.php',
        '/admin/extensions' => 'admin-update.php',
        default => throw new InvalidArgumentException('Unknown administration route.'),
    };
    $handlers = [
        'admin-dashboard.php' => 'handle_admin_dashboard',
        'admin-categories.php' => 'handle_admin_categories',
        'admin-audit.php' => 'handle_admin_audit',
        'admin-reports.php' => 'handle_admin_reports',
        'admin-extensions-manage.php' => 'handle_admin_extensions_manage',
        'admin-scanner.php' => 'handle_admin_security_scan',
        'admin-users-settings.php' => 'handle_admin_users_settings',
        'admin-market-review.php' => 'handle_admin_market_review',
        'admin-update.php' => 'handle_admin_program_update',
    ];
    if ($file === 'admin-update.php') require_once __DIR__.'/update.php';
    if ($file === 'admin-scanner.php') require_once __DIR__.'/scanner.php';
    $handlers[$file]($request);
}
