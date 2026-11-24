<?php
declare(strict_types=1);

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
        'theme.views'=>'1.0.0', 'events.strict'=>'1.0.0', 'request.context'=>'1.0.0',
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

function extension_dependency_report(string $id, array $manifest, bool $requireEnabled = true): array
{
    $relations = extension_manifest_relationships($manifest);
    $installed = extension_installed_plugin_versions();
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
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id) || !in_array($action, ['install','upgrade','enable','disable','uninstall'], true)) throw new InvalidArgumentException('Invalid plugin lifecycle operation.');
    $directory = dirname(__DIR__).'/plugins/'.$id;
    $manifest = extension_plugin_manifest($id);
    if (in_array($action, ['install','upgrade','enable'], true)) {
        if (!is_array($manifest)) throw new RuntimeException('Plugin manifest is unavailable.');
        extension_validate_directory($directory, 'plugin', $id);
    }
    $record = h('SELECT name,version,enabled FROM plugins WHERE name=?', [$id])->fetch() ?: null;
    if ($action === 'install' && $record) throw new RuntimeException('Plugin is already installed.');
    if (in_array($action, ['upgrade','enable','disable','uninstall'], true) && !$record) throw new RuntimeException('Plugin is not installed.');
    if ($action === 'install') {
        $report = extension_dependency_report($id, $manifest, false);
        if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
        plugin_apply_migrations(db(), $id, $directory, $manifest, enabledAfter: false, approvedByAdmin: $approvedByAdmin);
    } elseif ($action === 'upgrade') {
        if (!version_compare((string)$manifest['version'], (string)$record['version'], '>')) throw new RuntimeException('Upgrade version must be newer than the installed version.');
        $report = extension_dependency_report($id, $manifest, false);
        if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
        plugin_apply_migrations(db(), $id, $directory, $manifest, enabledAfter: (bool)$record['enabled'], approvedByAdmin: $approvedByAdmin);
    } elseif ($action === 'enable') {
        $report = extension_dependency_report($id, $manifest, true);
        if (!$report['ok']) throw new RuntimeException(implode('；', $report['errors']));
        plugin_apply_migrations(db(), $id, $directory, $manifest, enabledAfter: true, approvedByAdmin: $approvedByAdmin);
    } elseif ($action === 'disable') {
        $dependents = [];
        foreach (extension_enabled_plugins() as $plugin) {
            $other = (string)$plugin['name'];
            if ($other === $id) continue;
            $otherManifest = extension_plugin_manifest($other);
            if (is_array($otherManifest) && isset(extension_manifest_relationships($otherManifest)['dependencies'][$id])) $dependents[] = $other;
        }
        if ($dependents) throw new RuntimeException('以下插件仍依赖它：'.implode('、', $dependents));
        plugin_set_enabled_state(db(), $id, false);
    } else {
        if ((bool)$record['enabled']) throw new RuntimeException('Uninstall requires the plugin to be disabled first.');
        if (!$preserveData) {
            extension_drop_plugin_tables($id);
            extension_remove_plugin_storage($id);
        }
        h('DELETE FROM extension_jobs WHERE plugin_name=?', [$id]);
        h('DELETE FROM extension_job_failures WHERE plugin_name=?', [$id]);
        if (!$preserveData) h('DELETE FROM plugin_user_field_values WHERE plugin_name=?', [$id]);
        if (!$preserveData) h('DELETE FROM plugin_search_documents WHERE plugin_name=?', [$id]);
        h('DELETE FROM plugins WHERE name=?', [$id]);
    }
    extension_invalidate('plugins');
    audit('plugin.'.$action, 'plugin', null, ['name'=>$id, 'preserve_data'=>$preserveData]);
}

