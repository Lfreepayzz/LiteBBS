<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="<?= e($c['locale'] ?? 'zh-CN') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="<?= e(text_slice(strip_tags($description), 0, 220)) ?>">
  <meta name="robots" content="<?= $indexable ? 'index,follow' : 'noindex,follow' ?>">
  <title><?= e($title) ?> · <?= e($c['site'] ?? 'LiteBBS') ?></title>
  <?= theme_runtime_assets($safe, $isHomePage, $isTopicPage) ?>
  <?= plugin_stylesheets() ?><?= extension_stylesheets() ?>
  <?= extension_slot('head.assets', ['path'=>$requestPath, 'user'=>$user]) ?>
</head>
<body>
  <header class="aurora-header">
    <div class="aurora-header-inner">
      <a href="/" class="aurora-brand"><span class="aurora-brand-mark">A</span><span><?= e($c['site'] ?? 'LiteBBS') ?></span></a>
      <nav class="aurora-nav" aria-label="主导航"><a href="/"<?= $isHomePage ? ' aria-current="page"' : '' ?>>首页</a><a href="/search"<?= $requestPath === '/search' ? ' aria-current="page"' : '' ?>>发现</a><a href="/market">开发者</a></nav>
      <div class="aurora-account"><?php if ($user): ?><?php if (($user['role'] ?? '') === 'admin'): ?><a class="aurora-admin" href="/admin">后台管理</a><?php endif; ?><a href="/me" class="aurora-user"><span><?= e(text_slice($user['username'], 0, 1)) ?></span><?= e($user['username']) ?></a><?php else: ?><a href="/login">登录</a><a class="aurora-join" href="/register">加入社区</a><?php endif; ?></div>
    </div>
  </header>
  <main class="aurora-main">
    <div class="aurora-breadcrumb"><a href="/">社区</a><span>/</span><strong><?= e($title) ?></strong></div>
    <div class="aurora-grid"><section class="aurora-content"><?= $body ?></section><aside class="aurora-aside"><div class="aurora-aside-orb"></div><span class="aurora-kicker">AURORA COMMUNITY</span><h2>让好观点<br>被看见。</h2><p>分享经验，认真讨论，和志同道合的人一起建立有价值的社区。</p><?php if ($user): ?><a class="aurora-action" href="/new">发起新讨论 <span>↗</span></a><?php else: ?><a class="aurora-action" href="/register">加入社区 <span>↗</span></a><?php endif; ?><?= extension_slot('sidebar.sections', ['path'=>$requestPath, 'user'=>$user]) ?></aside></div>
  </main>
  <footer class="aurora-footer"><span><?= e($c['site'] ?? 'LiteBBS') ?></span><span>保持好奇，认真交流。</span></footer>
</body>
</html>
