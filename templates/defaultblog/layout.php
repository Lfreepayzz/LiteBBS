<?php
declare(strict_types=1);

$site = $c['site'] ?? 'LiteBBS';
$isCategoryPage = preg_match('~^/c/[0-9]+$~D', $requestPath) === 1;
$isFeedPage = ($isHomePage || $isCategoryPage) && $indexable;
$slotContext = ['path'=>$requestPath, 'user'=>$user];
$pageContext = $slotContext + ['title'=>$title, 'is_admin'=>false];
$assets = '/templates/'.rawurlencode(basename(__DIR__)).'/assets/';

$siteDays = null;
$shortcuts = [
    ['#recent-posts', '全部', '◎'],
    ['/search', '发现', '✦'],
    [$user ? '/new' : '/register', $user ? '发帖' : '加入', '➤'],
];
if ($isFeedPage) {
    if ($isHomePage) {
        $startDate = trim($settings['site_start_date'] ?? '');
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $startDate, $dateParts)
            && checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
            $start = new DateTimeImmutable($startDate);
            $today = new DateTimeImmutable('today');
            if ($start <= $today) $siteDays = $start->diff($today)->days;
        }
        preg_match_all('~<a class="nav-item(?: active)?" href="(/c/[0-9]+)"><span>([^<]*)</span></a>~', $left, $categories, PREG_SET_ORDER);
        foreach (array_slice($categories, 0, 3) as $index => $category) {
            $shortcuts[$index][0] = $category[1];
            $shortcuts[$index][1] = html_entity_decode($category[2], ENT_QUOTES, 'UTF-8');
        }
        $body = str_replace('<section class="topic-feed" aria-label="主题列表">', '<section class="topic-feed topic-grid" aria-label="主题列表">', $body);
    }

    // Decorate the fixed core card markup while retaining its links and metadata.
    $coverIndex = 0;
    $body = preg_replace_callback(
        '~<article class="topic topic-feed-item( is-pinned)?">'
        .'(<span class="topic-avatar"[^>]*>[^<]*</span>)'
        .'<div class="topic-feed-main"><h2>(<a href="(/t/[0-9]+)">[^<]*</a>)</h2>'
        .'<div class="topic-feed-meta">((?:(?!</?div\b|</article>).)*)</div></div>'
        .'(<a class="topic-replies"[^>]*>(?:(?!</a>|</article>).)*</a>)</article>~s',
        static function (array $card) use ($assets, &$coverIndex): string {
            [, $pinned, $avatar, $titleLink, $topicUrl, $meta, $replies] = $card;
            $loading = $coverIndex++ < 2 ? 'eager' : 'lazy';
            return '<article class="topic topic-feed-item recent-post-item'.$pinned.'">'
                .'<a class="post_cover" href="'.e($topicUrl).'" tabindex="-1" aria-hidden="true">'
                .'<img class="post-bg" src="'.e($assets.'default_cover.jpg').'" alt="" loading="'.$loading.'"></a>'
                .$avatar.'<div class="topic-feed-main recent-post-info"><h2 class="article-title">'.$titleLink.'</h2>'
                .'<div class="topic-feed-meta article-meta-wrap">'.$meta.'</div>'.$replies.'</div></article>';
        },
        $body
    ) ?? $body;
}

