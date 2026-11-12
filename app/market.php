<?php
declare(strict_types=1);

const LITEBBS_UPDATE_REPOSITORY = 'https://github.com/Lfreepayzz/LiteBBS/';
const LITEBBS_UPDATE_RAW_BASE = 'https://raw.githubusercontent.com/Lfreepayzz/LiteBBS/main';

    if ($path === '/market/index.json' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $envelope = cached('market:index:v1:'.cache_version('market'), 60, static function (): array {
        $items=h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id LIMIT 5000')->fetchAll();
        $data=['schema'=>1,'generated'=>gmdate('c'),'items'=>$items];
        $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $private=market_signing_key();
        $signature='';if($private&&openssl_sign($json,$raw,$private,OPENSSL_ALGO_SHA256))$signature=base64_encode($raw);
        return ['payload'=>$data,'signature'=>$signature,'algorithm'=>'RSA-SHA256'];
    });
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if (preg_match('~^/market/package/(\d+)$~',$path,$m)&&$method==='GET') {
    $item=h('SELECT package_file,sha256,kind,package_name FROM market_submissions WHERE id=? AND status="approved"',[(int)$m[1]])->fetch();
    if(!$item)fail(404,'审核通过的扩展不存在');
    if(!preg_match('/^[a-f0-9]{48}\.zip$/',$item['package_file']))fail(404,'扩展包不存在');$file=__DIR__.'/../market-packages/'.$item['package_file'];
    if(!is_file($file)||!hash_equals($item['sha256'],hash_file('sha256',$file)))fail(410,'扩展包校验失败');
    header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($item['package_name']).'.zip"');header('Content-Length: '.filesize($file));readfile($file);exit;
}
if ($path === '/market' && $method === 'GET') {
    $items = h('SELECT id,kind,package_name,title,version,summary,sha256 FROM market_submissions WHERE status="approved" ORDER BY id DESC LIMIT 100')->fetchAll();
    $user = current_user();
    $body = '<header class="market-heading"><div><p class="eyebrow">DEVELOPER COMMUNITY</p><h1>开发者</h1><p>发现社区开发者提交的插件与主题。所有作品均经过管理员审核后公开。</p></div><a class="primary market-submit-link" href="'.($user ? '/market/submit' : '/login').'">'.($user ? '发布作品' : '登录后发布').'</a></header><section class="market-grid" aria-label="已审核开发者作品">';
    if (!$items) $body .= '<div class="market-empty"><h2>还没有公开作品</h2><p>成为第一个向社区分享插件或主题的开发者。</p>'.($user ? '<a href="/market/submit">提交一个作品</a>' : '<a href="/login">登录后提交</a>').'</div>';
    foreach ($items as $item) {
        $kind = $item['kind'] === 'theme' ? '主题' : '插件';
        $body .= '<article class="market-card"><div class="market-card-top"><span class="market-kind">'.e($kind).'</span><span class="market-version">v'.e($item['version']).'</span></div><h2>'.e($item['title']).'</h2><p class="market-package">'.e($item['package_name']).'</p><p>'.e($item['summary']).'</p><a class="market-download" href="/market/package/'.(int)$item['id'].'">下载作品 <span aria-hidden="true">↓</span></a></article>';
    }
    $body .= '</section>';
    page('开发者',$body,'社区开发者作品',false);
    exit;
}
if ($path === '/market/submit' && in_array($method,['GET','POST'],true)) {
    $user=current_user();
    if (!$user) redirect('/login');
    if($method==='POST'){
        require_post();limit_action('market-submit',3,3600,'user:'.$user['id']);
        $kind=(string)($_POST['kind']??'');$name=(string)($_POST['package_name']??'');$title=trim((string)($_POST['title']??''));$version=trim((string)($_POST['version']??''));$summary=trim((string)($_POST['summary']??''));$upload=$_FILES['package']??null;
        if(!in_array($kind,['plugin','theme'],true)||!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$name)||!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',$version)||text_length($title)<2||text_length($title)>100||text_length($summary)>500||!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($upload['size']??0)>5242880||!is_uploaded_file($upload['tmp_name'])||strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION))!=='zip')fail(400,'提交信息无效，ZIP 文件上限为 5 MiB');
        $dir=__DIR__.'/../market-packages';if(!is_dir($dir)&&!mkdir($dir,0750,true))fail(500,'市场存储目录不可写');$stored=bin2hex(random_bytes(24)).'.zip';$target=$dir.'/'.$stored;if(!move_uploaded_file($upload['tmp_name'],$target))fail(500,'无法保存扩展包');try{market_validate_package($target,$kind,$name);}catch(Throwable $e){@unlink($target);fail(400,'扩展包结构无效：'.$e->getMessage());}$hash=hash_file('sha256',$target);
        try{h('INSERT INTO market_submissions(user_id,kind,package_name,title,version,summary,package_file,sha256,status,created_at) VALUES(?,?,?,?,?,?,?,? ,"pending",UTC_TIMESTAMP(6))',[$user['id'],$kind,$name,$title,$version,$summary,$stored,$hash]);audit('market.submitted','market',(int)db()->lastInsertId(),['kind'=>$kind,'name'=>$name]);}catch(Throwable $e){@unlink($target);throw $e;}redirect('/market/submit');
    }
    $rows=h('SELECT kind,title,version,status,review_note,created_at FROM market_submissions WHERE user_id=? ORDER BY id DESC LIMIT 50',[$user['id']])->fetchAll();
    $fields='<label>类型<select name="kind"><option value="plugin">插件</option><option value="theme">主题</option></select></label><label>作品 ID<input name="package_name" pattern="[a-z][a-z0-9_-]{1,31}" required></label><label>显示名称<input name="title" maxlength="100" required></label><label>版本（x.y.z）<input name="version" pattern="[0-9]+\\.[0-9]+\\.[0-9]+" required></label><label>简介<input name="summary" maxlength="500"></label><label>ZIP 包<input type="file" name="package" accept=".zip" required></label>';
    $body='<section class="market-submit"><a class="market-back" href="/market">← 返回开发者</a><h1>发布作品</h1><p>提交插件或主题后，管理员会进行审核。审核通过后会显示在开发者页面。ZIP 文件上限为 5 MiB，作品代码会以 PHP 进程权限运行，请只提交自己审查过的代码。</p><form class="form" method="post" enctype="multipart/form-data" action="/market/submit"><input type="hidden" name="csrf" value="'.e(csrf()).'">'.$fields.'<button class="primary">提交审核</button></form><h2>我的提交</h2>';
    if (!$rows) $body .= '<p class="admin-intro">你还没有提交过作品。</p>';
    foreach ($rows as $r) { $status = ['pending'=>'审核中','approved'=>'已通过','rejected'=>'已拒绝'][$r['status']] ?? $r['status']; $body .= '<p class="market-submission">'.e($r['title']).' · '.e($r['version']).' · '.e($status).($r['review_note'] !== '' ? ' · '.e($r['review_note']) : '').'</p>'; }
    page('发布作品',$body.'</section>');
}

