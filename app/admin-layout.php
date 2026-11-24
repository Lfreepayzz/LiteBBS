<?php
declare(strict_types=1);
$requestPath = is_string($requestPath ?? null) ? $requestPath : '/admin';
$pluginId = $_GET['plugin'] ?? null;
$pluginPageName = $_GET['page'] ?? null;
$pluginManagementId = $requestPath === '/admin/plugins' && is_string($pluginId) && preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $pluginId) ? $pluginId : null;
$pluginManagementName = $pluginManagementId !== null && is_string($pluginPageName) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pluginPageName) ? $pluginPageName : null;
extension_runtime_load($pluginManagementId);
$pluginManagementPage = $pluginManagementId !== null && $pluginManagementName !== null ? extension_plugin_management_page($pluginManagementId, $pluginManagementName) : null;
$menu = [
    '/admin' => ['总览', '▦', ['/admin/extensions' => '程序更新']],
    '/admin/content' => ['内容管理', '▤', ['/admin/categories' => '论坛分区']],
    '/admin/reports' => ['举报审核', '!'],
    '/admin/themes' => ['模板', '◧'],
    '/admin/plugins' => ['插件', '⊞'],
    '/admin/market' => ['扩展审核', '◇'],
    '/admin/users' => ['用户', '♙'],
    '/admin/audit' => ['审计日志', '≡'],
    '/admin/settings' => ['站点设置', '⚙'],
];
$showAdminSlots = $pluginManagementPage === null;
$breadcrumbs = $pluginManagementPage['breadcrumbs'] ?? [];
if ($showAdminSlots) {
    foreach (extension_registry()['admin_pages'] as $registeredPage) {
        if (!empty($registeredPage['plugin_management'])) continue;
        $menu[$registeredPage['path']] ??= [$registeredPage['navigation'], '›'];
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
<?=extension_stylesheets($requestPath, $pluginManagementId)?>
<?=$showAdminSlots ? extension_slot('admin.head', $adminExtensionView) : ''?>
</head>
<body class="admin-body">
<a class="admin-skip" href="#admin-main">跳到主要内容</a>
<header class="admin-toolbar">
    <button class="admin-menu-toggle" type="button" aria-controls="admin-menu" aria-expanded="true" aria-label="收起管理菜单" hidden><span aria-hidden="true">☰</span></button>
    <a class="admin-brand" href="/admin"><?=e($siteName)?></a>
    <a class="admin-back" href="/">访问社区</a>
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
                <span class="admin-menu-icon" aria-hidden="true"><?=e($icon)?></span><?=e($label)?>
            </a>
            <?php if ($children): ?>
                <div class="admin-submenu">
                    <?php foreach ($children as $childUrl => $childLabel): ?>
                        <a class="admin-link" href="<?=e($childUrl)?>"<?=$requestPath === $childUrl ? ' aria-current="page"' : ''?>><?=e($childLabel)?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?=$showAdminSlots ? extension_slot('admin.navigation', $adminExtensionView) : ''?>
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
        <footer class="admin-footer"><span>LiteBBS</span><a href="https://bolgk.eu.org/" target="_blank" rel="noopener noreferrer">提交 BUG</a></footer>
    </main>
</div>
<script src="/assets/js/admin-menu.js?v=<?=filemtime(__DIR__.'/../assets/js/admin-menu.js')?>" defer></script>
<?=extension_javascripts($requestPath, $pluginManagementId)?>
</body></html>
