<?php
declare(strict_types=1);

require_once __DIR__.'/app/config.php';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
require __DIR__.'/app/core.php';
require __DIR__.'/app/extensions-security.php';

if (!extension_loaded('mbstring')) { http_response_code(500); exit('LiteBBS requires the mbstring extension.'); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_name(request_is_https() ? '__Host-lbbs' : 'lbbs');
session_set_cookie_params(['secure' => request_is_https(), 'httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
foreach (["Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'; script-src 'self'", 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: strict-origin-when-cross-origin', 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'Cross-Origin-Opener-Policy: same-origin'] as $header) header($header);
if (request_is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$legacyConfig = __DIR__.'/config.php';
if (!is_file(config_file()) && is_file($legacyConfig)) {
    try { load_config(); } catch (Throwable $error) { fail(503, '旧配置迁移失败，请先保护并移除公开目录中的 config.php。'); }
}
if (!is_file(install_lock_file()) && !in_array($path, ['/install', '/install.php', '/install.css'], true)) redirect('/install');
if ($path === '/admin.css' && $method === 'GET') {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache');
    $adminCss = __DIR__.'/app/admin.css';
    if (!is_file($adminCss)) fail(404, '后台样式文件不存在');
    readfile($adminCss);
    exit;
}

try { $pdo = db(); } catch (PDOException $error) { error_log('database: '.$error); fail(503, database_error_message($error)); }
require __DIR__.'/app/admin-categories.php';
require __DIR__.'/app/market.php';
require __DIR__.'/app/admin-extensions-manage.php';
require __DIR__.'/app/admin-users-settings.php';
require __DIR__.'/app/read-pages.php';
require __DIR__.'/app/admin-dashboard.php';
require __DIR__.'/app/admin-audit.php';

if ($path === '/robots.txt' && $method === 'GET') { header('Content-Type: text/plain; charset=utf-8'); echo "User-agent: *\nDisallow: /admin\nDisallow: /me\nSitemap: ".load_config()['url']."/sitemap.xml\n"; exit; }
if ($path === '/sitemap.xml' && $method === 'GET') {
    header('Content-Type: application/xml; charset=utf-8'); header('Cache-Control: public, max-age=3600');
    $base = rtrim((string)load_config()['url'], '/');
    $key = 'sitemap:v1:'.cache_version('topics').':'.cache_version('categories').':'.hash('sha256', $base);
    $document = cached($key, 3600, static function () use ($base): array {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.e($base.'/').'</loc></url>';
        $rows = h('SELECT t.id,t.last_post_at FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1 ORDER BY t.id DESC LIMIT 5000');
        while ($topic = $rows->fetch()) $xml .= '<url><loc>'.e($base.'/t/'.(int)$topic['id']).'</loc><lastmod>'.e(substr((string)$topic['last_post_at'], 0, 10)).'</lastmod></url>';
        return [$xml.'</urlset>'];
    });
    echo $document[0] ?? ''; exit;
}
if ($path === '/logout' && $method === 'POST') { require_post(); if ($user = current_user()) h('DELETE FROM sessions WHERE id=? AND user_id=?', [$_SESSION['sid'], $user['id']]); $_SESSION = []; session_destroy(); redirect('/'); }
if ($path === '/' && $method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $topicCount = cached_topic_count('all');
    $pages = max(1, (int)ceil($topicCount / 30));
    if ($page > $pages || $page > 10000) fail(404, '页面不存在');
    $key = 'home:v1:'.$page.':'.cache_version('topics').':'.cache_version('categories');
    $rows = cached($key, 30, static function () use ($page): array {
        return h('SELECT t.id,t.title,t.reply_count,t.last_post_at,t.is_pinned,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1 ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 30 OFFSET '.(($page - 1) * 30))->fetchAll();
    });
    $viewer = current_user();
    $body = '<header class="home-heading"><div><h1>讨论</h1><span>'.number_format($topicCount).' 个主题</span></div>'.($viewer?'<a class="feed-create" href="/new">发布主题</a>':'<a class="feed-create" href="/login">登录</a>').'</header><section class="topic-feed" aria-label="主题列表">';
    if (!$rows) $body .= '<div class="topic-empty"><h2>暂无主题</h2><a href="'.($viewer?'/new':'/login').'">'.($viewer?'发布主题':'登录后发布').' →</a></div>';
    foreach ($rows as $topic) {
        $updated = (string)$topic['last_post_at'];
        $body .= '<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'"><div class="topic-feed-main"><div class="topic-feed-meta"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a>'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'</div><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><p><a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time datetime="'.e(str_replace(' ','T',substr($updated,0,19))).'">'.e(substr($updated,0,16)).'</time></p></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';
    }
    $body .= '</section>'.pagination($page, $pages);
    page('首页', $body, '最新社区讨论', true);
    exit;
}
if ($path === '/register' && in_array($method, ['GET','POST'], true)) {
    if (setting('registration','1') !== '1') fail(403,'本站当前未开放注册。');
    if ($method === 'POST') { require_post(); $username=trim((string)($_POST['username']??''));$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');$maxPasswordLength=defined('PASSWORD_ARGON2ID')?128:72;limit_action('register',3,3600,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))); if (!preg_match('/^[\pL\pN_-]{3,32}$/u',$username)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($password)>$maxPasswordLength) $error='请检查用户名、邮箱和至少 12 位的密码。'; else try { h('INSERT INTO users(username,email,password_hash,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$username,$email,password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT)]);login_user((int)$pdo->lastInsertId());redirect('/'); } catch(PDOException $e) {$error='用户名或邮箱已使用。';} }
    page('注册','<p class="error">'.e($error??'加入这个社区').'</p>'.form('/register','创建账户',input('username','用户名（3-32 字符）','text','minlength="3" maxlength="32"').input('email','邮箱','email').input('password','密码（12-128 位）','password','minlength="12" maxlength="128"')));
}
if ($path === '/login' && in_array($method, ['GET','POST'], true)) {
    if ($method === 'POST') { require_post();$identity=trim((string)($_POST['account']??''));$password=(string)($_POST['password']??'');limit_action('login-ip',10,900,hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'')));$user=h('SELECT id,password_hash,status FROM users WHERE username=? OR email=? LIMIT 1',[$identity,$identity])->fetch();if($user&&$user['status']==='active'&&password_verify($password,$user['password_hash'])) { if(password_needs_rehash($user['password_hash'],defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT)) h('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT),$user['id']]);login_user((int)$user['id']);redirect('/');}$error='账号或密码不正确。'; }
    page('登录','<p class="error">'.e($error??'欢迎回来。').'</p>'.form('/login','登录',input('account','用户名或邮箱').input('password','密码','password')));
}
if ($path === '/me' && in_array($method, ['GET','POST'], true)) {
    $user = current_user(); if (!$user) redirect('/login');
    if ($method === 'POST') { require_post(); $locale=(string)($_POST['locale']??'zh-CN'); if(!in_array($locale,['zh-CN','en-US'],true)) fail(400,'语言选项无效'); h('UPDATE users SET locale=? WHERE id=?',[$locale,$user['id']]); redirect('/me'); }
    $posts=h('SELECT p.id,p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.user_id=? AND p.status="normal" ORDER BY p.id DESC LIMIT 20',[$user['id']])->fetchAll();
    $body='<section class="member-center"><h1>'.e($user['username']).'</h1><p>'.e($user['email']).' · 积分 '.(int)$user['points'].' · <a href="/u/'.rawurlencode($user['username']).'">公开资料</a></p><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><label>语言<select name="locale"><option value="zh-CN"'.($user['locale']==='zh-CN'?' selected':'').'>zh-CN</option><option value="en-US"'.($user['locale']==='en-US'?' selected':'').'>en-US</option></select></label><button>保存</button></form>';
    foreach($posts as $post)$body.='<article class="member-post"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><p>'.e(text_slice($post['body_markdown'],0,180)).'</p><a href="/p/'.(int)$post['id'].'/edit">编辑帖子</a></article>';
    page('会员中心',$body.'</section>');
}
if ($path === '/new' && in_array($method, ['GET','POST'], true)) {
    $user=current_user();if(!$user)redirect('/login');$categories=h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll();
    if($method==='POST'){require_post();limit_action('topic-create',3,300,'user:'.$user['id']);enforce_post_interval((int)$user['id']);$title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$category=(int)($_POST['category']??0);if(text_length($title)<4||text_length($title)>120||text_length($body)<5||text_length($body)>12000||!in_array($category,array_column($categories,'id'),true))$error='标题、正文或分区无效。';else{$pdo->beginTransaction();try{h('INSERT INTO topics(category_id,user_id,slug,title,created_at,last_post_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$category,$user['id'],slug($title),$title]);$topic=(int)$pdo->lastInsertId();h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$topic,$user['id'],$body,render_md($body)]);$pdo->commit();invalidate('topics');redirect('/t/'.$topic);}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();throw $exception;}}}
    $options='';foreach($categories as $category)$options.='<option value="'.(int)$category['id'].'">'.e($category['title']).'</option>';
    page('发布主题','<p class="error">'.e($error??'').'</p>'.form('/new','发布一个主题','<label>标题<input name="title" minlength="4" maxlength="120" required></label><label>分区<select name="category">'.$options.'</select></label><label>正文<textarea name="body" rows="12" maxlength="12000" required></textarea></label>'));
}
if (preg_match('~^/u/([^/]+)$~', $path, $match) && $method === 'GET') { $name=rawurldecode($match[1]);$profile=h('SELECT id,username,points,created_at FROM users WHERE username=? AND status="active"',[$name])->fetch();if(!$profile)fail(404,'用户不存在');$posts=h('SELECT p.topic_id,p.body_markdown,p.created_at,t.title FROM posts p JOIN topics t ON t.id=p.topic_id JOIN categories c ON c.id=t.category_id WHERE p.user_id=? AND p.status="normal" AND t.status="normal" AND c.is_visible=1 ORDER BY p.id DESC LIMIT 20',[$profile['id']])->fetchAll();$body='<section class="member-center"><h1>'.e($profile['username']).'</h1><p>积分 '.(int)$profile['points'].' · 加入于 '.e(substr((string)$profile['created_at'],0,10)).'</p>';foreach($posts as $post)$body.='<article class="member-post"><a href="/t/'.(int)$post['topic_id'].'">'.e($post['title']).'</a><p>'.e(text_slice($post['body_markdown'],0,180)).'</p></article>';page($profile['username'],$body.'</section>','社区成员资料',true); }
if (preg_match('~^/p/(\d+)/edit$~', $path, $match) && in_array($method, ['GET','POST'], true)) { $user=current_user();if(!$user)redirect('/login');$post=h('SELECT p.*,t.status topic_status FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal"',[(int)$match[1]])->fetch();if(!$post||($post['topic_status']!=='normal'))fail(404,'帖子不存在');if((int)$post['user_id']!==(int)$user['id']&&$user['role']!=='admin')fail(403,'无权编辑');if($method==='POST'){require_post();$body=trim((string)($_POST['body']??''));if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');$pdo->beginTransaction();h('INSERT INTO post_revisions(post_id,editor_id,body_markdown,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$post['id'],$user['id'],$post['body_markdown']]);h('UPDATE posts SET body_markdown=?,body_html=?,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$body,render_md($body),$post['id']]);audit('post.edited','post',(int)$post['id']);$pdo->commit();invalidate('topics');redirect('/t/'.(int)$post['topic_id'].'#p'.(int)$post['id']);}$fields='<label>内容<textarea name="body" maxlength="12000" rows="10" required>'.e($post['body_markdown']).'</textarea></label>';page('编辑帖子',form('/p/'.(int)$post['id'].'/edit','编辑帖子',$fields)); }
if (preg_match('~^/t/(\d+)/reply$~', $path, $match) && $method === 'POST') { require_post();$user=current_user();if(!$user)redirect('/login');limit_action('reply',10,300,'user:'.$user['id']);enforce_post_interval((int)$user['id']);$body=trim((string)($_POST['body']??''));if(text_length($body)<1||text_length($body)>12000)fail(400,'回复长度无效');$pdo->beginTransaction();try{$topic=h('SELECT t.id,t.is_locked FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1 FOR UPDATE',[(int)$match[1]])->fetch();if(!$topic||$topic['is_locked']){ $pdo->rollBack();fail(404,'主题不存在、分区已隐藏或主题已锁定'); }h('INSERT INTO posts(topic_id,user_id,body_markdown,body_html,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$topic['id'],$user['id'],$body,render_md($body)]);$id=(int)$pdo->lastInsertId();h('UPDATE topics SET reply_count=reply_count+1,last_post_at=UTC_TIMESTAMP(6) WHERE id=?',[$topic['id']]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}invalidate('topics');redirect('/t/'.$topic['id'].'#p'.$id); }
if ($path === '/search' && $method === 'GET') { $term=trim((string)($_GET['q']??''));$body='<form class="search"><label>搜索主题标题<input name="q" maxlength="60" value="'.e($term).'"></label><button>搜索</button></form><h1>搜索讨论</h1>';if($term!==''){if(text_length($term)<3)$body.='<p>请输入至少三个字符。</p>';else{$viewer=current_user();$identity=$viewer?'user:'.$viewer['id']:'ip:'.($_SERVER['REMOTE_ADDR']??'');limit_action('search',10,300,$identity);$ascii=preg_match('/^[\x00-\x7F]+$/',$term)===1;$sql=$ascii?'MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE)':'t.title LIKE ?';$arg=$ascii?$term:'%'.$term.'%';$rows=h('SELECT t.id,t.title,t.reply_count,t.last_post_at,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1 AND '.$sql.' ORDER BY t.last_post_at DESC LIMIT 50',[$arg])->fetchAll();foreach($rows as $row)$body.='<article class="topic"><a href="/c/'.(int)$row['category_id'].'">'.e($row['category']).'</a><h2><a href="/t/'.(int)$row['id'].'">'.e($row['title']).'</a></h2></article>';if(!$rows)$body.='<p>没有找到匹配的主题。</p>';}}page('搜索讨论',$body,'仅搜索公开主题标题'); }
if ($path === '/' && $method === 'GET') { $page=max(1,(int)($_GET['page']??1));$topicCount=(int)h('SELECT COUNT(*) FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1')->fetchColumn();$pages=max(1,(int)ceil($topicCount/30));if($page>$pages)fail(404,'页面不存在');$offset=($page-1)*30;$rows=h('SELECT t.id,t.title,t.reply_count,t.last_post_at,t.is_pinned,c.id category_id,c.title category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1 ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT 30 OFFSET '.$offset)->fetchAll();$body='<header class="home-heading"><div><h1>讨论</h1><span>'.number_format($topicCount).' 个主题</span></div>'.(current_user()?'<a class="feed-create" href="/new">发布主题</a>':'<a class="feed-create" href="/login">登录</a>').'</header><section class="topic-feed" aria-label="主题列表">';if(!$rows)$body.='<div class="topic-empty"><h2>暂无主题</h2><a href="'.(current_user()?'/new':'/login').'">'.(current_user()?'发布主题':'登录后发布').' →</a></div>';foreach($rows as $topic){$updated=(string)$topic['last_post_at'];$body.='<article class="topic topic-feed-item'.($topic['is_pinned']?' is-pinned':'').'"><div class="topic-feed-main"><div class="topic-feed-meta"><a class="topic-category" href="/c/'.(int)$topic['category_id'].'">'.e($topic['category']).'</a>'.($topic['is_pinned']?'<span class="topic-pinned">置顶</span>':'').'</div><h2><a href="/t/'.(int)$topic['id'].'">'.e($topic['title']).'</a></h2><p><a class="topic-author" href="/u/'.rawurlencode($topic['username']).'">'.e($topic['username']).'</a><time datetime="'.e(str_replace(' ','T',substr($updated,0,19))).'">'.e(substr($updated,0,16)).'</time></p></div><a class="topic-replies" href="/t/'.(int)$topic['id'].'#replies" aria-label="'.(int)$topic['reply_count'].' 条回复"><strong>'.(int)$topic['reply_count'].'</strong><span>回复</span></a></article>';}$body.='</section>'.pagination($page,$pages);page('首页',$body,'公开讨论',true); }
fail(404,'页面不存在');
