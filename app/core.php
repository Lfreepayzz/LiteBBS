<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function text_slice(string $value, int $start, int $length): string { return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length); }
function text_length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
function password_length_valid(string $value): bool { $length=strlen($value);return $length>=6&&$length<=255; }
function public_read_response_cache(?array $user): void { if ($user === null) { header('Cache-Control: public, max-age=120, s-maxage=120, stale-while-revalidate=90'); header('Vary: Cookie'); } }
function &session_runtime_state(): array { static $state=['migrate_legacy_cookie'=>false];return $state; }
function session_cookie_is_secure(): bool { if(request_is_https())return true;if(!is_file(config_file()))return false;$scheme=parse_url((string)(load_config()['url']??''),PHP_URL_SCHEME);return is_string($scheme)&&strtolower($scheme)==='https'; }
function configure_session(): void { static $configured=false;if($configured)return;if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Session was started before LiteBBS configured it');$secure=session_cookie_is_secure();ini_set('session.use_only_cookies','1');ini_set('session.use_strict_mode','1');ini_set('session.use_trans_sid','0');$name=$secure?'__Host-lbbs':'lbbs';session_name($name);session_set_cookie_params(['secure'=>$secure,'httponly'=>true,'samesite'=>'Lax','path'=>'/']);if($secure&&!isset($_COOKIE[$name])&&isset($_COOKIE['lbbs'])&&is_string($_COOKIE['lbbs'])&&preg_match('/^[A-Za-z0-9,-]{1,128}$/',$_COOKIE['lbbs'])){$_COOKIE[$name]=$_COOKIE['lbbs'];$state=&session_runtime_state();$state['migrate_legacy_cookie']=true;}$configured=true; }
function start_session(): void { if (session_status() !== PHP_SESSION_ACTIVE) { configure_session();$host=getenv('LITEBBS_REDIS_HOST');if($host!==false&&$host!==''){if(!class_exists(Redis::class))throw new RuntimeException('Redis session storage is configured but ext-redis is unavailable');$port=(int)(getenv('LITEBBS_REDIS_PORT')?:6379);$path='tcp://'.$host.':'.$port;$options=['timeout=0.25','read_timeout=0.25'];$password=getenv('LITEBBS_REDIS_PASSWORD');if($password!==false&&$password!=='')$options[]='auth='.rawurlencode($password);$database=(int)(getenv('LITEBBS_REDIS_DATABASE')?:0);if($database)$options[]='database='.$database;$prefix=getenv('LITEBBS_REDIS_PREFIX')?:'litebbs';$options[]='prefix='.rawurlencode($prefix.':session:');$path.='?'.implode('&',$options);ini_set('session.save_handler','redis');ini_set('session.save_path',$path); }if(!session_start()||session_id()==='')throw new RuntimeException('PHP session_start failed');$state=&session_runtime_state();if($state['migrate_legacy_cookie']){$params=session_get_cookie_params();setcookie(session_name(),session_id(),['expires'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);setcookie('lbbs','',['expires'=>time()-3600,'path'=>'/','secure'=>(bool)($params['secure']??true),'httponly'=>true,'samesite'=>'Lax']);unset($_COOKIE['lbbs']);$state['migrate_legacy_cookie']=false;} } }
function release_session(): void { if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) throw new RuntimeException('PHP session write failed'); }
function release_read_session(): void { if (in_array((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET','HEAD','OPTIONS'], true)) release_session(); }
function clear_session_cookie(): void { $params=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>(string)($params['path']??'/'),'secure'=>(bool)($params['secure']??false),'httponly'=>(bool)($params['httponly']??true),'samesite'=>(string)($params['samesite']??'Lax')]); }
function request_is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (PHP_SAPI !== 'cli' && getenv('LITEBBS_HTTPS') === '1'); }
function database_error_message(PDOException $error): string { $info=$error->errorInfo;$driverCode=(int)($info[1]??$error->getCode());return match($driverCode){1045,1698=>'数据库认证失败：请检查数据库用户名、密码，以及该用户是否允许从当前 Web 服务器连接。',1049=>'数据库不存在：请先创建填写的数据库，或更正数据库名称。',1044,1142,1143=>'数据库权限不足：该账户需要访问目标数据库并创建表、索引和初始数据。',default=>'数据库不可用：请检查数据库主机、端口、网络访问和服务状态。'}; }
function db(): PDO { static $pdo; if (!$pdo) { $startedAt=microtime(true);try{$c=load_config();$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];if(getenv('LITEBBS_DB_PERSISTENT')==='1')$options[PDO::ATTR_PERSISTENT]=true;$pdo=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['pass'],$options);}catch(PDOException $error){error_log('database: '.$error);fail(503,database_error_message($error));}finally{$state=&request_cache_state();$state['@db-connect-ms']=((float)($state['@db-connect-ms']??0))+(microtime(true)-$startedAt)*1000;}} return $pdo; }
function database_host_is_valid(string $host): bool {
    $host=trim($host);
    if ($host==='' || strlen($host)>253 || preg_match('~[\x00-\x20\\\\/]~',$host)) return false;
    if (strcasecmp($host,'localhost')===0) return true;
    if (filter_var($host,FILTER_VALIDATE_IP)) return true;
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',$host)) return false;
    return true;
}
function h(string $sql, array $args=[]): PDOStatement { $startedAt=microtime(true);try{$q=db()->prepare($sql);$q->execute($args);return $q;}finally{$state=&request_cache_state();$state['@sql-count']=((int)($state['@sql-count']??0))+1;$state['@sql-ms']=((float)($state['@sql-ms']??0))+(microtime(true)-$startedAt)*1000;} }
function redirect(string $to, int $status=303): void { $location=preg_match('~^/(?!/)~',$to)?$to:'/';if(defined('LITEBBS_FRAMEWORK_DISPATCH'))throw new \LiteBBS\Framework\ResponseHalt((new \LiteBBS\Framework\Response('', $status))->header('Location',$location));header('Location: '.$location,true,$status);exit; }
function redirect_external_https(string $url, int $status=303): void { if (preg_match('/[\r\n]/', $url) || request_url_parts($url) === null) fail(400, '外部地址无效'); if(defined('LITEBBS_FRAMEWORK_DISPATCH'))throw new \LiteBBS\Framework\ResponseHalt((new \LiteBBS\Framework\Response('', $status))->header('Location',$url));header('Location: '.$url,true,$status);exit; }
function csrf(): string { static $token=null;if($token!==null)return $token;start_session();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));$token=(string)$_SESSION['csrf'];release_session();return $token; }
function verify_csrf_token(string $submitted): bool { start_session();$stored=$_SESSION['csrf']??null;$valid=is_string($stored)&&$stored!==''&&$submitted!==''&&hash_equals($stored,$submitted);if(!$valid)error_log('csrf rejected: '.(!isset($_COOKIE[session_name()])?'session cookie missing':(!is_string($stored)||$stored===''?'session token missing':'token mismatch')));release_session();return $valid; }
function confirmation_token(string $action,string $subject): string { start_session();$token=bin2hex(random_bytes(32));$_SESSION['confirmations'][$token]=['action'=>$action,'subject'=>$subject,'expires'=>time()+300];release_session();return $token; }
function require_confirmation(string $action,string $subject,string $token): void { start_session();$item=$_SESSION['confirmations'][$token]??null;unset($_SESSION['confirmations'][$token]);release_session();if(!is_array($item)||($item['expires']??0)<time()||!hash_equals((string)($item['action']??''),$action)||!hash_equals((string)($item['subject']??''),$subject))fail(403,'确认令牌无效或已过期'); }
function confirmation_page(string $title,string $message,string $action,array $fields,string $subject): void { $token=confirmation_token($action,$subject);$inputs='<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="confirm_token" value="'.e($token).'">';foreach($fields as $name=>$value)$inputs.='<input type="hidden" name="'.e((string)$name).'" value="'.e((string)$value).'">';$details=['topic.delete'=>['/admin/content','确认永久删除'],'theme.delete'=>['/admin/themes','确认删除模板'],'plugin.purge'=>['/admin/plugins','确认删除插件'],'audit.clear'=>['/admin/audit','确认清空日志']];[$cancel,$buttonLabel]=$details[$action]??['/admin','确认执行'];$body='<section class="destructive-confirm"><div class="destructive-confirm-copy"><p>'.e($message).'</p><small>此操作不可撤销。</small></div><form method="post">'.$inputs.'<div class="destructive-confirm-actions"><a href="'.e($cancel).'">取消</a><button type="submit">'.e($buttonLabel).'</button></div></form></section>';page($title,$body,'',false,true); }
function setting(string $key, string $default=''): string { static $values=null,$version=null;$current=cache_version('settings');if($values===null||$version!==$current){$values=cached('settings:all:'.$current,30,static function(): array {$out=[];foreach(h('SELECT setting_key,setting_value FROM settings')->fetchAll() as $row)$out[$row['setting_key']]=$row['setting_value'];return $out;});$version=$current;}return (string)($values[$key]??$default); }
function install_token(): string { start_session();$token=time().'.'.bin2hex(random_bytes(16));$_SESSION['install_token']=$token;release_session();return $token; }
function verify_install_token(string $token): bool { start_session();$valid=isset($_SESSION['install_token']) && hash_equals($_SESSION['install_token'],$token) && abs(time()-(int)explode('.',$token)[0])<3600;release_session();return $valid; }
function request_url_parts(string $url): ?array { if($url===''||preg_match('/[\x00-\x20\\\\@]/',$url))return null;$parts=parse_url($url);if(!is_array($parts)||isset($parts['user'])||isset($parts['pass'])||strtolower((string)($parts['scheme']??''))!=='https'||!isset($parts['host']))return null;$host=strtolower((string)$parts['host']);if($host===''||strlen($host)>253)return null;$port=(int)($parts['port']??443);if($port<1||$port>65535)return null;return ['scheme'=>'https','host'=>$host,'port'=>$port]; }
function same_https_origin(string $source,string $expected): bool { $actual=request_url_parts($source);$target=request_url_parts($expected);return $actual!==null&&$target!==null&&$actual===$target; }
function install_request_origin(): ?string { $host=(string)($_SERVER['HTTP_HOST']??'');if($host===''||preg_match('/[\x00-\x20\\\\\/\?\#@]/',$host))return null;return request_url_parts('https://'.$host)!==null?'https://'.$host:null; }
function require_post(): void { if(($_SERVER['REQUEST_METHOD']??'')!=='POST')fail(405,'只接受 POST 请求');if(!request_is_https())fail(403,'状态变更必须使用 HTTPS');$requestPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$install=in_array($requestPath,['/install','/install.php'],true);if($install){if(!verify_install_token((string)($_POST['install_token']??'')))fail(403,'安装 nonce 无效');}elseif(!verify_csrf_token((string)($_POST['csrf']??'')))fail(403,'CSRF token 无效');$source=(string)($_SERVER['HTTP_ORIGIN']??$_SERVER['HTTP_REFERER']??'');$expected=$install?install_request_origin():(string)(load_config()['url']??'');if($source===''||$expected===''||!same_https_origin($source,$expected))fail(403,'来源校验失败');if($install){start_session();unset($_SESSION['install_token']);release_session();} }
function fail(int $status,string $message): void { if(defined('LITEBBS_FRAMEWORK_DISPATCH')){ob_start();http_response_code($status);page('请求失败','<section class="message"><h1>'.e($message).'</h1><a href="/">返回首页</a></section>');throw new \LiteBBS\Framework\ResponseHalt(new \LiteBBS\Framework\Response((string)ob_get_clean(),$status));}http_response_code($status);page('请求失败','<section class="message"><h1>'.e($message).'</h1><a href="/">返回首页</a></section>');exit; }
function session_auth_state(array $session): string { $present=array_map(static fn(string $key):bool=>array_key_exists($key,$session),['sid','uid','token']);if(!in_array(true,$present,true))return 'anonymous';return !in_array(false,$present,true)&&(int)$session['sid']>0&&(int)$session['uid']>0&&is_string($session['token'])&&$session['token']!==''?'complete':'invalid'; }
function current_user(): ?array {
    static $loaded=false,$user=null;
    if($loaded)return $user;
    $loaded=true;
    if(!is_file(install_lock_file())||!is_file(config_file())||empty($_COOKIE[session_name()]))return null;

    start_session();
    $authState=session_auth_state($_SESSION);
    if($authState==='anonymous'){
        release_session();
        return null;
    }
    $sid=(int)($_SESSION['sid']??0);
    $uid=(int)($_SESSION['uid']??0);
    $token=(string)($_SESSION['token']??'');
    if($authState==='invalid'){
        $_SESSION=[];
        release_session();
        clear_session_cookie();
        return null;
    }
    // Release the per-browser PHP session lock before authentication queries.
    // This permits parallel reads from the same signed-in browser.
    release_session();

    $user=h('SELECT u.id,u.username,u.email,u.role,u.is_developer,u.points,u.locale,u.avatar_path,s.user_agent_hash,s.last_seen_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.user_id=? AND s.token_hash=? AND s.expires_at>UTC_TIMESTAMP(6) AND u.status="active"',[$sid,$uid,hash('sha256',$token)])->fetch()?:null;
    if(!$user){
        start_session();
        $_SESSION=[];
        release_session();
        clear_session_cookie();
        return null;
    }
    $ua=hash('sha256',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200));
    if(!hash_equals((string)$user['user_agent_hash'],$ua)){
        h('DELETE FROM sessions WHERE id=?',[$sid]);
        start_session();
        $_SESSION=[];
        release_session();
        clear_session_cookie();
        return null;
    }
    if(strtotime((string)$user['last_seen_at'])<time()-300)h('UPDATE sessions SET last_seen_at=UTC_TIMESTAMP(6) WHERE id=?',[$sid]);
    unset($user['user_agent_hash'],$user['last_seen_at']);
    return $user;
}
function is_developer(?array $user=null): bool { $user=$user??current_user();return is_array($user)&&((int)($user['is_developer']??0)===1); }
function login_user(int $id): void { start_session();if(!session_regenerate_id(true))throw new RuntimeException('PHP session ID regeneration failed');$token=bin2hex(random_bytes(32));$ip=(string)($_SERVER['REMOTE_ADDR']??'');$prefix=strpos($ip,':')!==false?substr($ip,0,strrpos($ip,':')):$ip;h('INSERT INTO sessions(user_id,token_hash,expires_at,last_seen_at,user_agent_hash,ip_prefix_hash) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 30 DAY),UTC_TIMESTAMP(6),?,?)',[$id,hash('sha256',$token),hash('sha256',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200)),hash('sha256',$prefix)]);$_SESSION=['uid'=>$id,'sid'=>(int)db()->lastInsertId(),'token'=>$token,'csrf'=>bin2hex(random_bytes(32))];release_session(); }
function admin(): array { $u=current_user();if(!$u)redirect('/login');if($u['role']!=='admin')fail(403,'无权访问');return $u; }
function enforce_post_interval(int $userId): void { $interval=(int)setting('post_interval','0');if($interval<=0)return;$last=h('SELECT created_at FROM posts WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$userId])->fetchColumn();if(!is_string($last)||$last==='')return;$remaining=$interval-(time()-(int)strtotime($last.' UTC'));if($remaining>0)fail(429,'发帖间隔尚未结束，请在 '.$remaining.' 秒后重试'); }
function enforce_content_rate_limit(string $kind,int $userId): void { if(!in_array($kind,['topic','reply'],true))throw new InvalidArgumentException('Unknown content rate-limit kind.');$max=(int)setting($kind.'_rate_max','0');if($max<=0)return;$window=max(1,(int)setting($kind.'_rate_window','300'));$label=$kind==='topic'?'发布主题':'回复';limit_action($kind.'-create:configured:'.$max.':'.$window,$max,$window,'user:'.$userId,$label.'操作过于频繁，请稍后再试'); }
function limit_action(string $action,int $max,int $seconds,string $identity,string $message='操作过于频繁，请稍后再试'): void { $max=max(1,min(1000000,$max));$seconds=max(1,min(86400,$seconds));$bucket=hash('sha256',$action.'|'.$identity);$redis=redis_cache_client();if($redis){try{$key=shared_cache_key('rate:'.$bucket);$count=(int)$redis->eval("local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],ARGV[1]); end; return n",[$key,(string)$seconds],1);if($count>$max)fail(429,$message);return;}catch(\LiteBBS\Framework\ResponseHalt $halt){throw $halt;}catch(Throwable $error){cache_error('Redis rate limit failed: '.$error->getMessage());if((getenv('LITEBBS_REDIS_HOST')?:'')!=='')throw new RuntimeException('Shared Redis rate limiting is unavailable',0,$error);}}elseif((getenv('LITEBBS_REDIS_HOST')?:'')!=='')throw new RuntimeException('Shared Redis rate limiting is unavailable');$pdo=db();h('INSERT INTO rate_limits(bucket,counter,reset_at) VALUES(?,LAST_INSERT_ID(1),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL '.$seconds.' SECOND)) ON DUPLICATE KEY UPDATE counter=LAST_INSERT_ID(IF(reset_at<=UTC_TIMESTAMP(6),1,counter+1)),reset_at=IF(reset_at<=UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL '.$seconds.' SECOND),reset_at)',[$bucket]);if((int)$pdo->lastInsertId()>$max)fail(429,$message); }
function unread_counts(int $userId): array { return cached('unread:v1:'.$userId.':'.cache_version('unread-user:'.$userId),10,static function()use($userId):array{$counts=h('SELECT (SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL) notifications,(SELECT COUNT(*) FROM private_messages WHERE recipient_id=? AND read_at IS NULL) messages',[$userId,$userId])->fetch()?:[];return [(int)($counts['notifications']??0),(int)($counts['messages']??0)];}); }
function render_md(string $text): string { $lines=preg_split('/\R/u',$text)?:[];$html='';$quote=[];$flush=function()use(&$html,&$quote):void{if($quote){$html.='<blockquote>'.nl2br(e(implode("\n",$quote)),false).'</blockquote>';$quote=[];}};foreach($lines as $index=>$line){if(str_starts_with($line,'> ')){$quote[]=substr($line,2);continue;}$flush();$html.=e($line);if($index<count($lines)-1)$html.='<br>';} $flush();return $html; }
function form(string $action,string $title,string $fields,string $submitLabel='保存'): string { $token=in_array($action,['/install','/install.php'],true)?'<input type="hidden" name="install_token" value="'.e(install_token()).'">':'<input type="hidden" name="csrf" value="'.e(csrf()).'">';return '<form class="form" method="post" action="'.e($action).'"><h1>'.e($title).'</h1>'.$token.$fields.'<button class="primary">'.e($submitLabel).'</button></form>'; }
function input(string $name,string $label,string $type='text',string $attrs=''): string { return '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" '.$attrs.' required></label>'; }
function audit(string $action,string $type,?int $id,array $meta=[]): void { $u=current_user();h('INSERT INTO audit_logs(actor_id,action,target_type,target_id,metadata_json,ip_prefix_hash,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$u['id']??null,$action,$type,$id,json_encode($meta,JSON_UNESCAPED_UNICODE),hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))]); }
function remove_tree(string $path): void { if (is_link($path)) { @unlink($path); return; } if (!is_dir($path)) return; foreach (scandir($path) ?: [] as $item) { if ($item === '.' || $item === '..') continue; $child = $path.'/'.$item; if (is_dir($child) && !is_link($child)) remove_tree($child); else @unlink($child); } @rmdir($path); }
function permanently_delete_topic(int $id): void { $db=db();$categoryId=0;$postIds=[];$actorId=(int)(current_user()['id']??0);$db->beginTransaction();try{$topic=h('SELECT id,category_id,user_id FROM topics WHERE id=? FOR UPDATE',[$id])->fetch();if(!$topic){$db->rollBack();fail(404,'主题不存在或已删除');}$categoryId=(int)$topic['category_id'];$postIds=array_map('intval',array_column(h('SELECT id FROM posts WHERE topic_id=? FOR UPDATE',[$id])->fetchAll(),'id'));extension_event_sync('topic.deleting',['topic_id'=>$id,'category_id'=>$categoryId,'actor_id'=>$actorId,'post_ids'=>$postIds]);$where='(ref_type="topic" AND ref_id=?)';$args=[$id];if($postIds){$marks=implode(',',array_fill(0,count($postIds),'?'));$where.=' OR (ref_type="post" AND ref_id IN ('.$marks.'))';$args=array_merge($args,$postIds);}$deltas=h('SELECT user_id,SUM(delta) AS delta FROM point_ledger WHERE '.$where.' GROUP BY user_id FOR UPDATE',$args)->fetchAll();foreach($deltas as $delta)h('UPDATE users SET points=points-? WHERE id=?',[(int)$delta['delta'],(int)$delta['user_id']]);h('DELETE FROM point_ledger WHERE '.$where,$args);h('DELETE FROM notifications WHERE topic_id=?',[$id]);if($postIds){$marks=implode(',',array_fill(0,count($postIds),'?'));h('DELETE FROM notifications WHERE post_id IN ('.$marks.')',$postIds);h('DELETE FROM post_revisions WHERE post_id IN ('.$marks.')',$postIds);}h('DELETE FROM posts WHERE topic_id=?',[$id]);h('DELETE FROM topics WHERE id=?',[$id]);audit('topic.permanently_deleted','topic',$id);extension_event_async('topic.deleted',['topic_id'=>$id,'category_id'=>$categoryId,'post_ids'=>$postIds,'actor_id'=>$actorId]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}invalidate('topic:'.$id);invalidate_topic_listing_cache($categoryId,true); }
function moderate_topic(int $id,string $column,int $value,string $action): void { if($id<1||!in_array($column,['is_pinned','is_locked'],true)||!in_array($value,[0,1],true))fail(400,'操作无效');$topic=h('SELECT category_id FROM topics WHERE id=?',[$id])->fetch();if(!$topic)fail(404,'主题不存在或已删除');h('UPDATE topics SET '.$column.'=? WHERE id=?',[$value,$id]);audit('topic.'.$action,'topic',$id);extension_event_async('topic.moderated',['topic_id'=>$id,'category_id'=>(int)$topic['category_id'],'action'=>$action,'value'=>$value,'actor_id'=>(int)(current_user()['id']??0)]);invalidate_topic_listing_cache((int)$topic['category_id']); }
function cache_error(string $message): void { error_log('cache: '.$message); }
function asset_version(string $file): string { static $versions=[];if(isset($versions[$file]))return $versions[$file];$modified=@filemtime($file);$size=@filesize($file);if($modified===false||$size===false)throw new RuntimeException('Asset is unavailable.');$key='asset-version:v1:'.hash('sha256',$file.'|'.$modified.'|'.$size);$value=cached($key,86400,static function()use($file):array{$hash=hash_file('sha256',$file);if($hash===false)throw new RuntimeException('Cannot hash asset.');return [substr($hash,0,12)];});return $versions[$file]=(string)($value[0]??''); }
function redis_cache_client(): ?Redis {
    static $client = false;
    if ($client !== false) return $client;
    $host = getenv('LITEBBS_REDIS_HOST');
    if ($host === false || $host === '') return $client = null;
    if (!class_exists(Redis::class)) { cache_error('Redis is configured but ext-redis is unavailable'); return $client = null; }
    try {
        $redis = new Redis();
        $timeout = (float)(getenv('LITEBBS_REDIS_TIMEOUT') ?: 0.25);
        if ($timeout <= 0 || $timeout > 5) $timeout = 0.25;
        if (!$redis->connect($host, (int)(getenv('LITEBBS_REDIS_PORT') ?: 6379), $timeout)) throw new RuntimeException('connect failed');
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        $password = getenv('LITEBBS_REDIS_PASSWORD');
        if ($password !== false && $password !== '' && !$redis->auth($password)) throw new RuntimeException('authentication failed');
        $database = (int)(getenv('LITEBBS_REDIS_DATABASE') ?: 0);
        if ($database !== 0 && !$redis->select($database)) throw new RuntimeException('database selection failed');
        return $client = $redis;
    } catch (Throwable $error) { $state=&request_cache_state();$state['@redis-failed']=true;cache_error('Redis unavailable: '.$error->getMessage()); return $client = null; }
}
function shared_cache_key(string $key): string { return (getenv('LITEBBS_REDIS_PREFIX') ?: 'litebbs').':'.hash('sha256', $key); }
function &request_cache_state(): array { static $state=[];return $state; }
function write_cache(string $key,array $data,int $ttl=300): void { $state=&request_cache_state();$state[$key]=['expires'=>time()+min($ttl,300),'value'=>$data];try{$encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(Throwable $error){cache_error('cannot encode cache value');return;} $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();if($redis){try{$redis->setex(shared_cache_key($key),max(1,$ttl),$encoded);return;}catch(Throwable $error){cache_error('Redis write failed: '.$error->getMessage());if($redisConfigured)return;}}elseif($redisConfigured){return;} $dir=dirname(__DIR__).'/cache';if(!is_dir($dir)&&!@mkdir($dir,0700,true)){cache_error('cannot create cache directory');return;}$path=$dir.'/'.hash('sha256',$key).'.cache';$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';if(@file_put_contents($tmp,$encoded,LOCK_EX)===false){cache_error('cannot write cache temporary file');return;}if(!@rename($tmp,$path)){if(is_file($path))@unlink($path);if(!@rename($tmp,$path)){@unlink($tmp);cache_error('cannot rename cache temporary file');}} }
function read_cache(string $key,int $ttl=300): ?array { $state=&request_cache_state();if(isset($state[$key])){if($state[$key]['expires']>=time())return $state[$key]['value'];unset($state[$key]);}$redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();if($redisConfigured&&!$redis)return null;if($redis){try{$raw=$redis->get(shared_cache_key($key));$data=is_string($raw)?json_decode($raw,true):null;if(is_array($data)){$state[$key]=['expires'=>time()+min($ttl,300),'value'=>$data];return $data;}return null;}catch(Throwable $error){$state['@redis-failed']=true;cache_error('Redis read failed: '.$error->getMessage());return null;}}$path=dirname(__DIR__).'/cache/'.hash('sha256',$key).'.cache';if(!is_file($path))return null;$modified=filemtime($path);if($modified===false||$modified<time()-$ttl)return null;$data=json_decode((string)@file_get_contents($path),true);if(!is_array($data))return null;$state[$key]=['expires'=>min($modified+$ttl,time()+300),'value'=>$data];return $data; }
function public_cached(string $key,int $ttl,callable $load): array { return cached($key,$ttl,$load); }
function cached(string $key,int $ttl,callable $load): array { $value=read_cache($key,$ttl);if($value!==null)return $value;$redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();if($redisConfigured&&!$redis)return $load();if($redis){$lockKey=shared_cache_key('lock:'.$key);$token=bin2hex(random_bytes(16));$loaded=false;try{$owner=(bool)$redis->set($lockKey,$token,['nx','ex'=>5]);if(!$owner){$deadline=microtime(true)+0.5;do{usleep(random_int(10000,20000));$value=read_cache($key,$ttl);if($value!==null)return $value;}while(microtime(true)<$deadline);}$value=$load();if(!is_array($value))throw new RuntimeException('cache loader must return an array');$loaded=true;$redis->setex(shared_cache_key($key),max(1,$ttl),json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$state=&request_cache_state();$state[$key]=['expires'=>time()+min($ttl,300),'value'=>$value];return $value;}catch(Throwable $error){cache_error('Redis cache single-flight failed: '.$error->getMessage());if($loaded)return $value;throw $error;}finally{if(isset($owner)&&$owner){try{$redis->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0",[$lockKey,$token],1);}catch(Throwable $error){cache_error('Redis cache lock release failed: '.$error->getMessage());}}}}$dir=dirname(__DIR__).'/cache';if(!is_dir($dir)&&!@mkdir($dir,0700,true)){cache_error('cannot create cache directory');return $load();}$path=$dir.'/'.hash('sha256',$key).'.cache';$lock=@fopen(cache_lock_path($key),'c');if(!$lock){cache_error('cannot open cache lock');return $load();}$owner=false;$deadline=microtime(true)+0.1;do{$owner=flock($lock,LOCK_EX|LOCK_NB);if(!$owner){usleep(random_int(5000,10000));$value=read_cache($key,$ttl);if($value!==null){fclose($lock);return $value;}}}while(!$owner&&microtime(true)<$deadline);if(!$owner){fclose($lock);$value=$load();if(!is_array($value))throw new RuntimeException('cache loader must return an array');return $value;}try{$value=read_cache($key,$ttl);if($value!==null)return $value;$value=$load();if(!is_array($value))throw new RuntimeException('cache loader must return an array');write_cache($key,$value,$ttl);return $value;}finally{flock($lock,LOCK_UN);fclose($lock);} }
function cache_lock_path(string $key): string {
    // Locks must be per cache key. Sharing a small hash prefix serializes
    // unrelated cold-cache requests when Redis is not configured.
    return dirname(__DIR__).'/cache/active-lock-'.hash('sha256',$key).'.lock';
}
function page_cache_read(string $key, int $ttl): ?array {
    $value=read_cache($key,$ttl);if($value!==null)return $value;
    $state=&request_cache_state();$stateKey='@page-lock:'.$key;
    $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();
    if($redisConfigured&&!$redis)return null;
    if($redis){$lockKey=shared_cache_key('page-lock:'.$key);$token=bin2hex(random_bytes(16));try{$owner=(bool)$redis->set($lockKey,$token,['nx','ex'=>10]);if($owner){$state[$stateKey]=['redis'=>$redis,'key'=>$lockKey,'token'=>$token];page_cache_register_shutdown();return null;}$deadline=microtime(true)+0.15;do{usleep(random_int(5000,10000));$value=read_cache($key,$ttl);if($value!==null)return $value;}while(microtime(true)<$deadline);}catch(Throwable $error){cache_error('page cache single-flight failed: '.$error->getMessage());}return null;}
    $dir=dirname(__DIR__).'/cache';if(!is_dir($dir)&&!@mkdir($dir,0700,true))return null;$lock=@fopen(cache_lock_path($key),'c');if(!$lock)return null;$deadline=microtime(true)+0.15;do{if(flock($lock,LOCK_EX|LOCK_NB)){$value=read_cache($key,$ttl);if($value!==null){flock($lock,LOCK_UN);fclose($lock);return $value;}$state[$stateKey]=['file'=>$lock];page_cache_register_shutdown();return null;}usleep(random_int(5000,10000));$value=read_cache($key,$ttl);if($value!==null){fclose($lock);return $value;}}while(microtime(true)<$deadline);fclose($lock);return null;
}
function page_cache_register_shutdown(): void {
    static $registered=false;
    if($registered)return;
    $registered=true;
    register_shutdown_function(static function():void {
        $state=&request_cache_state();
        foreach(array_keys($state) as $key)if(str_starts_with($key,'@page-lock:'))page_cache_release($key);
    });
}
function page_cache_release(string $stateKey): void {
    $state=&request_cache_state();$lock=$state[$stateKey]??null;unset($state[$stateKey]);
    if(!is_array($lock))return;
    if(isset($lock['redis'])){try{$lock['redis']->eval("if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) end return 0",[$lock['key'],$lock['token']],1);}catch(Throwable $error){cache_error('page cache lock release failed: '.$error->getMessage());}}
    elseif(isset($lock['file'])&&is_resource($lock['file'])){flock($lock['file'],LOCK_UN);fclose($lock['file']);}
}
function page_cache_write(string $key, array $data, int $ttl): void {
    $state=&request_cache_state();$stateKey='@page-lock:'.$key;
    // A request that timed out waiting for the cache owner must not replace its result.
    if (!isset($state[$stateKey])) return;
    try { write_cache($key,$data,$ttl); } finally {page_cache_release($stateKey);}
}
function cached_topic_count(string $scope,?int $categoryId=null): int { if($categoryId===null){$key='topic-count:v2:'.$scope.':'.cache_version('topics').':'.cache_version('categories');}else{$key='topic-count:v2:'.$scope.':'.$categoryId.':'.cache_version('category-topics:'.$categoryId);}$values=cached($key,30,static function() use($categoryId): array {$sql=$categoryId===null?'SELECT COUNT(*) FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1':'SELECT COUNT(*) FROM topics WHERE category_id=? AND status="normal"';$count=$categoryId===null?h($sql)->fetchColumn():h($sql,[$categoryId])->fetchColumn();return [(int)$count];});return (int)($values[0]??0); }
function cached_sitemap_max_topic_id(): int { $versions=cache_versions(['topics','categories','sitemap']);$key='sitemap:max-topic-id:v1:'.(int)$versions['topics'].':'.(int)$versions['categories'].':'.(int)$versions['sitemap'];$value=public_cached($key,3600,static function():array{return [(int)h('SELECT COALESCE(MAX(t.id),0) FROM topics t JOIN categories c ON c.id=t.category_id WHERE t.status="normal" AND c.is_visible=1')->fetchColumn()];});return (int)($value[0]??0); }
function topic_last_post_pointer_available(): bool { $value=cached('schema:topics:last-post-pointer',60,static function():array{return [(bool)h('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="topics" AND column_name="last_post_id" LIMIT 1')->fetchColumn()];});return !empty($value[0]); }
function migrate_topic_last_post_pointer(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-last-post-pointer']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        $column=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="topics" AND column_name="last_post_id" LIMIT 1');$column->execute();
        if ($column->fetchColumn()) { write_cache('schema:topics:last-post-pointer',[true],60); return false; }
        $pdo->exec('ALTER TABLE topics ADD COLUMN last_post_id BIGINT UNSIGNED NULL AFTER view_count');
        $pdo->exec('UPDATE topics t LEFT JOIN (SELECT topic_id,MAX(id) AS post_id FROM posts WHERE status="normal" GROUP BY topic_id) p ON p.topic_id=t.id SET t.last_post_id=p.post_id');
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');$migration->execute(['3.2.0-last-post-pointer']);
        write_cache('schema:topics:last-post-pointer',[true],60);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-last-post-pointer']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function private_messages_sender_index_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $index=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name="private_messages" AND index_name="ix_messages_sender" LIMIT 1');
    $index->execute();
    return (bool)$index->fetchColumn();
}
function migrate_private_messages_sender_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:private-messages-sender-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (private_messages_sender_index_available($pdo)) return false;
        $pdo->exec('ALTER TABLE private_messages ADD INDEX ix_messages_sender (sender_id,id)');
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');$migration->execute(['3.3.0-private-messages-sender-index']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:private-messages-sender-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function private_messages_conversation_reverse_index_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $index=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name="private_messages" AND index_name="ix_messages_conversation_reverse" LIMIT 1');
    $index->execute();
    return (bool)$index->fetchColumn();
}
function migrate_private_messages_conversation_reverse_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:private-messages-conversation-reverse-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (private_messages_conversation_reverse_index_available($pdo)) return false;
        $pdo->exec('ALTER TABLE private_messages ADD INDEX ix_messages_conversation_reverse (recipient_id,sender_id,id)');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.6.0-message-conversation-index']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:private-messages-conversation-reverse-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function migrate_performance_indexes(?PDO $pdo=null): array {
    $pdo=$pdo??db();
    return ['last_post_pointer'=>migrate_topic_last_post_pointer($pdo),'private_messages_sender_index'=>migrate_private_messages_sender_index($pdo),'private_messages_conversation_reverse_index'=>migrate_private_messages_conversation_reverse_index($pdo),'topic_participants'=>migrate_topic_participants($pdo),'topic_search_prefix_index'=>migrate_topic_search_prefix_index($pdo),'queue_cleanup_indexes'=>migrate_queue_cleanup_indexes($pdo),'users_status_created_index'=>migrate_users_status_created_index($pdo),'topic_participants_duplicate_index_removed'=>migrate_topic_participants_duplicate_index($pdo)];
}
function database_index_available(PDO $pdo,string $table,string $index): bool {
    $statement=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
    $statement->execute([$table,$index]);
    return (bool)$statement->fetchColumn();
}
function users_status_created_index_available(?PDO $pdo=null): bool {
    return database_index_available($pdo??db(),'users','ix_users_status_created');
}
function migrate_users_status_created_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    if(users_status_created_index_available($pdo))return false;
    $pdo->exec('ALTER TABLE users ADD INDEX ix_users_status_created (status,created_at,id)');
    $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.8.0-runtime-performance']);
    return true;
}
function topic_participants_duplicate_index_removed(?PDO $pdo=null): bool {
    return !database_index_available($pdo??db(),'topic_participants','ix_topic_participants_topic_user');
}
function migrate_topic_participants_duplicate_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    if(topic_participants_duplicate_index_removed($pdo))return false;
    $pdo->exec('ALTER TABLE topic_participants DROP INDEX ix_topic_participants_topic_user');
    $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.8.0-runtime-performance']);
    return true;
}
function queue_cleanup_indexes_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    foreach(['outbox_events'=>'ix_outbox_claim_ready','failed_outbox_events'=>'ix_failed_outbox_event_failed','extension_jobs'=>'ix_extension_jobs_claim_ready','extension_job_failures'=>'ix_extension_job_failures_failed'] as $table=>$index)if(!database_index_available($pdo,$table,$index))return false;
    return true;
}
function migrate_queue_cleanup_indexes(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:queue-cleanup-indexes']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        $indexes=['outbox_events'=>['ix_outbox_claim_ready'=>'processed_at,lease_until,available_at,id','ix_outbox_processed'=>'processed_at,id'],'failed_outbox_events'=>['ix_failed_outbox_event_failed'=>'failed_at,id'],'extension_jobs'=>['ix_extension_jobs_claim_ready'=>'completed_at,lease_until,available_at,id','ix_extension_jobs_completed'=>'completed_at,id'],'extension_job_failures'=>['ix_extension_job_failures_failed'=>'failed_at,id']];
        $changed=false;
        foreach($indexes as $table=>$list)foreach($list as $name=>$columns)if(!database_index_available($pdo,$table,$name)){$pdo->exec('ALTER TABLE `'.$table.'` ADD INDEX `'.$name.'` ('.$columns.')');$changed=true;}
        $migration=$pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))');$migration->execute(['3.7.0-queue-cleanup-indexes']);
        return $changed;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:queue-cleanup-indexes']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_search_prefix_index_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $index=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name="topics" AND index_name="ix_topics_search_prefix" LIMIT 1');
    $index->execute();
    return (bool)$index->fetchColumn();
}
function migrate_topic_search_prefix_index(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-search-prefix-index']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (topic_search_prefix_index_available($pdo)) return false;
        $pdo->exec('ALTER TABLE topics ADD INDEX ix_topics_search_prefix (status,title)');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.5.0-topic-search-prefix-index']);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-search-prefix-index']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_participants_available(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $value=cached('schema:topic-participants',60,static function() use($pdo): array {$table=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="topic_participants" LIMIT 1');$table->execute();return [(bool)$table->fetchColumn()];});
    return !empty($value[0]);
}
function migrate_topic_participants(?PDO $pdo=null): bool {
    $pdo=$pdo??db();
    $lock=$pdo->prepare('SELECT GET_LOCK(?, 5)');$lock->execute(['litebbs:topic-participants']);
    if (!(bool)$lock->fetchColumn()) throw new RuntimeException('数据库优化正在由另一项任务执行，请稍后重试。');
    try {
        if (topic_participants_available($pdo)) return false;
        $pdo->exec('CREATE TABLE topic_participants(topic_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,last_post_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(topic_id,user_id),FOREIGN KEY(topic_id) REFERENCES topics(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('INSERT INTO topic_participants(topic_id,user_id,last_post_id) SELECT topic_id,user_id,MAX(id) FROM posts WHERE status="normal" GROUP BY topic_id,user_id ON DUPLICATE KEY UPDATE last_post_id=GREATEST(last_post_id,VALUES(last_post_id))');
        $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES(?,UTC_TIMESTAMP(6))')->execute(['3.4.0-topic-participants']);
        write_cache('schema:topic-participants',[true],60);
        return true;
    } finally {
        try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute(['litebbs:topic-participants']); } catch (Throwable $error) { cache_error('database migration lock release failed: '.$error->getMessage()); }
    }
}
function topic_last_post_join_sql(): string { return topic_last_post_pointer_available() ? 'LEFT JOIN posts rp ON rp.id=t.last_post_id' : 'LEFT JOIN posts rp ON rp.id=(SELECT MAX(p.id) FROM posts p WHERE p.topic_id=t.id AND p.status="normal")'; }
function anonymous_topic_view_is_new(int $topicId): bool {
    $now = time();
    $seen = [];
    foreach (explode(',', (string)($_COOKIE['lbbs_topic_views'] ?? '')) as $entry) {
        [$seenId, $expiresAt] = array_pad(explode(':', $entry, 2), 2, '');
        if (ctype_digit($seenId) && ctype_digit($expiresAt) && (int)$expiresAt > $now) $seen[(int)$seenId] = (int)$expiresAt;
    }
    if (isset($seen[$topicId])) return false;
    $seen[$topicId] = $now + 60;
    arsort($seen, SORT_NUMERIC);
    $seen = array_slice($seen, 0, 20, true);
    $value = implode(',', array_map(static fn(int $id, int $expiresAt): string => $id.':'.$expiresAt, array_keys($seen), $seen));
    // Keep this anonymous view-deduplication cookie off listing routes so it
    // does not fragment their public cache variants.
    setcookie('lbbs_topic_views', $value, ['expires'=>$now + 60, 'path'=>'/t/', 'secure'=>request_is_https(), 'httponly'=>true, 'samesite'=>'Lax']);
    return true;
}
function count_topic_view(int $id): void { $redis=redis_cache_client();$sampleRate=max(1,min(100,(int)(getenv('LITEBBS_TOPIC_VIEW_SAMPLE_RATE')?:10)));if($redis){try{$viewer=current_user();$identity=$viewer?'user:'.(int)$viewer['id']:'ip:'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));if($viewer)$sampleRate=1;if($sampleRate>1&&(hexdec(substr(hash('sha256',$id.'|'.$identity.'|'.intdiv(time(),60)),0,8))%$sampleRate)!==0)return;$seen=shared_cache_key('topic-view-seen:'.$id.':'.$identity);if($redis->set($seen,'1',['nx','ex'=>60]))$redis->hIncrBy(shared_cache_key('topic-views'),(string)$id,$sampleRate);return;}catch(Throwable $error){cache_error('topic view increment failed: '.$error->getMessage());}}if((getenv('LITEBBS_REDIS_HOST')?:'')!=='')return;if(!anonymous_topic_view_is_new($id))return;$identity=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'').'|'.substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200));if($sampleRate>1&&(hexdec(substr(hash('sha256',$id.'|'.$identity.'|'.intdiv(time(),60)),0,8))%$sampleRate)!==0)return;$root=dirname(__DIR__).'/cache/topic-views';if((!is_dir($root)&&!@mkdir($root,0700,true))||!is_dir($root))return;$lock=@fopen(dirname($root).'/topic-views.lock','c');if(!$lock)return;try{if(!flock($lock,LOCK_SH))return;@file_put_contents($root.'/'.$id.'.cache',str_repeat('.',$sampleRate),FILE_APPEND|LOCK_EX);}finally{flock($lock,LOCK_UN);fclose($lock);} }
function local_cache_version(string $key): int { $path=dirname(__DIR__).'/cache/version-'.hash('sha256',$key);return is_file($path)?max(1,(int)@file_get_contents($path)):1; }
function mirror_cache_version(string $key,int $version): void { $dir=dirname(__DIR__).'/cache';if(!is_dir($dir)&&!@mkdir($dir,0700,true))return;$path=$dir.'/version-'.hash('sha256',$key);$lock=@fopen($path.'.lock','c');if(!$lock)return;if(flock($lock,LOCK_EX)){$current=is_file($path)?max(1,(int)@file_get_contents($path)):1;@file_put_contents($path,(string)max($current,$version),LOCK_EX);flock($lock,LOCK_UN);}fclose($lock); }
function cache_versions(array $keys): array { $state=&request_cache_state();$keys=array_values(array_unique(array_filter($keys,static fn(mixed $key): bool=>is_string($key)&&$key!=='')));$versions=[];$missing=[];foreach($keys as $key){$cacheKey='@version:'.$key;if(isset($state[$cacheKey]))$versions[$key]=(int)$state[$cacheKey]['value'];else $missing[]=$key;}if(!$missing)return $versions;$redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();if($redis){try{$values=$redis->mGet(array_map(static fn(string $key): string=>shared_cache_key('@version:'.$key),$missing));foreach($missing as $index=>$key){$version=max(1,(int)($values[$index]??1));$state['@version:'.$key]=['expires'=>PHP_INT_MAX,'value'=>$version];$versions[$key]=$version;}return $versions;}catch(Throwable $error){cache_error('Redis version batch read failed: '.$error->getMessage());}}foreach($missing as $key){$version=local_cache_version($key);$state['@version:'.$key]=['expires'=>PHP_INT_MAX,'value'=>$version];$versions[$key]=$version;}return $versions; }
function cache_version(string $key): int { $versions=cache_versions([$key]);return (int)($versions[$key]??1); }
function invalidate(string $key): void { $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();$next=null;if($redisConfigured&&!$redis){cache_error('shared Redis unavailable; cache invalidation skipped');return;}if($redis){try{$versionKey=shared_cache_key('@version:'.$key);$next=(int)$redis->eval("if redis.call('EXISTS',KEYS[1])==0 then redis.call('SET',KEYS[1],1) end; return redis.call('INCR',KEYS[1])",[$versionKey],1);mirror_cache_version($key,$next);}catch(Throwable $error){cache_error('Redis version write failed: '.$error->getMessage());if($redisConfigured)return;}}if($next===null){$next=local_cache_version($key)+1;mirror_cache_version($key,$next);}$state=&request_cache_state();$state['@version:'.$key]=['expires'=>PHP_INT_MAX,'value'=>$next];foreach(array_keys($state) as $cacheKey)if($cacheKey!==('@version:'.$key)&&strpos($cacheKey,$key)!==false)unset($state[$cacheKey]); }
function invalidate_user_cache(int $userId): void { invalidate('unread-user:'.$userId); }
function invalidate_user_caches(array $userIds, bool $allowTtlFallback=false): void {
    $userIds=array_values(array_unique(array_filter(array_map('intval',$userIds),static fn(int $id):bool=>$id>0)));
    if(!$userIds)return;
    if(count($userIds)===1){invalidate_user_cache($userIds[0]);return;}
    $redisConfigured=(getenv('LITEBBS_REDIS_HOST')?:'')!=='';$redis=redis_cache_client();
    if(!$redis){
        // Fan-out can touch hundreds of users. The unread value already expires
        // after 10 seconds, so avoid hundreds of local version files and locks.
        if($allowTtlFallback&&!$redisConfigured)return;
        foreach($userIds as $userId)invalidate_user_cache($userId);
        return;
    }
    try{
        $pipeline=$redis->multi(Redis::PIPELINE);
        foreach($userIds as $userId){$key=shared_cache_key('@version:unread-user:'.$userId);$pipeline->setNx($key,1);$pipeline->incr($key);}
        $results=$pipeline->exec();
        $state=&request_cache_state();
        foreach($userIds as $index=>$userId){$version=max(2,(int)($results[$index*2+1]??2));$state['@version:unread-user:'.$userId]=['expires'=>PHP_INT_MAX,'value'=>$version];}
    }catch(Throwable $error){
        cache_error('Redis user cache batch invalidation failed: '.$error->getMessage());
        if(!$redisConfigured)foreach($userIds as $userId)invalidate_user_cache($userId);
    }
}
function invalidate_topic_listing_cache(int $categoryId, bool $countChanged=false): void { invalidate('home-feed');if($categoryId>0){invalidate('category-feed:'.$categoryId);if($countChanged)invalidate('category-topics:'.$categoryId);}if($countChanged)invalidate('topics'); }
function invalidate_topic_read_cache(int $topicId, ?int $categoryId = null, bool $invalidateListings = true, bool $invalidateSitemap = true): void { if ($categoryId === null) $categoryId=(int)h('SELECT category_id FROM topics WHERE id=?',[$topicId])->fetchColumn();invalidate('topic:'.$topicId);if($invalidateSitemap)invalidate('sitemap');if($invalidateListings)invalidate_topic_listing_cache($categoryId); }
function slug(string $s): string { $lower=function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);$s=trim((string)preg_replace('/[^\pL\pN]+/u','-',$lower),'-');return $s?:'topic'; }
function admin_nav(): string { return '<nav class="adminnav"><a href="/admin">总览</a><a href="/admin/content">内容</a><a href="/admin/users">用户</a><a href="/admin/categories">分区</a><a href="/admin/themes">模板</a><a href="/admin/plugins">插件</a><a href="/admin/market">扩展审核</a><a href="/admin/audit">审计</a><a href="/admin/settings">设置</a><a href="/admin/extensions">程序更新</a></nav>'; }
function render_template(string $file, array $variables): void {
    $basename=basename($file);$isLayout=$basename==='layout.php';$isAdmin=$basename==='admin-layout.php';
    if(function_exists('theme_render_view'))$html=theme_render_view($isLayout?'layout':pathinfo($basename,PATHINFO_FILENAME),$variables,$file,!$isAdmin);
    else{extract($variables,EXTR_SKIP);ob_start();include $file;$html=(string)ob_get_clean();}
    if (function_exists('seo_inject_head')) $html = seo_inject_head($html, $variables);
    echo $html;
}
function page(string $title,string $body,string $description='轻量、开放的社区讨论',bool $indexable=false,bool $isAdmin=false): void { $root=dirname(__DIR__);$installed=is_file(install_lock_file())&&is_file(config_file());$config=$installed?load_config():['site'=>'LiteBBS','theme'=>'default','url'=>''];$theme=(string)($config['theme']??'default');if(!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$theme))$theme='default';$themeDir=$root.'/templates/'.$theme;if(!$isAdmin&&!extension_theme_runtime_valid($themeDir,$theme)){error_log('active theme failed runtime validation: '.$theme);$theme='default';$themeDir=$root.'/templates/default';if(!extension_theme_runtime_valid($themeDir,'default'))throw new RuntimeException('默认模板校验失败');}$user=$installed?current_user():null;if($user||!$indexable)header('Cache-Control: private, no-store');$scheme=request_is_https()?'https':'http';$host=(string)($_SERVER['HTTP_HOST']??'localhost');$requestPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$canonical=rtrim((string)($config['url']??($scheme.'://'.$host)),'/').$requestPath;$adminPage=$isAdmin||strpos($requestPath,'/admin')===0;$left='';if($installed&&!$adminPage){$version=cache_version('categories');$categories=cached('categories:nav:'.$version,60,static function(): array{return h('SELECT id,title FROM categories WHERE is_visible=1 ORDER BY position,id')->fetchAll();});foreach($categories as $category){$id=(int)$category['id'];$left.='<a class="nav-item'.($requestPath==='/c/'.$id?' active':'').'" href="/c/'.$id.'"><span>'.e($category['title']).'</span></a>';}}$variables=['title'=>$title,'body'=>$body,'description'=>$description,'indexable'=>$indexable,'canonical'=>$canonical,'requestPath'=>$requestPath,'c'=>['site'=>(string)($config['site']??'LiteBBS'),'url'=>(string)($config['url']??''),'theme'=>$theme,'locale'=>(string)($config['locale']??'zh-CN')],'isAdminPage'=>$adminPage,'isHomePage'=>$requestPath==='/','isTopicPage'=>preg_match('~^/t/\d+~',$requestPath)===1,'user'=>$user,'safe'=>$theme,'left'=>$left,'right'=>'','active'=>''];render_template($adminPage?$root.'/app/admin-layout.php':$themeDir.'/layout.php',$variables);exit; }
