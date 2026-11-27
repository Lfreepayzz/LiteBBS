<?php
declare(strict_types=1);

/**
 * Versioned, single-file trusted plugin runtime.
 * This is the only supported plugin entry point for web, admin, install, and CLI.
 */

// ===== extensions-security.php =====

function extension_manifest_admin_path(array $manifest, string $id): ?string {
    if (!array_key_exists('admin_path', $manifest)) return null;
    $path = $manifest['admin_path'];
    if (!is_string($path) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $path)) {
        throw new RuntimeException('Invalid plugin admin path.');
    }
    return $path;
}

function extension_manifest_public_route_prefix(array $manifest): ?string {
    if (!array_key_exists('public_route_prefix', $manifest)) return null;
    $prefix = (string)$manifest['public_route_prefix'];
    if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $prefix) || in_array($prefix, ['admin','plugin','plugin-assets','assets','uploads','install','login','logout','register','me','new','search','market','robots','sitemap','t','c','u','p','avatar','messages','topic','post','report','user'], true)) throw new RuntimeException('Invalid public plugin route prefix.');
    return $prefix;
}

function extension_validate_directory(string $directory, string $kind, string $name): void {
    if (!in_array($kind, ['plugin', 'theme'], true) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name) || !is_dir($directory)) {
        throw new RuntimeException('扩展目录或类型无效');
    }

    if ($kind === 'plugin') {
        if (!is_file($directory.'/plugin.json')) throw new RuntimeException('插件必须使用 plugin.json OOP 清单。');
        extension_validate_v2_plugin($directory, $name);
        return;
    }
    // Themes use the runtime validator too, so marketplace and runtime rules cannot drift.
    if (!function_exists('theme_validate_package')) throw new RuntimeException('Theme runtime is unavailable.');
    theme_validate_package($directory, $name);
}

function extension_validate_v2_plugin(string $directory, string $name): void {
    $manifest = json_decode((string)file_get_contents($directory.'/plugin.json'), true);
    $entry = is_array($manifest) ? (string)($manifest['entry'] ?? '') : '';
    $class = is_array($manifest) ? (string)($manifest['class'] ?? '') : '';
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $name || !preg_match('/^\d+\.\d+\.\d+$/', (string)($manifest['version'] ?? '')) || !is_array($manifest['requires'] ?? null) || !extension_supports_core((string)($manifest['requires']['core'] ?? '')) || !extension_v2_entry_file($directory, $entry) || !extension_class_name_valid($class)) throw new RuntimeException('新插件清单无效');
    $prefix = 'plg_'.str_replace('-', '_', $name).'_';
    $tables = $manifest['database_tables'] ?? [];
    if (!is_array($tables) || count($tables) > 100 || count($tables) !== count(array_unique($tables))) throw new RuntimeException('新插件 database_tables 必须是无重复的有效数组');
    foreach ($tables as $table) if (!is_string($table) || !preg_match('/^'.preg_quote($prefix, '/').'[a-z][a-z0-9_]{0,55}$/', $table)) throw new RuntimeException('新插件数据表必须以 '.$prefix.' 开头');
    extension_manifest_admin_path($manifest, $name);
    extension_manifest_public_route_prefix($manifest);
    extension_manifest_permissions($manifest);
    extension_manifest_links($manifest);
    extension_manifest_entry_defaults($manifest);
    extension_manifest_named_capabilities($manifest, 'commands', ['topic.create', 'reply.create', 'topic.update', 'post.update', 'post.delete', 'user.profile.update']);
    extension_manifest_named_capabilities($manifest, 'policies', ['category.view', 'topic.view', 'attachment.download', 'topic.create', 'topic.reply', 'topic.edit', 'post.edit', 'topic.delete', 'post.delete', 'profile.view', 'user.username_reserved', 'user.can_speak']);
    extension_manifest_extension_flags($manifest);
    extension_manifest_signals($manifest, $name);
    extension_manifest_autoload($manifest, $directory);
    foreach (extension_manifest_hook_keys() as $kind => $key) extension_manifest_hooks($manifest, $kind);
    foreach (extension_manifest_assets($manifest) ?? [] as $asset) {
        if (extension_secure_runtime_file($directory, (string)$asset['path']) === null) throw new RuntimeException('新插件声明的资源文件不存在：'.$asset['path']);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($files as $file) {
        if (++$count > 300 || $file->isLink() || !$file->isFile() || $file->getSize() > 2097152) throw new RuntimeException('新插件包含过多文件、链接或超限文件');
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json','php','css','js','png','jpg','jpeg','gif','webp','svg','txt','md','map'], true) || str_starts_with($relative, '.') || str_contains($relative, '/.')) throw new RuntimeException('新插件包含不允许的文件：'.$relative);
        if ($extension === 'php' && !str_starts_with($relative, 'src/') && !str_starts_with($relative, 'migrations/')) throw new RuntimeException('新插件 PHP 文件必须位于 src 或 migrations 目录');
        if ($extension === 'php') extension_structure_preflight_php((string)file_get_contents($file->getPathname()), $relative);
        if (str_starts_with($relative, 'migrations/')) {
            if (!preg_match('~^migrations/[0-9]{8}_[0-9]{4}_[a-z0-9_]+\.php$~', $relative)) throw new RuntimeException('新插件迁移文件名无效');
            extension_validate_migration_source((string)file_get_contents($file->getPathname()), $relative, $prefix);
        }
    }
    if ($prefix === '') throw new RuntimeException('插件数据表前缀无效');
}

function extension_v2_entry_file(string $directory, string $entry): ?string {
    // Validate the manifest path and its resolved target. A regex alone accepts
    // `src/../...`, which can escape the plugin directory when it is required.
    if (!preg_match('~^src/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$~', $entry)) return null;
    return extension_secure_runtime_file($directory, $entry);
}

function extension_validate_migration_source(string $source, string $relative, string $prefix): void {
    // This is a conservative naming check in addition to PHP preflight, not a sandbox.
    if (preg_match_all('~["\']([A-Za-z0-9_]+)["\']~', $source, $matches)) foreach ($matches[1] as $identifier) {
        if (str_starts_with($identifier, 'plg_') && !str_starts_with($identifier, $prefix)) throw new RuntimeException($relative.' 引用了其他插件的数据表前缀');
    }
}

function extension_market_manifest(string $directory, string $kind, string $expectedName): array {
    extension_validate_directory($directory, $kind, $expectedName);
    $file=$directory.($kind === 'plugin' ? '/plugin.json' : '/theme.json');
    $manifest=json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    $name=(string)($kind === 'plugin' ? ($manifest['id'] ?? '') : ($manifest['name'] ?? ''));
    $version=(string)($manifest['version'] ?? '');
    if ($name !== $expectedName || !preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new RuntimeException('包清单身份或版本无效');
    return ['kind'=>$kind,'name'=>$name,'version'=>$version,'manifest_json'=>json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
}

function extension_class_name_valid(string $class): bool {
    $parts = explode('\\', $class);
    if (!$parts || in_array('', $parts, true)) return false;
    foreach ($parts as $part) if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) return false;
    return true;
}

function extension_manifest_autoload(array $manifest, string $directory): array
{
    if (!array_key_exists('autoload', $manifest)) return [];
    $autoload = $manifest['autoload'];
    if (!is_array($autoload) || count($autoload) > 16) throw new RuntimeException('Plugin autoload declaration is invalid.');
    $validated = [];
    foreach ($autoload as $namespace=>$path) {
        if (!is_string($namespace) || !str_ends_with($namespace, '\\') || !extension_class_name_valid(rtrim($namespace, '\\')) || !is_string($path) || !preg_match('~^src(?:/[A-Za-z0-9_-]+)*/$~', $path)) {
            throw new RuntimeException('Plugin autoload declaration is invalid.');
        }
        $resolved = realpath($directory.'/'.rtrim($path, '/'));
        if ($resolved === false || !is_dir($resolved) || is_link($resolved)) throw new RuntimeException('Plugin autoload directory is unavailable.');
        $validated[$namespace] = rtrim($path, '/').'/';
    }
    return $validated;
}

function extension_register_plugin_autoloader(string $id, string $directory, array $manifest): void
{
    static $registered = [];
    if (isset($registered[$id])) return;
    $autoload = extension_manifest_autoload($manifest, $directory);
    if (!$autoload) { $registered[$id] = true; return; }
    spl_autoload_register(static function (string $class) use ($autoload, $directory): void {
        foreach ($autoload as $namespace=>$basePath) {
            if (!str_starts_with($class, $namespace)) continue;
            $relative = substr($class, strlen($namespace));
            if ($relative === '' || !preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~', $relative)) return;
            $file = extension_secure_runtime_file($directory, $basePath.str_replace('\\', '/', $relative).'.php');
            if ($file !== null) require_once $file;
            return;
        }
    });
    $registered[$id] = true;
}

function extension_validate_theme_pagination(array $manifest): void {
    $pagination = $manifest['pagination'] ?? [];
    if (!is_array($pagination)) throw new RuntimeException('Invalid theme pagination declaration.');
    foreach ($pagination as $context=>$size) {
        if ($context === 'home_path' && $size === 'forum') continue;
        if (!in_array($context, ['home','category','topic'], true) || !is_int($size) || $size < 1 || $size > 100) throw new RuntimeException('Invalid theme pagination size.');
    }
}

function extension_secure_runtime_file(string $root, string $relative): ?string {
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, "\n") || str_contains($relative, "\r")) return null;
    $rootReal = realpath($root);
    $relative = str_replace('\\', '/', $relative);
    if ($rootReal === false || !is_dir($rootReal) || str_starts_with($relative, '/') || preg_match('~(?:^|/)\.\.(?:/|$)~', $relative)) return null;
    $parts = preg_split('~/+~', $relative, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return null;
    $current = $rootReal;
    foreach ($parts as $part) {
        if ($part === '.' || $part === '' || is_link($current.'/'.$part)) return null;
        $current .= DIRECTORY_SEPARATOR.$part;
    }
    $resolved = realpath($current);
    if ($resolved === false || !is_file($resolved) || is_link($resolved)) return null;
    $prefix = rtrim($rootReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (DIRECTORY_SEPARATOR === '\\') {
        if (!str_starts_with(strtolower($resolved), strtolower($prefix))) return null;
    } elseif (!str_starts_with($resolved, $prefix)) {
        return null;
    }
    return $resolved;
}

function extension_supports_core(string $range): bool {
    if (!preg_match('/^>=([0-9]+\.[0-9]+(?:\.[0-9]+)?)\s+<([0-9]+\.[0-9]+(?:\.[0-9]+)?)$/', $range, $match)) return false;
    return version_compare(LITEBBS_VERSION, $match[1], '>=') && version_compare(LITEBBS_VERSION, $match[2], '<');
}

// This rejects unsupported package shapes; it does not sandbox or establish PHP safety.
function extension_structure_preflight_php(string $source, string $relative): void {
    $forbiddenTokens = [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EXIT, T_GLOBAL];
    $forbiddenCalls = ['assert', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client', 'putenv', 'ini_set', 'set_error_handler', 'register_shutdown_function', 'get_defined_vars', 'reflectionclass', 'reflectionfunction', 'class_alias'];
    $forbiddenVariables = ['$GLOBALS', '$_ENV', '$_SERVER'];
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) continue;
        if (in_array($token[0], $forbiddenTokens, true)) throw new RuntimeException($relative.' 使用了受禁止的 PHP 语法');
        if ($token[0] === T_VARIABLE && in_array($token[1], $forbiddenVariables, true)) throw new RuntimeException($relative.' 直接读取了请求或全局变量');
        if ($token[0] === T_STRING && in_array(strtolower($token[1]), $forbiddenCalls, true)) throw new RuntimeException($relative.' 调用了受禁止的系统、文件或网络 API');
    }
}


// ===== extension-hooks.php =====

const LITEBBS_HOOK_REGISTRY_VERSION = 5;

function extension_hook_registry(): array
{
    static $hooks = [
        'boot'=>[
            'request.start'=>['version'=>1,'strict'=>true,'return'=>'void'],
        ],
        'slot'=>[
            'head.assets'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'navigation.primary'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.content.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.content.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'login.form.fields.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'login.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'register.form.fields.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'register.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'member.profile.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.main.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.main.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'page.header.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'page.footer.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'sidebar.sections'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'footer.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'body.end'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'home.feed.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'home.feed.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'category.header.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'category.feed.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'category.feed.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'topic.create.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.create.sidebar'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.edit.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.edit.sidebar'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.header.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.actions.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'topic.thread.before'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'topic.thread.after'=>['version'=>2,'strict'=>false,'return'=>'html'],
            'post.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.create.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.create.sidebar'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.edit.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.edit.sidebar'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'reply.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.edit.fields'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.edit.form.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'profile.header.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'profile.content.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.after-body'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.head'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.navigation'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.content.before'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'admin.content.after'=>['version'=>1,'strict'=>false,'return'=>'html'],
        ],
        'middleware'=>[
            'request.before'=>['version'=>1,'strict'=>true,'return'=>'response_or_null'],
        ],
        'preload'=>[
            'home.feed.items'=>['version'=>1,'strict'=>false,'return'=>'void'],
            'category.feed.items'=>['version'=>1,'strict'=>false,'return'=>'void'],
            'topic.replies'=>['version'=>1,'strict'=>false,'return'=>'void'],
            'notification.list'=>['version'=>1,'strict'=>false,'return'=>'void'],
            'post.after-body'=>['version'=>1,'strict'=>false,'return'=>'void'],
            'post.after'=>['version'=>1,'strict'=>false,'return'=>'void'],
        ],
        'renderer'=>[
            'post.markdown'=>['version'=>1,'strict'=>false,'return'=>'html'],
        ],
        'filter'=>[
            'post.markdown.source'=>['version'=>1,'strict'=>true,'return'=>'markdown'],
            'post.rendered'=>['version'=>1,'strict'=>false,'return'=>'html'],
            'post.row'=>['version'=>1,'strict'=>true,'return'=>'html_or_null'],
            'view.document'=>['version'=>1,'strict'=>true,'return'=>'html_or_null'],
        ],
        'event'=>[
            'topic.create.preflight'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'topic.created'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'post.created'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'post.updated'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'topic.deleting'=>['version'=>1,'strict'=>false,'sync'=>true,'sensitive'=>true,'deprecated_since'=>'1.1'],
            'topic.create.preflight.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['actor_id'=>'int','title'=>'string','body'=>'string','category_id'=>'int','uploads'=>'uploads']],
            'topic.deleting.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','category_id'=>'int','actor_id'=>'int','post_ids'=>'int_list']],
            'topic.created.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int','title'=>'string','body'=>'string','category_id'=>'int','uploads'=>'uploads']],
            'post.created.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int','body'=>'string','uploads'=>'uploads']],
            'post.updated.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int','body'=>'string','previous_body_markdown'=>'string']],
            'post.deleting.v2'=>['version'=>2,'strict'=>true,'sync'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','category_id'=>'int','actor_id'=>'int','reason'=>'string']],
            'topic.updated.async'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int','title'=>'string','category_id'=>'int','previous_title'=>'string','previous_category_id'=>'int']],
            'topic.created.async'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int']],
            'post.created.async'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','post_id'=>'int','actor_id'=>'int']],
            'notification.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['notification_id'=>'int','recipient_id'=>'int','actor_id'=>'int','kind'=>'string','topic_id'=>'int','post_id'=>'int']],
            'topic.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','category_id'=>'int','post_ids'=>'int_list','actor_id'=>'int','reason'=>'optional_string']],
            'post.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','actor_id'=>'int','reason'=>'optional_string']],
            'topic.moderated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','category_id'=>'int','action'=>'string','value'=>'int','actor_id'=>'int']],
            'category.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['category_id'=>'int','actor_id'=>'int']],
            'category.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['category_id'=>'int','actor_id'=>'int']],
            'category.visibility.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['category_id'=>'int','visible'=>'bool','actor_id'=>'int']],
            'reaction.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','actor_id'=>'int','reaction'=>'string']],
            'reaction.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['post_id'=>'int','topic_id'=>'int','actor_id'=>'int','reaction'=>'string']],
            'bookmark.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'bookmark.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'subscription.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'subscription.deleted'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['topic_id'=>'int','actor_id'=>'int']],
            'report.created'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['report_id'=>'int','target_type'=>'string','target_id'=>'int','actor_id'=>'int']],
            'report.reviewed'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['report_id'=>'int','target_type'=>'string','target_id'=>'int','status'=>'string','actor_id'=>'int']],
            'user.registered'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int']],
            'user.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int','actor_id'=>'int','role'=>'string','status'=>'string','is_developer'=>'int']],
            'user.profile.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int']],
            'user.avatar.updated'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['user_id'=>'int']],
            'user.blocked'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['actor_id'=>'int','user_id'=>'int']],
            'user.unblocked'=>['version'=>1,'strict'=>true,'async'=>true,'fields'=>['actor_id'=>'int','user_id'=>'int']],
        ],
    ];
    static $surfaces;
    if ($surfaces === null) {
        $read = array_fill_keys(ReadSelection::SURFACES, ['version'=>1,'strict'=>true,'return'=>'id_list_or_null']);
        $surfaces = $hooks + [
            'selection'=>$read,
            'read_transform'=>$read,
            'view'=>array_fill_keys(['layout', ...array_keys(theme_view_contracts())], ['version'=>1,'strict'=>true,'return'=>'html_or_null']),
        ];
    }
    return $surfaces;
}

function extension_hook_contract(string $kind,string $name): ?array
{
    return extension_hook_registry()[$kind][$name]??null;
}

function extension_safe_uploads(array $files): array
{
    $uploads=[];
    foreach($files as $field=>$file){
        if(!is_string($field)||!is_array($file)||is_array($file['name']??null))continue;
        $uploads[]=['field'=>$field,'name'=>basename(str_replace('\\','/',(string)($file['name']??''))),'type'=>(string)($file['type']??''),'size'=>(int)($file['size']??0),'error'=>(int)($file['error']??UPLOAD_ERR_NO_FILE)];
    }
    return $uploads;
}

function extension_validate_hook_payload(string $name,array $payload): void
{
    $contract=extension_hook_contract('event',$name);
    if(!is_array($contract)||empty($contract['strict']))return;
    $fields=$contract['fields']??[];
    $required=array_keys(array_filter($fields,static fn(string $type):bool=>!str_starts_with($type,'optional_')));
    if(array_diff(array_keys($payload),array_keys($fields))||array_diff($required,array_keys($payload)))throw new InvalidArgumentException('Invalid extension event payload fields: '.$name);
    foreach($fields as $field=>$type){if(!array_key_exists($field,$payload)&&str_starts_with($type,'optional_'))continue;$value=$payload[$field];$valid=match($type){'int'=>is_int($value)&&$value>=0,'string','optional_string'=>is_string($value),'bool'=>is_bool($value),'int_list'=>is_array($value)&&array_reduce($value,static fn(bool $ok,mixed $item):bool=>$ok&&is_int($item)&&$item>=0,true),'uploads'=>is_array($value)&&array_reduce($value,static fn(bool $ok,mixed $upload):bool=>$ok&&is_array($upload)&&!array_diff(array_keys($upload),['field','name','type','size','error'])&&!array_diff(['field','name','type','size','error'],array_keys($upload))&&is_string($upload['field'])&&is_string($upload['name'])&&is_string($upload['type'])&&is_int($upload['size'])&&$upload['size']>=0&&is_int($upload['error']),true),default=>false};if(!$valid)throw new InvalidArgumentException('Invalid extension event payload type: '.$name.'.'.$field);}
}

function extension_validate_async_event_payload(string $name,array $payload):void
{
    $contract=extension_hook_contract('event',$name);
    if(!is_array($contract)||empty($contract['async'])||empty($contract['strict'])||!isset($contract['version']))throw new InvalidArgumentException('Async extension event requires a strict versioned contract: '.$name);
    extension_validate_hook_payload($name,$payload);
}


// ===== extension-framework.php =====

const LITEBBS_EXTENSION_API_VERSION = '2.6';
const LITEBBS_EXTENSION_PRELOAD_MAX_QUERIES = 5;
const LITEBBS_EXTENSION_PRELOAD_MAX_BYTES = 1048576;
const LITEBBS_EXTENSION_PROFILE_PAGE_SIZE = 30;
const LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS = 0.01;

interface LiteBBSPlugin
{
    public function boot(PluginContext $context): void;
}

final class PluginBootRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function onRequest(callable $handler, int $priority = 0): void { extension_validate_priority($priority); $this->registry['boot']['request.start'][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler]; }
}

final class PluginContext
{
    private array $registry;

    public function __construct(private string $id, private array $permissions, array &$registry) { $this->registry =& $registry; }

    public function id(): string { return $this->id; }