if ($path==='/admin/extensions'&&in_array($method,['GET','POST'],true)) {
    require_once __DIR__.'/core-updater.php';
    $actor=admin();$config=load_config();
    if($method==='POST'){
        require_post();$action=(string)($_POST['action']??'');
        if($action==='install')fail(403,'网页端扩展安装已禁用。请在隔离环境审查代码后，通过受控部署流程安装。');
        if($action==='check-update'){unset($_SESSION['pending_core_update']);try{$updateResult=check_repository_update($config);if(!empty($updateResult['commit']))$_SESSION['pending_core_update']=$updateResult['commit'];}catch(Throwable $e){$updateResult=['message'=>'更新检查失败：'.text_slice($e->getMessage(),0,200)];}$_SESSION['update_result']=$updateResult;redirect('/admin/extensions');}
        if($action==='apply-update'){$commit=(string)($_POST['commit']??'');$pending=(string)($_SESSION['pending_core_update']??'');if(!preg_match('/^[a-f0-9]{40}$/',$commit)||$pending===''||!hash_equals($pending,$commit))fail(409,'更新版本已过期，请重新检查更新');try{$result=apply_core_update($commit,$config);unset($_SESSION['pending_core_update']);$_SESSION['update_result']=$result;}catch(Throwable $e){$_SESSION['update_result']=['message'=>'自动更新失败：'.text_slice($e->getMessage(),0,300)];}redirect('/admin/extensions');}
        fail(400,'扩展操作无效');
    }
    $body='<section class="market-review"><h2>程序更新</h2><p>官方仓库：<a href="'.e(LITEBBS_UPDATE_REPOSITORY).'" rel="nofollow noopener">'.e(LITEBBS_UPDATE_REPOSITORY).'</a></p><p>检查 GitHub <code>main</code> 分支并自动备份、下载和覆盖仓库中的程序及模板文件。不会覆盖私有配置、上传内容、缓存和市场包，也不会删除本地额外文件；包含数据库结构变更的版本会拒绝在线安装。</p><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="check-update"><button>检查更新</button></form>';
    if(!empty($_SESSION['update_result'])){$r=$_SESSION['update_result'];unset($_SESSION['update_result']);$body.='<p>'.e($r['message']??'检查完成').'</p>';if(!empty($r['url']))$body.='<p><a href="'.e($r['url']).'" rel="nofollow noopener">查看仓库提交</a></p>';if(!empty($r['commit'])&&hash_equals((string)($_SESSION['pending_core_update']??''),(string)$r['commit']))$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="apply-update"><input type="hidden" name="commit" value="'.e($r['commit']).'"><button class="primary">立即更新</button></form>';}$body.='</section>';
    page('程序更新',$body);exit;
}

if($path==='/admin/market'&&in_array($method,['GET','POST'],true)){
    $actor=admin();
    if($method==='POST'){require_post();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');$note=text_slice(trim((string)($_POST['review_note']??'')),0,500);if(!$id||!in_array($status,['approved','rejected'],true))fail(400,'审核操作无效');$row=h('SELECT * FROM market_submissions WHERE id=? AND status="pending"',[$id])->fetch();if(!$row)fail(404,'待审核项目不存在');$package=__DIR__.'/../market-packages/'.$row['package_file'];if($status==='approved'){if(!is_file($package)||!hash_equals($row['sha256'],hash_file('sha256',$package)))fail(400,'扩展包缺失或哈希不一致');market_validate_package($package,$row['kind'],$row['package_name']);}h('UPDATE market_submissions SET status=?,review_note=?,reviewed_at=UTC_TIMESTAMP(6) WHERE id=?',[$status,$note,$id]);if($status==='rejected')@unlink($package);invalidate('market');audit('market.reviewed','market',$id,['status'=>$status]);redirect('/admin/market');}
    $rows=h('SELECT m.*,u.username FROM market_submissions m JOIN users u ON u.id=m.user_id ORDER BY FIELD(m.status,"pending","approved","rejected"),m.id DESC LIMIT 100')->fetchAll();$body='<p class="admin-intro">审核仅代表本站信任该代码。获批扩展在安装站点以 PHP 进程权限运行，并非沙箱。</p>';foreach($rows as$r){$body.='<section class="market-review"><h2>'.e($r['title']).' <small>'.e($r['kind']).' · '.e($r['package_name']).' · '.e($r['version']).'</small></h2><p>'.e($r['summary']).' · 提交者 '.e($r['username']).' · SHA-256 <code>'.e($r['sha256']).'</code></p><p>状态：'.e($r['status']).' '.e($r['review_note']).'</p>';if($r['status']==='pending')foreach(['approved'=>'审核通过','rejected'=>'拒绝']as$st=>$label)$body.='<form method="post" class="inline"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="status" value="'.$st.'"><input name="review_note" maxlength="500" placeholder="审核说明"><button>'.$label.'</button></form>';$body.='</section>';}
    page('扩展市场审核',$body);exit;
}

function market_fetch_index(array $config):array{
    $key=(string)($config['market_public_key']??'');if(!$key)throw new RuntimeException('请先配置官方更新源的签名公钥');
    $raw=market_download(LITEBBS_UPDATE_RAW_BASE.'/market/index.json',1048576);$envelope=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$payload=$envelope['payload']??null;$signature=base64_decode((string)($envelope['signature']??''),true);if(!is_array($payload)||!is_string($signature)||($envelope['algorithm']??'')!=='RSA-SHA256')throw new RuntimeException('市场签名清单格式或算法错误');$canonical=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(openssl_verify($canonical,$signature,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('市场清单签名不可信，已拒绝显示与安装');return $payload;
}
function market_signing_key(): string {
    $config=load_config();
    if (!empty($config['market_signing_key'])) return (string)$config['market_signing_key'];
    $legacy=(string)h('SELECT setting_value FROM settings WHERE setting_key="market_signing_key"')->fetchColumn();
    if ($legacy==='') return '';
    $config['market_signing_key']=$legacy;
    write_config($config);
    h('DELETE FROM settings WHERE setting_key="market_signing_key"');
    return $legacy;
}
function market_download(string $url,int $limit,int $timeout=12):string{
    if(parse_url($url,PHP_URL_SCHEME)!=='https'||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null||(int)(parse_url($url,PHP_URL_PORT)?:443)!==443)throw new RuntimeException('仅允许有效的 HTTPS 443 下载地址');$host=(string)parse_url($url,PHP_URL_HOST);if(filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('更新源必须使用 HTTPS 域名');$records=dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];foreach($records?:[]as$record){$ip=$record['ip']??$record['ipv6']??null;if($ip)$ips[]=$ip;}if(!$ips)throw new RuntimeException('更新源域名无法解析');foreach(array_unique($ips)as$ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('更新源解析到非公网地址，已拒绝请求');if(!function_exists('curl_init'))throw new RuntimeException('更新源请求需要 cURL，以固定已验证的 DNS 地址');$resolve=[];foreach($ips as$ip)$resolve[]=$host.':443:'.(strpos($ip,':')!==false?'['.$ip.']':$ip);$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>max(12,min($timeout,120)),CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>$resolve,CURLOPT_HTTPHEADER=>['Accept: application/json, application/zip','User-Agent: LiteBBS-Updater']]);$data=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$length=is_string($data)?strlen($data):$limit+1;curl_close($ch);if(!is_string($data)||$length>$limit||$status!==200)throw new RuntimeException('HTTPS 下载失败、拒绝重定向或响应超过大小限制');return $data;
}
function market_unpack(string $archive,string $out):string{
    if(!class_exists('ZipArchive'))throw new RuntimeException('启用 PHP zip 扩展后才能处理扩展包');$z=new ZipArchive();if($z->open($archive)!==true)throw new RuntimeException('ZIP 包不可读');if($z->numFiles<1||$z->numFiles>200){$z->close();throw new RuntimeException('ZIP 文件数量超限');}if(!mkdir($out,0700,true)){$z->close();throw new RuntimeException('临时目录创建失败');}$seen=[];$total=0;for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$path=(string)($s['name']??'');$size=(int)($s['size']??0);$opsys=0;$attrs=0;$z->getExternalAttributesIndex($i,$opsys,$attrs);$unixMode=($attrs>>16)&0170000;$total+=$size;if($path===''||strpos($path,'\\')!==false||preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~',$path)||isset($seen[$path])||$size>2097152||$total>10485760||($opsys===ZipArchive::OPSYS_UNIX&&$unixMode===0120000)){$z->close();throw new RuntimeException('ZIP 含重复路径、链接、路径穿越或超出解压限额');}$seen[$path]=true;if(substr($path,-1)==='/'){if(!is_dir($out.'/'.$path)&&!mkdir($out.'/'.$path,0700,true)){$z->close();throw new RuntimeException('无法创建包目录');}continue;}$parent=dirname($out.'/'.$path);if(!is_dir($parent)&&!mkdir($parent,0700,true)){$z->close();throw new RuntimeException('无法创建包目录');}$stream=$z->getStream($s['name']);if(!$stream){$z->close();throw new RuntimeException('ZIP 文件项无法读取');}$data=stream_get_contents($stream,2097153);fclose($stream);if($data===false||strlen($data)!==$size){$z->close();throw new RuntimeException('ZIP 文件项大小不一致');}if(file_put_contents($out.'/'.$path,$data,LOCK_EX)===false){$z->close();throw new RuntimeException('无法写入扩展文件');}}$z->close();$children=array_values(array_diff(scandir($out)?:[],['.','..']));return count($children)===1&&is_dir($out.'/'.$children[0])?$out.'/'.$children[0]:$out;
}
function market_validate_package(string $archive,string $kind,string $name):void{$tmp=dirname(__DIR__).'/cache/inspect-'.bin2hex(random_bytes(8));try{$root=market_unpack($archive,$tmp);extension_validate_directory($root,$kind,$name);}finally{remove_tree($tmp);}}
function remove_tree(string $path):void{if(is_link($path)){@unlink($path);return;}if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;if(is_dir($child)&&!is_link($child))remove_tree($child);else @unlink($child);}@rmdir($path);}
function official_repository_head(): array {$data=json_decode(market_download('https://api.github.com/repos/Lfreepayzz/LiteBBS/commits/main',262144),true,32,JSON_THROW_ON_ERROR);$sha=(string)($data['sha']??'');if(!preg_match('/^[a-f0-9]{40}$/',$sha))throw new RuntimeException('官方仓库未返回有效提交版本');return ['commit'=>$sha,'url'=>(string)($data['html_url']??LITEBBS_UPDATE_REPOSITORY)];}
function check_repository_update(array $config): array {$remote=official_repository_head();$current=(string)($config['official_update_commit']??'');if($current!==''&&hash_equals($current,$remote['commit']))return ['message'=>'当前已是官方仓库最新提交（'.substr($remote['commit'],0,12).'）。','url'=>$remote['url']];return ['message'=>'发现官方仓库新提交 '.substr($remote['commit'],0,12).'。确认后可自动备份并更新程序文件。','commit'=>$remote['commit'],'url'=>$remote['url']];}
