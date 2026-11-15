<?php
declare(strict_types=1);
require_once __DIR__.'/builtin-plugin-schema.php';
require_once __DIR__.'/plugin-migrations.php';

if (!in_array($path, ['/admin/themes', '/admin/plugins'], true) || !in_array($method, ['GET', 'POST'], true)) return;

$actor = admin();
$section = $path === '/admin/themes' ? 'themes' : 'plugins';
$root = dirname(__DIR__);

if ($section === 'plugins' && array_key_exists('plugin', $_GET)) {
    $pluginId = (string)($_GET['plugin'] ?? '');
    $pageName = (string)($_GET['page'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $pluginId) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $pageName)) fail(400, '插件管理页面无效');
    $record = h('SELECT enabled FROM plugins WHERE name=?', [$pluginId])->fetch();
    if (!$record || !(int)$record['enabled']) fail(404, '插件未启用或不存在');
    $adminPage = extension_plugin_management_page($pluginId, $pageName);
    if ($adminPage === null) fail(404, '插件管理页面不存在');
    if ($method === 'POST') require_post();
    try {
        $result = ($adminPage['handler'])($request);
        if (!is_array($result) || !isset($result['body'])) throw new RuntimeException('Admin page handler must return a body array.');
        if ($method === 'POST' && $adminPage['audit'] !== '') audit($adminPage['audit'], 'plugin', null, ['name'=>$pluginId]);
    } catch (\LiteBBS\Framework\ResponseHalt $halt) {
        throw $halt;
    } catch (Throwable $error) {
        error_log('plugin admin '.$pluginId.' failed: '.$error->getMessage());
        fail(503, '插件管理页面暂时不可用');
    }
    $pages = [];
    foreach (extension_registry()['admin_pages'] as $registeredPage) {
        if (($registeredPage['plugin'] ?? '') !== $pluginId || empty($registeredPage['plugin_management'])) continue;
        $url = '/admin/plugins?plugin='.rawurlencode($pluginId).'&page='.rawurlencode((string)$registeredPage['management_page']);
        $pages[] = '<a'.($registeredPage['management_page'] === $pageName ? ' class="active"' : '').' href="'.e($url).'">'.e($registeredPage['navigation']).'</a>';
    }
    $body = '<nav class="plugin-management-tabs" aria-label="插件管理页面"><a href="/admin/plugins">返回插件列表</a>'.implode('', $pages).'</nav>'.(string)$result['body'];
    page((string)($result['title'] ?? $adminPage['title']), $body, '', false, true);
    return;
}

