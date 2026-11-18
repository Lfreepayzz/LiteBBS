<?php
declare(strict_types=1);

require_once __DIR__.'/app/config.php';
require_once __DIR__.'/app/framework.php';
$request = \LiteBBS\Framework\Request::fromGlobals();
$requestStartedAt = microtime(true);
$requestTimings = [];
$markRequestTiming = static function (string $name, float $startedAt) use (&$requestTimings): void {
    $requestTimings[$name] = round((microtime(true) - $startedAt) * 1000, 1);
};
$path = $request->path;
require __DIR__.'/app/core.php';
require __DIR__.'/app/seo.php';
require __DIR__.'/app/extensions-security.php';
require __DIR__.'/app/extension-framework.php';
require __DIR__.'/app/theme-runtime.php';
require __DIR__.'/app/outbox.php';
require __DIR__.'/app/application-routes.php';

if (!extension_loaded('mbstring')) { http_response_code(500); exit('LiteBBS requires the mbstring extension.'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
configure_session();
foreach (["Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'; script-src 'self'", 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: strict-origin-when-cross-origin', 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'Cross-Origin-Opener-Policy: same-origin'] as $header) header($header);
if (request_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
$method = $request->method;
$legacyConfig = __DIR__.'/config.php';
if (!is_file(config_file()) && is_file($legacyConfig)) {
    try { load_config(); } catch (Throwable $error) { fail(503, '旧配置迁移失败，请先保护并移除公开目录中的 config.php。'); }
}
if (!is_file(install_lock_file()) && !in_array($path, ['/install', '/install.php', '/assets/css/install.css'], true)) redirect('/install.php');

$rawRequestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (in_array($method, ['GET','HEAD'], true) && preg_match('~^/(?:t/\d+(?:-[^/]+)?|c/\d+|u/[^/]+)/+$~', $rawRequestPath)) {
    $query = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    redirect(rtrim($rawRequestPath, '/').(is_string($query) && $query !== '' ? '?'.$query : ''), 301);
}

define('LITEBBS_FRAMEWORK_DISPATCH',true);
$kernel = new \LiteBBS\Framework\Kernel();
$kernel->router->add('GET', '~^/plugin-assets/(?P<plugin>[a-z][a-z0-9_-]{1,31})/(?P<asset>.+)$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): \LiteBBS\Framework\Response {
    return extension_plugin_asset_response((string)$params['plugin'], rawurldecode((string)$params['asset']));
});
$kernel->router->add('POST', '~^/t/(?P<topicId>[0-9]+)/reply$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): ?\LiteBBS\Framework\Response {
    require_once __DIR__.'/app/reply-handler.php';
    try { handle_topic_reply((int)$params['topicId'],$request->post,$request->files); }
    catch (\LiteBBS\Framework\ResponseHalt $halt) { return $halt->response; }
});
$kernel->router->add('GET', '~^/robots\.txt$~', static function (): \LiteBBS\Framework\Response {
    $config=load_config();
    if (!seo_indexing_enabled()) return (new \LiteBBS\Framework\Response("User-agent: *\nDisallow: /\n"))->header('Content-Type','text/plain; charset=utf-8')->header('Cache-Control','public, max-age=3600');
    return (new \LiteBBS\Framework\Response("User-agent: *\nDisallow: /admin\nDisallow: /me\nSitemap: ".rtrim((string)$config['url'],'/')."/sitemap.xml\n"))->header('Content-Type','text/plain; charset=utf-8')->header('Cache-Control','public, max-age=3600');
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
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8')->header('Cache-Control','public, max-age=3600');
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
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8')->header('Cache-Control','public, max-age=3600');
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
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8')->header('Cache-Control','public, max-age=3600');
});
$kernel->router->add('GET', '~^/(?:forum-(?:[2-9]|[1-9][0-9]+))?$~', static function (\LiteBBS\Framework\Request $request): \LiteBBS\Framework\Response {
    $numbered=$request->path!=='/';
    $pageNumber=$numbered?(int)substr($request->path,7):1;
    if($numbered&&((string)$pageNumber!==substr($request->path,7)||$pageNumber<2))fail(404,'页面不存在');
    $cursor=(string)($request->query['cursor']??'');
    if($numbered&&$request->query)fail(400,'分页参数无效');
    if(strlen($cursor)>256)fail(400,'分页游标无效');
    if (array_key_exists('page', $request->query)) fail(400,'主题列表仅支持游标翻页。');
    $config=load_config();
    $theme=(string)($config['theme']??'default');
    $versions=cache_versions(['topics','categories','home-feed','extension-read-pages','plugins','theme-settings:'.$theme]);
    $anonymous=empty($_COOKIE[session_name()]);
    $pageSize=theme_page_size($theme,'home');
    $homePath=theme_home_page_path($theme);
    if($numbered&&$homePath!=='forum')fail(404,'页面不存在');
    if(!$numbered&&$homePath==='forum'&&$cursor!=='')fail(404,'页面不存在');
    $shellVersion=hash('sha256',implode("\0",[
        (string)($config['site']??''),
        (string)($config['url']??''),
        (string)($config['theme']??'default'),
        (string)($config['locale']??'zh-CN'),
        json_encode($config['seo']??[], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '',
    ]));
    $pageCacheKey='home-page:v7:'.$pageSize.':'.hash('sha256',$numbered?$request->path:$cursor).':'.$shellVersion.':'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['home-feed'].':'.(int)$versions['extension-read-pages'].':'.(int)$versions['plugins'].':'.(int)$versions['theme-settings:'.$theme];
    $cursorData=null;
    if($cursor!==''){$decoded=base64_decode(strtr($cursor,'-_','+/'),true);$candidate=is_string($decoded)?json_decode($decoded,true):null;if(!is_array($candidate)||!isset($candidate['p'],$candidate['d'],$candidate['i'])||!in_array((int)$candidate['p'],[0,1],true)||!is_string($candidate['d'])||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',$candidate['d'])||(int)$candidate['i']<1)fail(400,'分页游标无效');$cursorData=['p'=>(int)$candidate['p'],'d'=>$candidate['d'],'i'=>(int)$candidate['i']];}
    if($anonymous&&($cachedPage=page_cache_read($pageCacheKey,120))!==null){
        public_read_response_cache(null);
        return new \LiteBBS\Framework\Response((string)($cachedPage[0]??''));
    }
    $topicCount=cached_topic_count('all');
    if($numbered&&($topicCount===0||$pageNumber>intdiv($topicCount-1,$pageSize)+1))fail(404,'页面不存在');
    if($numbered){
        $anchorKey='home:page-anchor:v2:'.$pageSize.':'.$pageNumber.':'.(int)$versions['home-feed'].':'.(int)$versions['categories'];
        $previousKey='home:page-anchor:v2:'.$pageSize.':'.($pageNumber-1).':'.(int)$versions['home-feed'].':'.(int)$versions['categories'];
        $previous=$pageNumber>2?read_cache($previousKey,120):null;
        $anchor=cached($anchorKey,120,static function()use($pageSize,$pageNumber,$previous):array{
            $last=null;
            if(is_array($previous)&&isset($previous['p'],$previous['d'],$previous['i'])){
                $last=h('SELECT t.is_pinned p,t.last_post_at d,t.id i FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1 AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?)))) ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 1 OFFSET '.($pageSize-1),[$previous['p'],$previous['p'],$previous['d'],$previous['d'],$previous['i']])->fetch();
            }else{
                $offset=($pageNumber-1)*$pageSize-1;
                $last=h('SELECT t.is_pinned p,t.last_post_at d,t.id i FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1 ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 1 OFFSET '.$offset)->fetch();
            }
            return $last?['p'=>(int)$last['p'],'d'=>(string)$last['d'],'i'=>(int)$last['i']]:[];
        });
        if(!$anchor)fail(404,'页面不存在');
        $cursorData=$anchor;
    }
    $key='home:v7:'.$pageSize.':'.hash('sha256',$numbered?$request->path:$cursor).':'.(int)$versions['home-feed'].':'.(int)$versions['categories'].':'.(int)$versions['extension-read-pages'];
    $loadRows=static function()use($cursorData,$pageSize):array{$where='t.status="normal" AND c.is_visible=1';$args=[];if($cursorData){$where.=' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';array_push($args,$cursorData['p'],$cursorData['p'],$cursorData['d'],$cursorData['d'],$cursorData['i']);}$sql='SELECT t.id,t.title,t.reply_count,t.created_at,t.last_post_at,t.is_pinned,c.id category_id,c.title category,u.username,ru.username reply_username,rp.created_at reply_created_at FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id '.topic_last_post_join_sql().' LEFT JOIN users ru ON ru.id=rp.user_id WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT '.($pageSize+1);return h($sql,$args)->fetchAll();};
    // Numbered paths are stable within their feed version, unlike legacy cursor URLs.
    // Cache their bounded keyset result for both anonymous and signed-in requests.
    $rows=($numbered||$cursorData===null)?cached($key,120,$loadRows):$loadRows();
    if($numbered&&!$rows)fail(404,'页面不存在');
    $hasMore=count($rows)>$pageSize;if($hasMore)array_pop($rows);$nextCursor='';if($hasMore&&$rows){$last=end($rows);$payload=json_encode(['p'=>(int)$last['is_pinned'],'d'=>(string)$last['last_post_at'],'i'=>(int)$last['id']]);$nextCursor=rtrim(strtr(base64_encode((string)$payload),'+/','-_'),'=');}
    if($numbered&&$hasMore&&$rows){
        $nextAnchorKey='home:page-anchor:v2:'.$pageSize.':'.($pageNumber+1).':'.(int)$versions['home-feed'].':'.(int)$versions['categories'];
        cached($nextAnchorKey,120,static fn():array=>['p'=>(int)$last['is_pinned'],'d'=>(string)$last['last_post_at'],'i'=>(int)$last['id']]);
    }
    $viewer=$anonymous?null:current_user();
    $homeView=['topics'=>$rows,'viewer'=>$viewer,'topic_count'=>$topicCount,'next_cursor'=>$nextCursor];
    $body='<header class="home-heading"><div><h1>讨论</h1><span>'.number_format($topicCount).' 个主题</span></div>'.($viewer?'<a class="feed-create" href="/new">发布主题</a>':'<a class="feed-create" href="/login">登录</a>').'</header>'.extension_slot('home.feed.before',$homeView).'<section class="topic-feed" aria-label="主题列表">';
    if(!$rows)$body.='<div class="topic-empty"><h2>暂无主题</h2><a href="'.($viewer?'/new':'/login').'">'.($viewer?'发布主题':'登录后发布').' →</a></div>';
    foreach($rows as $topic){$created=(string)$topic['created_at'];$replied=(string)$topic['reply_created_at'];$body.='<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'">'.'<span class="topic-avatar" aria-hidden="true">'.e(text_slice($topic['username'],0,1)).'</span><div class="topic-feed-main"><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><div class="topic-feed-meta"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a>'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'<a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time class="topic-created" datetime="'.e(str_replace(' ','T',substr($created,0,19))).'">'.e(substr($created,0,16)).'</time>'.((int)$topic['reply_count']>0?'<a class="topic-reply-author" href="/u/'.rawurlencode((string)$topic['reply_username']).'">'.e((string)$topic['reply_username']).'</a><span class="topic-activity">回复</span><time class="topic-replied" datetime="'.e(str_replace(' ','T',substr($replied,0,19))).'">'.e(substr($replied,0,16)).'</time>':'').'</div></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';}
    ob_start();
    $nextUrl=$nextCursor!==''?($homePath==='forum'&&$cursor===''?'/forum-'.($pageNumber+1):'/?cursor='.rawurlencode($nextCursor)):'';
    $body.='</section>'.extension_slot('home.feed.after',$homeView).theme_render_standard('pagination',['pagination'=>['context'=>'home','page_size'=>$pageSize,'first_url'=>'/','page_number'=>$pageNumber,'page_count'=>max(1,intdiv(max(0,$topicCount-1),$pageSize)+1),'current_cursor'=>$numbered?(string)$pageNumber:$cursor,'next_cursor'=>$nextCursor,'has_more'=>$nextCursor!=='','next_url'=>$nextUrl]],__DIR__.'/app/pagination.php');
    public_read_response_cache($viewer);
    page($numbered?'讨论 · 第'.$pageNumber.'页':'首页',$body,$numbered?'社区讨论第'.$pageNumber.'页':'最新社区讨论',true);
    $html=(string)ob_get_clean();
    if($anonymous)page_cache_write($pageCacheKey,[$html],120);
    return new \LiteBBS\Framework\Response($html);
});
register_application_routes($kernel->router);
// Load only the extension that owns the requested public route. Plugin
// management is dispatched inside the shared /admin/plugins controller.
if (($publicPlugin = extension_public_route_plugin($path)) !== null) extension_runtime_register_routes($kernel->router, $publicPlugin);
$dispatchStartedAt = microtime(true);
$frameworkResponse = $kernel->handle($request);
$markRequestTiming('dispatch', $dispatchStartedAt);
if ($frameworkResponse instanceof \LiteBBS\Framework\Response) {
    $requestTimings['total'] = round((microtime(true) - $requestStartedAt) * 1000, 1);
    $requestState =& request_cache_state();
    if (isset($requestState['@db-connect-ms'])) $requestTimings['db'] = round((float)$requestState['@db-connect-ms'], 1);
    if (isset($requestState['@sql-ms'])) $requestTimings['sql'] = round((float)$requestState['@sql-ms'], 1);
    if (isset($requestState['@sql-count'])) $requestTimings['queries'] = (float)$requestState['@sql-count'];
    if (!headers_sent()) header('Server-Timing: '.implode(', ', array_map(static fn(string $name, float $duration): string => $name.';dur='.$duration, array_keys($requestTimings), $requestTimings)));
    $frameworkResponse->send(); exit;
}
ob_start();
page('请求失败', '<section class="message"><h1>页面不存在</h1><a href="/">返回首页</a></section>');
$notFound = new \LiteBBS\Framework\Response((string)ob_get_clean(), 404);
$notFound->send();
exit;