// Match only the fixed core heading prefix. Leave the body intact if its structure changes.
$hasPostHero = $isTopicPage && preg_match(
    '~\A<header class="topic-page-heading"><div class="topic-heading-topline">'
    .'(<a class="topic-category"[^>]*>[^<]*</a>)(<span class="topic-state(?: is-locked)?">[^<]*</span>)</div>'
    .'<div class="topic-title-row"><h1>([^<]*)</h1>((?:<a class="post-edit"[^>]*>[^<]*</a>)?)</div>'
    .'<div class="topic-page-meta">(.*?)</div>~s',
    $body,
    $postHeading
) === 1;
$postToc = [];
if ($hasPostHero) {
    [, $postCategory, $postState, $postTitle, $postEdit, $postMeta] = $postHeading;
    $postDates = '';
    $postStats = preg_replace_callback('~<time\b[^>]*>[^<]*</time>~', static function (array $date) use (&$postDates): string {
        $postDates .= $date[0];
        return '';
    }, $postMeta);
    $postRemainder = substr($body, strlen($postHeading[0]));
    $postActions = '';
    if (preg_match('~<div class="topic-actions">(?=<form method="post" action="/topic/[0-9]+/bookmark")((?:(?!</div>|</header>).)*)</div>(?=</header>)~s', $postRemainder, $actions, PREG_OFFSET_CAPTURE)) {
        $postActions = $actions[1][0];
        $postRemainder = substr_replace($postRemainder, '', $actions[0][1], strlen($actions[0][0]));
    }
    $body = str_starts_with($postRemainder, '</header>')
        ? substr($postRemainder, strlen('</header>'))
        : '<header class="topic-page-heading">'.$postRemainder;

    if (preg_match('~<article class="post post-original" id="(p[0-9]+)"><div class="body">(?<content>(?&div_content))</div>(?(DEFINE)(?<div_content>(?:[^<]++|<(?!/?div\b|/article\b)|<div\b[^>]*>(?&div_content)</div>)*))~i', $body, $firstPost, PREG_OFFSET_CAPTURE)) {
        [$content, $contentOffset] = $firstPost['content'];
        $content = preg_replace_callback('~<h([2-6])\b((?:"[^"]*"|\'[^\']*\'|[^\'"<>])*)>(.*?)</h\1>~is', static function (array $heading) use (&$postToc, $firstPost, $body): string {
            [, $level, $attributes, $headingText] = $heading;
            $label = trim(html_entity_decode(strip_tags($headingText), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '') return $heading[0];

            if (preg_match('~\sid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $attributes, $existingId, PREG_UNMATCHED_AS_NULL)) {
                $id = html_entity_decode($existingId[1] ?? $existingId[2] ?? $existingId[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($id === '') return $heading[0];
            } else {
                $id = 'default-'.$firstPost[1][0].'-heading-'.(count($postToc) + 1);
                while (str_contains($body, $id)) $id .= '-';
                $attributes .= ' id="'.e($id).'"';
            }
            $postToc[] = [$level, $id, $label];
            return '<h'.$level.$attributes.'>'.$headingText.'</h'.$level.'>';
        }, $content) ?? $content;
        $body = substr_replace($body, $content, $contentOffset, strlen($firstPost['content'][0]));
    }

    // Keep the original post and its attachments together, including nested article elements.
    if (preg_match('~<a href="(/u/[^"<>]+)">([^<]+)</a>~', $postStats, $author)
        && preg_match('~<article class="post post-original" id="p[0-9]+">(?&contents)</article>(?(DEFINE)(?<contents>(?:[^<]++|<(?!/?article\b)|<article\b[^>]*>(?&contents)</article>)*))~i', $body, $original, PREG_OFFSET_CAPTURE)) {
        $authorName = html_entity_decode($author[2], ENT_QUOTES, 'UTF-8');
        $replyTarget = $user ? '#reply-composer' : '/login';
        if (str_contains($postState, 'is-locked')) {
            $replyTarget = str_contains($body, '<article class="post post-reply"') ? '#replies' : '';
        }
        ob_start();
        ?>
        <div class="default-post-tail">
          <section class="post-copyright" aria-label="帖子署名">
            <div class="post-copyright__author_box">
              <a class="post-copyright__author_img" href="<?= $author[1] ?>" aria-label="<?= e($authorName) ?>"><span aria-hidden="true"><?= e(text_slice($authorName, 0, 1)) ?></span></a>
              <a class="post-copyright__author_name" href="<?= $author[1] ?>"><?= e($authorName) ?></a>
              <span class="post-copyright__author_desc"><?= e($site) ?></span>
            </div>
            <div class="post-copyright__post__info"><a class="post-copyright-title" href="<?= e($requestPath) ?>"><?= $postTitle ?></a></div>
            <div class="post-tail-tools">
              <?php if ($replyTarget !== ''): ?><a class="post-tail-reply" href="<?= e($replyTarget) ?>">参与讨论</a><?php endif; ?>
              <button type="button" data-share-topic>分享</button><button type="button" data-copy-topic>复制链接</button>
            </div>
            <p class="post-copyright__notice" role="status">分享此帖时请附上原帖链接。</p>
          </section>
        </div>
        <?php
        $body = substr_replace($body, (string)ob_get_clean(), $original[0][1] + strlen($original[0][0]), 0);
    }
}
?>
<!doctype html>
<html lang="<?= e($c['locale'] ?? 'zh-CN') ?>" class="default-stable-layout">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" href="<?= e($assets.'512.png') ?>" type="image/png">
  <?php if ($isHomePage && $isFeedPage): ?>
    <link rel="preload" as="image" href="<?= e($assets.'banner.webp') ?>" fetchpriority="high">
  <?php endif; ?>
  <?php if ($isFeedPage && $coverIndex > 0): ?>
    <link rel="preload" as="image" href="<?= e($assets.'default_cover.jpg') ?>">
  <?php endif; ?>
  <?= seo_head($title, $description, $indexable, $canonical, $c) ?>
  <?= $theme_assets ?>
  <?= $plugin_stylesheets ?>
  <?= $theme_slots['head'] ?? '' ?>
</head>
<body id="top" class="default">
  <a class="skip-link" href="#content-inner">跳到主要内容</a>
  <header id="page-header" class="<?= $hasPostHero ? 'post-bg' : 'nav-fixed' ?>">
    <nav id="nav" aria-label="主导航">
      <div id="blog-info"><a id="site-name" href="/"><?= e($site) ?></a></div>
      <div id="menus">
        <?php if ($hasPostHero): ?><a id="page-name" href="#top" hidden><?= $postTitle ?></a><?php endif; ?>
        <div class="menus_items">
        <a href="/"<?= $isHomePage ? ' aria-current="page"' : '' ?>>首页</a>
        <div class="menus_item"><a href="#forum-categories">分区</a>
          <div class="menus_item_child"><a href="/">全部讨论</a><?= $left ?></div>
        </div>
        <a href="/market"<?= str_starts_with($requestPath, '/market') ? ' aria-current="page"' : '' ?>>开发者</a>
        <?php $extensionNavigation = $theme_slots['navigation'] ?? ''; ?>
        <?php if ($extensionNavigation !== ''): ?><div id="extension-navigation"><?= $extensionNavigation ?></div><?php endif; ?>
      </div></div>
      <div id="nav-right">
        <a class="nav-button nav-icon" href="/search" aria-label="搜索" title="搜索"><span class="search-icon" aria-hidden="true"></span></a>
        <a class="nav-button nav-icon" href="<?= $user ? '/new' : '/login' ?>" aria-label="发帖" title="发帖"><span class="write-icon" aria-hidden="true">＋</span></a>
        <details id="toggle-menu" class="nav-button">
          <summary aria-label="菜单" title="菜单">☰</summary>
          <div class="nav-popover">
            <a href="/">首页</a><a href="/market">开发者</a><a href="#forum-categories">讨论分区</a>
            <?php if ($user): ?>
              <a href="/me">个人中心</a><a href="/me?tab=notifications">通知</a><a href="/messages">私信</a>
              <?php if (($user['role'] ?? '') === 'admin'): ?><a href="/admin">管理后台</a><?php endif; ?>
              <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit">退出登录</button></form>
            <?php else: ?>
              <a href="/login">登录</a><a href="/register">注册</a>
            <?php endif; ?>
          </div>
        </details>
      </div>
    </nav>
    <?php if ($hasPostHero): ?>
      <div id="post-hero">
        <div id="post-info">
          <div id="post-firstinfo" class="topic-heading-topline"><?= $postState ?><?= $postCategory ?></div>
          <h1 class="post-title"><?= $postTitle ?></h1>
          <?php if ($postEdit !== '' || $postActions !== ''): ?>
            <div class="topic-actions" role="group" aria-label="主题操作"><?= $postEdit ?><?= $postActions ?></div>
          <?php endif; ?>
          <div id="post-meta"><div class="meta-firstline"><?= $postDates ?></div><div class="meta-secondline"><?= $postStats ?></div></div>
        </div>
        <div class="main-hero-waves-area waves-area" aria-hidden="true">
          <svg class="waves-svg" viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
            <defs>
              <path id="gentle-wave" d="M -160 44 c 30 0 58 -18 88 -18 s 58 18 88 18 s 58 -18 88 -18 s 58 18 88 18 v 44 h -352 Z"/>
            </defs>
            <g class="parallax">
              <use href="#gentle-wave" x="48" y="0"/>
              <use href="#gentle-wave" x="48" y="3"/>
              <use href="#gentle-wave" x="48" y="5"/>
              <use href="#gentle-wave" x="48" y="7"/>
            </g>
          </svg>
        </div>
      </div>
    <?php endif; ?>
  </header>
  <?= $theme_slots['header_after'] ?? '' ?>

  <?php if ($isHomePage && $isFeedPage): ?>
    <div id="home_top">
      <?php if (($settings['announcement'] ?? '') !== ''): ?>
        <div id="bbTimeList"><strong>公告</strong><div id="bbtalk"><a href="#forum-announcement"><?= e($settings['announcement']) ?></a></div><a href="#recent-posts" aria-label="查看主题">⟶</a></div>
      <?php endif; ?>
      <div id="home_top_content">
        <div id="home_top_left">
          <div id="random-banner">
            <div class="banners-title"><div class="banners-title-big"><?= e($settings['banner_title'] ?? '生活明朗') ?></div><div class="banners-title-big"><?= e($settings['banner_subtitle'] ?? '万物可爱。') ?></div><p class="banners-title-small"><?= e($site) ?></p></div>
            <div id="skills-tags-group-all" aria-hidden="true">
              <div class="tags-group-track"><div class="tags-group-wrapper">
                <?php $iconPairs = [
                    ['java.jpg', 'docker.png'], ['photoshop.png', 'node.svg'],
                    ['webpack.png', 'pinia.svg'], ['python.png', 'vite.svg'],
                    ['flutter.png', 'vue.png'], ['react.svg', 'css3.png'],
                    ['js.png', 'html.png'], ['git.webp', 'apifox.png'],
                ]; ?>
                <?php for ($copy = 0; $copy < 2; $copy++): ?>
                  <div class="tags-group-sequence">
                    <?php foreach ($iconPairs as $pair): ?>
                      <div class="tags-group-icon-pair">
                        <?php foreach ($pair as $icon): ?>
                          <span class="tags-group-icon skill-<?= e(pathinfo($icon, PATHINFO_FILENAME)) ?>"><img src="<?= e($assets.'skill-'.$icon) ?>" width="60" height="60" alt=""></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endfor; ?>
              </div></div>
            </div>
            <a id="random-hover" href="#recent-posts">
              <svg class="banner-plane" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 2 2 10l8 3 3 8 9-19Zm-4.4 5.2-5.4 9.5-1.5-3.8 6.9-5.7Z"/></svg>
              <span class="bannerText">随便逛逛 <svg class="banner-arrow" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 12h17m-7-7 7 7-7 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </a>
          </div>
          <div class="categoryGroup" aria-label="快捷导航">
            <?php foreach ($shortcuts as [$shortcutUrl, $shortcutText, $shortcutIcon]): ?>
              <a href="<?= e($shortcutUrl) ?>"><?= e($shortcutText) ?> <i aria-hidden="true"><?= e($shortcutIcon) ?></i></a>
            <?php endforeach; ?>
          </div>
        </div>
        <div id="home_top_right">
          <div class="topGroup">
            <a id="todayCard" class="todayCard" href="#recent-posts">
              <div class="todayCard-info"><div class="todayCard-tips">社区推荐</div><div class="todayCard-title">浏览社区讨论</div></div>
              <img class="todayCard-cover" src="<?= e($assets.'banner.webp') ?>" width="800" height="450" fetchpriority="high" alt="">
              <div class="banner-button-group"><span class="banner-button">查看讨论 →</span></div>
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <main id="content-inner" class="layout" tabindex="-1">
    <div class="default-main">
      <?= $theme_slots['content_before'] ?? '' ?>
      <?php if ($isFeedPage): ?><div id="recent-posts" class="recent-posts"><?php endif; ?>
      <?= $body ?>
      <?php if ($isFeedPage): ?></div><?php endif; ?>
      <?= $theme_slots['content_after'] ?? '' ?>
    </div>
    <aside id="aside-content" aria-label="社区侧栏">
      <section class="card-widget card-info">
        <div class="card-content">
          <a id="author-info__sayhi" class="author-info__sayhi" href="<?= $user ? '/me' : '/login' ?>"><?= $user ? '欢迎回来' : '你好，很高兴见到你' ?></a>
          <a class="author-info-avatar" href="/"><img class="avatar-img" src="<?= e($assets.'512.png') ?>" width="118" height="118" alt="<?= e($site) ?>"></a>
          <p class="author-info__description"><?= e($site) ?><br>分享经验，参与社区讨论。</p>
          <div class="author-info__bottom-group">
            <a class="author-info__bottom-group-left" href="/"><strong class="author-info__name"><?= e($site) ?></strong><span class="author-info__desc">社区论坛</span></a>
            <div class="card-info-social-icons">
              <a href="/search" aria-label="搜索" title="搜索">⌕</a>
              <a href="<?= $user ? '/me' : '/register' ?>" aria-label="<?= $user ? '个人中心' : '注册' ?>" title="<?= $user ? '个人中心' : '注册' ?>"><?= $user ? '我' : '＋' ?></a>
            </div>
          </div>
        </div>
      </section>
      <a class="card-widget card-weixin" href="<?= $user ? '/new' : '/register' ?>">
        <span class="card-weixin-copy"><strong><?= $user ? '参与社区讨论' : '加入社区' ?></strong><span><?= e($site) ?></span></span>
        <span class="card-weixin-arrow" aria-hidden="true">→</span>
      </a>
      <?php if (($settings['announcement'] ?? '') !== ''): ?>
        <section class="card-widget card-announcement" id="forum-announcement"><h2 class="item-headline"><span class="announcement-icon" aria-hidden="true">◖</span>公告</h2><div><?= e($settings['announcement']) ?></div></section>
      <?php endif; ?>
      <div class="sticky_layout">
        <?php if ($postToc): ?>
          <section class="card-widget" id="card-toc">
            <h2 class="item-headline">目录</h2>
            <nav aria-label="帖子目录"><ol id="aside-topic-toc" class="toc-content">
              <?php foreach ($postToc as [$level, $headingId, $label]): ?>
                <li data-level="<?= e($level) ?>"><a href="#<?= e(rawurlencode($headingId)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ol></nav>
          </section>
        <?php endif; ?>
        <section class="card-widget card-tags" id="forum-categories">
          <nav class="card-tag-cloud" aria-label="讨论分区"><a href="/">全部讨论</a><?= $left ?></nav>
        </section>
        <?php if ($siteDays !== null): ?>
          <section class="card-widget card-webinfo" id="aside-site-info" aria-labelledby="aside-site-info-title">
            <h2 class="item-headline" id="aside-site-info-title">站点统计</h2>
            <dl class="webinfo">
              <div class="webinfo-item">
                <dt class="webinfo-item-title">建站天数</dt>
                <dd class="item-count"><?= e(number_format($siteDays).' 天') ?></dd>
              </div>
            </dl>
          </section>
        <?php endif; ?>
        <?= $theme_slots['sidebar'] ?? '' ?>
      </div>
    </aside>
  </main>

  <?= $theme_slots['footer_before'] ?? '' ?>
  <?= $theme_slots['footer'] ?? '' ?>
  <footer id="footer">
    <div id="footer-wrap" aria-hidden="true"></div>
    <div id="footer-bar">
      <div class="footer-bar-links">
        <div class="footer-bar-left"><div id="footer-bar-tips"><div class="copyright">&copy;<?= date('Y') ?> By <a class="footer-bar-link" href="/"><?= e($site) ?></a></div></div></div>
        <div class="footer-bar-right"><a class="footer-bar-link" href="https://github.com/default-c/hexo-theme-default">主题</a></div>
      </div>
    </div>
  </footer>
  <div id="rightside"><button type="button" id="theme-mode" aria-label="切换深色模式" title="切换深色模式" hidden>◐</button><a href="#top" aria-label="返回顶部" title="返回顶部">↑</a></div>
  <?= $theme_slots['body_end'] ?? '' ?>
  <?= $plugin_javascripts ?>
</body>
</html>
