<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="<?= e($c['locale'] ?? 'zh-CN') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="<?= e(text_slice(strip_tags($description), 0, 220)) ?>">
  <meta name="robots" content="<?= $indexable ? 'index,follow' : 'noindex,follow' ?>">
  <title><?= e($title) ?> · <?= e($c['site'] ?? 'LiteBBS') ?></title>
  <?php if ($indexable): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
  <link rel="stylesheet" href="/templates/<?= e($safe) ?>/assets/theme.css">
  <?php if ($isHomePage || str_starts_with($requestPath, '/c/')): ?><link rel="stylesheet" href="/templates/<?= e($safe) ?>/assets/home.css"><?php endif; ?>
  <?php if ($isTopicPage): ?><link rel="stylesheet" href="/templates/<?= e($safe) ?>/assets/topic.css"><?php endif; ?>
</head>
<body>
  <a class="skip-link" href="#main-content">跳到主要内容</a>
  <header class="site-header">
    <div class="header-inner">
      <a class="brand" href="/" aria-label="<?= e($c['site'] ?? 'LiteBBS') ?> 首页"><span class="brand-mark" aria-hidden="true"><?= e(text_slice($c['site'] ?? 'L', 0, 1)) ?></span><span><?= e($c['site'] ?? 'LiteBBS') ?></span></a>
        <nav class="primary-nav" aria-label="主导航"><a href="/"<?= $isHomePage ? ' aria-current="page"' : '' ?>>首页</a><a href="/search">发现</a><a href="/market"<?= str_starts_with($requestPath, '/market') ? ' aria-current="page"' : '' ?>>开发者</a></nav>
      <form class="header-search" action="/search" method="get" role="search"><label class="visually-hidden" for="rhex-search">搜索主题</label><input id="rhex-search" name="q" type="search" maxlength="60" placeholder="搜索讨论"><button type="submit" aria-label="搜索">⌕</button></form>
      <nav class="account-nav" aria-label="账户">
        <?php if ($user): ?>
          <a class="user-link" href="/me"><span class="mini-avatar" aria-hidden="true"><?= e(text_slice($user['username'], 0, 1)) ?></span><span><?= e($user['username']) ?></span></a>
          <?php if ($user['role'] === 'admin'): ?><a href="/admin">管理</a><?php endif; ?>
          <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit">退出</button></form>
        <?php else: ?>
          <a href="/login">登录</a><a class="header-register" href="/register">注册</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <div class="forum-shell">
    <main class="main-column" id="main-content" tabindex="-1">
      <div class="content-toolbar"><div class="breadcrumbs"><a href="/">社区</a><span>/</span><span><?= e($title) ?></span></div></div>
      <div class="page-content"><?= $body ?></div>
    </main>

    <aside class="right-sidebar" aria-label="社区侧边栏">
      <section class="welcome-card">
        <span class="welcome-icon" aria-hidden="true"><?= e(text_slice($c['site'] ?? 'L', 0, 1)) ?></span>
        <p class="card-kicker">WELCOME TO COMMUNITY</p>
        <h2><?= e($c['site'] ?? 'LiteBBS') ?></h2>
        <p>分享你的见解、经验和灵感，与社区成员一起认真讨论。</p>
        <?php if ($user): ?><a class="sidebar-primary" href="/new">发起新讨论</a><?php else: ?><a class="sidebar-primary" href="/register">加入社区</a><a class="sidebar-secondary" href="/login">已有账号？登录</a><?php endif; ?>
      </section>
      <section class="sidebar-card">
        <div class="sidebar-heading"><h2>讨论分区</h2><a href="/">全部</a></div>
        <nav class="category-list" aria-label="讨论分区"><?= $left ?></nav>
      </section>
      <section class="sidebar-card rules-card">
        <h2>社区公约</h2>
        <p><span>01</span>友善交流，尊重不同观点</p><p><span>02</span>发布清晰且有价值的内容</p><p><span>03</span>拒绝广告与重复灌水</p>
        <a href="/robots.txt">查看社区规则 <span aria-hidden="true">→</span></a>
      </section>
    </aside>
  </div>
  <footer class="site-footer"><span><?= e($c['site'] ?? 'LiteBBS') ?></span><span>保持好奇，认真交流。</span></footer>
</body>
</html>