    public function routes(): PluginRouteRegistrar { $this->requirePermission('routes'); return new PluginRouteRegistrar($this->id, $this->registry); }
    public function requests(): PluginRequestRegistrar { $this->requirePermission('security'); return new PluginRequestRegistrar($this->id, $this->registry); }
    public function lifecycle(): PluginBootRegistrar { return new PluginBootRegistrar($this->id, $this->registry); }
    public function slots(): PluginSlotRegistrar { $this->requirePermission('views'); return new PluginSlotRegistrar($this->id, $this->registry); }
    public function preloads(): PluginPreloadRegistrar { $this->requirePermission('views'); return new PluginPreloadRegistrar($this->id, $this->registry); }
    public function events(): PluginEventRegistrar { return new PluginEventRegistrar($this->id, $this->registry); }
    public function signals(): PluginSignalRegistrar { return new PluginSignalRegistrar($this->id, $this->registry); }
    public function assets(): PluginAssetRegistrar { $this->requirePermission('assets'); return new PluginAssetRegistrar($this->id, $this->registry); }
    public function content(): PluginContentRegistrar { $this->requirePermission('views'); return new PluginContentRegistrar($this->id, $this->registry); }
    public function filters(): PluginFilterRegistrar { $this->requirePermission('views'); return new PluginFilterRegistrar($this->id, $this->registry); }
    /** Return up to ReadSelection::limit candidate IDs, including a pagination lookahead, or null for core loading. */
    public function readSelections(): PluginSurfaceRegistrar { $this->requirePermission('views'); return new PluginSurfaceRegistrar($this->id, 'selection', $this->registry); }
    public function readTransforms(): PluginSurfaceRegistrar { $this->requirePermission('views'); return new PluginSurfaceRegistrar($this->id, 'read_transform', $this->registry); }
    public function views(): PluginSurfaceRegistrar { $this->requirePermission('views'); return new PluginSurfaceRegistrar($this->id, 'view', $this->registry); }
    public function database(): PluginDatabase { $this->requirePermission('database'); return new PluginDatabase($this->id); }
    public function logger(): PluginLogger { return new PluginLogger($this->id); }
    public function settings(): PluginSettings { $this->requirePermission('database'); return new PluginSettings($this->id, $this->registry); }
    public function cache(): PluginCache { return new PluginCache($this->id); }
    public function admin(): PluginAdminRegistrar { $this->requirePermission('views'); return new PluginAdminRegistrar($this->id, $this->registry); }
    public function forms(): PluginForms { return new PluginForms(); }
    public function tasks(): PluginTaskRegistrar { $this->requirePermission('database'); return new PluginTaskRegistrar($this->id, $this->registry); }
    public function metrics(): PluginMetrics { return new PluginMetrics($this->id); }
    public function uploads(): PluginUploads { $this->requirePermission('storage'); return new PluginUploads($this->id); }
    public function notifications(): PluginNotifications { $this->requirePermission('database'); return new PluginNotifications($this->id); }
    public function search(): PluginSearchRegistrar { $this->requirePermission('database'); return new PluginSearchRegistrar($this->id, $this->registry); }
    public function userFields(): PluginUserFields { $this->requirePermission('database'); return new PluginUserFields($this->id, $this->registry); }
    public function commands(): PluginCommandRegistrar { $this->requirePermission('views'); return new PluginCommandRegistrar($this->id, $this->registry); }
    public function policies(): PluginPolicyRegistrar { $this->requirePermission('views'); return new PluginPolicyRegistrar($this->id, $this->registry); }
    public function postActions(): PluginPostActionRegistrar { $this->requirePermission('views'); return new PluginPostActionRegistrar($this->id, $this->registry); }
    public function navigation(): PluginNavigationRegistrar { $this->requirePermission('views'); return new PluginNavigationRegistrar($this->id, $this->registry); }
    public function postViews(): PluginPostViewRegistrar { $this->requirePermission('views'); return new PluginPostViewRegistrar($this->id, $this->registry); }
    public function topicLists(): PluginTopicListRegistrar { $this->requirePermission('views'); return new PluginTopicListRegistrar($this->id, $this->registry); }
    public function publicProfiles(): PluginPublicProfileRegistrar { $this->requirePermission('views'); return new PluginPublicProfileRegistrar($this->id, $this->registry); }
    public function profiles(): PluginProfileRegistrar { $this->requirePermission('views'); return new PluginProfileRegistrar($this->id, $this->registry); }
    public function notificationTabs(): PluginNotificationTabRegistrar { $this->requirePermission('views'); return new PluginNotificationTabRegistrar($this->id, $this->registry); }
    public function notificationPresenters(): PluginNotificationPresenterRegistrar { $this->requirePermission('views'); return new PluginNotificationPresenterRegistrar($this->id, $this->registry); }
    public function avatars(): PluginAvatarRegistrar { $this->requirePermission('views'); return new PluginAvatarRegistrar($this->id, $this->registry); }
    public function seo(): PluginSeoRegistrar { $this->requirePermission('views'); return new PluginSeoRegistrar($this->id, $this->registry); }
    public function authentication(): PluginAuthenticationRegistrar { $this->requirePermission('security'); return new PluginAuthenticationRegistrar($this->id, $this->registry); }
    public function read(): CoreReadRepository { return new CoreReadRepository(); }
    public function urls(): PluginUrlGenerator { return new PluginUrlGenerator($this->id); }
    public function config(): array { return $this->registry['plugins'][$this->id]['config'] ?? []; }

    private function requirePermission(string $permission): void
    {
        if (!in_array($permission, $this->permissions, true)) throw new RuntimeException('Plugin '.$this->id.' must declare the '.$permission.' permission.');
    }
}

final class ReadSelection
{
    public const SURFACES = ['home', 'category', 'topic'];

    public function __construct(
        public readonly string $surface,
        public readonly int $limit,
        public readonly ?int $subjectId = null,
        public readonly ?array $cursor = null,
        public readonly ?int $viewerId = null,
    ) {}

    public function accepts(mixed $ids): bool
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > $this->limit) return false;
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || isset($seen[$id])) return false;
            $seen[$id] = true;
        }
        return true;
    }

}

final class PluginView
{
    public function __construct(public readonly string $surface, public readonly array $data) {}
}

final class PluginSurfaceRegistrar
{
    private array $registry;

    public function __construct(private string $id, private string $kind, array &$registry)
    {
        $this->registry =& $registry;
    }

    public function provide(string $surface, callable $handler, int $priority = 0): void
    {
        $key = extension_manifest_hook_keys()[$this->kind] ?? null;
        if ($key === null || !extension_hook_contract($this->kind, $surface)) throw new InvalidArgumentException('Unknown extension surface.');
        extension_validate_priority($priority);
        if (isset($this->registry[$key][$surface])) throw new LogicException('Duplicate plugin surface provider.');
        $this->registry[$key][$surface][] = ['plugin'=>$this->id, 'handler'=>$handler, 'priority'=>$priority];
    }
}

function extension_surface_value(string $kind, string $surface, ReadSelection|PluginView $context, callable $accept, bool $allowSql = false): mixed
{
    extension_runtime_load(null, $kind, $surface);
    $key = extension_manifest_hook_keys()[$kind];
    foreach (extension_registry()[$key][$surface] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $value = extension_item_callback($entry['plugin'], $kind.'.'.$surface, static function () use ($entry, $context, $accept): mixed {
            $value = ($entry['handler'])($context);
            if ($value !== null && !$accept($value)) throw new UnexpectedValueException('Invalid extension provider result.');
            return $value;
        }, [], $allowSql);
        if ($value !== null) return $value;
    }
    return null;
}

function extension_select_ids(ReadSelection $context): ?array
{
    return extension_surface_value('selection', $context->surface, $context, $context->accepts(...), true);
}

function extension_transform_rows(ReadSelection $context, array $rows, ?int $protectedId = null): array
{
    if (!$rows) return $rows;
    extension_runtime_load(null, 'read_transform', $context->surface);
    $entries = extension_registry()['read_transforms'][$context->surface] ?? [];
    if (!$entries) return $rows;

    $byId = array_column($rows, null, 'id');
    foreach ($entries as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $ids = extension_item_callback($entry['plugin'], 'read_transform.'.$context->surface, static function () use ($entry, $context, $byId): ?array {
            $ids = ($entry['handler'])($context, array_values($byId));
            if ($ids !== null && (!$context->accepts($ids) || array_diff_key(array_fill_keys($ids, true), $byId))) {
                throw new UnexpectedValueException('Read transform returned rows outside the visible page.');
            }
            return $ids;
        }, []);
        if ($ids === null) continue;
        if ($protectedId !== null && isset($byId[$protectedId])) array_unshift($ids, $protectedId);
        $ordered = [];
        foreach ($ids as $id) $ordered[$id] = $byId[$id];
        $byId = $ordered;
    }
    if ($context->surface === 'topic') return array_filter($rows, static fn(array $row): bool => isset($byId[(int)$row['id']]));
    return array_values($byId);
}

function extension_view_html(string $surface, array $data): ?string
{
    return extension_surface_value('view', $surface, new PluginView($surface, $data), static fn(mixed $html): bool => is_string($html) && strlen($html) <= 1048576);
}

function plugin_viewer_summary(?array $viewer): ?array
{
    return $viewer === null ? null : array_intersect_key($viewer, ['id'=>true, 'username'=>true, 'role'=>true]);
}

function extension_dynamic_read_page(string $surface): bool
{
    return extension_candidate_plugins('selection', $surface) !== []
        || extension_candidate_plugins('read_transform', $surface) !== []
        || extension_candidate_plugins('view', $surface) !== []
        || extension_candidate_plugins('view', 'pagination') !== []
        || extension_candidate_plugins('view', 'layout') !== []
        || extension_candidate_plugins('policies', 'category.view') !== []
        || extension_candidate_plugins('policies', 'topic.view') !== []
        || extension_has_active_slots($surface)
        || ($surface === 'topic' && extension_candidate_plugins('filter', 'post.row') !== [])
        || extension_candidate_plugins('filter', 'view.document') !== [];
}

/** Slot output can be request-specific, so it must never enter a public page cache. */
function extension_has_active_slots(string $surface): bool
{
    $slots = match ($surface) {
        'home' => ['home.feed.before', 'home.feed.after'],
        'category' => ['category.header.after', 'category.feed.before', 'category.feed.after'],
        'topic' => ['topic.header.after', 'topic.actions.after', 'topic.thread.before', 'topic.thread.after', 'post.before', 'post.after-body', 'post.after', 'reply.create.fields', 'reply.create.sidebar', 'reply.form.after'],
        default => [],
    };
    // Layout slots can render on every public read page.
    $slots = array_merge($slots, ['head.assets', 'navigation.primary', 'page.content.before', 'page.content.after', 'page.main.before', 'page.main.after', 'page.header.after', 'page.footer.before', 'footer.before', 'body.end', 'sidebar.sections']);
    foreach ($slots as $slot) {
        if (extension_candidate_plugins('slot', $slot) !== []) return true;
    }
    return false;
}

final class PluginUrlGenerator
{
    public function __construct(private string $plugin) {}
    public function route(string $path = '', array $query = []): string
    {
        if ($path !== '' && !preg_match('~^[a-z0-9/_-]{1,120}$~', $path)) throw new InvalidArgumentException('Invalid plugin route path.');
        foreach ($query as $key=>$value) if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) || !is_scalar($value)) throw new InvalidArgumentException('Invalid plugin route query.');
        $manifest = extension_plugin_manifest($this->plugin);
        $prefix = is_array($manifest) ? extension_manifest_public_route_prefix($manifest) : null;
        if ($prefix === null) throw new RuntimeException('Plugin does not declare a public route prefix.');
        $url = '/'.$prefix.($path === '' ? '' : '/'.trim($path, '/'));
        return $query ? $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
    }
}

final class CoreReadRepository
{
    public function topic(int $id): ?array
    {
        if ($id < 1) return null;
        $row = h('SELECT t.id,t.category_id,t.user_id,t.title,t.reply_count,t.view_count,t.created_at,t.last_post_at,c.title AS category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.id=? AND t.status="normal" AND c.is_visible=1', [$id])->fetch();
        return is_array($row) ? $row : null;
    }
    public function topics(int $limit = 20, ?int $afterId = null): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT t.id,t.category_id,t.user_id,t.title,t.reply_count,t.view_count,t.created_at,t.last_post_at,c.title AS category,u.username FROM topics t JOIN categories c ON c.id=t.category_id JOIN users u ON u.id=t.user_id WHERE t.status="normal" AND c.is_visible=1';
        $args = [];
        if ($afterId !== null && $afterId > 0) { $sql .= ' AND t.id<?'; $args[] = $afterId; }
        return h($sql.' ORDER BY t.id DESC LIMIT '.$limit, $args)->fetchAll();
    }
    public function user(string $username): ?array
    {
        if ($username === '') return null;
        $row = h('SELECT id,username,points,bio,avatar_path,created_at FROM users WHERE username=? AND status="active"', [$username])->fetch();
        return is_array($row) ? $row : null;
    }
    public function category(int $id): ?array
    {
        if ($id < 1) return null;
        $row = h('SELECT id,slug,title,description,position FROM categories WHERE id=? AND is_visible=1', [$id])->fetch();
        return is_array($row) ? $row : null;
    }
}

final class ContentDraft
{
    public function __construct(public readonly string $kind, public readonly string $title, public readonly string $body, public readonly int $categoryId) {}
    public function with(string $title, string $body, int $categoryId): self { return new self($this->kind, $title, $body, $categoryId); }
}

final class UserProfileDraft
{
    public function __construct(public readonly string $email, public readonly string $bio, public readonly string $locale) {}
    public function with(string $email, string $bio, string $locale): self { return new self($email, $bio, $locale); }
}

final class PostUpdateDraft
{
    public function __construct(public readonly int $postId, public readonly int $topicId, public readonly int $revision, public readonly bool $isTopic, public readonly string $title, public readonly string $body, public readonly int $categoryId) {}
    public function with(string $title, string $body, int $categoryId): self { return new self($this->postId, $this->topicId, $this->revision, $this->isTopic, $title, $body, $categoryId); }
}

final class DeleteRequest
{
    public function __construct(public readonly string $targetType, public readonly int $targetId, public readonly int $topicId, public readonly int $categoryId, public readonly int $actorId, public readonly string $mode, public readonly string $reason) {}
    public function with(string $mode, string $reason): self { return new self($this->targetType, $this->targetId, $this->topicId, $this->categoryId, $this->actorId, $mode, $reason); }
}

final class PolicyDecision
{
    public const Allow = 'allow';
    public const Deny = 'deny';
    public const Abstain = 'abstain';
}

/** Read-only policy input passed to every declared business policy. */
final class PolicyContext implements ArrayAccess
{
    public function __construct(public readonly string $ability, private array $values) {}
    public function actor(): ?array { $actor = $this->values['actor'] ?? null; return is_array($actor) ? $actor : null; }
    public function subject(string $name): mixed { return $this->values[$name] ?? null; }
    public function all(): array { return $this->values; }
    public function offsetExists(mixed $offset): bool { return is_string($offset) && array_key_exists($offset, $this->values); }
    public function offsetGet(mixed $offset): mixed { return is_string($offset) ? ($this->values[$offset] ?? null) : null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new LogicException('Policy context is read-only.'); }
    public function offsetUnset(mixed $offset): void { throw new LogicException('Policy context is read-only.'); }
}

final class PluginCommandRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function filter(string $command, callable $handler, int $priority = 0): void
    {
        if (!in_array($command, ['topic.create', 'reply.create', 'topic.update', 'post.update', 'post.delete', 'user.profile.update'], true)) throw new InvalidArgumentException('Unknown plugin command.');
        extension_validate_priority($priority);
        $this->registry['commands'][$command][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler];
    }
}

final class PluginPolicyRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function allow(string $ability, callable $handler, int $priority = 0): void
    {
        if (!in_array($ability, ['category.view', 'topic.view', 'attachment.download', 'topic.create', 'topic.reply', 'topic.edit', 'post.edit', 'topic.delete', 'post.delete', 'profile.view', 'user.username_reserved', 'user.can_speak'], true)) throw new InvalidArgumentException('Unknown plugin policy.');
        extension_validate_priority($priority);
        $this->registry['policies'][$ability][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler];
    }
}

final class PostAction
{
    public function __construct(public readonly string $id, public readonly string $label, public readonly string $href, public readonly string $placement = 'primary', public readonly int $priority = 0) {}
}

/** A framework-owned navigation item that does not depend on theme markup. */
final class NavigationLink
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $href,
        public readonly string $surface = 'navigation.primary',
        public readonly int $priority = 0
    ) {}
}

final class PluginNavigationRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(NavigationLink $link): void
    {
        if (!str_starts_with($link->id, $this->id.'-') || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $link->id) || $link->label === '' || text_length($link->label) > 80 || !ExtensionLink::validHref($link->href) || !in_array($link->surface, ['navigation.primary', 'admin.navigation'], true) || $link->priority < -1000 || $link->priority > 1000 || isset($this->registry['navigation_links'][$link->surface][$link->id])) {
            throw new InvalidArgumentException('Invalid plugin navigation link.');
        }
        $this->registry['navigation_links'][$link->surface][$link->id] = ['plugin'=>$this->id, 'link'=>$link];
    }
}

/** Immutable data available while rendering one stored post. */
final class PostView
{
    public function __construct(
        public readonly array $post,
        public readonly array $topic,
        public readonly ?array $viewer,
        public readonly int $floor,
        public readonly bool $isOriginal
    ) {}
}

/** Request-only, plugin-namespaced preload data for zero-query item renderers. */
final class PluginPreloadContext
{
    public function __construct(private string $plugin, private string $surface, private array $view, private bool $writable = false) {}
    public function view(): array { return $this->view; }
    public function put(string $key, mixed $value): void
    {
        if (!$this->writable) throw new LogicException('Preload values are read-only outside a preloader.');
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $key) || !extension_config_value_valid($value)) throw new InvalidArgumentException('Invalid preload value.');
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > LITEBBS_EXTENSION_PRELOAD_MAX_BYTES) throw new RuntimeException('Preload value exceeds the request limit.');
        $state =& request_cache_state();
        $storeKey = '@extension-preloads:'.$this->plugin.':'.$this->surface;
        $store = $state[$storeKey] ?? ['bytes'=>0, 'values'=>[]];
        $previous = isset($store['values'][$key]) ? strlen(json_encode($store['values'][$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') : 0;
        $bytes = $store['bytes'] - $previous + strlen($encoded);
        if ($bytes > LITEBBS_EXTENSION_PRELOAD_MAX_BYTES) throw new RuntimeException('Preload store exceeds the request limit.');
        $store['bytes'] = $bytes;
        $store['values'][$key] = $value;
        $state[$storeKey] = $store;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $key)) throw new InvalidArgumentException('Invalid preload key.');
        $state =& request_cache_state();
        return $state['@extension-preloads:'.$this->plugin.':'.$this->surface]['values'][$key] ?? $default;
    }
}

/** A constrained display-only replacement for a PostView. */
final class PostViewDraft
{
    public function __construct(public readonly string $bodyHtml) {}
    public function withBodyHtml(string $bodyHtml): self { return new self($bodyHtml); }
}

final class PostActionProvider
{
    public readonly Closure $provider;
    public function __construct(callable $provider, public readonly int $priority = 0) { $this->provider = Closure::fromCallable($provider); }
}

final class PluginPostViewRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function decorate(callable $handler, int $priority = 0): void
    {
        if ($priority < -1000 || $priority > 1000) throw new InvalidArgumentException('Invalid post view priority.');
        $this->registry['post_views'][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler];
    }
}

final class PluginPostActionRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function add(PostAction $action): void
    {
        extension_validate_post_action($action);
        $this->registry['post_actions'][] = ['plugin'=>$this->id, 'action'=>$action];
    }
    public function provide(PostActionProvider $provider): void
    {
        if ($provider->priority < -1000 || $provider->priority > 1000) throw new InvalidArgumentException('Invalid post action provider.');
        $this->registry['post_action_providers'][] = ['plugin'=>$this->id, 'provider'=>$provider];
    }
}

function extension_validate_post_action(PostAction $action): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $action->id) || $action->label === '' || text_length($action->label) > 80 || !preg_match('~^/(?!/)~', $action->href) || !in_array($action->placement, ['primary', 'menu'], true) || $action->priority < -1000 || $action->priority > 1000) throw new InvalidArgumentException('Invalid post action.');
}

final class TopicListItem
{
    public function __construct(public readonly array $topic, public readonly ?array $viewer, public readonly string $surface) {}
}

final class TopicListColumn
{
    public readonly Closure $renderer;
    public function __construct(public readonly string $id, public readonly string $label, callable $renderer, public readonly int $priority = 0) { $this->renderer = Closure::fromCallable($renderer); }
}

final class PluginTopicListRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function titleSuffix(callable $renderer, int $priority = 0): void
    {
        if ($priority < -1000 || $priority > 1000) throw new InvalidArgumentException('Invalid topic list priority.');
        $this->registry['topic_title_suffixes'][] = ['plugin'=>$this->id, 'priority'=>$priority, 'renderer'=>$renderer];
    }
    public function column(TopicListColumn $column): void
    {
        if (!str_starts_with($column->id, $this->id.'-') || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $column->id) || $column->label === '' || text_length($column->label) > 80 || $column->priority < -1000 || $column->priority > 1000 || isset($this->registry['topic_list_columns'][$column->id])) throw new InvalidArgumentException('Invalid topic list column.');
        $this->registry['topic_list_columns'][$column->id] = ['plugin'=>$this->id, 'column'=>$column];
    }
}

final class PublicProfileTab
{
    public readonly Closure $allowed;
    public readonly Closure $renderer;
    public readonly ?Closure $loader;
    public function __construct(public readonly string $id, public readonly string $label, callable $allowed, callable $renderer, public readonly int $priority = 0, ?callable $loader = null) { $this->allowed = Closure::fromCallable($allowed); $this->renderer = Closure::fromCallable($renderer); $this->loader = $loader === null ? null : Closure::fromCallable($loader); }
}

final class PublicProfilePageRequest
{
    public function __construct(public readonly array $profile, public readonly ?array $viewer, public readonly int $page, public readonly int $pageSize)
    {
        if ($page < 1 || $page > 200) throw new InvalidArgumentException('Invalid public profile page.');
    }
}

final class PublicProfilePage
{
    public function __construct(public readonly array $items, public readonly bool $hasMore = false)
    {
        if (!extension_config_value_valid($items)) throw new InvalidArgumentException('Invalid public profile page.');
    }
    public function hasMore(): bool { return $this->hasMore; }
}

final class PluginPublicProfileRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function tab(PublicProfileTab $tab): void
    {
        if (!str_starts_with($tab->id, $this->id.'-') || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $tab->id) || $tab->label === '' || text_length($tab->label) > 80 || $tab->priority < -1000 || $tab->priority > 1000 || isset($this->registry['public_profile_tabs'][$tab->id])) throw new InvalidArgumentException('Invalid public profile tab.');
        $this->registry['public_profile_tabs'][$tab->id] = ['plugin'=>$this->id, 'tab'=>$tab];
    }
}

final class ProfileTab
{
    public readonly Closure $renderer;
    public function __construct(public readonly string $id, public readonly string $label, callable $renderer, public readonly int $priority = 0) { $this->renderer = Closure::fromCallable($renderer); }
}

