<?php
declare(strict_types=1);
$requestPath = is_string($requestPath ?? null) ? $requestPath : '/admin';
$links = [
    '/admin' => '总览', '/admin/content' => '内容', '/admin/users' => '用户',
    '/admin/categories' => '分区', '/admin/themes' => '模板', '/admin/plugins' => '插件',
    '/admin/market' => '扩展审核', '/admin/audit' => '审计日志',
    '/admin/settings' => '站点设置',
];
?><!doctype html>
<html lang="<?=e($c['locale'] ?? 'zh-CN')?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · <?=e($c['site'] ?? 'LiteBBS')?></title>
<meta name="robots" content="noindex,follow">
<link rel="stylesheet" href="/admin.css">
</head>
<body class="admin-body">
<main class="admin-page"><div class="admin-shell"><aside class="admin-sidebar"><a class="admin-brand" href="/admin"><span class="admin-mark">R</span><span><?=e($c['site'] ?? 'LiteBBS')?></span></a><small>后台管理</small><?php foreach($links as $url => $label): ?><a class="admin-link<?=$requestPath === $url ? ' active' : ''?>" href="<?=e($url)?>"><?=e($label)?></a><?php endforeach; ?><a class="admin-back" href="/">返回社区</a><a class="admin-link<?=$requestPath === '/admin/extensions' ? ' active' : ''?>" href="/admin/extensions">程序更新</a></aside><section class="admin-workspace"><header class="admin-top"><div><p class="eyebrow">管理中心</p><h1><?=e($title)?></h1></div><span><?=e($user['username'] ?? '')?></span></header><div class="admin-content"><?=$body?></div></section></div></main>
</body></html>