if ($method === 'POST') {
    require_post();
    $name = (string)($_POST['name'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) fail(400, '扩展 ID 无效');
    if ($section === 'themes') {
        $directory = $root.'/templates/'.$name;
        $manifest = is_file($directory.'/theme.json') ? json_decode((string)file_get_contents($directory.'/theme.json'), true) : null;
        try { extension_validate_directory($directory, 'theme', $name); } catch (Throwable $error) { fail(400, '模板预检失败：'.$error->getMessage()); }

        if ($action === 'delete') {
            if (empty($_POST['confirm_token'])) confirmation_page('确认删除模板','将永久删除模板目录，此操作不可恢复。','theme.delete',['name'=>$name,'action'=>'delete'],$name);
            require_confirmation('theme.delete',$name,(string)$_POST['confirm_token']);
            if ($name === 'default') fail(400, '默认模板不能删除');
            $config=load_config();
            if (($config['theme'] ?? 'default') === $name) fail(409, '当前模板正在使用，请先启用其他模板');
            h('DELETE FROM themes WHERE name=?',[$name]);
            remove_tree($directory);
            audit('theme.deleted','theme',null,['name'=>$name]);
            redirect('/admin/themes');
        }

        if ($action !== 'enable') fail(400, '模板操作无效');
        h('UPDATE themes SET enabled=0');
        h('INSERT INTO themes(name,version,enabled,config_json) VALUES(?,?,1,JSON_OBJECT()) ON DUPLICATE KEY UPDATE enabled=1', [$name, (string)($manifest['version'] ?? '1.0.0')]);
        $config = load_config();
        $config['theme'] = $name;
        try { write_config($config); } catch (Throwable $error) { fail(500, '模板设置保存失败'); }
        audit('theme.activated', 'theme', null, ['name' => $name]);
        // Match plugin management: return to this management page after a
        // successful update instead of navigating the administrator to home.
        redirect('/admin/themes');
    }

    $directory = $root.'/plugins/'.$name;
    $manifestFile = is_file($directory.'/plugin.json') ? $directory.'/plugin.json' : $directory.'/manifest.json';
    $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
    if ($action === 'purge') {
        if (empty($_POST['confirm_token'])) confirmation_page('确认永久删除插件','将永久删除插件目录、登记、迁移记录、任务、用户字段、搜索文档和全部插件数据表，此操作不可恢复。','plugin.purge',['name'=>$name,'action'=>'purge'],$name);
        require_confirmation('plugin.purge',$name,(string)$_POST['confirm_token']);
        try { extension_purge_plugin($name); remove_tree($directory); if (is_dir($directory) || is_link($directory)) throw new RuntimeException('插件目录无法完全删除，请检查文件权限'); }
        catch(Throwable $error){plugin_runtime_invalidate();fail(409,'插件永久删除失败：'.$error->getMessage());}
        plugin_runtime_invalidate();
        redirect('/admin/plugins');
    }
    if (!in_array($action, ['disable','uninstall'], true)) try { extension_validate_directory($directory, 'plugin', $name); } catch (Throwable $error) { fail(400, '插件预检失败：'.$error->getMessage()); }

    if (!in_array($action, ['install','upgrade','enable','disable','uninstall'], true)) fail(400, '插件操作无效');
    if ($action === 'uninstall' && empty($_POST['confirm_token'])) {
        $orphaned=!is_dir($directory);
        $token=confirmation_token('plugin.uninstall',$name);
        $pluginTitle=is_array($manifest)?(string)($manifest['title']??$name):$name;
        $fields='<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="confirm_token" value="'.e($token).'">'.'<input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="uninstall">'.($orphaned?'':'<label class="plugin-confirm-option"><input type="checkbox" name="preserve_data" value="1" checked><span><strong>保留插件数据</strong><small>以后重新安装时，可以继续使用现有数据。</small></span></label><div class="plugin-data-result"><span>数据处理</span><strong class="when-kept">数据表与用户字段值将保留</strong><strong class="when-removed">数据表与用户字段值将永久删除</strong></div>').'<div class="plugin-confirm-actions"><a href="/admin/plugins">取消</a><button type="submit" class="plugin-confirm-danger">'.($orphaned?'强制永久删除':'确认卸载').'</button></div>';
        $message=$orphaned?'插件目录已经缺失。此操作将永久删除插件登记、迁移记录、待处理任务、用户字段、搜索文档和该插件的全部数据表，不可恢复。':'卸载会删除插件登记。取消保留数据时，还会永久删除插件表和用户字段值。';
        page($orphaned?'确认强制删除':'确认卸载插件','<section class="plugin-confirm'.($orphaned?' is-orphaned':'').'"><header><span class="plugin-confirm-mark" aria-hidden="true">!</span><div class="plugin-confirm-heading"><p class="eyebrow">'.($orphaned?'插件记录异常':'卸载插件').'</p><h2>'.e($pluginTitle).'</h2><code>'.e($name).'</code></div><span class="plugin-confirm-risk">'.($orphaned?'不可恢复':'谨慎操作').'</span></header><div class="plugin-confirm-body"><p class="plugin-confirm-message">'.e($message).'</p><div class="plugin-confirm-impact"><span>卸载后</span><strong>'.($orphaned?'插件及关联数据将被永久删除':'插件将从后台列表中移除').'</strong></div><form method="post">'.$fields.'</form></div></section>','',false,true);
        return;
    }
    if ($action === 'uninstall') {
        require_confirmation('plugin.uninstall',$name,(string)$_POST['confirm_token']);
        if (!is_dir($directory)) plugin_set_enabled_state(db(),$name,false);
    }
    if ($name === 'zip-remote-attachments' && in_array($action,['install','upgrade','enable'],true)) foreach (builtin_plugin_schema($name) as $sql) h($sql);
    try { extension_plugin_lifecycle($name,$action,!empty($_POST['preserve_data'])); }
    catch(Throwable $error){plugin_runtime_invalidate();fail(409,'插件操作失败：'.$error->getMessage());}
    plugin_runtime_invalidate();
    redirect('/admin/plugins');
}

$body = '';
$invalidExtensions = [];
if ($section === 'themes') {
    $body .= '<p class="admin-intro">启用前执行结构与质量预检，不是安全执行控制。模板目录名可以修改，清单中的主题 ID 无需同步修改。默认模板不能删除；删除其他模板前需先切换模板。</p>';
    $active = (string)(load_config()['theme'] ?? 'default');
    foreach (new DirectoryIterator($root.'/templates') as $dir) {
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dir->getFilename())) continue;
        $name = $dir->getFilename();
        $manifest = json_decode((string)@file_get_contents($dir->getPathname().'/theme.json'), true);
        try { extension_validate_directory($dir->getPathname(), 'theme', $name); } catch (Throwable $error) { $invalidExtensions[$name] = $error->getMessage(); continue; }
        $body .= '<section class="extension-row"><div><h2>'.e($manifest['title'] ?? $name).($name === $active ? ' <small>当前使用</small>' : '').'</h2><p>'.e($name).' · '.e($manifest['version'] ?? '未知版本').' · '.e($manifest['author'] ?? '未知作者').'</p></div><div class="extension-actions">';
        if ($name !== $active) $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="enable"><button class="primary">启用</button></form>';
        if ($name !== 'default' && $name !== $active) $body .= '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="delete"><button>删除模板</button></form>';
        $body .= '</div></section>';
    }
    if ($invalidExtensions) { $body .= '<section class="extension-errors"><h2>未通过检查的模板</h2>'; foreach ($invalidExtensions as $name => $reason) $body .= '<article><strong>'.e($name).'</strong><p>'.e($reason).'</p></article>'; $body .= '</section>'; }
} else {
    $body .= '<p class="admin-intro">插件按安装、升级、启用、停用、卸载的生命周期管理。依赖、冲突和核心能力在状态变更前检查；卸载可保留数据。插件仍是受信任的 PHP 代码，启用前必须完成人工审查。</p>';
    $installed = [];
    foreach (h('SELECT name,version,enabled FROM plugins')->fetchAll() as $row) $installed[$row['name']] = $row;
    foreach (is_dir($root.'/plugins') ? new DirectoryIterator($root.'/plugins') : [] as $dir) {
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dir->getFilename())) continue;
        $name = $dir->getFilename();
        $manifestFile = is_file($dir->getPathname().'/plugin.json') ? $dir->getPathname().'/plugin.json' : $dir->getPathname().'/manifest.json';
        $manifest = json_decode((string)@file_get_contents($manifestFile), true);
        try { extension_validate_directory($dir->getPathname(), 'plugin', $name); } catch (Throwable $error) { $invalidExtensions[$name] = $error->getMessage(); unset($installed[$name]); continue; }
        $record = $installed[$name] ?? null;
        $enabled = (int)($record['enabled'] ?? 0);
        try {
            $adminPath = is_array($manifest) ? extension_manifest_admin_path($manifest, $name) : null;
            if ($enabled && $adminPath !== null) $adminPath = extension_plugin_management_path($name, $adminPath);
        } catch (Throwable $error) { $invalidExtensions[$name] = $error->getMessage(); unset($installed[$name]); continue; }
        $state=$record?($enabled?'已启用':'已停用'):'未安装';
        $body .= '<section class="extension-row"><div><h2>'.e($manifest['title'] ?? $name).'</h2><p>'.e($name).' · '.e($manifest['version'] ?? '未知版本').' · '.e($manifest['description'] ?? '暂无说明').' · '.$state.'</p></div><div class="extension-actions">';
        if ($enabled && $adminPath !== null) $body .= '<a class="button" href="'.e($adminPath).'">管理</a>';
        if(!$record)$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="install"><button class="primary">安装</button></form>';
        else{$upgrade=version_compare((string)($manifest['version']??'0.0.0'),(string)$record['version'],'>');if($upgrade)$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="upgrade"><button>升级</button></form>';$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="'.($enabled?'disable':'enable').'"><button class="primary">'.($enabled?'停用':'启用').'</button></form>';if(!$enabled)$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="uninstall"><button>卸载</button></form>';}$body.='</div></section>';
        unset($installed[$name]);
    }
    foreach($installed as $name=>$record){$enabled=(int)$record['enabled'];$body.='<section class="extension-row"><div><h2>'.e($name).'</h2><p>插件目录缺失 · 已登记版本 '.e($record['version']).' · '.($enabled?'已启用':'已停用').'</p></div><div class="extension-actions">';if($enabled)$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="disable"><button class="primary">停用</button></form>';$body.='<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="uninstall"><button>强制永久删除</button></form>';$body.='</div></section>';}
    if ($invalidExtensions) { $body .= '<section class="extension-errors"><h2>未通过检查的插件</h2>'; foreach ($invalidExtensions as $name => $reason) $body .= '<article><strong>'.e($name).'</strong><p>'.e($reason).'</p><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="purge"><button>永久删除并清理数据</button></form></article>'; $body .= '</section>'; }
    $rendererDiagnostics=extension_markdown_renderer_diagnostics();
    if($rendererDiagnostics['conflict']){$body.='<section class="extension-errors"><h2>Markdown 主渲染器冲突</h2><p>当前选择：'.e((string)$rendererDiagnostics['active']).'。候选项按优先级排列：</p>';foreach($rendererDiagnostics['renderers'] as $renderer)$body.='<article><strong>'.e($renderer['plugin']).'</strong><p>优先级 '.(int)$renderer['priority'].' · '.e($renderer['reason']).'</p></article>';$body.='</section>';}
}
page($section === 'themes' ? '模板管理' : '插件管理', $body);
return;