final class PluginProfileRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function tab(ProfileTab $tab): void
    {
        if (!str_starts_with($tab->id, $this->id.'-') || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $tab->id) || $tab->label === '' || text_length($tab->label) > 80 || $tab->priority < -1000 || $tab->priority > 1000 || isset($this->registry['profile_tabs'][$tab->id])) throw new InvalidArgumentException('Invalid profile tab.');
        $this->registry['profile_tabs'][$tab->id] = ['plugin'=>$this->id, 'tab'=>$tab];
    }
}

function extension_profile_tabs(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    extension_runtime_load(null, 'profile_tabs', 'profile_tabs');
    $tabs = array_values(extension_registry()['profile_tabs']);
    usort($tabs, static fn(array $a, array $b): int => ($b['tab']->priority <=> $a['tab']->priority) ?: ($a['tab']->id <=> $b['tab']->id));
    return $cached = $tabs;
}

final class NotificationTab
{
    public readonly Closure $renderer;
    public function __construct(public readonly string $id, public readonly string $label, callable $renderer, public readonly int $priority = 0) { $this->renderer = Closure::fromCallable($renderer); }
}

final class PluginNotificationTabRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function tab(NotificationTab $tab): void
    {
        if (!str_starts_with($tab->id, $this->id.'-') || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $tab->id) || $tab->label === '' || text_length($tab->label) > 80 || $tab->priority < -1000 || $tab->priority > 1000 || isset($this->registry['notification_tabs'][$tab->id])) throw new InvalidArgumentException('Invalid notification tab.');
        $this->registry['notification_tabs'][$tab->id] = ['plugin'=>$this->id, 'tab'=>$tab];
    }
}

final class NotificationView
{
    public function __construct(public readonly array $notification, public readonly array $recipient) {}
}

final class NotificationPresentation
{
    public function __construct(public readonly string $title, public readonly string $summary, public readonly string $href)
    {
        if ($title === '' || text_length($title) > 160 || text_length($summary) > 500 || !preg_match('~^/(?!/)~', $href)) throw new InvalidArgumentException('Invalid notification presentation.');
    }
}

final class NotificationPresenter
{
    public readonly Closure $renderer;
    public function __construct(public readonly string $kind, callable $renderer) { $this->renderer = Closure::fromCallable($renderer); }
}

final class PluginNotificationPresenterRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function add(NotificationPresenter $presenter): void
    {
        $prefix = 'plugin.'.$this->id.'.';
        if (!str_starts_with($presenter->kind, $prefix) || strlen($presenter->kind) > 96 || !preg_match('/^plugin\.[a-z][a-z0-9_-]{1,31}\.[a-z][a-z0-9._-]{1,31}$/', $presenter->kind) || isset($this->registry['notification_presenters'][$presenter->kind])) throw new InvalidArgumentException('Invalid notification presenter.');
        $this->registry['notification_presenters'][$presenter->kind] = ['plugin'=>$this->id, 'presenter'=>$presenter];
    }
}

final class AvatarProvider
{
    public readonly Closure $resolver;
    public function __construct(callable $resolver, public readonly int $priority = 0) { $this->resolver = Closure::fromCallable($resolver); }
}

final class PluginAvatarRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function provide(AvatarProvider $provider): void
    {
        if ($provider->priority < -1000 || $provider->priority > 1000 || isset($this->registry['avatar_providers'][$this->id])) throw new InvalidArgumentException('Invalid avatar provider.');
        $this->registry['avatar_providers'][$this->id] = ['plugin'=>$this->id, 'provider'=>$provider];
    }
}

function extension_avatar_url(array $user): ?string
{
    $id = (int)($user['id'] ?? 0);
    if ($id < 1) return null;
    extension_runtime_load(null, 'avatar_providers', 'avatar_providers');
    $providers = array_values(extension_registry()['avatar_providers']);
    usort($providers, static fn(array $a, array $b): int => ($b['provider']->priority <=> $a['provider']->priority) ?: ($a['plugin'] <=> $b['plugin']));
    foreach ($providers as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $url = extension_item_callback($entry['plugin'], 'avatar provider', $entry['provider']->resolver, [[
            'id'=>$id, 'username'=>(string)($user['username'] ?? ''), 'avatar_path'=>(string)($user['avatar_path'] ?? ''),
        ]]);
        if ($url === null) continue;
        if (!is_string($url) || !extension_avatar_url_valid($url)) throw new RuntimeException('Avatar provider returned an invalid URL.');
        return $url;
    }
    $path = (string)($user['avatar_path'] ?? '');
    return $path !== '' && preg_match('/^[a-f0-9]{48}\.png$/', $path) ? '/avatar/'.$id.'?v='.rawurlencode($path) : null;
}

function extension_avatar_url_valid(string $url): bool
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)) return false;
    if (preg_match('~^/avatar/[1-9][0-9]*\?v=[a-f0-9]{48}\.png$~', $url)) return true;
    $parts = parse_url($url);
    return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && isset($parts['host']) && !isset($parts['user'], $parts['pass'], $parts['fragment']);
}

final class SeoContribution
{
    public function __construct(public readonly ?string $canonical = null, public readonly ?string $robots = null, public readonly array $jsonLd = [])
    {
        if (($canonical !== null && !extension_seo_canonical_valid($canonical)) || ($robots !== null && !in_array($robots, ['index,follow', 'noindex,follow', 'noindex,nofollow'], true)) || !extension_config_value_valid($jsonLd)) throw new InvalidArgumentException('Invalid SEO contribution.');
    }
}

function extension_seo_canonical_valid(string $url): bool
{
    $parts = parse_url($url);
    return strlen($url) <= 2048 && is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && isset($parts['host']) && !isset($parts['user'], $parts['pass'], $parts['fragment']);
}

final class PluginSeoRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function contribute(callable $provider, int $priority = 0): void
    {
        if ($priority < -1000 || $priority > 1000 || isset($this->registry['seo_providers'][$this->id])) throw new InvalidArgumentException('Invalid SEO provider.');
        $this->registry['seo_providers'][$this->id] = ['plugin'=>$this->id, 'priority'=>$priority, 'provider'=>Closure::fromCallable($provider)];
    }
}

function extension_seo_contributions(array $page): array
{
    extension_runtime_load(null, 'seo_providers', 'seo_providers');
    $providers = array_values(extension_registry()['seo_providers']);
    usort($providers, static fn(array $a, array $b): int => ($b['priority'] <=> $a['priority']) ?: ($a['plugin'] <=> $b['plugin']));
    $result = [];
    foreach ($providers as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $value = extension_item_callback($entry['plugin'], 'SEO provider', $entry['provider'], [$page]);
        if ($value === null) continue;
        if (!$value instanceof SeoContribution) throw new RuntimeException('SEO provider must return SeoContribution or null.');
        $result[] = $value;
    }
    return $result;
}

final class AuthenticationDecision
{
    private function __construct(public readonly string $state, public readonly string $challengeUrl = '') {}
    public static function allow(): self { return new self('allow'); }
    public static function deny(): self { return new self('deny'); }
    public static function challenge(string $url): self
    {
        if (!preg_match('~^/(?!/)~', $url)) throw new InvalidArgumentException('Invalid authentication challenge URL.');
        return new self('challenge', $url);
    }
}

final class PluginAuthenticationRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function afterPassword(callable $provider, int $priority = 0): void
    {
        if ($priority < -1000 || $priority > 1000 || isset($this->registry['authentication_providers'][$this->id])) throw new InvalidArgumentException('Invalid authentication provider.');
        $this->registry['authentication_providers'][$this->id] = ['plugin'=>$this->id, 'priority'=>$priority, 'provider'=>Closure::fromCallable($provider)];
    }
}

function extension_authentication_decision(array $user, array $request): AuthenticationDecision
{
    extension_runtime_load(null, 'authentication_providers', 'authentication_providers');
    $providers = array_values(extension_registry()['authentication_providers']);
    usort($providers, static fn(array $a, array $b): int => ($b['priority'] <=> $a['priority']) ?: ($a['plugin'] <=> $b['plugin']));
    foreach ($providers as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $payload = [
            'user'=>plugin_viewer_summary($user),
            'request'=>[
                'ip'=>(string)($request['ip'] ?? ''),
                'user_agent'=>(string)($request['user_agent'] ?? ''),
            ],
        ];
        try {
            $decision = extension_critical_callback($entry['plugin'], 'authentication provider', $entry['provider'], [$payload], 0);
        } catch (Throwable $error) {
            return AuthenticationDecision::deny();
        }
        if ($decision === null) continue;
        if (!$decision instanceof AuthenticationDecision) throw new RuntimeException('Authentication provider must return AuthenticationDecision or null.');
        if ($decision->state === 'challenge') extension_authentication_begin_challenge($entry['plugin'], (int)$user['id'], $decision->challengeUrl);
        return $decision;
    }
    return AuthenticationDecision::allow();
}

function extension_authentication_begin_challenge(string $plugin, int $userId, string $url): never
{
    $manifest = extension_plugin_manifest($plugin);
    $prefix = is_array($manifest) ? extension_manifest_public_route_prefix($manifest) : null;
    if ($prefix === null || !str_starts_with($url, '/'.$prefix.'/')) throw new RuntimeException('Authentication challenges must use the owning plugin public route.');
    start_session();
    $_SESSION['extension_authentication'] = ['plugin'=>$plugin, 'user_id'=>$userId, 'expires_at'=>time() + 300];
    release_session();
    redirect($url);
}

function extension_authentication_pending(string $plugin): ?array
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $plugin)) throw new InvalidArgumentException('Invalid authentication challenge provider.');
    start_session();
    $pending = $_SESSION['extension_authentication'] ?? null;
    release_session();
    if (!is_array($pending) || !hash_equals((string)($pending['plugin'] ?? ''), $plugin) || (int)($pending['expires_at'] ?? 0) < time() || (int)($pending['user_id'] ?? 0) < 1) return null;
    return ['user_id'=>(int)$pending['user_id'], 'expires_at'=>(int)$pending['expires_at']];
}

function extension_authentication_complete(string $plugin): never
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $plugin)) throw new InvalidArgumentException('Invalid authentication challenge provider.');
    start_session();
    $pending = $_SESSION['extension_authentication'] ?? null;
    unset($_SESSION['extension_authentication']);
    if (!is_array($pending) || !hash_equals((string)($pending['plugin'] ?? ''), $plugin) || (int)($pending['expires_at'] ?? 0) < time() || (int)($pending['user_id'] ?? 0) < 1) {
        release_session();
        fail(403, '认证挑战无效或已过期');
    }
    login_user((int)$pending['user_id']);
    redirect('/');
}

function extension_notification_presentation(array $notification, array $recipient): ?NotificationPresentation
{
    extension_runtime_load(null, 'notification_presenters', 'notification_presenters');
    $entry = extension_registry()['notification_presenters'][(string)($notification['kind'] ?? '')] ?? null;
    if (!is_array($entry) || extension_plugin_degraded($entry['plugin'])) return null;
    $result = extension_item_callback($entry['plugin'], 'notification presenter', $entry['presenter']->renderer, [new NotificationView($notification, $recipient), new PluginPreloadContext($entry['plugin'], 'notification.list', ['recipient'=>$recipient])]);
    if ($result === null) return null;
    if (!$result instanceof NotificationPresentation) throw new RuntimeException('Notification presenter must return NotificationPresentation.');
    return $result;
}

function extension_notification_tabs(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    extension_runtime_load(null, 'notification_tabs', 'notification_tabs');
    $tabs = array_values(extension_registry()['notification_tabs']);
    usort($tabs, static fn(array $a, array $b): int => ($b['tab']->priority <=> $a['tab']->priority) ?: ($a['tab']->id <=> $b['tab']->id));
    return $cached = $tabs;
}

function extension_post_actions(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    extension_runtime_load(null, 'post_actions', 'post_actions');
    $items = [];
    foreach (extension_registry()['post_actions'] as $entry) if (!extension_plugin_degraded($entry['plugin'])) $items[] = $entry['action'];
    usort($items, static fn(PostAction $a, PostAction $b): int => ($b->priority <=> $a->priority) ?: ($a->id <=> $b->id));
    return $cached = $items;
}

function extension_render_post_actions(?PostView $view = null): string
{
    $primary = '';
    $menu = '';
    foreach (extension_post_actions() as $action) {
        $item = '<a class="extension-post-action extension-post-action-'.e($action->id).'" href="'.e($action->href).'">'.e($action->label).'</a>';
        if ($action->placement === 'primary') $primary .= $item; else $menu .= $item;
    }
    if ($view !== null) {
        extension_runtime_load(null, 'post_actions', 'post_actions');
        foreach (extension_registry()['post_action_providers'] as $entry) {
            if (extension_plugin_degraded($entry['plugin'])) continue;
            $provided = extension_item_callback($entry['plugin'], 'post action provider', $entry['provider']->provider, [$view, new PluginPreloadContext($entry['plugin'], 'topic.replies', [])]);
            if ($provided === null) continue;
            if (!is_array($provided)) throw new RuntimeException('Post action provider must return PostAction values.');
            foreach ($provided as $action) {
                if (!$action instanceof PostAction) throw new RuntimeException('Post action provider must return PostAction values.');
                extension_validate_post_action($action);
                $item = '<a class="extension-post-action extension-post-action-'.e($action->id).'" href="'.e($action->href).'">'.e($action->label).'</a>';
                if ($action->placement === 'primary') $primary .= $item; else $menu .= $item;
            }
        }
    }
    return $primary.($menu === '' ? '' : '<details class="extension-post-action-menu"><summary>更多</summary>'.$menu.'</details>');
}

function extension_decorate_post(PostView $view): PostViewDraft
{
    extension_runtime_load(null, 'post_views', 'post_views');
    $draft = new PostViewDraft((string)($view->post['body_html'] ?? ''));
    foreach (extension_registry()['post_views'] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $next = extension_item_callback($entry['plugin'], 'post view decorator', $entry['handler'], [$view, $draft, new PluginPreloadContext($entry['plugin'], 'topic.replies', [])]);
        if ($next !== null && !$next instanceof PostViewDraft) throw new RuntimeException('Post view decorator must return PostViewDraft or null.');
        if ($next instanceof PostViewDraft) $draft = $next;
    }
    return $draft;
}

function extension_topic_title_suffix(TopicListItem $item): string
{
    extension_runtime_load(null, 'topic_lists', 'topic_lists');
    $html = '';
    foreach (extension_registry()['topic_title_suffixes'] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $output = extension_item_callback($entry['plugin'], 'topic title suffix', $entry['renderer'], [$item, new PluginPreloadContext($entry['plugin'], $item->surface === 'home' ? 'home.feed.items' : 'category.feed.items', [])]);
        if ($output === null) continue;
        if (!is_string($output) || strlen($output) > 8192) throw new RuntimeException('Topic title suffix must return at most 8 KiB of HTML.');
        $html .= $output;
    }
    return $html;
}

function extension_topic_list_columns(TopicListItem $item): string
{
    extension_runtime_load(null, 'topic_lists', 'topic_lists');
    $html = '';
    foreach (extension_registry()['topic_list_columns'] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $column = $entry['column'];
        $output = extension_item_callback($entry['plugin'], 'topic list column', $column->renderer, [$item, new PluginPreloadContext($entry['plugin'], $item->surface === 'home' ? 'home.feed.items' : 'category.feed.items', [])]);
        if ($output === null) continue;
        if (!is_string($output) || strlen($output) > 8192) throw new RuntimeException('Topic list column must return at most 8 KiB of HTML.');
        $html .= '<span class="extension-topic-column extension-topic-column-'.e($column->id).'">'.$output.'</span>';
    }
    return $html;
}

function extension_public_profile_tabs(array $profile, ?array $viewer): array
{
    static $loaded = false;
    if (!$loaded) { extension_runtime_load(null, 'public_profile_tabs', 'public_profile_tabs'); $loaded = true; }
    $tabs = [];
    foreach (extension_registry()['public_profile_tabs'] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $allowed = extension_item_callback($entry['plugin'], 'public profile tab policy', $entry['tab']->allowed, [['profile'=>$profile, 'viewer'=>$viewer]]);
        if ($allowed === null) continue;
        if (!is_bool($allowed)) throw new RuntimeException('Public profile tab policy must return bool.');
        if ($allowed) $tabs[] = $entry;
    }
    usort($tabs, static fn(array $a, array $b): int => ($b['tab']->priority <=> $a['tab']->priority) ?: ($a['tab']->id <=> $b['tab']->id));
    return $tabs;
}

function extension_public_profile_page(array $entry, PublicProfilePageRequest $request): PublicProfilePage
{
    $loader = $entry['tab']->loader;
    if ($loader === null) return new PublicProfilePage([]);
    $plugin = (string)$entry['plugin'];
    $started = microtime(true);
    $beforeSql = (int)(request_cache_state()['@sql-count'] ?? 0);
    try {
        $page = $loader($request, new PluginPreloadContext($plugin, 'public-profile-tab', ['profile'=>$request->profile, 'viewer'=>$request->viewer], true));
        $queries = (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql;
        if (!$page instanceof PublicProfilePage || count($page->items) > $request->pageSize || $queries > LITEBBS_EXTENSION_PRELOAD_MAX_QUERIES || (microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) throw new RuntimeException('Public profile tab loader returned an invalid or over-budget result.');
        extension_metric_observe($plugin, 'preload.public-profile-tab', (microtime(true) - $started) * 1000, $queries, false);
        return $page;
    } catch (Throwable $error) {
        extension_metric_observe($plugin, 'preload.public-profile-tab', (microtime(true) - $started) * 1000, (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql, true);
        error_log('plugin public profile tab '.$plugin.' failed: '.$error->getMessage());
        extension_plugin_failure($plugin);
        return new PublicProfilePage([]);
    }
}

function extension_apply_command(string $command, ContentDraft $draft, array $context): ContentDraft
{
    extension_runtime_load(null, 'commands', $command);
    foreach (extension_registry()['commands'][$command] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $next = extension_critical_callback($entry['plugin'], 'command.'.$command, $entry['handler'], [$draft, $context], 1);
        if ($next !== null && !$next instanceof ContentDraft) throw new RuntimeException('Plugin command filter must return ContentDraft or null.');
        if ($next instanceof ContentDraft) $draft = $next;
    }
    return $draft;
}

function extension_apply_profile_command(UserProfileDraft $draft, array $context): UserProfileDraft
{
    extension_runtime_load(null, 'commands', 'user.profile.update');
    foreach (extension_registry()['commands']['user.profile.update'] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $next = extension_critical_callback($entry['plugin'], 'command.user.profile.update', $entry['handler'], [$draft, $context], 1);
        if ($next !== null && !$next instanceof UserProfileDraft) throw new RuntimeException('Plugin profile command filter must return UserProfileDraft or null.');
        if ($next instanceof UserProfileDraft) $draft = $next;
    }
    return $draft;
}

function extension_apply_update_command(string $command, PostUpdateDraft $draft, array $context): PostUpdateDraft
{
    if (!in_array($command, ['topic.update', 'post.update'], true)) throw new InvalidArgumentException('Unknown update command.');
    extension_runtime_load(null, 'commands', $command);
    foreach (extension_registry()['commands'][$command] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $next = extension_critical_callback($entry['plugin'], 'command.'.$command, $entry['handler'], [$draft, $context], 1);
        if ($next !== null && !$next instanceof PostUpdateDraft) throw new RuntimeException('Plugin update command filter must return PostUpdateDraft or null.');
        if ($next instanceof PostUpdateDraft) $draft = $next;
    }
    return $draft;
}

function extension_apply_delete_command(DeleteRequest $request, array $context): DeleteRequest
{
    extension_runtime_load(null, 'commands', 'post.delete');
    foreach (extension_registry()['commands']['post.delete'] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $next = extension_critical_callback($entry['plugin'], 'command.post.delete', $entry['handler'], [$request, $context], 1);
        if ($next === false) fail(403, '插件拒绝此删除操作');
        if ($next !== null && !$next instanceof DeleteRequest) throw new RuntimeException('Plugin delete command filter must return DeleteRequest, false, or null.');
        if ($next instanceof DeleteRequest) $request = $next;
    }
    if ($request->targetType !== (string)($context['target_type'] ?? '') || $request->targetId !== (int)($context['target_id'] ?? 0) || !in_array($request->mode, ['soft', 'permanent'], true) || text_length($request->reason) > 255) throw new RuntimeException('Plugin delete command returned an invalid request.');
    return $request;
}

function extension_permits(string $ability, array|PolicyContext $context): bool
{
    extension_runtime_load(null, 'policies', $ability);
    $context = $context instanceof PolicyContext ? $context : new PolicyContext($ability, $context);
    foreach (extension_registry()['policies'][$ability] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try {
            $decision = extension_critical_callback($entry['plugin'], 'policy.'.$ability, $entry['handler'], [$context], 0);
            if (!in_array($decision, [null, true, false, PolicyDecision::Allow, PolicyDecision::Deny, PolicyDecision::Abstain], true)) {
                throw new RuntimeException('Plugin policy must return PolicyDecision, bool, or null.');
            }
        } catch (Throwable $error) {
            // Authorization must fail closed when an extension is unhealthy.
            return false;
        }
        if ($decision === false || $decision === PolicyDecision::Deny) return false;
    }
    return true;
}

function extension_authorize(string $ability, array|PolicyContext $context): void
{
    if (!extension_permits($ability, $context)) fail(403, '插件策略拒绝此操作');
}

function extension_authorize_speaking(array $actor, string $action, array $subject = []): void
{
    if (!in_array($action, ['topic.create', 'topic.reply', 'topic.edit', 'post.edit'], true)) throw new InvalidArgumentException('Invalid speaking action.');
    extension_authorize('user.can_speak', ['actor'=>$actor, 'action'=>$action] + $subject);
}

function extension_manifest_permissions(array $manifest): array
{
    $permissions = $manifest['permissions'] ?? null;
    if (!is_array($permissions) || count($permissions) > 16) throw new RuntimeException('Plugin permissions must be a valid array.');
    foreach ($permissions as $permission) {
        if (!is_string($permission) || !in_array($permission, ['database', 'storage', 'routes', 'views', 'assets', 'security'], true)) {
            throw new RuntimeException('Plugin permissions contain an invalid value.');
        }
    }
    if (count($permissions) !== count(array_unique($permissions))) throw new RuntimeException('Plugin permissions must not contain duplicates.');
    return $permissions;
}

final class ExtensionLink
{
    public const SURFACES = ['navigation.primary', 'admin.navigation', 'sidebar.sections', 'page.header.after', 'page.footer.before', 'profile.header.after', 'home.feed.before', 'category.header.after', 'topic.actions.after'];
    public const CONTEXT_KEYS = ['viewer_id', 'topic_id', 'category_id', 'profile_username'];

    public function __construct(public readonly string $surface, public readonly string $id, public readonly string $label, public readonly string $href, public readonly int $priority, public readonly string $when = 'all', public readonly array $parameters = []) {}

    public static function validHref(string $href): bool
    {
        return preg_match('~^/(?!/)~', $href) === 1 && !str_contains($href, "\\") && !preg_match('/[\x00-\x20\x7f]/', $href);
    }

    public function url(array $context): ?string
    {
        if (!$this->parameters) return $this->href;
        $query = [];
        foreach ($this->parameters as $name => $source) {
            if (!isset($context[$source])) return null;
            $query[$name] = $context[$source];
        }
        $parts = explode('#', $this->href, 2);
        return $parts[0].(str_contains($parts[0], '?') ? '&' : '?')
            .http_build_query($query, '', '&', PHP_QUERY_RFC3986).(isset($parts[1]) ? '#'.$parts[1] : '');
    }
}

function extension_manifest_links(array $manifest): array
{
    $links = $manifest['links'] ?? [];
    if (!is_array($links) || count($links) > 64) throw new RuntimeException('Plugin links declaration is invalid.');
    $valid = [];
    foreach ($links as $link) {
        if (!is_array($link)) throw new RuntimeException('Plugin link declaration is invalid.');
        $surface = (string)($link['surface'] ?? '');
        $id = (string)($link['id'] ?? '');
        $label = (string)($link['label'] ?? '');
        $href = (string)($link['href'] ?? '');
        $priority = (int)($link['priority'] ?? 0);
        $when = $link['when'] ?? 'all';
        if (!in_array($surface, ExtensionLink::SURFACES, true) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) || $label === '' || text_length($label) > 80 || !ExtensionLink::validHref($href) || !in_array($when, ['all','guest','member','admin'], true) || $priority < -1000 || $priority > 1000 || isset($valid[$surface.'|'.$id])) throw new RuntimeException('Plugin link declaration is invalid.');
        $parameters = $link['parameters'] ?? [];
        if (!is_array($parameters) || count($parameters) > count(ExtensionLink::CONTEXT_KEYS)) throw new RuntimeException('Invalid plugin link parameters.');
        foreach ($parameters as $name => $source) {
            if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $name) || !in_array($source, ExtensionLink::CONTEXT_KEYS, true)) {
                throw new RuntimeException('Invalid plugin link parameter.');
            }
        }
        $valid[$surface.'|'.$id] = new ExtensionLink($surface, $id, $label, $href, $priority, $when, $parameters);
    }
    return array_values($valid);
}

