<?php
declare(strict_types=1);

$requestStartedAt = microtime(true);
$requestTimings = [];
require __DIR__.'/app/kernel.php';

if (!extension_loaded('mbstring')) { http_response_code(500); exit('LiteBBS requires the mbstring extension.'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
configure_session();
$request = \LiteBBS\Framework\Request::fromGlobals();
$path = $request->path;
header('Cache-Control: private, no-store');
foreach (["Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'; script-src 'self'; connect-src 'self' https:", 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: strict-origin-when-cross-origin', 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'Cross-Origin-Opener-Policy: same-origin'] as $header) header($header);
if (request_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
$method = $request->method;
if (!is_file(install_lock_file()) && !in_array($path, ['/install', '/install.php', '/assets/css/install.css'], true)) redirect('/install.php');

$rawRequestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (in_array($method, ['GET','HEAD'], true) && preg_match('~^/(?:t/\d+(?:-[^/]+)?|c/\d+|u/[^/]+)/+$~', $rawRequestPath)) {
    $query = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    redirect(rtrim($rawRequestPath, '/').(is_string($query) && $query !== '' ? '?'.$query : ''), 301);
}

define('LITEBBS_FRAMEWORK_DISPATCH',true);
$kernel = new \LiteBBS\Framework\Kernel();
$kernel->middleware(new \LiteBBS\Application\RequestObservabilityMiddleware());
$kernel->middleware(new \LiteBBS\Application\PluginRequestMiddleware());
$kernel->middleware(new \LiteBBS\Application\AdminAuthorizationMiddleware());
$kernel->router->add('GET', '~^/theme-assets/(?P<theme>[a-z][a-z0-9_-]{1,31})/(?P<asset>[A-Za-z0-9][A-Za-z0-9._-]*\.(?:css|js))$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
    return theme_asset_response((string)$params['theme'], (string)$params['asset']);
});
$kernel->router->add('GET', '~^/plugin-assets/(?P<plugin>[a-z][a-z0-9_-]{1,31})/(?P<asset>.+)$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
    return extension_plugin_asset_response((string)$params['plugin'], rawurldecode((string)$params['asset']));
});
$kernel->router->add('GET', '~^/plugin-assets/_common/assets\.(?P<type>css|js)$~D', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
    return ExtensionAssetBundle::forType($params['type'])->response();
});
$kernel->router->add('GET', '~^/topic-views$~', [new \LiteBBS\Application\TopicViewController(), 'list']);
$kernel->router->add('POST', '~^/topic-views/(?P<topicId>[1-9][0-9]*)$~', new \LiteBBS\Application\TopicViewController());
$kernel->router->add('POST', '~^/t/(?P<topicId>[0-9]+)/reply$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): ?\LiteBBS\Framework\Response {
    try { handle_topic_reply($request, (int)$params['topicId']); }
    catch (\LiteBBS\Framework\ResponseHalt $halt) { return $halt->response; }
});
$kernel->router->add('GET', '~^/robots\.txt$~', static function (): \LiteBBS\Framework\Response {
    $body = "User-agent: *\nDisallow: /\n";
    if (seo_indexing_enabled()) {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /me\n";
        $base = rtrim((string)(load_config()['url'] ?? ''), '/');
        if ($base !== '') $body .= 'Sitemap: '.$base."/sitemap.xml\n";
    }
    return (new \LiteBBS\Framework\Response($body))
        ->header('Content-Type', 'text/plain; charset=utf-8');
});
$kernel->router->add('GET', '~^/sitemap\.xml$~', static function (): \LiteBBS\Framework\Response {
    if (!seo_indexing_enabled()) return (new \LiteBBS\Framework\Response('Not Found',404))->header('X-Robots-Tag','noindex');
    $base=rtrim((string)load_config()['url'],'/');
    $versions=cache_versions(['topics','categories','sitemap']);
    $key='sitemap:index:v2:'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['sitemap'].':'.hash('sha256',$base);
    $document=public_cached($key, 3600, static function () use ($base): array {
        $chunkSize=45000;
        $chunks=max(1,(int)ceil(cached_sitemap_max_topic_id()/$chunkSize));
        $xml='<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>'.e($base.'/sitemap-static.xml').'</loc></sitemap>';
        for($page=1;$page<=$chunks;$page++)$xml.='<sitemap><loc>'.e($base.'/sitemap-topics-'.$page.'.xml').'</loc></sitemap>';
        return [$xml.'</sitemapindex>'];
    });
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8');
});
$kernel->router->add('GET', '~^/sitemap-static\.xml$~', static function (): \LiteBBS\Framework\Response {
    if (!seo_indexing_enabled()) return (new \LiteBBS\Framework\Response('Not Found',404))->header('X-Robots-Tag','noindex');
    $base=rtrim((string)load_config()['url'],'/');
    $versions=cache_versions(['topics','categories','sitemap']);
    $key='sitemap:static:v2:'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['sitemap'].':'.hash('sha256',$base);
    $document=public_cached($key, 3600, static function () use ($base): array {
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.e($base.'/').'</loc></url>';
        foreach(h('SELECT id FROM categories WHERE is_visible=1 ORDER BY id')->fetchAll() as $category)$xml.='<url><loc>'.e($base.'/c/'.(int)$category['id']).'</loc></url>';
        return [$xml.'</urlset>'];
    });
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8');
});
$kernel->router->add('GET', '~^/sitemap-topics-(?P<page>[1-9][0-9]*)\.xml$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
    if (!seo_indexing_enabled()) return (new \LiteBBS\Framework\Response('Not Found',404))->header('X-Robots-Tag','noindex');
    $base=rtrim((string)load_config()['url'],'/');
    $page=(int)$params['page'];
    $chunkSize=45000;
    $maxTopicId=cached_sitemap_max_topic_id();
    if ($page > max(1,(int)ceil($maxTopicId/$chunkSize))) return new \LiteBBS\Framework\Response('Not Found',404);
    $versions=cache_versions(['topics','categories','sitemap']);
    $key='sitemap:topics:v2:'.$page.':'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['sitemap'].':'.hash('sha256',$base);
    $document=public_cached($key, 3600, static function () use ($base,$page,$chunkSize): array {
        $lowerId=($page-1)*$chunkSize;$upperId=$page*$chunkSize;
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $rows=h('SELECT t.id,t.last_post_at FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id>? AND t.id<=? AND t.status="normal" AND c.is_visible=1 ORDER BY t.id ASC',[$lowerId,$upperId]);
        while($topic=$rows->fetch())$xml.='<url><loc>'.e($base.'/t/'.(int)$topic['id']).'</loc><lastmod>'.e(substr((string)$topic['last_post_at'],0,10)).'</lastmod></url>';
        return [$xml.'</urlset>'];
    });
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8');
});
$kernel->router->add('GET', '~^/(?:forum-(?:[2-9]|[1-9][0-9]+))?$~', static function (\LiteBBS\Framework\Request $request): \LiteBBS\Framework\Response {
    $legacyNumbered = $request->path !== '/';
    $numberedPage = $legacyNumbered ? (int)substr($request->path, 7) : 1;
    $config = load_config();
    $theme = (string)($config['theme'] ?? 'default');
    $pageSize = theme_page_size($theme, 'home');
    if ($request->query) fail(400, '分页参数无效');
    if ($legacyNumbered) \LiteBBS\Application\NumericPageCursor::assertAllowed($numberedPage);
    $cursor = $legacyNumbered ? \LiteBBS\Application\NumericPageCursor::feed($numberedPage, $pageSize) : '';
    if ($legacyNumbered && $cursor === '') fail(404, '页面不存在');
    try {
        $cursorData = $cursor === '' ? null : \LiteBBS\Application\FeedCursor::decode($cursor);
    } catch (InvalidArgumentException) {
        fail(400, '分页游标无效');
    }
    $versions=cache_versions(['topics','categories','home-feed','extension-read-pages','plugins','theme-settings:'.$theme]);
    $anonymous=empty($request->cookies[session_name()]);
    $cachePage = $anonymous && theme_effective_package($theme) !== null && !extension_dynamic_read_page('home');
    $shellVersion=hash('sha256',implode("\0",[
        (string)($config['site']??''),
        (string)($config['url']??''),
        (string)($config['theme']??'default'),
        (string)($config['locale']??'zh-CN'),
        json_encode($config['seo']??[], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '',
        theme_asset_signature($theme),
    ]));
    $pageCacheKey='home-page:v9:'.$pageSize.':'.hash('sha256',$cursor).':'.$shellVersion.':'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['home-feed'].':'.(int)$versions['extension-read-pages'].':'.(int)$versions['plugins'].':'.(int)$versions['theme-settings:'.$theme];
    if($cachePage&&($cachedPage=page_cache_read($pageCacheKey,120))!==null){
        return new \LiteBBS\Framework\Response((string)($cachedPage[0]??''));
    }
    $key='home:v7:'.$pageSize.':'.hash('sha256',$cursor).':'.(int)$versions['home-feed'].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'];
    $viewer = $anonymous ? null : current_user();
    $readContext = new ReadSelection('home', $pageSize + 1, null, $cursorData, isset($viewer['id']) ? (int)$viewer['id'] : null);
    $selection = $numberedPage > 1 ? null : extension_select_ids($readContext);
    $loadRows = static fn(): array => read_feed_rows($cursorData, $pageSize, null, $selection, $viewer);
    $rows = $cursorData === null && $selection === null && extension_candidate_plugins('policies', 'category.view') === [] ? cached($key, 120, $loadRows) : $loadRows();
    $hasMore = count($rows) > $pageSize;
    $nextCursor = '';
    if ($hasMore) {
        array_pop($rows);
        $nextCursor = \LiteBBS\Application\FeedCursor::fromTopic(end($rows), ($cursorData['n'] ?? 1) + 1);
    }
    $rows = extension_transform_rows($readContext, $rows);
    $nextUrl = $nextCursor === '' ? '' : '/forum-'.($numberedPage + 1);
    $homeView = ['topics'=>$rows,'viewer'=>$viewer,'next_page'=>$hasMore ? $numberedPage + 1 : null];
    extension_preload('home.feed.items', $homeView);
    // Keep the exact total available to plugins that consume the home feed slots.
    extension_runtime_load(null, 'slot', 'home.feed.before');
    extension_runtime_load(null, 'slot', 'home.feed.after');
    $slots = extension_registry()['slots'];
    $topicCount = $selection === null || !empty($slots['home.feed.before']) || !empty($slots['home.feed.after'])
        ? cached_topic_count('all') : null;
    if ($topicCount !== null) $homeView['topic_count'] = $topicCount;
    $body='<header class="home-heading"><div><h1>讨论</h1></div>'.($viewer?'<a class="feed-create" href="/new">发布主题</a>':'<a class="feed-create" href="/login">登录</a>').'</header>'.extension_slot('home.feed.before',$homeView).'<section class="topic-feed" aria-label="主题列表">';
    if(!$rows)$body.='<div class="topic-empty"><h2>暂无主题</h2><a href="'.($viewer?'/new':'/login').'">'.($viewer?'发布主题':'登录后发布').' →</a></div>';
    foreach($rows as $topic){$created=(string)$topic['created_at'];$replied=(string)$topic['reply_created_at'];$item=new TopicListItem($topic,$viewer,'home');$body.='<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'" data-topic-id="'.(int)$topic['id'].'">'.'<span class="topic-avatar" aria-hidden="true">'.e(text_slice($topic['username'],0,1)).'</span><div class="topic-feed-main"><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a>'.extension_topic_title_suffix($item).'</h2><div class="topic-feed-meta"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a>'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'<a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><span class="topic-views" aria-label="'.(int)$topic['view_count'].' 次浏览"><i aria-hidden="true"></i>'.(int)$topic['view_count'].'</span><time class="topic-created" datetime="'.e(str_replace(' ','T',substr($created,0,19))).'">'.e(substr($created,0,16)).'</time>'.((int)$topic['reply_count']>0?'<a class="topic-reply-author" href="/u/'.rawurlencode((string)$topic['reply_username']).'">'.e((string)$topic['reply_username']).'</a><span class="topic-activity">回复</span><time class="topic-replied" datetime="'.e(str_replace(' ','T',substr($replied,0,19))).'">'.e(substr($replied,0,16)).'</time>':'').extension_topic_list_columns($item).'</div></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';}
    $pagination = ['context'=>'home','page_size'=>$pageSize,'first_url'=>'/','current_page'=>$numberedPage,'has_more'=>$hasMore,'next_url'=>$nextUrl];
    $pagination += \LiteBBS\Application\Pagination::forView($numberedPage, $selection === null ? $topicCount : null, $pageSize, '/', '/forum');
    $paginationHtml = theme_render_standard('pagination',['pagination'=>$pagination],static fn(array $view): string => template_pagination_fallback($view['pagination']));
    $body.='</section>'.extension_slot('home.feed.after',$homeView).$paginationHtml;
    $coreBody = $body;
    $body = theme_render_page('home', ['topics'=>$rows, 'viewer'=>$viewer, 'pagination'=>ThemeTrustedHtml::fromCore($paginationHtml)], static fn(array $view): string => $coreBody);
    ob_start();
    page($numberedPage === 1 ? '首页' : '更多讨论', $body, '最新社区讨论', true);
    $html=(string)ob_get_clean();
    if($cachePage)page_cache_write($pageCacheKey,[$html],120);
    return new \LiteBBS\Framework\Response($html);
});
register_application_routes($kernel->router);
// Load only the extension that owns the requested public route. Plugin
// management is dispatched inside the shared /admin/plugins controller.
if (($publicPlugin = extension_public_route_plugin($path)) !== null) extension_runtime_register_routes($kernel->router, $publicPlugin);
$dispatchStartedAt = microtime(true);
$frameworkResponse = $kernel->handle($request);
$requestTimings['dispatch'] = round((microtime(true) - $dispatchStartedAt) * 1000, 1);
if ($frameworkResponse instanceof \LiteBBS\Framework\Response) {
    $requestTimings['total'] = round((microtime(true) - $requestStartedAt) * 1000, 1);
    $requestState =& request_cache_state();
    if (isset($requestState['@db-connect-ms'])) $requestTimings['db'] = round((float)$requestState['@db-connect-ms'], 1);
    if (isset($requestState['@sql-ms'])) $requestTimings['sql'] = round((float)$requestState['@sql-ms'], 1);
    if (isset($requestState['@sql-count'])) $requestTimings['queries'] = (float)$requestState['@sql-count'];
    if (isset($requestState['@cache-wait-ms'])) $requestTimings['cache_wait'] = round((float)$requestState['@cache-wait-ms'], 1);
    if (!headers_sent() && getenv('LITEBBS_DEBUG_TIMING') === '1') header('Server-Timing: '.implode(', ', array_map(static fn(string $name, float $duration): string => $name.';dur='.$duration, array_keys($requestTimings), $requestTimings)));
    $frameworkResponse->send(); exit;
}
litebbs_not_found_response()->send();
exit;
