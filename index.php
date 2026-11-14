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
require __DIR__.'/app/extensions-security.php';
require __DIR__.'/app/extension-framework.php';
require __DIR__.'/app/theme-runtime.php';
require __DIR__.'/app/outbox.php';
require __DIR__.'/app/route-dispatcher.php';

if (!extension_loaded('mbstring')) { http_response_code(500); exit('LiteBBS requires the mbstring extension.'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_name(request_is_https() ? '__Host-lbbs' : 'lbbs');
session_set_cookie_params(['secure' => request_is_https(), 'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
foreach (["Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'; script-src 'self'", 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: strict-origin-when-cross-origin', 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'Cross-Origin-Opener-Policy: same-origin'] as $header) header($header);
if (request_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
$method = $request->method;
$legacyConfig = __DIR__.'/config.php';
if (!is_file(config_file()) && is_file($legacyConfig)) {
    try { load_config(); } catch (Throwable $error) { fail(503, '旧配置迁移失败，请先保护并移除公开目录中的 config.php。'); }
}
if (!is_file(install_lock_file()) && !in_array($path, ['/install', '/install.php', '/assets/css/install.css'], true)) redirect('/install');

$databaseStartedAt = microtime(true);
try { $pdo = db(); } catch (PDOException $error) { error_log('database: '.$error); fail(503, database_error_message($error)); }
$markRequestTiming('db', $databaseStartedAt);
require __DIR__.'/app/plugin-runtime.php';
define('LITEBBS_FRAMEWORK_DISPATCH',true);
$kernel = new \LiteBBS\Framework\Kernel();
$kernel->router->add('POST', '~^/t/(?P<topicId>[0-9]+)/reply$~', static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params): ?\LiteBBS\Framework\Response {
    require_once __DIR__.'/app/reply-handler.php';
    try { handle_topic_reply((int)$params['topicId'],$request->post,$request->files); }
    catch (\LiteBBS\Framework\ResponseHalt $halt) { return $halt->response; }
});
$kernel->router->add('GET', '~^/robots\.txt$~', static function (): \LiteBBS\Framework\Response {
    $config=load_config();
    return (new \LiteBBS\Framework\Response("User-agent: *\nDisallow: /admin\nDisallow: /me\nSitemap: ".(string)$config['url']."/sitemap.xml\n"))->header('Content-Type','text/plain; charset=utf-8');
});
$kernel->router->add('GET', '~^/sitemap\.xml$~', static function (): \LiteBBS\Framework\Response {
    $base=rtrim((string)load_config()['url'],'/');
    $key='sitemap:v1:'.cache_version('topics').':'.cache_version('categories').':'.hash('sha256',$base);
    $document=cached($key, 3600, static function () use ($base): array {
        $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.e($base.'/').'</loc></url>';
        $rows=h('SELECT t.id,t.last_post_at FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1 ORDER BY t.id DESC LIMIT 5000');
        while($topic=$rows->fetch())$xml.='<url><loc>'.e($base.'/t/'.(int)$topic['id']).'</loc><lastmod>'.e(substr((string)$topic['last_post_at'],0,10)).'</lastmod></url>';
        return [$xml.'</urlset>'];
    });
    return (new \LiteBBS\Framework\Response($document[0]??''))->header('Content-Type','application/xml; charset=utf-8')->header('Cache-Control','public, max-age=3600');
});
$kernel->router->add('GET', '~^/$~', static function (\LiteBBS\Framework\Request $request): \LiteBBS\Framework\Response {
    $cursor=(string)($request->query['cursor']??'');
    $page=max(1,(int)($request->query['page']??1));
    $topicCount=cached_topic_count('all');
    $pages=max(1,(int)ceil($topicCount/30));
    if($page>$pages||$page>10000)fail(404,'页面不存在');
    if($page>11&&$cursor==='')fail(400,'旧分页链接过深，请从首页开始使用游标浏览');
    if($page>1&&$cursor==='')fail(400,'分页方式已更新，请从首页使用游标继续浏览');
    $cursorData=null;
    if($cursor!==''){$decoded=base64_decode(strtr($cursor,'-_','+/'),true);$candidate=is_string($decoded)?json_decode($decoded,true):null;if(!is_array($candidate)||!isset($candidate['p'],$candidate['d'],$candidate['i'])||!in_array((int)$candidate['p'],[0,1],true)||!is_string($candidate['d'])||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',$candidate['d'])||(int)$candidate['i']<1)fail(400,'分页游标无效');$cursorData=['p'=>(int)$candidate['p'],'d'=>$candidate['d'],'i'=>(int)$candidate['i']];}
    $key='home:v3:'.$page.':'.hash('sha256',$cursor).':'.cache_version('home-feed').':'.cache_version('categories').':'.cache_version('extension-read-pages');
    $rows=cached($key,30,static function()use($cursorData):array{$where='t.status="normal" AND c.is_visible=1';$args=[];if($cursorData){$where.=' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';array_push($args,$cursorData['p'],$cursorData['p'],$cursorData['d'],$cursorData['d'],$cursorData['i']);}$sql='SELECT t.id,t.title,t.reply_count,t.last_post_at,t.is_pinned,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 31';return h($sql,$args)->fetchAll();});
    $hasMore=count($rows)>30;if($hasMore)array_pop($rows);$nextCursor='';if($hasMore&&$rows){$last=end($rows);$payload=json_encode(['p'=>(int)$last['is_pinned'],'d'=>(string)$last['last_post_at'],'i'=>(int)$last['id']]);$nextCursor=rtrim(strtr(base64_encode((string)$payload),'+/','-_'),'=');}
    $viewer=current_user();
    $body='<header class="home-heading"><div><h1>讨论</h1><span>'.number_format($topicCount).' 个主题</span></div>'.($viewer?'<a class="feed-create" href="/new">发布主题</a>':'<a class="feed-create" href="/login">登录</a>').'</header><section class="topic-feed" aria-label="主题列表">';
    if(!$rows)$body.='<div class="topic-empty"><h2>暂无主题</h2><a href="'.($viewer?'/new':'/login').'">'.($viewer?'发布主题':'登录后发布').' →</a></div>';
    foreach($rows as $topic){$updated=(string)$topic['last_post_at'];$body.='<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'">'.'<span class="topic-avatar" aria-hidden="true">'.e(text_slice($topic['username'],0,1)).'</span><div class="topic-feed-main"><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><div class="topic-feed-meta"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a>'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'<a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><span class="topic-activity">最后活跃</span><time datetime="'.e(str_replace(' ','T',substr($updated,0,19))).'">'.e(substr($updated,0,16)).'</time></div></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';}
    ob_start();
    $body.='</section>';if($nextCursor!=='')$body.='<nav class="pagination"><a class="pagination-next" href="/?cursor='.rawurlencode($nextCursor).'">'.($cursor!==''?'加载更多':'继续浏览').'</a></nav>';
    page('首页',$body,'最新社区讨论',true);
    return new \LiteBBS\Framework\Response((string)ob_get_clean());
});
plugin_runtime_register_routes($kernel->router);
extension_runtime_register_routes($kernel->router);
$kernel->middleware(static function (\LiteBBS\Framework\Request $request, callable $next): ?\LiteBBS\Framework\Response {
    ob_start();
    $context = $request->context(current_user(), db());
    ob_end_clean();
    return $next($request);
});
$dispatchStartedAt = microtime(true);
$frameworkResponse = $kernel->handle($request, static function (\LiteBBS\Framework\Request $request): ?\LiteBBS\Framework\Response {
    return dispatch_application_route($request, new \LiteBBS\Framework\Container());
});
$markRequestTiming('dispatch', $dispatchStartedAt);
if ($frameworkResponse instanceof \LiteBBS\Framework\Response) {
    $requestTimings['total'] = round((microtime(true) - $requestStartedAt) * 1000, 1);
    $requestState =& request_cache_state();
    if (isset($requestState['@sql-ms'])) $requestTimings['sql'] = round((float)$requestState['@sql-ms'], 1);
    if (isset($requestState['@sql-count'])) $requestTimings['queries'] = (float)$requestState['@sql-count'];
    if (!headers_sent()) header('Server-Timing: '.implode(', ', array_map(static fn(string $name, float $duration): string => $name.';dur='.$duration, array_keys($requestTimings), $requestTimings)));
    $frameworkResponse->send(); exit;
}
define('LITEBBS_LEGACY_DISPATCH_COMPLETE', true);
if ($path === '/logout' && $method === 'POST') { require_post(); if ($user = current_user()) h('DELETE FROM sessions WHERE id=? AND user_id=?', [$_SESSION['sid'], $user['id']]); $_SESSION = []; session_destroy(); redirect('/'); }

if ($path === '/register' && in_array($method, ['GET','POST'], true)) {
    if (setting('registration','1') !== '1') fail(403,'本站当前未开放注册。');
    if ($method === 'POST') { require_post(); $username=trim((string)($_POST['username']??''));$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');$maxPasswordLength=defined('PASSWORD_ARGON2ID')?128:72;limit_action('register',3,3600,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))); if (!preg_match('/^[\pL\pN_-]{3,32}$/u',$username)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($password)>$maxPasswordLength) $error='请检查用户名、邮箱和至少 12 位的密码。'; else try { h('INSERT INTO users(username,email,password_hash,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$username,$email,password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT)]);login_user((int)$pdo->lastInsertId());redirect('/'); } catch(PDOException $e) {$error='用户名或邮箱已使用。';} }
    page('注册','<p class="error">'.e($error??'加入这个社区').'</p>'.form('/register','创建账户',input('username','用户名（3-32 字符）','text','minlength="3" maxlength="32"').input('email','邮箱','email').input('password','密码（12-128 位）','password','minlength="12" maxlength="128"')));
}
if ($path === '/login' && in_array($method, ['GET','POST'], true)) {
    if ($method === 'POST') { require_post();$identity=trim((string)($_POST['account']??''));$password=(string)($_POST['password']??'');$normalized=mb_strtolower($identity,'UTF-8');limit_action('login-ip',10,900,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')));limit_action('login-account',5,900,hash('sha256',$normalized));$user=h('SELECT id,password_hash,status FROM users WHERE username=? OR email=? LIMIT 1',[$identity,$identity])->fetch();if($user&&$user['status']==='active'&&password_verify($password,$user['password_hash'])) { if(password_needs_rehash($user['password_hash'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT)) h('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT),$user['id']]);login_user((int)$user['id']);redirect('/');}$error='账号或密码不正确。'; }
    page('登录','<p class="error">'.e($error??'欢迎回来。').'</p>'.form('/login','登录',input('account','用户名或邮箱').input('password','密码','password')));
}
if ($path === '/me' && in_array($method, ['GET','POST'], true)) {
    $user=current_user();if(!$user)redirect('/login');$tab=(string)($_GET['tab']??'overview');
    if($method==='POST'){
        require_post();$action=(string)($_POST['action']??'profile');
        if($action==='password'){$current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$hash=(string)h('SELECT password_hash FROM users WHERE id=?',[$user['id']])->fetchColumn();if(!password_verify($current,$hash)||strlen($new)<12||strlen($new)>(defined('PASSWORD_ARGON2ID')?128:72))fail(400,'当前密码不正确，或新密码长度不符合要求');h('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT),$user['id']]);h('DELETE FROM sessions WHERE user_id=?',[$user['id']]);login_user((int)$user['id']);redirect('/me?tab=security');}
        if($action==='profile'){$email=trim((string)($_POST['email']??''));$bio=trim((string)($_POST['bio']??''));$locale=(string)($_POST['locale']??'zh-CN');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||text_length($bio)>500||!in_array($locale,['zh-CN','en-US'],true))fail(400,'资料格式无效');try{h('UPDATE users SET email=?,bio=?,locale=? WHERE id=?',[$email,$bio,$locale,$user['id']]);}catch(PDOException $error){fail(400,'该邮箱已被使用');}redirect('/me?tab=profile');}
        fail(400,'操作无效');
    }
    $user=h('SELECT id,username,email,role,points,locale,bio,created_at FROM users WHERE id=?',[$user['id']])->fetch();
    $tabs=['overview'=>'概览','profile'=>'个人资料','security'=>'账户安全','notifications'=>'通知','bookmarks'=>'收藏','blocks'=>'屏蔽用户'];
    $body='<section class="member-center"><header class="member-hero"><span class="member-avatar">'.e(text_slice($user['username'],0,1)).'</span><div><p class="eyebrow">ACCOUNT CENTER</p><h1>'.e($user['username']).'</h1><p>'.e($user['email']).' · 积分 '.(int)$user['points'].'</p></div><a href="/u/'.rawurlencode($user['username']).'">公开资料</a></header><nav class="member-tabs" aria-label="会员中心">';
    foreach($tabs as $key=>$label)$body.='<a href="/me?tab='.$key.'"'.($tab===$key?' aria-current="page"':'').'>'.$label.'</a>';
    $body.='</nav><div class="member-panel">';
    if($tab==='profile')$body.='<h2>个人资料</h2><form class="member-form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="profile"><label>用户名<input value="'.e($user['username']).'" disabled></label><label>邮箱<input type="email" name="email" maxlength="254" value="'.e($user['email']).'" required></label><label>个人介绍<textarea name="bio" maxlength="500" rows="5">'.e($user['bio']).'</textarea></label><label>界面语言<select name="locale"><option value="zh-CN"'.($user['locale']==='zh-CN'?' selected':'').'>简体中文</option><option value="en-US"'.($user['locale']==='en-US'?' selected':'').'>English</option></select></label><button class="primary">保存资料</button></form><form id="avatar-form" class="member-form avatar-form" method="post" action="/avatar/upload" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.e(csrf()).'"><label>头像<input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required></label><button>上传头像</button></form>';
    elseif($tab==='security')$body.='<h2>修改密码</h2><form class="member-form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="password"><label>当前密码<input type="password" name="current_password" autocomplete="current-password" required></label><label>新密码<input type="password" name="new_password" minlength="12" maxlength="128" autocomplete="new-password" required><small>至少 12 位；保存后会退出其他设备。</small></label><button class="primary">更新密码</button></form>';
    elseif($tab==='notifications'){$rows=h('SELECT n.topic_id,n.post_id,n.kind,n.created_at,u.username,t.title FROM notifications n JOIN users u ON u.id=n.actor_id LEFT JOIN topics t ON t.id=n.topic_id WHERE n.user_id=? ORDER BY n.id DESC LIMIT 30',[$user['id']])->fetchAll();h('UPDATE notifications SET read_at=UTC_TIMESTAMP(6) WHERE user_id=? AND read_at IS NULL',[$user['id']]);invalidate_user_cache((int)$user['id']);$body.='<h2>通知</h2><div class="member-list">';foreach($rows as $row){$href=$row['kind']==='message'?'/messages?to='.rawurlencode($row['username']):'/t/'.(int)$row['topic_id'].'#p'.(int)$row['post_id'];$label=$row['kind']==='message'?'给你发送了私信':'回复了你的主题';$body.='<a class="member-row" href="'.e($href).'"><span><strong>'.e($row['username']).'</strong> '.e($label).'</span><span>'.e((string)($row['title']??'' )).'</span><time>'.e(substr((string)$row['created_at'],0,16)).'</time></a>';}if(!$rows)$body.='<p class="empty-state">暂无通知</p>';$body.='</div>';}
    elseif($tab==='bookmarks'){$rows=h('SELECT t.id,t.title,t.reply_count,t.last_post_at FROM bookmarks b JOIN topics t ON t.id=b.topic_id WHERE b.user_id=? AND t.status="normal" ORDER BY b.created_at DESC LIMIT 50',[$user['id']])->fetchAll();$body.='<h2>收藏的主题</h2><div class="member-list">';foreach($rows as $row)$body.='<a class="member-row" href="/t/'.(int)$row['id'].'"><strong>'.e($row['title']).'</strong><span>'.(int)$row['reply_count'].' 条回复</span><time>'.e(substr((string)$row['last_post_at'],0,16)).'</time></a>';if(!$rows)$body.='<p class="empty-state">还没有收藏主题</p>';$body.='</div>';}
    elseif($tab==='blocks'){$rows=h('SELECT u.id,u.username FROM user_blocks b JOIN users u ON u.id=b.blocked_user_id WHERE b.user_id=? ORDER BY b.created_at DESC',[$user['id']])->fetchAll();$body.='<h2>屏蔽用户</h2><div class="member-list">';foreach($rows as $row)$body.='<div class="member-row"><a href="/u/'.rawurlencode($row['username']).'">'.e($row['username']).'</a><form method="post" action="/user/'.(int)$row['id'].'/unblock"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>取消屏蔽</button></form></div>';if(!$rows)$body.='<p class="empty-state">没有屏蔽用户</p>';$body.='</div>';}
    else{$posts=h('SELECT p.id,p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.user_id=? AND p.status="normal" ORDER BY p.id DESC LIMIT 20',[$user['id']])->fetchAll();$unread=(int)h('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL',[$user['id']])->fetchColumn();$body.='<div class="member-stats"><a href="/me?tab=notifications"><strong>'.$unread.'</strong><span>未读通知</span></a><a href="/me?tab=bookmarks"><strong>'.(int)h('SELECT COUNT(*) FROM bookmarks WHERE user_id=?',[$user['id']])->fetchColumn().'</strong><span>收藏主题</span></a><a href="/messages"><strong>'.(int)h('SELECT COUNT(*) FROM private_messages WHERE recipient_id=? AND read_at IS NULL',[$user['id']])->fetchColumn().'</strong><span>未读私信</span></a></div><h2>最近发帖</h2><div class="member-list">';foreach($posts as $post)$body.='<article class="member-row"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><span>'.e(text_slice($post['body_markdown'],0,100)).'</span><a href="/p/'.(int)$post['id'].'/edit">编辑</a></article>';if(!$posts)$body.='<p class="empty-state">还没有发布内容</p>';$body.='</div><p class="member-links"><a href="/messages">打开站内私信</a></p>';}
    page('会员中心',$body.'</div></section>');
}
if (preg_match('~^/u/([^/]+)$~', $path, $match) && $method === 'GET') { $name=rawurldecode($match[1]);$profile=h('SELECT id,username,points,bio,avatar_path,created_at FROM users WHERE username=? AND status="active"',[$name])->fetch();if(!$profile)fail(404,'用户不存在');$viewer=current_user();$posts=h('SELECT p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.user_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 ORDER BY p.id DESC LIMIT 20',[$profile['id']])->fetchAll();$avatar=!empty($profile['avatar_path'])?'<img src="/avatar/'.(int)$profile['id'].'" alt="">':e(text_slice($profile['username'],0,1));$body='<section class="member-center"><header class="member-hero"><span class="member-avatar">'.$avatar.'</span><div><h1>'.e($profile['username']).'</h1><p>积分 '.(int)$profile['points'].' · 加入于 '.e(substr((string)$profile['created_at'],0,10)).'</p></div></header><p class="public-bio">'.nl2br(e((string)$profile['bio'])).'</p>'.($viewer&&((int)$viewer['id']!==(int)$profile['id'])?'<div class="topic-actions"><a href="/messages?to='.rawurlencode($profile['username']).'">发送私信</a><form method="post" action="/user/'.(int)$profile['id'].'/block"><input type="hidden" name="csrf" value="'.e(csrf()).'"><button>屏蔽用户</button></form><details class="report-control"><summary>举报用户</summary><form method="post" action="/report"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="target_type" value="user"><input type="hidden" name="target_id" value="'.(int)$profile['id'].'"><textarea name="reason" minlength="5" maxlength="1000" placeholder="请填写举报原因" required></textarea><button>提交举报</button></form></details></div>':'').'<h2>最近发布</h2>';foreach($posts as $post)$body.='<article class="member-post"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><p>'.e(text_slice($post['body_markdown'],0,180)).'</p></article>';page($profile['username'],$body.'</section>','社区成员资料',true); }
 if (preg_match('~^/p/(\d+)/edit$~', $path, $match) && in_array($method, ['GET','POST'], true)) { $user=current_user();if(!$user)redirect('/login');$post=h('SELECT p.*,t.status topic_status FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal"',[(int)$match[1]])->fetch();if(!$post||($post['topic_status']!=='normal'))fail(404,'帖子不存在');if((int)$post['user_id']!==(int)$user['id']&&$user['role']!=='admin')fail(403,'无权编辑');if($method==='POST'){require_post();$body=trim((string)($_POST['body']??''));$revision=(int)($_POST['revision']??0);if(text_length($body)<1||text_length($body)>12000||$revision<1)fail(400,'回复内容或版本无效');$pdo->beginTransaction();try{h('INSERT INTO post_revisions(post_id,editor_id,body_markdown,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$post['id'],$user['id'],$post['body_markdown']]);$updated=h('UPDATE posts SET body_markdown=?,body_html=?,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND revision=? AND status="normal"',[$body,render_md($body),$post['id'],$revision]);if($updated->rowCount()!==1){$pdo->rollBack();fail(409,'帖子已被其他人修改，请刷新后重试');}audit('post.edited','post',(int)$post['id']);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}invalidate_topic_read_cache((int)$post['topic_id']);redirect('/t/'.(int)$post['topic_id'].'#p'.(int)$post['id']);}$fields='<input type="hidden" name="revision" value="'.(int)$post['revision'].'"><label>内容<textarea name="body" maxlength="12000" rows="10" required>'.e($post['body_markdown']).'</textarea></label>';page('编辑帖子',form('/p/'.(int)$post['id'].'/edit','编辑帖子',$fields)); }
if ($path === '/search' && $method === 'GET') { $term=trim((string)($_GET['q']??''));$body='<form class="search"><label>搜索主题标题<input name="q" maxlength="60" value="'.e($term).'"></label><button>搜索</button></form><h1>搜索讨论</h1>';if($term!==''){if(text_length($term)<3)$body.='<p>请输入至少三个字符。</p>';else{$viewer=current_user();$identity=$viewer?'user:'.$viewer['id']:'ip:'.($_SERVER['REMOTE_ADDR']??'');limit_action('search',10,300,$identity);$ascii=preg_match('/^[\x00-\x7F]+$/',$term)===1;$sql=$ascii?'MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE)':'t.title LIKE ?';$arg=$ascii?$term:'%'.$term.'%';$rows=h('SELECT t.id,t.title,t.reply_count,t.last_post_at,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1 AND '.$sql.' ORDER BY t.last_post_at DESC LIMIT 50',[$arg])->fetchAll();foreach($rows as $row)$body.='<article class="topic"><a href="/c/'.(int)$row['category_id'].'">'.e($row['category']).'</a><h2><a href="/t/'.(int)$row['id'].'">'.e($row['title']).'</a></h2></article>';if(!$rows)$body.='<p>没有找到匹配的主题。</p>';}}page('搜索讨论',$body,'仅搜索公开主题标题'); }
fail(404,'页面不存在');