function extension_manifest_entry_defaults(array $manifest): array
{
    $entries = $manifest['entries'] ?? [];
    if (!is_array($entries) || count($entries) > 64) throw new RuntimeException('Plugin entries declaration is invalid.');
    foreach ($entries as $id=>$enabled) if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id) || !is_bool($enabled)) throw new RuntimeException('Plugin entries declaration is invalid.');
    return $entries;
}

function extension_manifest_named_capabilities(array $manifest, string $field, array $allowed): array
{
    $items = $manifest[$field] ?? [];
    if (!is_array($items) || array_diff($items, $allowed) || count($items) !== count(array_unique($items))) throw new RuntimeException('Plugin '.$field.' declaration is invalid.');
    return array_values($items);
}

function extension_manifest_extension_flags(array $manifest): array
{
    $flags = $manifest['extensions'] ?? [];
    if (!is_array($flags)) throw new RuntimeException('Plugin extensions declaration is invalid.');
    $allowed = ['post_actions', 'post_views', 'topic_lists', 'profile_tabs', 'public_profile_tabs', 'notification_tabs', 'notification_presenters', 'avatar_providers', 'seo_providers', 'authentication_providers', 'navigation_links', 'search', 'tasks'];
    if (array_diff(array_keys($flags), $allowed)) throw new RuntimeException('Plugin extensions declaration is invalid.');
    foreach ($flags as $enabled) if (!is_bool($enabled)) throw new RuntimeException('Plugin extensions declaration is invalid.');
    return $flags + array_fill_keys($allowed, false);
}

function extension_manifest_signals(array $manifest, string $id): array
{
    $signals = $manifest['signals'] ?? [];
    if (!is_array($signals) || array_diff(array_keys($signals), ['publishes', 'subscribes'])) throw new RuntimeException('Plugin signals declaration is invalid.');
    $valid = ['publishes'=>[], 'subscribes'=>[]];
    foreach (['publishes', 'subscribes'] as $kind) {
        $declared = $signals[$kind] ?? [];
        if (!is_array($declared) || count($declared) > 64) throw new RuntimeException('Plugin signals declaration is invalid.');
        foreach ($declared as $signal) {
            if (!is_string($signal) || !preg_match('/^[a-z][a-z0-9_-]{1,31}\.[a-z][a-z0-9._-]{0,63}$/', $signal)) throw new RuntimeException('Plugin signal name is invalid.');
            if ($kind === 'publishes' && !str_starts_with($signal, $id.'.')) throw new RuntimeException('Published plugin signals must use the plugin ID prefix.');
            if (in_array($signal, $valid[$kind], true)) throw new RuntimeException('Plugin signals declaration must not contain duplicates.');
            $valid[$kind][] = $signal;
        }
    }
    return $valid;
}

function extension_declared_links(string $surface): array
{
    static $cache = [];
    if (!in_array($surface, ExtensionLink::SURFACES, true)) return [];
    $key = cache_version('plugins');
    if (isset($cache[$key])) return $cache[$key][$surface] ?? [];
    $links = [];
    foreach (extension_runtime_plugins() as $record) {
        $id = (string)($record['name'] ?? '');
        try {
            $manifest = extension_plugin_manifest($id);
            if (!is_array($manifest)) continue;
            $defaults = extension_manifest_entry_defaults($manifest);
            $config = extension_config_decode((string)($record['config_json'] ?? '{}'));
            foreach (extension_manifest_links($manifest) as $link) {
                $enabled = $config['_entries'][$link->id] ?? ($defaults[$link->id] ?? true);
                if ($enabled === true) $links[$link->surface][] = $link;
            }
        } catch (Throwable $error) { error_log('extension links '.$id.' failed: '.$error->getMessage()); }
    }
    $cache[$key] = $links;
    return $links[$surface] ?? [];
}

function extension_render_links(string $surface, ?array $viewer = null, array $view = []): string
{
    $html = '';
    $links = [];
    foreach (extension_declared_links($surface) as $link) {
        $visible = match ($link->when) {
            'guest' => $viewer === null,
            'member' => $viewer !== null,
            'admin' => ($viewer['role'] ?? null) === 'admin',
            default => true,
        };
        if (!$visible) continue;
        $links[$link->id] ??= $link;
    }
    if (in_array($surface, ['navigation.primary','admin.navigation'], true)) {
        extension_runtime_load(null, 'navigation_links', 'navigation_links');
        foreach (extension_registry()['navigation_links'][$surface] ?? [] as $entry) {
            if (!extension_plugin_degraded($entry['plugin'])) $links[$entry['link']->id] ??= $entry['link'];
        }
    }
    uasort($links, static fn(ExtensionLink|NavigationLink $a, ExtensionLink|NavigationLink $b): int => ($b->priority <=> $a->priority) ?: ($a->id <=> $b->id));
    $context = [
        'viewer_id'=>$viewer['id'] ?? null,
        'topic_id'=>$view['topic']['id'] ?? null,
        'category_id'=>$view['category']['id'] ?? $view['topic']['category_id'] ?? null,
        'profile_username'=>$view['profile']['username'] ?? null,
    ];
    foreach ($links as $link) {
        $href = $link instanceof ExtensionLink ? $link->url($context) : $link->href;
        if ($href !== null) $html .= '<a class="extension-link extension-link-'.e($link->id).'" href="'.e($href).'">'.e($link->label).'</a>';
    }
    return $html;
}

final class PluginRouteRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string|array $methods, string $pattern, callable $handler, array $options = []): void
    {
        $this->front($methods, $pattern, $handler, $options);
    }

    public function front(string|array $methods, string $pattern, callable $handler, array $options = []): void
    {
        $methods = array_map('strtoupper', (array)$methods);
        extension_validate_public_plugin_route($this->id, $methods, $pattern);
        $options = extension_route_options($methods, $options);
        foreach ($this->registry['routes'] as $route) {
            if ($route['plugin'] === $this->id && $route['pattern'] === $pattern && array_intersect($route['methods'], $methods)) {
                throw new LogicException('Duplicate plugin route registration.');
            }
        }
        $this->registry['routes'][] = ['plugin'=>$this->id, 'methods'=>$methods, 'pattern'=>$pattern, 'handler'=>$handler, 'options'=>$options];
    }
}

final class PluginRequestRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function before(callable $handler, int $priority = 0): void
    {
        extension_validate_priority($priority);
        $this->registry['middleware']['request.before'][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler];
    }
}

final class PluginSlotRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $slot, callable $renderer, int $priority = 0): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $slot)) throw new InvalidArgumentException('Invalid plugin slot.');
        if (!extension_hook_contract('slot', $slot)) throw new InvalidArgumentException('Unknown plugin slot.');
        extension_validate_priority($priority);
        $this->registry['slots'][$slot][] = ['plugin'=>$this->id, 'priority'=>$priority, 'renderer'=>$renderer];
    }
}

final class PluginEventRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function listen(string $event, callable $listener): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $event)) throw new InvalidArgumentException('Invalid plugin event.');
        if (!extension_hook_contract('event', $event)) throw new InvalidArgumentException('Unknown plugin event.');
        $this->registry['events'][$event][] = ['plugin'=>$this->id, 'listener'=>$listener];
    }
}

/** Namespaced, manifest-declared plugin-to-plugin events. */
final class PluginSignalRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function emit(string $signal, array $payload = []): void
    {
        extension_emit_signal($this->id, $signal, $payload);
    }

    public function listen(string $signal, callable $listener): void
    {
        $manifest = extension_plugin_manifest($this->id);
        if (!is_array($manifest) || !in_array($signal, extension_manifest_signals($manifest, $this->id)['subscribes'], true)) {
            throw new InvalidArgumentException('Plugin signal subscription is not declared in the manifest.');
        }
        $this->registry['signals'][$signal][] = ['plugin'=>$this->id, 'listener'=>$listener];
    }
}

final class PluginPreloadRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $slot, callable $loader): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $slot)) throw new InvalidArgumentException('Invalid plugin preload slot.');
        if (!extension_hook_contract('preload', $slot)) throw new InvalidArgumentException('Unknown plugin preload slot.');
        $this->registry['preloads'][$slot][] = ['plugin'=>$this->id, 'loader'=>$loader];
    }
}

final class PluginAssetRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function css(string $path, array $pages = ['all']): void
    {
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.css$~', $path)) throw new InvalidArgumentException('Invalid plugin CSS asset.');
        $this->registry['assets'][] = ['plugin'=>$this->id, 'path'=>$path, 'type'=>'css', 'pages'=>extension_asset_pages($pages)];
    }

    public function js(string $path, array $pages = ['all']): void
    {
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.js$~', $path)) throw new InvalidArgumentException('Invalid plugin JavaScript asset.');
        $this->registry['assets'][] = ['plugin'=>$this->id, 'path'=>$path, 'type'=>'js', 'pages'=>extension_asset_pages($pages)];
    }

    /** Request a declared asset only when the plugin actually rendered it. */
    public function require(string $path): void
    {
        extension_require_asset($this->id, $path);
    }

    public function json(array $data): \LiteBBS\Framework\Response
    {
        $assets = extension_asset_handles(null, $this->id);
        if ($assets) $data['assets'] = $assets;
        return (new \LiteBBS\Framework\Response())->json($data)->header('Cache-Control', 'private, no-store');
    }
}

final class PluginContentRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function markdown(callable $renderer, int $priority = 0): void
    {
        extension_validate_priority($priority);
        $this->registry['renderers']['post.markdown'][] = ['plugin'=>$this->id, 'priority'=>$priority, 'renderer'=>$renderer];
    }
}

final class PluginFilterRegistrar
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function add(string $filter, callable $handler, int $priority = 0): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $filter)) throw new InvalidArgumentException('Invalid plugin filter.');
        if (!extension_hook_contract('filter', $filter)) throw new InvalidArgumentException('Unknown plugin filter.');
        extension_validate_priority($priority);
        $this->registry['filters'][$filter][] = ['plugin'=>$this->id, 'priority'=>$priority, 'handler'=>$handler];
    }
}

final class PluginSettings
{
    private array $registry;

    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }

    public function get(): array { return $this->registry['plugins'][$this->id]['config'] ?? []; }

    public function values(array $schema): array
    {
        return extension_plugin_config_values($this->get(), $schema);
    }

    public function save(array $config): void
    {
        if (!extension_config_value_valid($config)) throw new InvalidArgumentException('Plugin configuration must contain JSON-compatible scalar values, arrays, and objects only.');
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65535) throw new InvalidArgumentException('Plugin configuration exceeds 64 KiB.');
        h('UPDATE plugins SET config_json=? WHERE name=? AND enabled=1', [$json, $this->id]);
        if (!h('SELECT 1 FROM plugins WHERE name=? AND enabled=1', [$this->id])->fetchColumn()) throw new RuntimeException('Plugin configuration could not be saved.');
        $this->registry['plugins'][$this->id]['config'] = $config;
        audit('plugin.config.updated', 'plugin', null, ['name'=>$this->id]);
        extension_invalidate('plugins');
    }
}

final class PluginCache
{
    public function __construct(private string $id) {}

    public function remember(string $key, int $ttl, callable $loader): mixed
    {
        $baseKey = $this->key($key);
        $key = $baseKey.':'.cache_version($baseKey);
        $state =& request_cache_state();
        $existing = read_cache($key, max(1, min(86400, $ttl)));
        if ($existing !== null) {
            $state['@plugin-cache-hits:'.$this->id] = ((int)($state['@plugin-cache-hits:'.$this->id] ?? 0)) + 1;
            return $existing['value'] ?? null;
        }
        $state['@plugin-cache-misses:'.$this->id] = ((int)($state['@plugin-cache-misses:'.$this->id] ?? 0)) + 1;
        $value = cached($key, max(1, min(86400, $ttl)), static function () use ($loader): array {
            $loaded = $loader();
            if (!extension_config_value_valid($loaded)) throw new RuntimeException('Plugin cache values must be JSON-compatible.');
            return ['value'=>$loaded];
        });
        return $value['value'] ?? null;
    }

    public function forget(string $key): void { invalidate($this->key($key)); }

    private function key(string $key): string
    {
        if (!preg_match('/^[a-z][a-z0-9._:-]{0,95}$/', $key)) throw new InvalidArgumentException('Invalid plugin cache key.');
        return 'plugin:'.$this->id.':'.$key;
    }
}

final class PluginDatabase
{
    private string $prefix;

    public function __construct(string $id) { $this->prefix = 'plg_'.str_replace('-', '_', $id).'_'; }

    public function table(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,55}$/', $name)) throw new InvalidArgumentException('Invalid plugin table name.');
        return $this->prefix.$name;
    }

    public function query(string $sql, array $parameters = []): PDOStatement
    {
        $normalized = trim($sql);
        if (str_contains($normalized, ';') || preg_match('~(?:--|/\*|\*/)~', $normalized) || !preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/i', $normalized) || preg_match('/\b(?:ALTER|CREATE|DROP|TRUNCATE|GRANT|REVOKE|RENAME|CALL|LOAD|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', $normalized)) throw new RuntimeException('Plugin queries must be a single comment-free DML statement on plugin tables; use migrations for schema changes.');
        preg_match_all('/\b(?:FROM|JOIN|INTO)\s+(`?[a-zA-Z0-9_]+`?)/i', $normalized, $matches);
        $tables = array_map(static fn(string $table): string => trim($table, '`'), $matches[1] ?? []);
        if (preg_match('/^UPDATE\s+(`?[a-zA-Z0-9_]+`?)/i', $normalized, $update)) $tables[] = trim($update[1], '`');
        $tables = array_values(array_unique($tables));
        if (!$tables) throw new RuntimeException('Plugin query must reference a plugin table.');
        foreach ($tables as $table) if (!str_starts_with($table, $this->prefix)) throw new RuntimeException('Plugin queries may only reference the plugin table prefix.');
        return h($normalized, $parameters);
    }

}

final class PluginLogger
{
    public function __construct(private string $id) {}
    public function error(string $message): void { error_log('plugin['.$this->id.'] '.$message); }
}

function &extension_registry(): array
{
    static $registry;
    $registry ??= array_fill_keys(array_values(extension_manifest_hook_keys()), []) + [
        'plugins'=>[], 'routes'=>[], 'signals'=>[], 'assets'=>[], 'commands'=>[], 'policies'=>[],
        'post_actions'=>[], 'post_action_providers'=>[], 'post_views'=>[], 'topic_title_suffixes'=>[],
        'topic_list_columns'=>[], 'profile_tabs'=>[], 'public_profile_tabs'=>[], 'notification_tabs'=>[],
        'notification_presenters'=>[], 'avatar_providers'=>[], 'seo_providers'=>[], 'authentication_providers'=>[], 'navigation_links'=>[], 'admin_pages'=>[], 'tasks'=>[], 'search'=>[], 'user_fields'=>[],
    ];
    return $registry;
}

function extension_config_decode(string $json): array
{
    $config = json_decode($json, true);
    return is_array($config) ? $config : [];
}

function extension_manifest_hook_keys(): array
{
    return ['boot'=>'boot', 'middleware'=>'middleware', 'slot'=>'slots', 'preload'=>'preloads', 'event'=>'events', 'renderer'=>'renderers', 'filter'=>'filters', 'selection'=>'selections', 'read_transform'=>'read_transforms', 'view'=>'views'];
}

function extension_validate_priority(int $priority): void
{
    if ($priority < -1000 || $priority > 1000) throw new InvalidArgumentException('Invalid plugin priority.');
}

function extension_manifest_hooks(array $manifest, string $kind): array
{
    if ($kind === 'signals') return extension_manifest_signals($manifest, (string)($manifest['id'] ?? ''))['subscribes'];
    if ($kind === 'commands') return extension_manifest_named_capabilities($manifest, 'commands', ['topic.create', 'reply.create', 'topic.update', 'post.update', 'post.delete', 'user.profile.update']);
    if ($kind === 'policies') return extension_manifest_named_capabilities($manifest, 'policies', ['category.view', 'topic.view', 'attachment.download', 'topic.create', 'topic.reply', 'topic.edit', 'post.edit', 'topic.delete', 'post.delete', 'profile.view', 'user.username_reserved', 'user.can_speak']);
    if (in_array($kind, ['post_actions', 'post_views', 'topic_lists', 'profile_tabs', 'public_profile_tabs', 'notification_tabs', 'notification_presenters', 'avatar_providers', 'seo_providers', 'authentication_providers', 'navigation_links', 'search', 'tasks'], true)) return !empty(extension_manifest_extension_flags($manifest)[$kind]) ? [$kind === 'tasks' ? 'registered' : $kind] : [];
    $key = extension_manifest_hook_keys()[$kind] ?? throw new InvalidArgumentException('Invalid plugin hook kind.');
    $declarations = $manifest['hooks'] ?? [];
    if (!is_array($declarations) || array_diff_key($declarations, array_flip(extension_manifest_hook_keys()))) throw new RuntimeException('Unknown or invalid plugin hook declaration.');
    $hooks = array_key_exists($key, $declarations) ? $declarations[$key] : [];
    if (!is_array($hooks) || !array_is_list($hooks)) throw new RuntimeException('Plugin '.$key.' declaration must be a list.');
    foreach ($hooks as $hook) {
        if (!is_string($hook) || !preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $hook)) throw new RuntimeException('Invalid plugin '.$key.' declaration.');
        if (!extension_hook_contract($kind, $hook)) throw new RuntimeException('Unknown plugin '.$key.' declaration: '.$hook);
    }
    if (count($hooks) !== count(array_unique($hooks))) throw new RuntimeException('Duplicate plugin '.$key.' declaration.');
    return $hooks;
}

