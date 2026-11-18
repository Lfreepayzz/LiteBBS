<?php
declare(strict_types=1);
$requestPath = is_string($requestPath ?? null) ? $requestPath : '/admin';
$pluginManagementId = $requestPath === '/admin/plugins' && preg_match('/^[a-z][a-z0-9_-]{1,31}$/', (string)($_GET['plugin'] ?? '')) ? (string)$_GET['plugin'] : null;
$pluginManagementName = $pluginManagementId !== null && preg_match('/^[a-z][a-z0-9_-]{0,63}$/', (string)($_GET['page'] ?? '')) ? (string)$_GET['page'] : null;
extension_runtime_load($pluginManagementId);
$pluginManagementPage = $pluginManagementId !== null && $pluginManagementName !== null ? extension_plugin_management_page($pluginManagementId, $pluginManagementName) : null;
$links = [
    '/admin' => '总览', '/admin/content' => '内容', '/admin/users' => '用户',
    '/admin/categories' => '分区', '/admin/themes' => '模板', '/admin/plugins' => '插件',
    '/admin/market' => '扩展审核', '/admin/audit' => '审计日志',
    '/admin/settings' => '站点设置', '/admin/reports' => '举报审核',
];
if($pluginManagementPage===null)foreach(extension_registry()['admin_pages'] as $registeredPage)if(empty($registeredPage['plugin_management']))$links[$registeredPage['path']]=$registeredPage['navigation'];
$breadcrumbs=[];
foreach(extension_registry()['admin_pages'] as $registeredPage)if((!empty($registeredPage['plugin_management'])&&$registeredPage===$pluginManagementPage)||$registeredPage['path']===$requestPath)$breadcrumbs=$registeredPage['breadcrumbs'];
$adminExtensionView=['path'=>$requestPath,'user'=>$user,'plugin_management'=>$pluginManagementPage!==null,'plugin'=>$pluginManagementPage['plugin']??null];
?><!doctype html>
<html lang="<?=e($c['locale'] ?? 'zh-CN')?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · <?=e($c['site'] ?? 'LiteBBS')?></title>
<meta name="robots" content="noindex,follow">
<link rel="stylesheet" href="/assets/css/admin.css?v=<?=asset_version(__DIR__.'/../assets/css/admin.css')?>">
<link rel="stylesheet" href="/assets/css/admin-settings.css">
<link rel="stylesheet" href="/assets/css/admin-management.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-management.css')?>">
<link rel="stylesheet" href="/assets/css/admin-audit.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-audit.css')?>">
<link rel="stylesheet" href="/assets/css/admin-confirm.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-confirm.css')?>">
<link rel="stylesheet" href="/assets/css/admin-extensions.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-extensions.css')?>">
<?=extension_stylesheets($requestPath, $pluginManagementId)?>
<?=$pluginManagementPage===null?extension_slot('admin.head', $adminExtensionView):''?>
</head>
<body class="admin-body">
<main class="admin-page"><div class="admin-shell"><aside class="admin-sidebar"><a class="admin-brand" href="/admin"><span class="admin-mark">R</span><span><?=e($c['site'] ?? 'LiteBBS')?></span></a><small>后台管理</small><?php foreach($links as $url => $label): ?><a class="admin-link<?=($requestPath === $url || ($pluginManagementPage!==null && $url==='/admin/plugins')) ? ' active' : ''?>" href="<?=e($url)?>"><?=e($label)?></a><?php endforeach; ?><?=$pluginManagementPage===null?extension_slot('admin.navigation', $adminExtensionView):''?><a class="admin-back" href="/">返回社区</a><a class="admin-link<?=$requestPath === '/admin/extensions' ? ' active' : ''?>" href="/admin/extensions">程序更新</a><a class="admin-link" href="https://bolgk.eu.org/" target="_blank" rel="noopener noreferrer">提交BUG</a></aside><section class="admin-workspace"><header class="admin-top"><div><?php if($breadcrumbs): ?><nav aria-label="面包屑"><?php foreach($breadcrumbs as $label=>$url): ?><?php if(is_string($url)): ?><a href="<?=e($url)?>"><?=e($label)?></a><?php else: ?><span><?=e((string)$url)?></span><?php endif; ?><?php endforeach; ?></nav><?php else: ?><p class="eyebrow">管理中心</p><?php endif; ?><h1><?=e($title)?></h1></div><span><?=e($user['username'] ?? '')?></span></header><div class="admin-content"><?=$pluginManagementPage===null?extension_slot('admin.content.before', $adminExtensionView+['title'=>$title,'is_admin'=>true]):''?><?=$body?><?=$pluginManagementPage===null?extension_slot('admin.content.after', $adminExtensionView+['title'=>$title,'is_admin'=>true]):''?></div></section></div></main>
<?=extension_javascripts($requestPath, $pluginManagementId)?>
</body></html>
