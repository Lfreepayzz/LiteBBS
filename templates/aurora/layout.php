<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="<?= e($c['locale'] ?? 'zh-CN') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?= seo_head($title, $description, $indexable, $canonical, $c) ?>
  <?= theme_runtime_assets($safe, $isHomePage, $isTopicPage) ?>
  <?= plugin_stylesheets() ?><?= extension_stylesheets($requestPath) ?>
  <?= extension_slot('head.assets', ['path'=>$requestPath, 'user'=>$user]) ?>
</head>
<body<?= $isTopicPage ? ' class="topic-page"' : '' ?>>
  <header class="aurora-header">
    <div class="aurora-header-inner">
      <a href="/" class="aurora-brand"><span class="aurora-brand-mark">A</span><span><?= e($c['site'] ?? 'LiteBBS') ?></span></a>
      <nav class="aurora-nav" aria-label="主导航"><a href="/"<?= $isHomePage ? ' aria-current="page"' : '' ?>>首页</a><a href="/search"<?= $requestPath === '/search' ? ' aria-current="page"' : '' ?>>发现</a><a href="/market">开发者</a><?= extension_slot('navigation.primary', ['path'=>$requestPath, 'user'=>$user]) ?></nav>
      <div class="aurora-account"><?php if ($user): ?><?php if (($user['role'] ?? '') === 'admin'): ?><a class="aurora-admin" href="/admin">后台管理</a><?php endif; ?><a href="/me" class="aurora-user"><span><?= e(text_slice($user['username'], 0, 1)) ?></span><?= e($user['username']) ?></a><?php else: ?><a href="/login">登录</a><a class="aurora-join" href="/register">加入社区</a><?php endif; ?></div>
    </div>
  </header>
  <main class="aurora-main">
    <div class="aurora-breadcrumb"><a href="/">社区</a><span>/</span><strong><?= e($title) ?></strong></div>
    <div class="aurora-grid"><section class="aurora-content"><?= extension_slot('page.content.before', ['path'=>$requestPath, 'title'=>$title, 'user'=>$user, 'is_admin'=>false]) ?><?= $body ?><?= extension_slot('page.content.after', ['path'=>$requestPath, 'title'=>$title, 'user'=>$user, 'is_admin'=>false]) ?></section><div class="aurora-sidebar"><aside class="aurora-aside"><div class="aurora-aside-orb"></div><span class="aurora-kicker">AURORA COMMUNITY</span><h2>让好观点<br>被看见。</h2><p>分享经验，认真讨论，和志同道合的人一起建立有价值的社区。</p><?php if ($user): ?><a class="aurora-action" href="/new">发起新讨论 <span>↗</span></a><?php else: ?><a class="aurora-action" href="/register">加入社区 <span>↗</span></a><?php endif; ?></aside><?php // Front-end plugin sidebar blocks render at the bottom of this column. ?><?= extension_slot('sidebar.sections', ['path'=>$requestPath, 'user'=>$user]) ?></div></div>
  </main>
  <?= extension_slot('footer.before', ['path'=>$requestPath, 'user'=>$user]) ?>
  <footer class="aurora-footer"><span><?= e($c['site'] ?? 'LiteBBS') ?></span><span>保持好奇，认真交流。</span></footer>
  <?= extension_slot('body.end', ['path'=>$requestPath, 'user'=>$user]) ?>
  <?= extension_javascripts($requestPath) ?>
</body>
</html>