function extension_manifest_assets(array $manifest): ?array
{
    if (!array_key_exists('assets', $manifest)) return null;
    if (!is_array($manifest['assets'])) throw new RuntimeException('Plugin assets declaration must be an array.');
    $assets = [];
    foreach ($manifest['assets'] as $asset) {
        if (is_string($asset)) $asset = ['path'=>$asset];
        if (!is_array($asset)) throw new RuntimeException('Invalid plugin asset declaration.');
        $path = $asset['path'] ?? '';
        if (!is_string($path) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.(css|js)$~D', $path, $match)) throw new RuntimeException('Invalid plugin asset declaration.');
        $pages = extension_asset_pages($asset['pages'] ?? ['all']);
        // Common JS must be a self-contained classic script. Common CSS must not depend on its source URL.
        $common = $asset['common'] ?? false;
        if (!is_bool($common) || ($common && $pages !== ['all'])) throw new RuntimeException('Common assets must be declared for all pages.');
        if (isset($assets[$path]) && $assets[$path]['common'] !== $common) throw new RuntimeException('Conflicting common asset declarations.');
        $pages = array_values(array_unique(array_merge($assets[$path]['pages'] ?? [], $pages)));
        $assets[$path] = ['path'=>$path, 'type'=>$match[1], 'pages'=>$pages, 'common'=>$common];
    }
    return array_values($assets);
}

function extension_asset_pages(array $pages): array
{
    $allowed = ['all','manual','home','category','topic.view','topic.create','post.edit','profile','member','search','plugin','admin'];
    if (!$pages || count($pages) > 16) throw new InvalidArgumentException('Invalid plugin asset page declaration.');
    foreach ($pages as $page) if (!is_string($page) || !in_array($page, $allowed, true)) throw new InvalidArgumentException('Invalid plugin asset page declaration.');
    return array_values(array_unique($pages));
}

function extension_asset_matches(array $asset, string $path): bool
{
    $surfaces = ['all'];
    if ($path === '/new') $surfaces[] = 'topic.create';
    elseif (preg_match('~^/p/[0-9]+/edit$~', $path)) $surfaces[] = 'post.edit';
    elseif (str_starts_with($path, '/plugin/')) $surfaces[] = 'plugin';
    elseif ($path === '/admin' || str_starts_with($path, '/admin/')) $surfaces[] = 'admin';
    else $surfaces[] = theme_asset_surface($path);
    return (bool)array_intersect($asset['pages'] ?? ['all'], $surfaces);
}

function extension_declared_assets(?string $onlyPlugin = null): array
{
    static $assets = [];
    $version = cache_version('plugins');
    $key = $version.':'.($onlyPlugin ?? '*');
    if (!isset($assets[$key])) {
        $declared = [];
        foreach (extension_runtime_plugins() as $record) {
            $id = (string)($record['name'] ?? '');
            if ($onlyPlugin !== null && $id !== $onlyPlugin) continue;
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
            try {
                $manifest = extension_plugin_manifest($id);
                if ($manifest === null) continue;
                foreach (extension_manifest_assets($manifest) ?? [] as $asset) {
                    $declared[] = ['plugin'=>$id] + $asset;
                }
            } catch (Throwable $error) {
                error_log('extension assets '.$id.' failed: '.$error->getMessage());
            }
        }
        $assets[$key] = array_values($declared);
    }
    return $assets[$key];
}

function extension_config_value_valid(mixed $value, int $depth = 0): bool
{
    if ($depth > 12) return false;
    if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) return true;
    if (!is_array($value)) return false;
    foreach ($value as $key => $item) if ((!is_int($key) && (!is_string($key) || strlen($key) > 128)) || !extension_config_value_valid($item, $depth + 1)) return false;
    return true;
}

function extension_plugin_manifest(string $id): ?array
{
    static $cache = [];
    if (!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $id)) return null;
    $directory = dirname(__DIR__).'/plugins/'.$id;
    $file = is_link($directory) ? null : extension_secure_runtime_file($directory, 'plugin.json');
    if ($file === null) return null;
    $signature = (string)@filemtime($file).':'.(string)@filesize($file);
    if (($cache[$id]['signature'] ?? null) === $signature) return $cache[$id]['manifest'];
    try {
        $manifest = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $cache[$id] = ['signature'=>$signature, 'manifest'=>is_array($manifest) ? $manifest : null];
        return $cache[$id]['manifest'];
    } catch (Throwable $error) {
        error_log('extension manifest '.$id.' failed: '.$error->getMessage());
        $cache[$id] = ['signature'=>$signature, 'manifest'=>null];
        return null;
    }
}

function extension_enabled_plugins(): array
{
    return cached('extensions:v1:'.cache_version('plugins'), 30, static function (): array {
        return h('SELECT name,version,config_json FROM plugins WHERE enabled=1')->fetchAll();
    });
}

function extension_runtime_plugins(): array
{
    static $eligible = [];
    $generation = cache_version('plugins');
    if (isset($eligible[$generation])) return $eligible[$generation];

    $enabled = array_column(extension_enabled_plugins(), null, 'name');
    $versions = [];
    foreach ($enabled as $id => $record) $versions[$id] = ['version'=>(string)$record['version'], 'enabled'=>true];
    $plugins = [];
    $dependencies = [];
    foreach ($enabled as $id => $record) {
        try {
            $manifest = extension_plugin_manifest($id);
            $version = $manifest['version'] ?? null;
            $core = $manifest['requires']['core'] ?? null;
            if (($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? null) !== $id || !is_string($version)
                || !preg_match('/\A\d+\.\d+\.\d+\z/', $version) || $version !== $record['version']) {
                throw new RuntimeException('Deployed plugin manifest must match the installed ID and version.');
            }
            if (!is_string($core) || !extension_supports_core($core)) throw new RuntimeException('Plugin is incompatible with this core version.');
            $report = extension_dependency_report($id, $manifest, true, $versions);
            if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
            $dependencies[$id] = extension_manifest_relationships($manifest)['dependencies'];
            $plugins[$id] = $record;
        } catch (Throwable $error) {
            error_log('extension plugin '.$id.' skipped: '.$error->getMessage());
        }
    }
    // A dependency can be enabled in the database yet ineligible on disk.
    do {
        $changed = false;
        foreach ($plugins as $id => $record) {
            if (!array_diff_key($dependencies[$id], $plugins)) continue;
            unset($plugins[$id]);
            $changed = true;
            error_log('extension plugin '.$id.' skipped: a dependency is unavailable at runtime.');
        }
    } while ($changed);
    return $eligible[$generation] = $plugins;
}

function extension_candidate_plugins(string $kind, ?string $hook): array
{
    if ($hook === null) return extension_runtime_plugins();
    static $indexes = [];
    $scanKey = cache_version('plugins').':'.$kind;
    if (!isset($indexes[$scanKey])) {
        $built = [];
        foreach (extension_runtime_plugins() as $id => $record) {
            try {
                $manifest = extension_plugin_manifest($id);
                if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
                $hooks = extension_manifest_hooks($manifest, $kind);
                foreach ($hooks as $declaredHook) $built[$declaredHook][] = $record;
            } catch (Throwable $error) {
                error_log('extension manifest '.$id.' failed: '.$error->getMessage());
            }
        }
        $indexes[$scanKey] = $built;
    }
    return $indexes[$scanKey][$hook] ?? [];
}

function extension_validate_public_plugin_route(string $id, array $methods, string $pattern): void
{
    if (!$methods || array_diff($methods, ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS']) || count($methods) !== count(array_unique($methods))) throw new InvalidArgumentException('Invalid plugin route methods.');
    if (str_contains($pattern, "\0") || preg_match('/[\r\n]/', $pattern) || !preg_match('~^(.)(.*)\\1([a-z]*)$~sD', $pattern, $match)) throw new InvalidArgumentException('Invalid plugin route pattern.');
    $manifest = extension_plugin_manifest($id);
    $prefix = is_array($manifest) ? extension_manifest_public_route_prefix($manifest) : null;
    if ($prefix === null) throw new InvalidArgumentException('Plugin must declare a public_route_prefix before registering front-end routes.');
    $body = $match[2];
    if (!preg_match('~^\^/'.preg_quote($prefix, '~').'(?:/|\$)~', $body) || !str_ends_with($body, '$') || str_contains($body, '|') || @preg_match($pattern, '') === false) throw new InvalidArgumentException('Plugin routes must be exact routes within their declared public prefix.');
}

function extension_public_route_plugin(string $path): ?string
{
    if (!preg_match('~^/([a-z][a-z0-9-]{1,63})(?:/|$)~', $path, $match)) return null;
    $prefix = $match[1];
    // These built-in namespaces can never be valid V2 plugin prefixes. Avoid
    // loading the enabled-plugin registry on every normal forum request.
    if (in_array($prefix, ['admin','plugin','plugin-assets','assets','uploads','install','login','logout','register','me','new','search','market','robots','sitemap','t','c','u','p','avatar','messages','topic','post','report','user'], true)) return null;
    static $maps = [];
    $version = cache_version('plugins');
    if (!isset($maps[$version])) {
        $maps[$version] = (static function (): array {
            $map = [];
            foreach (extension_runtime_plugins() as $record) {
                $id = (string)($record['name'] ?? '');
                if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
                try {
                    $manifest = extension_plugin_manifest($id);
                    $declared = is_array($manifest) ? extension_manifest_public_route_prefix($manifest) : null;
                    if ($declared === null) continue;
                    if (isset($map[$declared])) {
                        error_log('duplicate public plugin route prefix: '.$declared);
                        $map[$declared] = '';
                    } else $map[$declared] = $id;
                } catch (Throwable $error) {
                    error_log('extension manifest '.$id.' failed: '.$error->getMessage());
                }
            }
            return $map;
        })();
    }
    $owner = (string)($maps[$version][$prefix] ?? '');
    return $owner !== '' ? $owner : null;
}

function extension_plugin_asset_response(string $plugin, string $asset): \LiteBBS\Framework\Response
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $plugin) || $asset === '' || str_contains($asset, "\0")) return new \LiteBBS\Framework\Response('Not Found', 404);
    if (!isset(extension_runtime_plugins()[$plugin])) return new \LiteBBS\Framework\Response('Not Found', 404);

    $manifest = extension_plugin_manifest($plugin);
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $plugin) return new \LiteBBS\Framework\Response('Not Found', 404);
    $assets = extension_manifest_assets($manifest);
    $type = null;
    foreach ($assets ?? [] as $entry) {
        if (($entry['path'] ?? '') === $asset && in_array($entry['type'] ?? '', ['css', 'js'], true)) { $type = $entry['type']; break; }
    }
    if ($type === null) return new \LiteBBS\Framework\Response('Not Found', 404);

    $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$plugin, $asset);
    if ($file === null) return new \LiteBBS\Framework\Response('Not Found', 404);
    return extension_asset_http_response($type, asset_version($file), static fn() => file_get_contents($file));
}

function extension_asset_http_response(string $type, string $version, callable $read, string $unversionedCache = 'public, max-age=3600'): \LiteBBS\Framework\Response
{
    $etag = '"'.$version.'"';
    $cacheControl = isset($_GET['v']) && is_string($_GET['v']) && hash_equals($version, $_GET['v'])
        ? 'public, max-age=31536000, immutable'
        : $unversionedCache;
    if (hash_equals($etag, (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))) {
        return (new \LiteBBS\Framework\Response('', 304))
            ->header('ETag', $etag)
            ->header('Cache-Control', $cacheControl);
    }
    $body = $read();
    if (!is_string($body)) return new \LiteBBS\Framework\Response('Not Found', 404);
    return (new \LiteBBS\Framework\Response($body))
        ->header('Content-Type', $type === 'js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8')
        ->header('ETag', $etag)
        ->header('Cache-Control', $cacheControl)
        ->header('X-Content-Type-Options', 'nosniff');
}

function extension_route_options(array $methods, array $options): array
{
    $unknown = array_diff(array_keys($options), ['auth', 'csrf']);
    if ($unknown) throw new InvalidArgumentException('Unknown plugin route option.');
    $auth = (string)($options['auth'] ?? 'public');
    if (!in_array($auth, ['public', 'user', 'moderator', 'admin'], true)) throw new InvalidArgumentException('Invalid plugin route authorization.');
    $unsafeMethods = array_diff($methods, ['GET', 'HEAD', 'OPTIONS']);
    if ($unsafeMethods && $methods !== ['POST']) throw new InvalidArgumentException('Plugin state-changing routes must be POST-only.');
    $csrf = $unsafeMethods ? true : (bool)($options['csrf'] ?? false);
    if ($csrf && $methods !== ['POST']) throw new InvalidArgumentException('Plugin CSRF enforcement requires a POST-only route.');
    return ['auth'=>$auth, 'csrf'=>$csrf];
}

function extension_authorize_route(array $options): void
{
    $auth = (string)($options['auth'] ?? 'public');
    if ($auth === 'admin') {
        admin();
    } elseif ($auth !== 'public') {
        $user = current_user();
        if (!$user) redirect('/login');
        if ($auth === 'moderator' && !in_array((string)($user['role'] ?? ''), ['moderator', 'admin'], true)) fail(403, '无权访问');
    }
    if (!empty($options['csrf'])) require_post();
}

function extension_runtime_load(?string $onlyPlugin = null, ?string $hookKind = null, ?string $hook = null): array
{
    static $loaded = [];
    $registry =& extension_registry();
    $enabled = $hookKind === null ? extension_runtime_plugins() : extension_candidate_plugins($hookKind, $hook);
    
    $root = dirname(__DIR__).'/plugins/';
    $registryChanged = false;
    foreach ($enabled as $record) {
        $id = (string)($record['name'] ?? '');
        if ($onlyPlugin !== null && $id !== $onlyPlugin) continue;
        if (isset($loaded[$id])) continue;
        $loaded[$id] = true;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) continue;
        try {
            $manifest = extension_plugin_manifest($id);
            if (!is_array($manifest)) throw new RuntimeException('Invalid plugin manifest.');
            $entry = (string)($manifest['entry'] ?? '');
            $class = (string)($manifest['class'] ?? '');
            $entryFile = extension_v2_entry_file($root.$id, $entry);
            if (($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? '') !== $id || $entryFile === null || !extension_class_name_valid($class)) throw new RuntimeException('Invalid plugin manifest.');
            $permissions = extension_manifest_permissions($manifest);
            extension_register_plugin_autoloader($id, $root.$id, $manifest);
            $declaredHooks = [];
            foreach (extension_manifest_hook_keys() as $kind => $key) $declaredHooks[$key] = extension_manifest_hooks($manifest, $kind);
            $manifestAssets = extension_manifest_assets($manifest) ?? [];
            extension_manifest_admin_path($manifest, $id);
            extension_manifest_public_route_prefix($manifest);
            $declaredSignals = extension_manifest_signals($manifest, $id);
            $declaredCommands = extension_manifest_named_capabilities($manifest, 'commands', ['topic.create', 'reply.create', 'topic.update', 'post.update', 'post.delete', 'user.profile.update']);
            $declaredPolicies = extension_manifest_named_capabilities($manifest, 'policies', ['category.view', 'topic.view', 'attachment.download', 'topic.create', 'topic.reply', 'topic.edit', 'post.edit', 'topic.delete', 'post.delete', 'profile.view', 'user.username_reserved', 'user.can_speak']);
            $declaredExtensions = extension_manifest_extension_flags($manifest);
            require_once $entryFile;
            $plugin = new $class();
            if (!$plugin instanceof LiteBBSPlugin) throw new RuntimeException('Plugin entry must implement LiteBBSPlugin.');
            $config = extension_config_decode((string)($record['config_json'] ?? '{}'));
            $temporary = array_fill_keys(array_keys($registry), []);
            $temporary['plugins'][$id] = ['config'=>$config];
            $context = new PluginContext($id, $permissions, $temporary);
            $outputLevel = ob_get_level();
            ob_start();
            try { $plugin->boot($context); $bootOutput = (string)ob_get_clean(); }
            catch (Throwable $error) { while (ob_get_level() > $outputLevel) ob_end_clean(); throw $error; }
            if ($bootOutput !== '') throw new RuntimeException('Plugin boot must not produce output.');
            foreach ($declaredHooks as $key => $declared) {
                $registered = array_keys($temporary[$key]);
                if (array_diff($registered, $declared) || array_diff($declared, $registered)) throw new RuntimeException('Plugin '.$key.' registrations must exactly match its manifest declaration.');
            }
            $registeredSignals = array_keys($temporary['signals']);
            if (array_diff($registeredSignals, $declaredSignals['subscribes']) || array_diff($declaredSignals['subscribes'], $registeredSignals)) throw new RuntimeException('Plugin signal registrations must exactly match its manifest declaration.');
            if (array_diff(array_keys($temporary['commands']), $declaredCommands) || array_diff($declaredCommands, array_keys($temporary['commands']))) throw new RuntimeException('Plugin command registrations must exactly match its manifest declaration.');
            if (array_diff(array_keys($temporary['policies']), $declaredPolicies) || array_diff($declaredPolicies, array_keys($temporary['policies']))) throw new RuntimeException('Plugin policy registrations must exactly match its manifest declaration.');
            foreach (['post_actions', 'post_views', 'topic_lists', 'profile_tabs', 'public_profile_tabs', 'notification_tabs', 'notification_presenters', 'avatar_providers', 'seo_providers', 'authentication_providers', 'navigation_links', 'search', 'tasks'] as $extension) {
                $registered = $extension === 'post_actions' ? !empty($temporary['post_actions']) || !empty($temporary['post_action_providers']) : ($extension === 'topic_lists' ? !empty($temporary['topic_title_suffixes']) || !empty($temporary['topic_list_columns']) : !empty($temporary[$extension]));
                if ((bool)$declaredExtensions[$extension] !== $registered) throw new RuntimeException('Plugin '.$extension.' registrations must match its manifest declaration.');
            }
            $declaredAssets = [];
            foreach ($manifestAssets as $asset) $declaredAssets[$asset['path']] = $asset;
            foreach ($temporary['assets'] as $asset) {
                $declared = $declaredAssets[$asset['path']] ?? null;
                if ($declared === null || $declared['type'] !== $asset['type'] || array_diff($asset['pages'], $declared['pages'])) throw new RuntimeException('Plugin asset registration is missing from the manifest declaration.');
            }
            foreach ($temporary as $key => $registrations) {
                if ($key === 'plugins' || !$registrations) continue;
                if (isset($declaredHooks[$key]) || in_array($key, ['signals','commands','policies'], true)) foreach ($registrations as $slot => $entries) $registry[$key][$slot] = array_merge($registry[$key][$slot] ?? [], $entries);
                elseif ($key === 'navigation_links') foreach ($temporary[$key] as $surface => $entries) $registry[$key][$surface] = array_merge($registry[$key][$surface] ?? [], $entries);
                else $registry[$key] = array_merge($registry[$key], $registrations);
            }
            $registry['plugins'][$id] = ['manifest'=>$manifest, 'plugin'=>$plugin, 'config'=>$config];
            $registryChanged = true;
        } catch (Throwable $error) {
            error_log('extension plugin '.$id.' disabled: '.$error->getMessage());
        }
    }
    if ($registryChanged) {
        foreach (['boot','middleware','slots','renderers','filters','selections','read_transforms','views','commands','policies','post_action_providers','post_views','topic_title_suffixes'] as $kind) {
            foreach ($registry[$kind] as &$entries) {
                if (count($entries) > 1) usort($entries, static fn(array $a, array $b): int => ($b['priority'] <=> $a['priority']) ?: (($a['plugin'] ?? '') <=> ($b['plugin'] ?? '')));
            }
            unset($entries);
        }
        if (count($registry['topic_list_columns']) > 1) {
            uasort($registry['topic_list_columns'], static fn(array $a, array $b): int => ($b['column']->priority <=> $a['column']->priority) ?: ($a['column']->id <=> $b['column']->id));
        }
        foreach ($registry['signals'] as &$entries) {
            if (count($entries) > 1) usort($entries, static fn(array $a, array $b): int => $a['plugin'] <=> $b['plugin']);
        }
        unset($entries);
    }
    return $registry['plugins'];
}

function extension_runtime_register_routes(\LiteBBS\Framework\Router $router, ?string $onlyPlugin = null): void
{
    extension_runtime_load($onlyPlugin);
    foreach (extension_registry()['routes'] as $route) {
        if ($onlyPlugin !== null && $route['plugin'] !== $onlyPlugin) continue;
        $router->add($route['methods'], $route['pattern'], static function (\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params) use ($route): \LiteBBS\Framework\Response {
            try {
                extension_authorize_route($route['options'] ?? []);
                $outputLevel = ob_get_level();
                ob_start();
                try { $result = ($route['handler'])($request, $params); $output = (string)ob_get_clean(); }
                catch (Throwable $error) { while (ob_get_level() > $outputLevel) ob_end_clean(); throw $error; }
                if ($result instanceof \LiteBBS\Framework\Response) {
                    if ($output !== '') $result->body = $output.$result->body;
                    return $result;
                }
                return new \LiteBBS\Framework\Response($output.(is_string($result) ? $result : ''));
            } catch (Throwable $error) {
                if ($error instanceof \LiteBBS\Framework\ResponseHalt) return $error->response;
                error_log('plugin route '.$route['plugin'].' failed: '.$error->getMessage());
                return new \LiteBBS\Framework\Response('Plugin temporarily unavailable.', 503);
            }
        });
    }
    foreach (extension_registry()['admin_pages'] as $adminPage) {
        if ($onlyPlugin !== null && $adminPage['plugin'] !== $onlyPlugin) continue;
        if (!empty($adminPage['plugin_management'])) continue;
        $router->add(['GET','POST'], '~^'.preg_quote($adminPage['path'], '~').'$~', static function (\LiteBBS\Framework\Request $request) use ($adminPage): \LiteBBS\Framework\Response {
            try {
                $user = current_user();
                if (!$user) redirect('/login');
                if ($adminPage['permission'] === 'admin' && ($user['role'] ?? '') !== 'admin') fail(403, '无权访问');
                if ($adminPage['permission'] === 'moderator' && !in_array((string)($user['role'] ?? ''), ['moderator','admin'], true)) fail(403, '无权访问');
                if ($request->method === 'POST') require_post();
                $result = ($adminPage['handler'])($request);
                if (!is_array($result) || !isset($result['body'])) throw new RuntimeException('Admin page handler must return a body array.');
                if ($request->method === 'POST' && $adminPage['audit'] !== '') audit($adminPage['audit'], 'plugin', null, ['name'=>$adminPage['plugin']]);
                require_once __DIR__.'/admin.php';
                ob_start();
                admin_page((string)($result['title'] ?? $adminPage['title']), (string)$result['body']);
                return new \LiteBBS\Framework\Response((string)ob_get_clean());
            } catch (\LiteBBS\Framework\ResponseHalt $halt) { return $halt->response; }
            catch (Throwable $error) { error_log('plugin admin '.$adminPage['plugin'].' failed: '.$error->getMessage()); return new \LiteBBS\Framework\Response('Plugin temporarily unavailable.', 503); }
        });
    }
}