function extension_drop_plugin_tables(string $id): void
{
    $prefix = 'plg_'.str_replace('-', '_', $id).'_';
    $tables = h('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE ?', [$prefix.'%'])->fetchAll(PDO::FETCH_COLUMN);
    h('SET FOREIGN_KEY_CHECKS=0');
    try { foreach ($tables as $table) if (is_string($table) && preg_match('/^'.preg_quote($prefix, '/').'[a-z0-9_]+$/', $table)) h('DROP TABLE `'.$table.'`'); }
    finally { h('SET FOREIGN_KEY_CHECKS=1'); }
}

function extension_remove_plugin_storage(string $id): void
{
    if ($id === 'zip-remote-attachments') remove_tree(dirname(__DIR__).'/uploads/plugin-zip');
}

function extension_purge_plugin(string $id): void
{
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id)) throw new InvalidArgumentException('Invalid plugin ID.');
    $record = h('SELECT enabled FROM plugins WHERE name=?', [$id])->fetch();
    if ($record && (bool)$record['enabled']) plugin_set_enabled_state(db(), $id, false);
    extension_drop_plugin_tables($id);
    extension_remove_plugin_storage($id);
    h('DELETE FROM extension_jobs WHERE plugin_name=?', [$id]);
    h('DELETE FROM extension_job_failures WHERE plugin_name=?', [$id]);
    h('DELETE FROM plugin_user_field_values WHERE plugin_name=?', [$id]);
    h('DELETE FROM plugin_search_documents WHERE plugin_name=?', [$id]);
    h('DELETE FROM plugins WHERE name=?', [$id]);
    extension_invalidate('plugins');
    audit('plugin.purged', 'plugin', null, ['name'=>$id]);
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
            $value=$type==='checkbox'?(bool)$raw:trim(is_scalar($raw)?(string)$raw:'');
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
        if(!extension_config_value_valid($payload))throw new InvalidArgumentException('Task payload must be JSON-compatible.');
        $key=$idempotencyKey!==''?hash('sha256',$this->id.'|'.$name.'|'.$idempotencyKey):hash('sha256',$this->id.'|'.$name.'|'.json_encode($payload).'|'.bin2hex(random_bytes(8)));
        h('INSERT IGNORE INTO extension_jobs(plugin_name,task_name,idempotency_key,payload_json,available_at,created_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),UTC_TIMESTAMP(6))',[$this->id,$name,$key,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),max(0,min(86400,$delaySeconds))]);
    }
}

function extension_schedule_tasks(?int $timestamp=null): int
{
    extension_runtime_load();$timestamp=$timestamp??time();$scheduled=0;
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
                ($task['handler'])($payload, $key);
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
    public function send(int $userId,int $actorId,string $kind,?int $topicId=null,?int $postId=null): void
    {
        $qualified='plugin.'.$this->id.'.'.$kind;if($userId<1||$actorId<1||!preg_match('/^[a-z][a-z0-9._-]{1,31}$/',$kind)||strlen($qualified)>96)throw new InvalidArgumentException('Invalid notification.');h('INSERT INTO notifications(user_id,actor_id,topic_id,post_id,kind,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',[$userId,$actorId,$topicId,$postId,$qualified]);invalidate_user_cache($userId);
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
    extension_runtime_load();$limit=max(1,min(50,$limit));$results=[];if(text_length($query)>=2){foreach(h('SELECT plugin_name,title,url,LEFT(body,240) excerpt FROM plugin_search_documents WHERE MATCH(title,body) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY updated_at DESC LIMIT '.$limit,[$query])->fetchAll() as $item)$results[]=['plugin'=>$item['plugin_name'],'title'=>$item['title'],'url'=>$item['url'],'excerpt'=>$item['excerpt']];}foreach(extension_registry()['search'] as $provider){try{$items=($provider['handler'])($query,$limit);if(is_array($items))foreach($items as $item)if(is_array($item)&&isset($item['title'],$item['url']))$results[]=$item+['plugin'=>$provider['plugin']];}catch(Throwable $error){extension_plugin_failure($provider['plugin']);}}return array_slice($results,0,$limit);
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
