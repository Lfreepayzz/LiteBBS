<?php
declare(strict_types=1);

require_once __DIR__.'/app/config.php';
require __DIR__.'/app/core.php';
require __DIR__.'/app/extensions-security.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-store');
foreach (["Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'; script-src 'self'", 'X-Frame-Options: DENY', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: strict-origin-when-cross-origin', 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'Cross-Origin-Opener-Policy: same-origin'] as $header) header($header);
$secureRequest = request_is_https();
if ($secureRequest) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$operatorSecret = getenv('LITEBBS_INSTALL_SECRET', true);
if (!$secureRequest || !is_string($operatorSecret) || !preg_match('/\A[a-f0-9]{64}\z/', $operatorSecret)) {
    http_response_code(404);
    exit('安装入口未开放。');
}
if (($_SERVER['PHP_AUTH_USER'] ?? null) !== 'installer' || !is_string($_SERVER['PHP_AUTH_PW'] ?? null) || !hash_equals($operatorSecret, $_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="LiteBBS Installer", charset="UTF-8"');
    http_response_code(401);
    exit('需要安装运维凭据。');
}
unset($operatorSecret);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}
if (!extension_loaded('mbstring')) { http_response_code(500); exit('LiteBBS requires the mbstring extension.'); }
configure_session();

$locked = is_file(install_lock_file());
$configured = is_file(config_file()) || is_file(__DIR__.'/config.php');
$installStateFile = config_directory().'/install-state.php';
if ($locked) {
    error_log('installer blocked: installation lock detected');
    http_response_code(409);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>安装已锁定</title><h1>安装已锁定</h1><p>此站点已标记为已安装，不能再次显示安装表单。</p></html>');
}

if (is_file($installStateFile) && $method === 'GET') $installRecoveryNotice = '检测到未完成的安装，可以用下方信息继续。系统只会接受没有论坛业务数据的安装数据库，不会删除已有数据。';
elseif ($configured && $method === 'GET') $installRecoveryNotice = '检测到未完成安装的私有配置。请使用原数据库账号再次提交；系统只会恢复初始状态的数据库。';

if ($method === 'POST') {
    require_post();
    $installerLock = @fopen(config_directory().'/install.locking', 'c');
    if ($installerLock === false || !flock($installerLock, LOCK_EX | LOCK_NB)) {
        http_response_code(409);
        exit('安装正在进行，请稍后重试。');
    }
    register_shutdown_function(static function () use ($installerLock): void { flock($installerLock, LOCK_UN); fclose($installerLock); });
    clearstatcache();
    if (is_file(install_lock_file())) {
        http_response_code(409);
        exit('安装已锁定。');
    }
    $host = trim((string)($_POST['host'] ?? ''));
    $port = (int)($_POST['port'] ?? 3306);
    $name = trim((string)($_POST['dbname'] ?? ''));
    $dbuser = (string)($_POST['dbuser'] ?? '');
    $dbpass = (string)($_POST['dbpass'] ?? '');
    $site = trim((string)($_POST['site'] ?? ''));
    $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
    $admin = trim((string)($_POST['admin'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!database_host_is_valid($host) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbuser) || $port < 1 || $port > 65535 || text_length($site) < 2 || text_length($site) > 100 || ($siteUrl !== '' && request_origin_parts($siteUrl) === null) || text_length($admin) < 3 || text_length($admin) > 32 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !\LiteBBS\Application\PasswordPolicy::accepts($password)) $installError = '请检查站点、数据库和管理员信息。站点 URL 可留空，填写时必须是有效的 HTTP 或 HTTPS 地址。管理员密码长度为 '.\LiteBBS\Application\PasswordPolicy::MIN_CHARACTERS.'-'.\LiteBBS\Application\PasswordPolicy::MAX_BYTES.' 位。';
    else try {
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('服务器缺少 pdo_mysql PHP 扩展。');
        $requestHash = hash('sha256', json_encode([$host,$port,$name,$dbuser,$dbpass,$site,$siteUrl,$admin,$email]));
        $state = is_file($installStateFile) ? require $installStateFile : null;
        $recoveringConfiguredInstall = false;
        $existingConfig = [];
        if ($state !== null && (!is_array($state) || !isset($state['request_hash']))) throw new RuntimeException('私有安装状态无效，请由运维人员检查 private/install-state.php。');
        $resuming = $state !== null;
        if ($state !== null && !hash_equals((string)$state['request_hash'], $requestHash)) throw new RuntimeException('安装恢复信息与首次提交不一致，拒绝覆盖未完成安装。');
        if (is_file(config_file()) || is_file(__DIR__.'/config.php')) {
            $existingConfig = load_config();
            $sameDatabase = hash_equals((string)($existingConfig['host'] ?? ''), $host) && (int)($existingConfig['port'] ?? 0) === $port && hash_equals((string)($existingConfig['name'] ?? ''), $name) && hash_equals((string)($existingConfig['user'] ?? ''), $dbuser) && hash_equals((string)($existingConfig['pass'] ?? ''), $dbpass);
            if (!$sameDatabase) throw new RuntimeException('安装恢复必须使用私有配置中原有的数据库连接信息。');
            $recoveringConfiguredInstall = true;
            $resuming = true;
        }
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $dbuser, $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $allowed = ['schema_migrations','topic_view_flush_batches','users','sessions','categories','topics','posts','post_revisions','point_ledger','settings','plugins','themes','audit_logs','rate_limits','market_submissions','notifications','notification_outbox','outbox_events','failed_outbox_events','extension_jobs','extension_job_failures','plugin_user_field_values','plugin_search_documents','plugin_migrations','bookmarks','reactions','reports','topic_subscriptions','user_blocks','private_messages','topic_participants'];
        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($tables, $allowed) || (!$resuming && $tables)) throw new RuntimeException('数据库不是由本次安装创建的空数据库，拒绝继续');
        $state = ['request_hash'=>$requestHash];
        $stateTmp = $installStateFile.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (@file_put_contents($stateTmp, "<?php\nreturn ".var_export($state, true).";\n", LOCK_EX) === false || !@rename($stateTmp, $installStateFile)) { @unlink($stateTmp); throw new RuntimeException('无法写入私有安装状态'); }
        @chmod($installStateFile, 0600);
        $schemaLines = array_filter(array_map('trim', preg_split('/\R/', (string)file_get_contents(__DIR__.'/database/schema.sql'))));
        foreach ($schemaLines as $sql) {
            if (!preg_match('/^(?:CREATE TABLE|INSERT INTO)\s+.+;$/i', $sql) || substr_count($sql, ';') !== 1) throw new RuntimeException('schema.sql 仅允许单行、无内嵌分号的简单 CREATE TABLE 或 INSERT 语句');
            $sql = substr($sql, 0, -1);
            $sql = preg_replace('/^CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $sql);
            $sql = preg_replace('/^INSERT INTO schema_migrations/i', 'INSERT IGNORE INTO schema_migrations', $sql);
            $sql = preg_replace('/^INSERT INTO themes/i', 'INSERT IGNORE INTO themes', $sql);
            $sql = preg_replace('/^INSERT INTO plugins/i', 'INSERT IGNORE INTO plugins', $sql);
            $pdo->exec($sql);
        }
        $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $categories = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        $existingAdmin = $pdo->prepare('SELECT password_hash FROM users WHERE username=? AND email=?');
        $existingAdmin->execute([$admin,$email]);
        $existingHash = $existingAdmin->fetchColumn();
        if ($users > 1 || $categories > 1 || ($users === 1 && (!$existingHash || !password_verify($password, (string)$existingHash)))) throw new RuntimeException('数据库不符合本次安装的可恢复状态');
        $nonEmptyTables = [];
            foreach (['sessions','topics','posts','post_revisions','point_ledger','settings','audit_logs','rate_limits','market_submissions','notifications','notification_outbox','outbox_events','failed_outbox_events','extension_jobs','extension_job_failures','plugin_user_field_values','plugin_search_documents','plugin_migrations','bookmarks','reactions','reports','topic_subscriptions','user_blocks','private_messages'] as $table) {
            if ((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() !== 0) $nonEmptyTables[] = $table;
        }
        if ($nonEmptyTables) throw new RuntimeException('数据库已包含论坛运行数据（'.implode(', ', $nonEmptyTables).'），拒绝将其作为未完成安装继续；没有数据被删除。已有站点请保留 private 配置和安装锁后直接访问论坛；升级现有站点请使用 CLI 迁移脚本。');
        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        if (!$users) $pdo->prepare("INSERT INTO users(username,email,password_hash,role,created_at) VALUES(?,?,?,'admin',UTC_TIMESTAMP(6))")->execute([$admin, $email, $hash]);
        if (!$categories) $pdo->prepare('INSERT INTO categories(slug,title,description,position) VALUES(?,?,?,1)')->execute(['general', '日常讨论', '分享想法，也听听不同的声音']);
        $nextConfig = ['host'=>$host, 'port'=>$port, 'name'=>$name, 'user'=>$dbuser, 'pass'=>$dbpass, 'site'=>$site, 'url'=>$siteUrl, 'key'=>$recoveringConfiguredInstall ? (string)($existingConfig['key'] ?? bin2hex(random_bytes(32))) : bin2hex(random_bytes(32)), 'theme'=>$recoveringConfiguredInstall ? (string)($existingConfig['theme'] ?? 'default') : 'default'];
        if ($recoveringConfiguredInstall) foreach (['market_signing_key','market_public_key'] as $key) if (array_key_exists($key, $existingConfig)) $nextConfig[$key] = $existingConfig[$key];
        write_config($nextConfig);
        write_install_lock();
        @unlink($installStateFile);
        redirect('/login');
    } catch (Throwable $error) {
        if ($error instanceof PDOException) $installError = database_error_message($error);
        elseif ($error instanceof RuntimeException) $installError = $error->getMessage();
        else $installError = '安装失败：请确认 private/ 目录对 PHP Web 进程可写；详细原因已写入 PHP 错误日志。';
        error_log('install: '.$error);
    }
}

$error = isset($installError) ? '<p class="install-message install-error">'.e($installError).'</p>' : (isset($installRecoveryNotice) ? '<p class="install-message">'.e($installRecoveryNotice).'</p>' : '');
$fields = '<section class="install-section"><div class="section-heading"><span>01</span><div><h2>站点信息</h2><p>论坛的名称与公开访问地址</p></div></div><div class="field-grid">'.input('site', '站点名称').'<label>站点 URL <small>可选；支持 HTTP 或 HTTPS</small><input name="site_url" type="url" placeholder="https://forum.example.com"></label></div></section>';
$fields .= '<section class="install-section"><div class="section-heading"><span>02</span><div><h2>数据库连接</h2><p>填写论坛使用的数据库及账号</p></div></div><div class="field-grid">'.input('host', '数据库主机').input('port', '端口', 'number', 'value="3306"').input('dbname', '数据库名').input('dbuser', '数据库用户名').input('dbpass', '数据库密码', 'password').'</div></section>';
    $fields .= '<section class="install-section"><div class="section-heading"><span>03</span><div><h2>管理员账户</h2><p>用于首次登录论坛管理后台</p></div></div><div class="field-grid">'.input('admin', '管理员用户名').input('email', '管理员邮箱', 'email').input('password', '管理员密码（'.\LiteBBS\Application\PasswordPolicy::MIN_CHARACTERS.'-'.\LiteBBS\Application\PasswordPolicy::MAX_BYTES.' 位）', 'password', 'minlength="'.\LiteBBS\Application\PasswordPolicy::MIN_CHARACTERS.'" maxlength="'.\LiteBBS\Application\PasswordPolicy::MAX_BYTES.'"').'</div></section>';
$form = form('/install.php', '完成基础配置', $fields);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#edf2ed"><title>安装 LiteBBS</title><link rel="stylesheet" href="/assets/css/install.css"></head><body><main class="install-shell"><aside class="install-rail"><a class="brand" href="/install.php"><span class="brand-mark">L</span><span>LiteBBS</span></a><div class="rail-copy"><span class="rail-kicker">FIRST RUN</span><h1>搭建你的<br>讨论空间。</h1><p>只需完成三项基础配置，即可开始使用论坛。</p></div><div class="rail-steps"><span><b>01</b> 站点信息</span><span><b>02</b> 数据库连接</span><span><b>03</b> 管理员账户</span></div><small>SECURE SETUP · HTTP / HTTPS</small></aside><section class="install-main"><header class="mobile-brand"><span class="brand-mark">L</span><span>LiteBBS</span><span class="setup-tag">INITIAL SETUP</span></header><div class="page-heading"><p class="eyebrow">INITIAL SETUP <span> / </span> CONFIGURATION</p><h2>欢迎，开始安装</h2><p>设置站点、数据库和首位管理员。安装不会删除数据库中的现有数据。</p></div>'.$error.$form.'<footer>数据库账号将用于论坛日常运行及受控数据库升级。</footer></section></main></body></html>';
exit;