function extension_plugin_config_values(array $config, array $schema): array
{
    if (count($schema) > 64) throw new InvalidArgumentException('Plugin configuration schema is too large.');
    $values = [];
    foreach ($schema as $key=>$definition) {
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) || !is_array($definition)) throw new InvalidArgumentException('Plugin configuration schema is invalid.');
        $type = (string)($definition['type'] ?? '');
        $default = $definition['default'] ?? null;
        $value = $config[$key] ?? $default;
        $valid = match($type) {
            'bool' => is_bool($value),
            'int' => is_int($value) && $value >= (int)($definition['min'] ?? PHP_INT_MIN) && $value <= (int)($definition['max'] ?? PHP_INT_MAX),
            'string' => is_string($value) && text_length($value) <= max(0, min(4096, (int)($definition['max_length'] ?? 255))),
            'enum' => is_string($value) && in_array($value, is_array($definition['values'] ?? null) ? $definition['values'] : [], true),
            default => false,
        };
        if (!$valid) {
            $defaultValid = match($type) {
                'bool' => is_bool($default),
                'int' => is_int($default) && $default >= (int)($definition['min'] ?? PHP_INT_MIN) && $default <= (int)($definition['max'] ?? PHP_INT_MAX),
                'string' => is_string($default) && text_length($default) <= max(0, min(4096, (int)($definition['max_length'] ?? 255))),
                'enum' => is_string($default) && in_array($default, is_array($definition['values'] ?? null) ? $definition['values'] : [], true),
                default => false,
            };
            if (!$defaultValid) throw new InvalidArgumentException('Plugin configuration schema default is invalid.');
            $value = $default;
        }
        $values[$key] = $value;
    }
    return $values;
}

function extension_require_asset(string $plugin, string $path): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $plugin) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.(?:css|js)$~', $path)) {
        throw new InvalidArgumentException('Invalid required plugin asset.');
    }
    foreach (extension_declared_assets($plugin) as $asset) {
        if (($asset['plugin'] ?? '') !== $plugin || ($asset['path'] ?? '') !== $path) continue;
        $state =& request_cache_state();
        $state['@extension-assets:'.$plugin.'|'.$path] = true;
        return;
    }
    throw new RuntimeException('Plugin asset must be declared before it can be required.');
}

function extension_asset_required(string $plugin, string $path): bool
{
    $state =& request_cache_state();
    return !empty($state['@extension-assets:'.$plugin.'|'.$path]);
}

function extension_request_before(\LiteBBS\Framework\Request $request): ?\LiteBBS\Framework\Response
{
    extension_runtime_load(null, 'middleware', 'request.before');
    foreach (extension_registry()['middleware']['request.before'] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        try {
            $response = ($entry['handler'])($request, [
                'method'=>$request->method,
                'path'=>$request->path,
                'query'=>$request->query,
                'headers'=>$request->headers,
                'ip'=>(string)($request->server['REMOTE_ADDR'] ?? ''),
            ]);
            if ($response !== null && !$response instanceof \LiteBBS\Framework\Response) throw new RuntimeException('Request middleware must return Response or null.');
            $elapsed = (microtime(true) - $started) * 1000;
            extension_metric_observe($entry['plugin'], 'middleware.request.before', $elapsed, 0, false);
            if ($elapsed > 10) {
                error_log('plugin request middleware '.$entry['plugin'].' exceeded 10ms budget');
                extension_plugin_failure($entry['plugin']);
            }
            if ($response instanceof \LiteBBS\Framework\Response) return $response;
        } catch (Throwable $error) {
            extension_metric_observe($entry['plugin'], 'middleware.request.before', (microtime(true) - $started) * 1000, 0, true);
            error_log('plugin request middleware '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return null;
}

function extension_request_boot(\LiteBBS\Framework\Request $request): void
{
    extension_runtime_load(null, 'boot', 'request.start');
    foreach (extension_registry()['boot']['request.start'] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        try {
            ($entry['handler'])(['method'=>$request->method, 'path'=>$request->path, 'query'=>$request->query]);
            $elapsed = (microtime(true) - $started) * 1000;
            extension_metric_observe($entry['plugin'], 'boot.request.start', $elapsed);
            if ($elapsed > 10) extension_plugin_failure($entry['plugin']);
        } catch (Throwable $error) {
            extension_metric_observe($entry['plugin'], 'boot.request.start', (microtime(true) - $started) * 1000, 0, true);
            error_log('plugin boot '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
}

function extension_plugin_management_path(string $id, ?string $declaredPath = null): ?string
{
    $page = extension_plugin_management_page($id, null, $declaredPath);
    if ($page === null) return null;
    return '/admin/plugins?plugin='.rawurlencode($id).'&page='.rawurlencode((string)$page['management_page']);
}

function extension_plugin_management_page(string $id, ?string $pageName = null, ?string $declaredPath = null): ?array
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) return null;
    if ($pageName !== null && !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $pageName)) return null;
    if (!isset(extension_runtime_load($id)[$id])) return null;
    $fallback = null;
    foreach (extension_registry()['admin_pages'] as $page) {
        if (($page['plugin'] ?? '') !== $id || empty($page['plugin_management'])) continue;
        if ($declaredPath !== null && (($page['management_page'] ?? '') === $declaredPath || ($page['path'] ?? '') === $declaredPath)) return $page;
        if ($pageName !== null && ($page['management_page'] ?? '') === $pageName) return $page;
        if (!empty($page['plugin_management_default'])) $fallback = $page;
        elseif ($fallback === null) $fallback = $page;
    }
    if ($declaredPath !== null) throw new RuntimeException('Plugin admin_path is not registered through the management page API.');
    return $pageName === null ? $fallback : null;
}

function extension_slot(string $slot, array $view = []): string
{
    $registry =& extension_registry();
    extension_runtime_load(null, 'slot', $slot);
    $entries = $registry['slots'][$slot] ?? [];
    $html = in_array($slot, ExtensionLink::SURFACES, true) ? extension_render_links($slot, $view['viewer'] ?? $view['user'] ?? null, $view) : '';
    foreach ($entries as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        $beforeSql=(int)(request_cache_state()['@sql-count']??0);
        try {
            $output = ($entry['renderer'])($view);
            if (!is_string($output)) throw new RuntimeException('Plugin slot must return HTML text.');
            if (strlen($output) > 1048576 || strlen($html) + strlen($output) > 1048576) throw new RuntimeException('Plugin slot output exceeds 1 MiB.');
            $html .= $output;
            if ((microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) { error_log('plugin slot '.$entry['plugin'].' exceeded 10ms budget'); extension_plugin_failure($entry['plugin']); }
            extension_metric_observe($entry['plugin'],'slot.'.$slot,(microtime(true)-$started)*1000,(int)(request_cache_state()['@sql-count']??0)-$beforeSql,false);
        } catch (Throwable $error) {
            extension_metric_observe($entry['plugin'],'slot.'.$slot,(microtime(true)-$started)*1000,(int)(request_cache_state()['@sql-count']??0)-$beforeSql,true);
            error_log('plugin slot '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return $html;
}

function extension_preload(string $slot, array $view = []): void
{
    extension_runtime_load(null, 'preload', $slot);
    foreach (extension_registry()['preloads'][$slot] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        $beforeSql = (int)(request_cache_state()['@sql-count'] ?? 0);
        try {
            ($entry['loader'])($view, new PluginPreloadContext($entry['plugin'], $slot, $view, true));
            $queries = (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql;
            if ($queries > LITEBBS_EXTENSION_PRELOAD_MAX_QUERIES) throw new RuntimeException('Plugin preload exceeded the query limit.');
            if ((microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) throw new RuntimeException('Plugin preload exceeded the 10ms budget.');
            extension_metric_observe($entry['plugin'], 'preload.'.$slot, (microtime(true) - $started) * 1000, $queries, false);
        } catch (Throwable $error) {
            extension_metric_observe($entry['plugin'], 'preload.'.$slot, (microtime(true) - $started) * 1000, (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql, true);
            error_log('plugin preload '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
}

function extension_emit_signal(string $publisher, string $signal, array $payload = []): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $publisher) || !str_starts_with($signal, $publisher.'.')) throw new InvalidArgumentException('Plugin signal publisher is invalid.');
    if (!extension_config_value_valid($payload) || strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > 65535) throw new InvalidArgumentException('Plugin signal payload is invalid.');
    $manifest = extension_plugin_manifest($publisher);
    if (!is_array($manifest) || !in_array($signal, extension_manifest_signals($manifest, $publisher)['publishes'], true)) throw new InvalidArgumentException('Plugin signal publication is not declared in the manifest.');

    $state =& request_cache_state();
    $stack = $state['@extension-signal-stack'] ?? [];
    if (!is_array($stack) || count($stack) >= 8 || in_array($signal, $stack, true)) throw new RuntimeException('Plugin signal recursion is not allowed.');
    $stack[] = $signal;
    $state['@extension-signal-stack'] = $stack;
    try {
        extension_runtime_load(null, 'signals', $signal);
        foreach (extension_registry()['signals'][$signal] ?? [] as $entry) {
            if (extension_plugin_degraded($entry['plugin'])) continue;
            $started = microtime(true);
            try {
                ($entry['listener'])($payload);
                if ((microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) throw new RuntimeException('Plugin signal exceeded the execution budget.');
            } catch (Throwable $error) {
                error_log('plugin signal '.$entry['plugin'].' failed: '.$error->getMessage());
                extension_plugin_failure($entry['plugin']);
            }
        }
    } finally {
        array_pop($stack);
        $state['@extension-signal-stack'] = $stack;
    }
}

// The core write paths call this inside their transaction. Listener errors must escape.
function extension_event_or_fail(string $event, array $payload): void
{
    extension_validate_hook_payload($event,$payload);
    $registry =& extension_registry();
    extension_runtime_load(null, 'event', $event);
    foreach ($registry['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        try {
            ($entry['listener'])($payload);
        } catch (Throwable $error) {
            extension_plugin_failure($entry['plugin']);
            throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $error);
        }
    }
}

function extension_event_sync(string $event, array $payload): void
{
    extension_validate_hook_payload($event,$payload);
    $contract=extension_hook_contract('event',$event);static $deprecated=[];if(!empty($contract['sensitive'])&&!isset($deprecated[$event])){$deprecated[$event]=true;error_log('deprecated sensitive extension event dispatched: '.$event);}
    extension_runtime_load(null, 'event', $event);
    foreach (extension_registry()['events'][$event] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started=microtime(true);
        try { ($entry['listener'])($payload); if((microtime(true)-$started)>LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS){error_log('plugin sync event '.$entry['plugin'].' exceeded 10ms budget');extension_plugin_failure($entry['plugin']);} }
        catch (\LiteBBS\Framework\ResponseHalt $error) { extension_plugin_failure($entry['plugin']); throw $error; }
        catch (Throwable $error) { error_log('plugin sync event '.$entry['plugin'].' failed: '.$error->getMessage()); extension_plugin_failure($entry['plugin']); throw new RuntimeException('Plugin event '.$entry['plugin'].' failed.', 0, $error); }
    }
}

function extension_event_async(string $event, array $payload, int $delaySeconds = 0): void
{
    try {
        extension_enqueue_async_event($event, $payload, $delaySeconds);
    } catch (Throwable $error) {
        error_log('plugin async event '.$event.' enqueue failed: '.$error->getMessage());
    }
}

// Use this inside a write transaction when enqueue errors must roll back the write.
function extension_enqueue_async_event(string $event, array $payload, int $delaySeconds = 0): void
{
    extension_validate_async_event_payload($event,$payload);
    
    if (!extension_config_value_valid($payload)) throw new InvalidArgumentException('Async plugin event payload must be JSON-compatible.');
    if (!extension_has_listeners('event', $event)) return;
    enqueue_outbox_event($event, $payload, max(0, min(86400, $delaySeconds)));
}

function extension_has_listeners(string $kind, string $hook): bool
{
    static $known = [];
    $key = cache_version('plugins').':'.$kind.':'.$hook;
    if (array_key_exists($key, $known)) return $known[$key];
    return $known[$key] = (bool)extension_candidate_plugins($kind, $hook);
}

function extension_render_post_body(string $markdown, array $context = []): string
{
    $registry =& extension_registry();
    extension_runtime_load(null, 'renderer', 'post.markdown');
    foreach ($registry['renderers']['post.markdown'] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        try {
            $html = ($entry['renderer'])($markdown, $context);
            if (!is_string($html) || strlen($html) > 1048576) throw new RuntimeException('Post renderer returned invalid output.');
            if ((microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) {
                error_log('plugin renderer '.$entry['plugin'].' exceeded 10ms budget');
                extension_plugin_failure($entry['plugin']);
            }
            return extension_filter('post.rendered', $html, $context + ['markdown'=>$markdown]);
        } catch (Throwable $error) {
            error_log('plugin renderer '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return extension_filter('post.rendered', render_md($markdown), $context + ['markdown'=>$markdown]);
}

function extension_filter(string $filter, string $value, array $context = []): string
{
    extension_runtime_load(null, 'filter', $filter);
    $contract = extension_hook_contract('filter', $filter);
    $strict = !empty($contract['strict']);
    $nullable = ($contract['return'] ?? null) === 'html_or_null';
    foreach (extension_registry()['filters'][$filter] ?? [] as $entry) {
        if (extension_plugin_degraded($entry['plugin'])) continue;
        $started = microtime(true);
        $beforeSql = (int)(request_cache_state()['@sql-count'] ?? 0);
        try {
            $filtered = ($entry['handler'])($value, $context);
            $unchanged = $filtered === null && $nullable;
            if (!$unchanged && (!is_string($filtered) || strlen($filtered) > 1048576)) throw new RuntimeException('Plugin filter returned invalid output.');
            $queries = (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql;
            if (($strict && $queries !== 0) || (microtime(true) - $started) > ($strict ? LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS : 0.1)) throw new RuntimeException('Plugin filter exceeded its execution budget.');
            if (!$unchanged) $value = $filtered;
            extension_metric_observe($entry['plugin'], 'filter.'.$filter, (microtime(true) - $started) * 1000, $queries, false);
        } catch (Throwable $error) {
            extension_metric_observe($entry['plugin'], 'filter.'.$filter, (microtime(true) - $started) * 1000, (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql, true);
            error_log('plugin filter '.$entry['plugin'].' failed: '.$error->getMessage());
            extension_plugin_failure($entry['plugin']);
        }
    }
    return $value;
}

function extension_invalidate(string $key): void
{
    invalidate($key);
    invalidate('extension-read-pages');
}

function extension_plugin_degraded(string $id, bool $fresh = false): bool
{
    static $requestState = [];
    if (!$fresh && array_key_exists($id, $requestState)) return $requestState[$id];
    $redis = redis_cache_client();
    if (!$redis) return $requestState[$id] = false;
    try { return $requestState[$id] = (bool)$redis->get(shared_cache_key('plugin-degraded:'.$id)); }
    catch (Throwable $error) { cache_error('plugin health read failed: '.$error->getMessage()); return $requestState[$id] = false; }
}

function extension_plugin_failure(string $id): void
{
    $redis = redis_cache_client();
    if (!$redis) return;
    try { $key=shared_cache_key('plugin-failures:'.$id);$count=(int)$redis->incr($key);if($count===1)$redis->expire($key,60);if($count>=3)$redis->setex(shared_cache_key('plugin-degraded:'.$id),60,'1'); }
    catch (Throwable $error) { cache_error('plugin health write failed: '.$error->getMessage()); }
}

/** With no request path, return only explicitly required assets for a fragment. */
function extension_asset_handles(?string $requestPath, ?string $onlyPlugin = null, ?string $type = null): array
{
    $handles = [];
    $bundleTypes = [];
    foreach (extension_declared_assets($onlyPlugin) as $asset) {
        if ($type !== null && $asset['type'] !== $type) continue;
        if (!extension_asset_required($asset['plugin'], $asset['path']) && ($requestPath === null || !extension_asset_matches($asset, $requestPath))) continue;
        if (($requestPath === null || $onlyPlugin === null) && $asset['common']) {
            $bundle = ExtensionAssetBundle::forType($asset['type']);
            if ($bundle->contains($asset)) {
                $bundleTypes[$asset['type']] = $bundle->url();
                continue;
            }
        }
        $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$asset['plugin'], $asset['path']);
        if ($file === null) continue;
        $url = '/plugin-assets/'.rawurlencode($asset['plugin']).'/'.implode('/', array_map('rawurlencode', explode('/', $asset['path']))).'?v='.asset_version($file);
        $handles[] = ['type'=>$asset['type'], 'url'=>$url];
    }
    $common = [];
    foreach ($bundleTypes as $kind => $url) $common[] = ['type'=>$kind, 'url'=>$url];
    return array_merge($common, $handles);
}

final class ExtensionAssetBundle
{
    public const TYPES = ['css', 'js'];
    private const FORMAT_VERSION = 'v1';
    private const MAX_BYTES = 1048576;
    private const CACHE_TTL = 3600;
    private array $sources = [];
    private string $version = '';

    private function __construct(private string $type)
    {
        $versions = [];
        $bytes = 0;
        $assets = array_filter(extension_declared_assets(), static fn(array $asset): bool => $asset['common'] && $asset['type'] === $type);
        usort($assets, static fn(array $a, array $b): int => strcmp($a['plugin'], $b['plugin']));
        foreach ($assets as $asset) {
            $file = extension_secure_runtime_file(dirname(__DIR__).'/plugins/'.$asset['plugin'], $asset['path']);
            if ($file === null) continue;
            $size = filesize($file);
            if ($size === false || $bytes + $size > self::MAX_BYTES) continue;
            $version = asset_version($file);
            $key = $asset['plugin'].'/'.$asset['path'];
            if ($type === 'css') {
                [$eligible] = cached('common-css:v1:'.$version, self::CACHE_TTL, static function () use ($file): array {
                    $source = file_get_contents($file, false, null, 0, self::MAX_BYTES + 1);
                    return [is_string($source) && self::canBundleCss($source)];
                });
                if (!$eligible) continue;
            }
            $this->sources[$key] = $file;
            $versions[$key] = $version;
            $bytes += $size;
        }
        if ($versions) $this->version = hash('sha256', json_encode([self::FORMAT_VERSION, $type, $versions], JSON_THROW_ON_ERROR));
    }

    public static function forType(string $type): self
    {
        if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Invalid common asset type.');
        static $bundles = [];
        $key = cache_version('plugins').':'.$type;
        return $bundles[$key] ??= new self($type);
    }

    public static function canBundleCss(string $source): bool
    {
        // Keep files with URL or encoding dependencies at their original URLs.
        return !str_contains($source, '\\') && !str_starts_with($source, "\xef\xbb\xbf")
            && !preg_match('/@(?:import|charset|namespace)\b|\b(?:url|(?:-webkit-)?image-set|src)\s*\(/i', $source);
    }

    public function contains(array $asset): bool
    {
        return isset($this->sources[$asset['plugin'].'/'.$asset['path']]);
    }

    public function url(): ?string
    {
        return $this->version === '' ? null : '/plugin-assets/_common/assets.'.$this->type.'?v='.$this->version;
    }

    public function response(): \LiteBBS\Framework\Response
    {
        if ($this->version === '') return new \LiteBBS\Framework\Response('Not Found', 404);
        return extension_asset_http_response($this->type, $this->version, function (): ?string {
            [$body] = cached('common-asset:'.$this->version, self::CACHE_TTL, fn(): array => [$this->build()]);
            return $body;
        }, 'public, no-cache');
    }

    private function build(): ?string
    {
        $chunks = [];
        $bytes = 0;
        foreach ($this->sources as $file) {
            $source = file_get_contents($file, false, null, 0, self::MAX_BYTES - $bytes + 1);
            if (!is_string($source)) return null;
            $bytes += strlen($source);
            if ($bytes > self::MAX_BYTES || ($this->type === 'css' && !self::canBundleCss($source))) return null;
            $chunks[] = $this->type === 'css' ? $source : ";try { (function () {\n".$source."\n}).call(window); } catch (error) { console.error(error); }";
        }
        return implode("\n", $chunks);
    }
}

function extension_stylesheets(string $requestPath, ?string $onlyPlugin = null): string
{
    $html = '';
    foreach (extension_asset_handles($requestPath, $onlyPlugin, 'css') as $asset) $html .= '<link rel="stylesheet" href="'.e($asset['url']).'">';
    return $html;
}

function extension_javascripts(string $requestPath, ?string $onlyPlugin = null): string
{
    $html = '';
    foreach (extension_asset_handles($requestPath, $onlyPlugin, 'js') as $asset) $html .= '<script src="'.e($asset['url']).'" defer></script>';
    return $html;
}


// ===== plugin-migrations.php =====

function plugin_require_trusted_execution(): void
{
    if (getenv('LITEBBS_ALLOW_TRUSTED_PLUGINS', true) !== '1') {
        throw new RuntimeException('Set LITEBBS_ALLOW_TRUSTED_PLUGINS=1 after reviewing the plugin PHP code.');
    }
}

// Serialize schema and lifecycle changes because table prefixes can overlap across IDs.
const LITEBBS_PLUGIN_LIFECYCLE_LOCK = 'litebbs:plugin-lifecycle';

function plugin_apply_migrations(PDO $pdo,string $id,string $pluginDirectory,array $manifest,?callable $logger=null,?bool $enabledAfter=null,bool $allowPending=false,bool $approvedByAdmin=false): array
{
    if (!$approvedByAdmin) plugin_require_trusted_execution();
    if(!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/',$id)||($manifest['id']??'')!==$id||!preg_match('/\A\d+\.\d+\.\d+\z/',(string)($manifest['version']??'')))throw new RuntimeException('Plugin manifest is invalid.');
    if (is_link($pluginDirectory) || is_link($pluginDirectory.'/migrations')) throw new RuntimeException('Plugin migration directories cannot be symbolic links.');
    extension_validate_directory($pluginDirectory,'plugin',$id);
    $migrationDirectory=$pluginDirectory.'/migrations';
    $files=is_dir($migrationDirectory)?glob($migrationDirectory.'/*.php'):[];
    if ($files === false) throw new RuntimeException('Cannot list plugin migrations.');
    sort($files,SORT_STRING);
    $lockName=LITEBBS_PLUGIN_LIFECYCLE_LOCK;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);
    if(!(bool)$lock->fetchColumn())throw new RuntimeException('Could not acquire plugin migration lock.');
    $applied=[];
    try{
        $existing = $pdo->prepare('SELECT name,version,enabled FROM plugins WHERE name=?');
        $existing->execute([$id]);
        $registration = $existing->fetch(PDO::FETCH_ASSOC);
        if ($registration && $registration['name'] !== $id) throw new RuntimeException('Plugin ID does not match its database registration.');
        if ($registration && version_compare($manifest['version'], $registration['version'], '<')) throw new RuntimeException('Plugin downgrades are not supported.');
        $prefix = 'plg_'.str_replace('-', '_', $id).'_';
        $tables = $manifest['database_tables'] ?? [];
        if (!is_array($tables)) throw new RuntimeException('Plugin database table declaration is invalid.');
        foreach ($tables as $table) {
            if (!is_string($table) || strlen($table) > 64 || !preg_match('/\A'.preg_quote($prefix, '/').'[a-z][a-z0-9_]*\z/', $table)) throw new RuntimeException('Plugin database table declaration is invalid.');
        }

        $recorded = $pdo->prepare('SELECT migration_id,checksum FROM plugin_migrations WHERE plugin_name=?');
        $recorded->execute([$id]);
        $checksums = $recorded->fetchAll(PDO::FETCH_KEY_PAIR);
        $pending = [];
        foreach ($files as $file) {
            $migrationId = basename($file, '.php');
            if (strlen($migrationId) > 128 || !preg_match('/\A\d{8}_\d{4}_[a-z0-9_]+\z/', $migrationId)) throw new RuntimeException('Invalid migration file: '.basename($file));
            $relative = 'migrations/'.basename($file);
            $resolved = extension_secure_runtime_file($pluginDirectory, $relative);
            $source = $resolved === null ? false : file_get_contents($resolved);
            if (!is_string($source)) throw new RuntimeException('Cannot read migration: '.$migrationId);
            $checksum = hash('sha256', $source);
            if (isset($checksums[$migrationId])) {
                if (!hash_equals($checksums[$migrationId], $checksum)) throw new RuntimeException('Applied migration checksum changed: '.$migrationId);
                continue;
            }
            extension_validate_migration_source($source, $relative, $prefix);
            $pending[$migrationId] = [$resolved, $checksum];
        }
        if ($pending && !$approvedByAdmin) throw new RuntimeException('Pending migrations require administrator approval.');

        $targetEnabled = $enabledAfter ?? (bool)($registration['enabled'] ?? false);
        // Do not disable an existing plugin until its pending migrations have
        // completed. A failed upgrade must leave the previously working state intact.
        $register=$pdo->prepare('INSERT INTO plugins(name,version,enabled,config_json,installed_at) VALUES(?,?,0,JSON_OBJECT(),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE name=VALUES(name)');
        $register->execute([$id,(string)$manifest['version']]);
        foreach($pending as $migrationId => [$file, $checksum]){
            // Migrations execute as trusted PHP code before their returned SQL is checked.
            $currentChecksum = hash_file('sha256', $file);
            if (!is_string($currentChecksum) || !hash_equals($checksum, $currentChecksum)) throw new RuntimeException('Migration changed before execution: '.$migrationId);
            $statements=require $file;
            $executedChecksum=hash_file('sha256',$file);if(!is_string($executedChecksum)||!hash_equals($checksum,$executedChecksum))throw new RuntimeException('Migration changed while being executed: '.$migrationId);
            if(!is_array($statements)||!$statements||array_filter($statements,static fn(mixed $sql):bool=>!is_string($sql)||trim($sql)===''))throw new RuntimeException('Migration must return non-empty SQL statement array: '.$migrationId);
            $executableStatements = [];
            foreach($statements as $sql){
                $normalized=preg_replace('/\s+/',' ',trim($sql));
                if(!is_string($normalized)||str_contains($normalized,';')||preg_match('~/\*|--|#~',$normalized)||preg_match('/\b(?:DROP|RENAME|TRUNCATE|GRANT|REVOKE|LOAD DATA|INTO OUTFILE|INTO DUMPFILE)\b/i',$normalized))throw new RuntimeException('Plugin migration contains an unsafe SQL statement: '.$migrationId);
                $ownedTable = '(?:'.preg_quote($prefix,'/').'[a-z][a-z0-9_]*)';
                $isCreateTable = preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?('.$ownedTable.')`?\s*\(/i',$normalized,$target) === 1;
                $isAddColumn = preg_match('/^ALTER TABLE\s+`?('.$ownedTable.')`?\s+ADD(?: COLUMN)?\s+`?[a-z][a-z0-9_]{0,63}`?\s+/i',$normalized,$target) === 1;
                $isCreateIndex = preg_match('/^CREATE (?:UNIQUE )?INDEX\s+`?[a-z][a-z0-9_]{0,63}`?\s+ON\s+`?('.$ownedTable.')`?\s*\(/i',$normalized,$target) === 1;
                if (!$isCreateTable && !$isAddColumn && !$isCreateIndex) throw new RuntimeException('Plugin migration may only create a table, add a column, or create an index on a plugin-owned table: '.$migrationId);
                if (!in_array($target[1], $tables, true)) throw new RuntimeException('Migration changes an undeclared table: '.$migrationId);
                preg_match_all('/\bREFERENCES\s+`?([a-zA-Z0-9_]+)`?/i',$normalized,$references);
                foreach($references[1]??[] as $identifier)if(!str_starts_with(strtolower($identifier),$prefix))throw new RuntimeException('Migration references an out-of-scope table: '.$migrationId);
                $executable = $isCreateTable ? preg_replace('/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i','CREATE TABLE IF NOT EXISTS ',$sql,1) : $sql;
                $executableStatements[] = is_string($executable) ? $executable : $sql;
            }
            foreach ($executableStatements as $sql) $pdo->exec($sql);
            $insert=$pdo->prepare('INSERT INTO plugin_migrations(plugin_name,migration_id,checksum,applied_at) VALUES(?,?,?,UTC_TIMESTAMP(6))');$insert->execute([$id,$migrationId,$checksum]);
            $applied[]=$migrationId;if($logger)$logger($migrationId);
        }
        $tableExists=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        foreach($tables as $table){$tableExists->execute([$table]);if(!$tableExists->fetchColumn())throw new RuntimeException('Plugin migration did not create declared table: '.$table);}
        $finish=$pdo->prepare('UPDATE plugins SET version=?,enabled=? WHERE name=?');$finish->execute([(string)$manifest['version'],$targetEnabled?1:0,$id]);
    }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
    if ($allowPending) extension_invalidate('plugins');
    return $applied;
}

function plugin_set_enabled_state(PDO $pdo,string $id,bool $enabled): void
{
    $lockName=LITEBBS_PLUGIN_LIFECYCLE_LOCK;$lock=$pdo->prepare('SELECT GET_LOCK(?,30)');$lock->execute([$lockName]);if(!(bool)$lock->fetchColumn())throw new RuntimeException('Could not acquire plugin state lock.');
    try{$statement=$pdo->prepare('UPDATE plugins SET enabled=? WHERE name=?');$statement->execute([$enabled?1:0,$id]);}finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
}


// ===== extension-platform.php =====

const LITEBBS_EXTENSION_PLATFORM_VERSION = '2.1';

function extension_semver_valid(string $version): bool
{
    return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
}

function extension_version_satisfies(string $version, string $constraint): bool
{
    if (!extension_semver_valid($version) || trim($constraint) === '') return false;
    foreach (preg_split('/\s+/', trim($constraint)) ?: [] as $term) {
        if (!preg_match('/^(\^|~|>=|<=|>|<|=)?(\d+\.\d+(?:\.\d+)?)$/', $term, $match)) return false;
        $operator = $match[1] ?: '=';
        $target = substr_count($match[2], '.') === 1 ? $match[2].'.0' : $match[2];
        if ($operator === '^') {
            $parts = array_map('intval', explode('.', $target));
            $upper = $parts[0] > 0 ? ($parts[0] + 1).'.0.0' : '0.'.($parts[1] + 1).'.0';
            if (!version_compare($version, $target, '>=') || !version_compare($version, $upper, '<')) return false;
        } elseif ($operator === '~') {
            $parts = array_map('intval', explode('.', $target));
            if (!version_compare($version, $target, '>=') || !version_compare($version, $parts[0].'.'.($parts[1] + 1).'.0', '<')) return false;
        } elseif (!version_compare($version, $target, $operator === '=' ? '==' : $operator)) return false;
    }
    return true;
}

function extension_core_capabilities(): array
{
    return [
        'admin.pages'=>'1.1.0', 'admin.plugin-pages'=>'1.0.0', 'forms'=>'1.0.0', 'jobs'=>'1.0.0', 'metrics'=>'1.0.0',
        'uploads'=>'1.0.0', 'notifications'=>'1.0.0', 'search'=>'1.0.0', 'user.fields'=>'1.0.0',
        'theme.views'=>'1.0.0', 'events.strict'=>'1.0.0', 'request.context'=>'1.0.0', 'request.middleware'=>'1.0.0',
        'response.json'=>'1.0.0', 'slots.expanded'=>'1.0.0',
    ];
}

function extension_manifest_relationships(array $manifest): array
{
    $result = ['dependencies'=>[], 'conflicts'=>[], 'integrations'=>[], 'capabilities'=>[]];
    foreach (array_keys($result) as $key) {
        $items = $manifest[$key] ?? [];
        if (!is_array($items) || count($items) > 64) throw new RuntimeException('Invalid plugin '.$key.' declaration.');
        foreach ($items as $id=>$constraint) {
            if (is_int($id)) { $id = (string)$constraint; $constraint = '*'; }
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $id) || !is_string($constraint)) throw new RuntimeException('Invalid plugin '.$key.' declaration.');
            if ($constraint !== '*' && !extension_version_satisfies('999999.0.0', $constraint) && !preg_match('/^(?:\^|~|>=|<=|>|<|=)?\d+\.\d+(?:\.\d+)?(?:\s+(?:>=|<=|>|<|=)\d+\.\d+(?:\.\d+)?)*$/', $constraint)) throw new RuntimeException('Invalid version constraint for '.$id.'.');
            $result[$key][$id] = $constraint;
        }
    }
    return $result;
}

function extension_installed_plugin_versions(): array
{
    $versions = [];
    foreach (h('SELECT name,version,enabled FROM plugins')->fetchAll() as $row) $versions[(string)$row['name']] = ['version'=>(string)$row['version'], 'enabled'=>(bool)$row['enabled']];
    return $versions;
}

function extension_dependency_report(string $id, array $manifest, bool $requireEnabled = true, ?array $installed = null): array
{
    $relations = extension_manifest_relationships($manifest);
    $installed ??= extension_installed_plugin_versions();
    $errors = [];
    $optional = [];
    foreach ($relations['dependencies'] as $dependency=>$constraint) {
        $record = $installed[$dependency] ?? null;
        if (!$record || ($requireEnabled && !$record['enabled'])) $errors[] = '缺少依赖 '.$dependency;
        elseif ($constraint !== '*' && !extension_version_satisfies($record['version'], $constraint)) $errors[] = '依赖 '.$dependency.' 版本不满足 '.$constraint;
    }
    foreach ($relations['conflicts'] as $conflict=>$constraint) {
        $record = $installed[$conflict] ?? null;
        if ($record && $record['enabled'] && ($constraint === '*' || extension_version_satisfies($record['version'], $constraint))) $errors[] = '与已启用插件 '.$conflict.' 冲突';
    }
    $capabilities = extension_core_capabilities();
    foreach ($relations['capabilities'] as $capability=>$constraint) {
        if (!isset($capabilities[$capability]) || ($constraint !== '*' && !extension_version_satisfies($capabilities[$capability], $constraint))) $errors[] = '核心能力不可用：'.$capability;
    }
    foreach ($relations['integrations'] as $integration=>$constraint) {
        $record = $installed[$integration] ?? null;
        $optional[$integration] = (bool)($record && $record['enabled'] && ($constraint === '*' || extension_version_satisfies($record['version'], $constraint)));
    }
    return ['ok'=>$errors === [], 'errors'=>$errors, 'integrations'=>$optional];
}

function extension_plugin_lifecycle(string $id, string $action, bool $preserveData = true, bool $approvedByAdmin = false): void
{
    if (!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $id) || !in_array($action, ['install','upgrade','enable','disable','uninstall'], true)) throw new InvalidArgumentException('Invalid plugin lifecycle operation.');
    if (!h('SELECT GET_LOCK(?,30)', [LITEBBS_PLUGIN_LIFECYCLE_LOCK])->fetchColumn()) throw new RuntimeException('Could not acquire plugin lifecycle lock.');
    try {
        extension_apply_plugin_lifecycle($id, $action, $preserveData, $approvedByAdmin);
    } finally {
        h('SELECT RELEASE_LOCK(?)', [LITEBBS_PLUGIN_LIFECYCLE_LOCK]);
    }
}

function extension_apply_plugin_lifecycle(string $id, string $action, bool $preserveData, bool $approvedByAdmin): void
{
    $directory = dirname(__DIR__).'/plugins/'.$id;
    $manifest = extension_plugin_manifest($id);
    if (in_array($action, ['install','upgrade','enable'], true)) {
        if (!is_array($manifest)) throw new RuntimeException('Plugin manifest is unavailable.');
        extension_validate_directory($directory, 'plugin', $id);
    }
    $record = h('SELECT name,version,enabled FROM plugins WHERE name=?', [$id])->fetch() ?: null;
    if ($record && $record['name'] !== $id) throw new RuntimeException('Plugin ID does not match its database registration.');
    if ($action === 'install' && $record && (bool)$record['enabled']) throw new RuntimeException('Plugin is already installed.');
    if (in_array($action, ['upgrade','enable','disable','uninstall'], true) && !$record) throw new RuntimeException('Plugin is not installed.');
    if ($action === 'install') {
        $report = extension_dependency_report($id, $manifest, false);
        if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
        plugin_apply_migrations(db(), $id, $directory, $manifest, enabledAfter: false, approvedByAdmin: $approvedByAdmin);
    } elseif ($action === 'upgrade') {
        if (!version_compare((string)$manifest['version'], (string)$record['version'], '>')) throw new RuntimeException('Upgrade version must be newer than the installed version.');
        $report = extension_dependency_report($id, $manifest, false);
        if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
        plugin_apply_migrations(db(), $id, $directory, $manifest, approvedByAdmin: $approvedByAdmin);
    } elseif ($action === 'enable') {
        if ($manifest['version'] !== $record['version']) throw new RuntimeException('Register the deployed version before enabling it.');
        $report = extension_dependency_report($id, $manifest, true);
        if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
        plugin_apply_migrations(db(), $id, $directory, $manifest, enabledAfter: true, approvedByAdmin: $approvedByAdmin);
    } elseif ($action === 'disable') {
        extension_disable_plugin($id);
    } else {
        if (!$preserveData) {
            extension_purge_plugin($id, deleteDirectory: false, requireDisabled: is_dir($directory));
        } else {
            if ((bool)$record['enabled']) throw new RuntimeException('Uninstall requires the plugin to be disabled first.');
            h('DELETE FROM extension_jobs WHERE plugin_name=?', [$id]);
            h('DELETE FROM extension_job_failures WHERE plugin_name=?', [$id]);
            // Keep the migration ledger so retained tables are not migrated twice.
            plugin_set_enabled_state(db(), $id, false);
        }
    }
    extension_invalidate('plugins');
    audit('plugin.'.$action, 'plugin', null, ['name'=>$id, 'preserve_data'=>$preserveData]);
}

function extension_disable_plugin(string $id): void
{
    $dependents = [];
    foreach (extension_installed_plugin_versions() as $other => $record) {
        if (!$record['enabled'] || $other === $id) continue;
        $dependencies = extension_plugin_manifest($other)['dependencies'] ?? null;
        if (is_array($dependencies) && (array_key_exists($id, $dependencies) || in_array($id, $dependencies, true))) $dependents[] = $other;
    }
    if ($dependents) throw new RuntimeException('以下插件仍依赖它：'.implode('、', $dependents));
    plugin_set_enabled_state(db(), $id, false);
}

function extension_plugin_tables_for_purge(string $id, ?string $installedVersion): array
{
    $prefix = 'plg_'.str_replace('-', '_', $id).'_';
    $candidates = h('SELECT table_name AS name, table_type AS type FROM information_schema.tables WHERE table_schema=DATABASE() AND LOWER(LEFT(table_name, ?))=?', [strlen($prefix), $prefix])->fetchAll();
    if (!$candidates) return [];

    $manifest = extension_plugin_manifest($id);
    if ($installedVersion === null || ($manifest['schema'] ?? null) !== 1 || ($manifest['id'] ?? null) !== $id || ($manifest['version'] ?? null) !== $installedVersion || !is_array($manifest['database_tables'] ?? null)) {
        throw new RuntimeException('Cannot verify table ownership without a manifest matching the installed plugin.');
    }

    $otherPrefixes = [];
    $known = array_keys(extension_installed_plugin_versions());
    foreach (new DirectoryIterator(dirname(__DIR__).'/plugins') as $directory) {
        if ($directory->isDir() && !$directory->isDot()) $known[] = $directory->getFilename();
    }
    foreach ($known as $other) {
        if ($other !== $id && preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', strtolower($other))) $otherPrefixes[] = 'plg_'.str_replace('-', '_', strtolower($other)).'_';
    }
    $tables = [];
    foreach ($candidates as $candidate) {
        $table = $candidate['name'];
        foreach ($otherPrefixes as $otherPrefix) {
            if (str_starts_with(strtolower($table), $otherPrefix)) throw new RuntimeException('Plugin table ownership is ambiguous: '.$table);
        }
        if ($candidate['type'] !== 'BASE TABLE' || strlen($table) > 64 || !preg_match('/\A'.preg_quote($prefix, '/').'[a-z][a-z0-9_]*\z/', $table) || !in_array($table, $manifest['database_tables'], true)) {
            throw new RuntimeException('Cannot verify ownership of plugin database object: '.$table);
        }
        $tables[] = $table;
    }
    return $tables;
}

function extension_remove_plugin_storage(string $id): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) throw new InvalidArgumentException('Invalid plugin ID.');
    $directory = dirname(__DIR__).'/uploads/plugin/'.$id;
    if (is_dir($directory) || is_link($directory)) remove_tree($directory);
    if (file_exists($directory) || is_link($directory)) throw new RuntimeException('Plugin storage could not be fully removed.');
}

function extension_preprocess_post_markdown(string $markdown, array $context = []): string
{
    if (str_contains($markdown, "\0") || strlen($markdown) > 48000) throw new InvalidArgumentException('Invalid post markdown source.');
    $markdown = extension_filter('post.markdown.source', $markdown, $context);
    if (str_contains($markdown, "\0") || strlen($markdown) > 48000) throw new RuntimeException('Plugin markdown preprocessing returned invalid source.');
    return $markdown;
}

/** Commands and policies affect writes or authorization, so failures must reach the caller. */
function extension_critical_callback(string $plugin, string $operation, callable $callback, array $arguments, int $maxQueries): mixed
{
    $started = microtime(true);
    $beforeSql = (int)(request_cache_state()['@sql-count'] ?? 0);
    try {
        $value = $callback(...$arguments);
        $queries = (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql;
        if ($queries > $maxQueries) throw new RuntimeException('Plugin '.$operation.' exceeded its query budget.');
        if ((microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) throw new RuntimeException('Plugin '.$operation.' exceeded the 10ms callback budget.');
        extension_metric_observe($plugin, $operation, (microtime(true) - $started) * 1000, $queries, false);
        return $value;
    } catch (Throwable $error) {
        extension_metric_observe($plugin, $operation, (microtime(true) - $started) * 1000, (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql, true);
        extension_plugin_failure($plugin);
        error_log('plugin '.$operation.' '.$plugin.' failed: '.$error->getMessage());
        throw new RuntimeException('Plugin '.$operation.' failed.', 0, $error);
    }
}

/** Rendering callbacks cannot query the database. Read providers may batch one query. */
function extension_item_callback(string $plugin, string $name, callable $callback, array $arguments, bool $allowSql = false): mixed
{
    $started = microtime(true);
    $beforeSql = (int)(request_cache_state()['@sql-count'] ?? 0);
    try {
        $value = $callback(...$arguments);
        $sqlCount = (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql;
        if ($sqlCount > (int)$allowSql) throw new RuntimeException('Plugin '.$name.' exceeded its query budget.');
        if ((microtime(true) - $started) > LITEBBS_EXTENSION_SYNC_CALLBACK_MAX_SECONDS) throw new RuntimeException('Plugin '.$name.' exceeded the 10ms callback budget.');
        extension_metric_observe($plugin, 'item.'.$name, (microtime(true) - $started) * 1000, $sqlCount, false);
        return $value;
    } catch (Throwable $error) {
        extension_metric_observe($plugin, 'item.'.$name, (microtime(true) - $started) * 1000, (int)(request_cache_state()['@sql-count'] ?? 0) - $beforeSql, true);
        extension_plugin_failure($plugin);
        error_log('plugin '.$name.' '.$plugin.' failed: '.$error->getMessage());
        return null;
    }
}

function extension_purge_plugin(string $id, bool $deleteDirectory = true, bool $requireDisabled = false): void
{
    if (!preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $id)) throw new InvalidArgumentException('Invalid plugin ID.');
    if (!h('SELECT GET_LOCK(?,30)', [LITEBBS_PLUGIN_LIFECYCLE_LOCK])->fetchColumn()) throw new RuntimeException('Could not acquire plugin purge lock.');
    try {
        $record = h('SELECT name,version,enabled FROM plugins WHERE name=?', [$id])->fetch();
        if ($record && $record['name'] !== $id) throw new RuntimeException('Plugin ID does not match its database registration.');
        if ($requireDisabled && !empty($record['enabled'])) throw new RuntimeException('Uninstall requires the plugin to be disabled first.');
        $tables = extension_plugin_tables_for_purge($id, $record['version'] ?? null);
        if (!empty($record['enabled'])) extension_disable_plugin($id);
        if ($tables) h('DROP TABLE '.implode(', ', array_map(static fn(string $table): string => '`'.$table.'`', $tables)));
        extension_remove_plugin_storage($id);
        if ($deleteDirectory) {
            $directory = dirname(__DIR__).'/plugins/'.$id;
            remove_tree($directory);
            if (file_exists($directory) || is_link($directory)) throw new RuntimeException('Plugin directory could not be fully removed.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            h('DELETE FROM extension_jobs WHERE plugin_name=?', [$id]);
            h('DELETE FROM extension_job_failures WHERE plugin_name=?', [$id]);
            h('DELETE FROM plugin_user_field_values WHERE plugin_name=?', [$id]);
            h('DELETE FROM plugin_search_documents WHERE plugin_name=?', [$id]);
            h('DELETE FROM plugins WHERE name=?', [$id]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    } finally {
        h('SELECT RELEASE_LOCK(?)', [LITEBBS_PLUGIN_LIFECYCLE_LOCK]);
    }
    extension_invalidate('plugins');
    if ($deleteDirectory) audit('plugin.purged', 'plugin', null, ['name'=>$id]);
}

final class PluginAdminRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function page(string $path, string $title, callable $handler, array $options = []): void
    {
        $pluginPage = preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $path) === 1;
        if (!$pluginPage) throw new InvalidArgumentException('Plugin admin pages must use a page name inside /admin/plugins.');
        $managementPage = $path;
        $path = '/admin/plugins?plugin='.rawurlencode($this->id).'&page='.rawurlencode($path);
        $unknown = array_diff(array_keys($options), ['navigation','breadcrumbs','permission','audit','default']);
        if ($unknown) throw new InvalidArgumentException('Unknown plugin admin page option.');
        $permission = (string)($options['permission'] ?? 'admin');
        if (!in_array($permission, ['moderator','admin'], true)) throw new InvalidArgumentException('Invalid plugin admin permission.');
        if ($permission !== 'admin') throw new InvalidArgumentException('Plugin management pages require administrator permission.');
        foreach ($this->registry['admin_pages'] as $registered) if (($registered['plugin'] ?? '') === $this->id && (($registered['management_page'] ?? null) === $managementPage || $registered['path'] === $path)) throw new InvalidArgumentException('Duplicate plugin admin page path.');
        if (!empty($options['default'])) foreach ($this->registry['admin_pages'] as $registered) if ($registered['plugin'] === $this->id && !empty($registered['plugin_management_default'])) throw new InvalidArgumentException('Duplicate default plugin management page.');
        $breadcrumbs = (array)($options['breadcrumbs'] ?? []);
        if (!$breadcrumbs) $breadcrumbs = ['插件管理'=>'/admin/plugins', $title=>null];
        $this->registry['admin_pages'][] = ['plugin'=>$this->id,'path'=>$path,'management_page'=>$managementPage,'title'=>$title,'handler'=>$handler,'navigation'=>(string)($options['navigation']??$title),'breadcrumbs'=>$breadcrumbs,'permission'=>$permission,'audit'=>(string)($options['audit']??''),'plugin_management'=>true,'plugin_management_default'=>!empty($options['default'])];
    }
}

final class PluginForm
{
    private array $fields = [];
    private array $errors = [];
    private array $values = [];
    public function field(string $name, string $type, string $label, array $rules = []): self
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) || !in_array($type, ['text','email','number','textarea','select','checkbox','url'], true)) throw new InvalidArgumentException('Invalid form field.');
        $this->fields[$name] = ['type'=>$type,'label'=>$label,'rules'=>$rules];
        return $this;
    }
    public function validate(array $input): bool
    {
        $this->errors=[];$this->values=[];
        foreach ($this->fields as $name=>$field) {
            $raw=$input[$name]??null;$rules=$field['rules'];$type=$field['type'];
            $value=$type==='checkbox'?in_array($raw,[true,1,'1','on'],true):trim(is_scalar($raw)?(string)$raw:'');
            if (($rules['required']??false) && ($value==='' || $value===false)) $this->errors[$name]='此字段必填';
            elseif (is_string($value) && isset($rules['max']) && text_length($value)>(int)$rules['max']) $this->errors[$name]='内容过长';
            elseif (is_string($value) && isset($rules['min']) && text_length($value)<(int)$rules['min']) $this->errors[$name]='内容过短';
            elseif ($type==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) $this->errors[$name]='邮箱格式无效';
            elseif ($type==='url' && $value!=='' && request_url_parts($value)===null) $this->errors[$name]='必须是有效 HTTPS 地址';
            elseif ($type==='number' && $value!=='' && !filter_var($value,FILTER_VALIDATE_INT)) $this->errors[$name]='必须是整数';
            elseif ($type==='select' && !in_array($value,(array)($rules['choices']??[]),true)) $this->errors[$name]='选项无效';
            $this->values[$name]=$type==='number'&&$value!==''?(int)$value:$value;
        }
        return !$this->errors;
    }
    public function values(): array { return $this->values; }
    public function errors(): array { return $this->errors; }
    public function render(string $action, string $submit = '保存'): string
    {
        $html='<form class="form" method="post" action="'.e($action).'"><input type="hidden" name="csrf" value="'.e(csrf()).'">';
        foreach($this->fields as $name=>$field){$value=$this->values[$name]??'';$error=$this->errors[$name]??'';$type=$field['type'];$html.='<label>'.e($field['label']);if($type==='textarea')$html.='<textarea name="'.e($name).'">'.e($value).'</textarea>';elseif($type==='select'){$html.='<select name="'.e($name).'">';foreach((array)($field['rules']['choices']??[]) as $choice)$html.='<option'.((string)$value===(string)$choice?' selected':'').'>'.e($choice).'</option>';$html.='</select>';}else{$html.='<input type="'.e($type).'" name="'.e($name).'" value="'.e($type==='checkbox'?'1':$value).'"'.($type==='checkbox'&&$value?' checked':'').'>';}$html.=$error!==''?'<small class="error">'.e($error).'</small>':'';$html.='</label>';}
        return $html.'<button class="primary">'.e($submit).'</button></form>';
    }
}

final class PluginForms { public function create(): PluginForm { return new PluginForm(); } }

final class PluginTaskRegistrar
{
    private array $registry;
    public function __construct(private string $id, array &$registry) { $this->registry =& $registry; }
    public function register(string $name, callable $handler, array $options = []): void
    {
        if(!preg_match('/^[a-z][a-z0-9._-]{1,63}$/',$name))throw new InvalidArgumentException('Invalid plugin task name.');
        $unknown=array_diff(array_keys($options),['retries','lease','every','payload']);if($unknown)throw new InvalidArgumentException('Unknown plugin task option.');
        $every=(int)($options['every']??0);if($every!==0&&($every<60||$every>2592000))throw new InvalidArgumentException('Recurring task interval must be between 60 seconds and 30 days.');
        $payload=$options['payload']??[];if(!is_array($payload)||!extension_config_value_valid($payload))throw new InvalidArgumentException('Recurring task payload must be JSON-compatible.');
        $this->registry['tasks'][$this->id.'.'.$name]=['plugin'=>$this->id,'name'=>$name,'handler'=>$handler,'retries'=>max(1,min(25,(int)($options['retries']??10))),'lease'=>max(30,min(3600,(int)($options['lease']??300))),'every'=>$every,'payload'=>$payload];
    }
    public function enqueue(string $name,array $payload=[],string $idempotencyKey='',int $delaySeconds=0): void
    {
        if (!isset($this->registry['tasks'][$this->id.'.'.$name])) throw new InvalidArgumentException('Plugin task is not registered.');
        if(!extension_config_value_valid($payload))throw new InvalidArgumentException('Task payload must be JSON-compatible.');
        $key=$idempotencyKey!==''?hash('sha256',$this->id.'|'.$name.'|'.$idempotencyKey):hash('sha256',$this->id.'|'.$name.'|'.json_encode($payload).'|'.bin2hex(random_bytes(8)));
        h('INSERT IGNORE INTO extension_jobs(plugin_name,task_name,idempotency_key,payload_json,available_at,created_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),UTC_TIMESTAMP(6))',[$this->id,$name,$key,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),max(0,min(86400,$delaySeconds))]);
    }
}

final class PluginTaskContext
{
    public function __construct(private int $jobId, private string $leaseToken, private int $leaseSeconds) {}
    public function touchLease(): bool
    {
        return h('UPDATE extension_jobs SET lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND) WHERE id=? AND lease_token=? AND completed_at IS NULL', [$this->leaseSeconds, $this->jobId, $this->leaseToken])->rowCount() === 1;
    }
}

function extension_schedule_tasks(?int $timestamp=null): int
{
    extension_runtime_load(null, 'tasks', 'registered');$timestamp=$timestamp??time();$scheduled=0;
    foreach(extension_registry()['tasks'] as $task){$every=(int)($task['every']??0);if($every<60)continue;$bucket=intdiv($timestamp,$every);$key=hash('sha256',$task['plugin'].'|'.$task['name'].'|schedule|'.$bucket);$json=json_encode($task['payload'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$scheduled+=h('INSERT IGNORE INTO extension_jobs(plugin_name,task_name,idempotency_key,payload_json,available_at,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$task['plugin'],$task['name'],$key,$json])->rowCount();}
    return $scheduled;
}

function extension_process_jobs(int $limit=100): int
{
    extension_schedule_tasks();
    $done = 0;
    $db = db();
    for ($i = 0; $i < max(1, min(1000, $limit)); $i++) {
        $lockAcquired = false;
        $db->beginTransaction();
        try {
            // Claim fresh jobs first so the common path uses the ready-queue index.
            // Expired leases are reclaimed only when no fresh work is available.
            $job = h('SELECT id,plugin_name,task_name,idempotency_key,payload_json,attempts FROM extension_jobs WHERE completed_at IS NULL AND lease_until IS NULL AND available_at<=UTC_TIMESTAMP(6) ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$job) $job = h('SELECT id,plugin_name,task_name,idempotency_key,payload_json,attempts FROM extension_jobs WHERE completed_at IS NULL AND lease_until IS NOT NULL AND lease_until<=UTC_TIMESTAMP(6) AND available_at<=UTC_TIMESTAMP(6) ORDER BY lease_until,id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
            if (!$job) { $db->commit(); break; }

            $id = (string)$job['id'];
            $key = (string)$job['idempotency_key'];
            // Namespace the lock within MySQL's 64-character limit. Acquire it
            // before replacing an expired lease that may still be in use.
            $prefix = 'litebbs:plugin-job:';
            $lockName = $prefix.substr(hash('sha256', $key), 0, 64 - strlen($prefix));
            $lockResult = (string)h('SELECT GET_LOCK(?, 0)', [$lockName])->fetchColumn();
            if ($lockResult === '0') {
                h('UPDATE extension_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 5 SECOND) WHERE id=?', [$id]);
                $db->commit();
                continue;
            }
            if ($lockResult !== '1') throw new RuntimeException('Could not acquire plugin job advisory lock.');
            $lockAcquired = true;

            $task = extension_registry()['tasks'][$job['plugin_name'].'.'.$job['task_name']] ?? null;
            if (!$task || !h('SELECT 1 FROM plugins WHERE name=? AND enabled=1', [$job['plugin_name']])->fetchColumn()) {
                h('UPDATE extension_jobs SET completed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=?', ['Task is not registered.', $id]);
                $db->commit();
                continue;
            }
            $token = bin2hex(random_bytes(32));
            h('UPDATE extension_jobs SET attempts=attempts+1,lease_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND) WHERE id=?', [$token, $task['lease'], $id]);
            $attempt = (int)$job['attempts'] + 1;
            $db->commit();

            try {
                $payload = json_decode((string)$job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                ($task['handler'])($payload, $key, new PluginTaskContext((int)$id, $token, (int)$task['lease']));
                if (h("UPDATE extension_jobs SET completed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error='' WHERE id=? AND lease_token=?", [$id, $token])->rowCount() === 1) $done++;
            } catch (Throwable $error) {
                $message = text_slice($error->getMessage(), 0, 500);
                if ($attempt >= $task['retries']) {
                    $db->beginTransaction();
                    if (h('UPDATE extension_jobs SET completed_at=UTC_TIMESTAMP(6),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, $id, $token])->rowCount() === 1) {
                        h('INSERT IGNORE INTO extension_job_failures(job_id,plugin_name,task_name,payload_json,attempts,last_error,failed_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$id, $job['plugin_name'], $job['task_name'], $job['payload_json'], $attempt, $message]);
                    }
                    $db->commit();
                } else {
                    h('UPDATE extension_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL LEAST(900,POW(2,attempts)) SECOND),lease_token=NULL,lease_until=NULL,last_error=? WHERE id=? AND lease_token=?', [$message, $id, $token]);
                }
            }
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } finally {
            if ($lockAcquired) h('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
    return $done;
}

function extension_replay_failed_job(int $failureId): void
{
    $failure=h('SELECT * FROM extension_job_failures WHERE id=?',[$failureId])->fetch();if(!$failure)throw new RuntimeException('Failed job not found.');
    $key=hash('sha256','replay|'.$failure['job_id'].'|'.bin2hex(random_bytes(8)));h('INSERT INTO extension_jobs(plugin_name,task_name,idempotency_key,payload_json,available_at,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$failure['plugin_name'],$failure['task_name'],$key,$failure['payload_json']]);h('UPDATE extension_job_failures SET replayed_at=UTC_TIMESTAMP(6) WHERE id=?',[$failureId]);
}

final class PluginMetrics
{
    public function __construct(private string $id) {}
    public function observe(string $operation,float $milliseconds,int $sqlCount=0,bool $error=false): void { extension_metric_observe($this->id,$operation,$milliseconds,$sqlCount,$error); }
    public function status(string $status): void { if(!in_array($status,['healthy','degraded','disabled'],true))throw new InvalidArgumentException('Invalid plugin status.');$redis=redis_cache_client();if($redis)$redis->setex(shared_cache_key('plugin-status:'.$this->id),3600,$status); }
    public function snapshot(): array { return extension_metric_snapshot($this->id); }
}

function extension_metric_observe(string $id,string $operation,float $milliseconds,int $sqlCount=0,bool $error=false): void
{
    $state=&request_cache_state();$key='@plugin-metrics:'.$id;$metric=$state[$key]??['calls'=>0,'duration_ms'=>0.0,'sql_count'=>0,'errors'=>0];$metric['calls']++;$metric['duration_ms']+=$milliseconds;$metric['sql_count']+=$sqlCount;$metric['errors']+=$error?1:0;$state[$key]=$metric;
}

function extension_metric_snapshot(string $id): array
{
    $state=&request_cache_state();$metric=$state['@plugin-metrics:'.$id]??['calls'=>0,'duration_ms'=>0.0,'sql_count'=>0,'errors'=>0];$calls=max(1,(int)$metric['calls']);$cacheHits=(int)($state['@plugin-cache-hits:'.$id]??0);$cacheMisses=(int)($state['@plugin-cache-misses:'.$id]??0);$status=extension_plugin_degraded($id)?'degraded':'healthy';$redis=redis_cache_client();if($redis){try{$status=(string)($redis->get(shared_cache_key('plugin-status:'.$id))?:$status);}catch(Throwable $error){cache_error('plugin status read failed: '.$error->getMessage());}}return $metric+['average_ms'=>$metric['duration_ms']/$calls,'error_rate'=>$metric['errors']/$calls,'cache_hit_rate'=>($cacheHits+$cacheMisses)>0?$cacheHits/($cacheHits+$cacheMisses):null,'status'=>$status];
}

final class PluginUploads
{
    public function __construct(private string $id) {}
    public function store(array $file,array $allowedMime,int $maxBytes=5242880): array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('Upload is invalid.');$size=(int)($file['size']??0);if($size<1||$size>$maxBytes)throw new RuntimeException('Upload size is invalid.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);if(!is_string($mime)||!in_array($mime,$allowedMime,true))throw new RuntimeException('Upload type is not allowed.');$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf','text/plain'=>'txt','application/zip'=>'zip'];if(!isset($extensions[$mime]))throw new RuntimeException('Upload type has no safe storage mapping.');$name=bin2hex(random_bytes(20)).'.'.$extensions[$mime];$relative='plugin/'.$this->id.'/'.$name;$directory=dirname(__DIR__).'/uploads/plugin/'.$this->id;if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Upload directory is unavailable.');if(!move_uploaded_file((string)$file['tmp_name'],$directory.'/'.$name))throw new RuntimeException('Upload could not be stored.');return ['path'=>$relative,'name'=>basename((string)$file['name']),'mime'=>$mime,'size'=>$size];
    }
}

final class PluginNotifications
{
    public function __construct(private string $id) {}
    public function send(int $userId,int $actorId,string $kind,?int $topicId=null,?int $postId=null): int
    {
        $qualified='plugin.'.$this->id.'.'.$kind;if($userId<1||$actorId<1||!preg_match('/^[a-z][a-z0-9._-]{1,31}$/',$kind)||strlen($qualified)>96)throw new InvalidArgumentException('Invalid notification.');$create=static fn():int=>extension_create_notification($userId,$actorId,$qualified,$topicId,$postId);$id=db()->inTransaction()?$create():\LiteBBS\Application\transaction_manager()->run($create);invalidate_user_cache($userId);return $id;
    }
}

final class PluginSearchRegistrar
{
    private array $registry;
    public function __construct(private string $id,array &$registry){$this->registry =& $registry;}
    public function provider(string $name,callable $search):void{if(!preg_match('/^[a-z][a-z0-9_-]{1,31}$/',$name))throw new InvalidArgumentException('Invalid search provider.');$this->registry['search'][$this->id.'.'.$name]=['plugin'=>$this->id,'handler'=>$search];}
    public function index(string $documentId,string $title,string $body,string $url):void{if(!preg_match('/^[A-Za-z0-9._:-]{1,128}$/',$documentId)||text_length($title)>200||text_length($body)>20000||!preg_match('~^/(?!/)~',$url))throw new InvalidArgumentException('Invalid search document.');h('INSERT INTO plugin_search_documents(plugin_name,document_id,title,body,url,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),url=VALUES(url),updated_at=VALUES(updated_at)',[$this->id,$documentId,$title,$body,$url]);$this->invalidate();}
    public function remove(string $documentId):void{h('DELETE FROM plugin_search_documents WHERE plugin_name=? AND document_id=?',[$this->id,$documentId]);$this->invalidate();}
    public function invalidate():void{invalidate('plugin-search:'.$this->id);}
}

function extension_search(string $query,int $limit=20):array
{
    extension_runtime_load(null, 'search', 'search');
    $limit = max(1, min(50, $limit));
    $results = [];
    if (text_length($query) >= 2) {
        foreach (h('SELECT plugin_name,title,url,LEFT(body,240) excerpt FROM plugin_search_documents WHERE MATCH(title,body) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY updated_at DESC LIMIT '.$limit, [$query])->fetchAll() as $item) {
            $results[] = ['plugin'=>$item['plugin_name'], 'title'=>$item['title'], 'url'=>$item['url'], 'excerpt'=>$item['excerpt']];
        }
    }
    foreach (extension_registry()['search'] as $provider) {
        if (count($results) >= $limit) break;
        if (extension_plugin_degraded($provider['plugin'])) continue;
        try {
            $items = extension_critical_callback($provider['plugin'], 'search', $provider['handler'], [$query, $limit - count($results)], 1);
            if (!is_array($items)) throw new RuntimeException('Plugin search provider must return an array.');
            foreach (array_slice($items, 0, $limit - count($results)) as $item) {
                if (!is_array($item) || !isset($item['title'], $item['url']) || !is_string($item['title']) || !is_string($item['url']) || text_length($item['title']) > 200 || !ExtensionLink::validHref($item['url'])) {
                    throw new RuntimeException('Plugin search provider returned an invalid result.');
                }
                $results[] = $item + ['plugin'=>$provider['plugin']];
            }
        } catch (Throwable $error) {
            // Search remains available when one optional provider fails.
        }
    }
    return $results;
}

final class PluginUserFields
{
    private array $registry;
    public function __construct(private string $id,array &$registry){$this->registry =& $registry;}
    public function register(string $name,string $label,array $rules=[]):void{if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/',$name))throw new InvalidArgumentException('Invalid user field.');$this->registry['user_fields'][$this->id.'.'.$name]=['plugin'=>$this->id,'name'=>$name,'label'=>$label,'rules'=>$rules];}
    public function get(int $userId,string $name):mixed{$json=h('SELECT value_json FROM plugin_user_field_values WHERE plugin_name=? AND field_name=? AND user_id=?',[$this->id,$name,$userId])->fetchColumn();return is_string($json)?json_decode($json,true):null;}
    public function set(int $userId,string $name,mixed $value):void{$field=$this->registry['user_fields'][$this->id.'.'.$name]??null;if(!is_array($field)||!extension_config_value_valid($value))throw new InvalidArgumentException('Invalid user field value.');$rules=$field['rules'];if(isset($rules['max'])&&is_string($value)&&text_length($value)>(int)$rules['max'])throw new InvalidArgumentException('User field value is too long.');$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($json)>4096)throw new InvalidArgumentException('User field value is too large.');h('INSERT INTO plugin_user_field_values(plugin_name,field_name,user_id,value_json,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)',[$this->id,$name,$userId,$json]);}
}

function extension_markdown_renderer_diagnostics(): array
{
    extension_runtime_load(null,'renderer','post.markdown');$entries=extension_registry()['renderers']['post.markdown']??[];$active=null;$items=[];foreach($entries as $index=>$entry){$selected=$active===null&&!extension_plugin_degraded($entry['plugin']);if($selected)$active=$entry['plugin'];$items[]=['plugin'=>$entry['plugin'],'priority'=>$entry['priority'],'selected'=>$selected,'reason'=>$selected?'selected':($index===0?'degraded':'lower_priority')];}return ['active'=>$active,'conflict'=>count($entries)>1,'renderers'=>$items];
}
