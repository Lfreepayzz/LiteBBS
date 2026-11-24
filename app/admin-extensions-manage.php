<?php
declare(strict_types=1);
require_once __DIR__.'/builtin-plugin-schema.php';
require_once __DIR__.'/plugin-migrations.php';
require_once __DIR__.'/extension-platform.php';

function handle_admin_extensions_manage(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
if (!in_array($path, ['/admin/themes', '/admin/plugins'], true) || !in_array($method, ['GET', 'POST'], true)) return;

$actor = admin();
$section = $path === '/admin/themes' ? 'themes' : 'plugins';
$root = dirname(__DIR__);

if ($section === 'plugins' && array_key_exists('plugin', $query)) {
    $pluginId = $query['plugin'];
    $pageName = $query['page'] ?? null;
    if (!is_string($pluginId) || !is_string($pageName)
        || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $pluginId)
        || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pageName)) fail(400, '插件管理页面无效');
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
        $pages[] = '<a'.($registeredPage['management_page'] === $pageName ? ' class="active" aria-current="page"' : '').' href="'.e($url).'">'.e($registeredPage['navigation']).'</a>';
    }
    $body = '<nav class="plugin-management-tabs catalog-nav" aria-label="插件管理页面"><a href="/admin/plugins">返回插件列表</a>'.implode('', $pages).'</nav>'.(string)$result['body'];
    page((string)($result['title'] ?? $adminPage['title']), $body, '', false, true);
    return;
}

if ($section === 'themes' && $method === 'GET' && array_key_exists('theme', $query)) {
    $themeId = $query['theme'];
    if (!is_string($themeId) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $themeId)) fail(400, '模板管理页面无效');
    $directory = $root.'/templates/'.$themeId;
    try { extension_validate_directory($directory, 'theme', $themeId); }
    catch (Throwable $error) { fail(404, '模板不可用：'.$error->getMessage()); }
    $manifest = json_decode((string)file_get_contents($directory.'/theme.json'), true);
    $definitions = is_array($manifest) ? theme_manifest_settings($manifest) : [];
    if (!$definitions) fail(404, '该模板未声明可管理设置。');
    $record = h('SELECT config_json FROM themes WHERE name=?', [$themeId])->fetch();
    if (!$record) fail(404, '模板尚未启用过，无法保存设置。');
    $values = theme_settings($themeId);
    $fields = '';
    foreach ($definitions as $key=>$definition) {
        $name = 'theme_setting_'.$key; $value = $values[$key] ?? $definition['default'];
        $label = e($definition['label']);
        if ($definition['type'] === 'textarea') $control = '<textarea class="admin-control" name="'.e($name).'" maxlength="'.(int)$definition['max'].'" rows="5">'.e((string)$value).'</textarea>';
        elseif ($definition['type'] === 'select') { $control = '<select class="admin-control" name="'.e($name).'">'; foreach ($definition['options'] as $optionValue=>$optionLabel) $control .= '<option value="'.e($optionValue).'"'.($value === $optionValue ? ' selected' : '').'>'.e($optionLabel).'</option>'; $control .= '</select>'; }
        elseif ($definition['type'] === 'checkbox') $control = '<input type="hidden" name="'.e($name).'" value="0"><label class="check-label"><input type="checkbox" name="'.e($name).'" value="1"'.($value ? ' checked' : '').'> '. $label.'</label>';
        else $control = '<input class="admin-control" type="'.($definition['type'] === 'color' ? 'color' : 'text').'" name="'.e($name).'" maxlength="'.(int)($definition['max'] ?? 7).'" value="'.e((string)$value).'">';
        $fields .= $definition['type'] === 'checkbox' ? '<div class="theme-setting">'.$control.'</div>' : '<label class="theme-setting">'.$label.$control.'</label>';
    }
    $body = '<nav class="plugin-management-tabs catalog-nav" aria-label="模板管理页面"><a href="/admin/themes">返回模板列表</a></nav>'
        .'<section class="theme-settings catalog-settings" aria-labelledby="catalog-settings-title"><header><h2 id="catalog-settings-title">'.e($manifest['title'] ?? $themeId).'</h2><p>显示设置</p></header>'
        .'<form class="form" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($themeId).'"><input type="hidden" name="action" value="save-settings"><div class="catalog-settings-fields">'.$fields.'</div><footer><button class="primary" type="submit">保存设置</button></footer></form></section>';
    page('模板设置', $body, '', false, true);
    return;
}

if ($method === 'POST') {
    require_post();
    $name = (string)($post['name'] ?? '');
    $action = (string)($post['action'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $name)) fail(400, '扩展 ID 无效');
    if ($section === 'themes') {
        $directory = $root.'/templates/'.$name;
        $manifest = is_file($directory.'/theme.json') ? json_decode((string)file_get_contents($directory.'/theme.json'), true) : null;
        try { extension_validate_directory($directory, 'theme', $name); } catch (Throwable $error) { fail(400, '模板预检失败：'.$error->getMessage()); }

        if ($action === 'save-settings') {
            $definitions = is_array($manifest) ? theme_manifest_settings($manifest) : [];
            if (!$definitions) fail(400, '该模板未声明可管理设置。');
            try { $values = theme_settings_from_input($post, $definitions); }
            catch (Throwable $error) { fail(400, '模板设置无效。'); }
            h('INSERT INTO themes(name,version,enabled,config_json) VALUES(?,?,0,?) ON DUPLICATE KEY UPDATE version=VALUES(version),config_json=VALUES(config_json)', [$name,(string)($manifest['version'] ?? '1.0.0'),json_encode($values, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            invalidate('theme-settings:'.$name);
            audit('theme.settings.updated', 'theme', null, ['name'=>$name]);
            redirect('/admin/themes?theme='.rawurlencode($name));
        }

        if ($action === 'delete') {
            if (empty($post['confirm_token'])) confirmation_page('确认删除模板','将永久删除模板目录，此操作不可恢复。','theme.delete',['name'=>$name,'action'=>'delete'],$name);
            require_confirmation('theme.delete',$name,(string)$post['confirm_token']);
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
        if (empty($post['confirm_token'])) confirmation_page('确认永久删除插件','将永久删除插件目录、登记、迁移记录、任务、用户字段、搜索文档和全部插件数据表，此操作不可恢复。','plugin.purge',['name'=>$name,'action'=>'purge'],$name);
        require_confirmation('plugin.purge',$name,(string)$post['confirm_token']);
        try { extension_purge_plugin($name); remove_tree($directory); if (is_dir($directory) || is_link($directory)) throw new RuntimeException('插件目录无法完全删除，请检查文件权限'); }
        catch(Throwable $error){extension_invalidate('plugins');fail(409,'插件永久删除失败：'.$error->getMessage());}
        extension_invalidate('plugins');
        redirect('/admin/plugins');
    }
    if (in_array($action, ['install','upgrade','enable'], true)) try {
        extension_validate_directory($directory, 'plugin', $name);
    } catch (Throwable $error) { fail(400, '插件预检失败：'.$error->getMessage()); }

    if (!in_array($action, ['install','upgrade','enable','disable','uninstall'], true)) fail(400, '插件操作无效');
    if ($action === 'uninstall' && empty($post['confirm_token'])) {
        $orphaned=!is_dir($directory);
        $token=confirmation_token('plugin.uninstall',$name);
        $pluginTitle=is_array($manifest)?(string)($manifest['title']??$name):$name;
        $fields='<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="confirm_token" value="'.e($token).'">'.'<input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="uninstall">'.($orphaned?'':'<label class="plugin-confirm-option"><input type="checkbox" name="preserve_data" value="1" checked><span><strong>保留插件数据</strong><small>以后重新安装时，可以继续使用现有数据。</small></span></label><div class="plugin-data-result"><span>数据处理</span><strong class="when-kept">数据表与用户字段值将保留</strong><strong class="when-removed">数据表与用户字段值将永久删除</strong></div>').'<div class="plugin-confirm-actions"><a href="/admin/plugins">取消</a><button type="submit" class="plugin-confirm-danger">'.($orphaned?'强制永久删除':'确认卸载').'</button></div>';
        $message=$orphaned?'插件目录已经缺失。此操作将永久删除插件登记、迁移记录、待处理任务、用户字段、搜索文档和该插件的全部数据表，不可恢复。':'卸载会删除插件登记。取消保留数据时，还会永久删除插件表和用户字段值。';
        page($orphaned?'确认强制删除':'确认卸载插件','<section class="plugin-confirm'.($orphaned?' is-orphaned':'').'"><header><span class="plugin-confirm-mark" aria-hidden="true">!</span><div class="plugin-confirm-heading"><p class="eyebrow">'.($orphaned?'插件记录异常':'卸载插件').'</p><h2>'.e($pluginTitle).'</h2><code>'.e($name).'</code></div><span class="plugin-confirm-risk">'.($orphaned?'不可恢复':'谨慎操作').'</span></header><div class="plugin-confirm-body"><p class="plugin-confirm-message">'.e($message).'</p><div class="plugin-confirm-impact"><span>卸载后</span><strong>'.($orphaned?'插件及关联数据将被永久删除':'插件将从后台列表中移除').'</strong></div><form method="post">'.$fields.'</form></div></section>','',false,true);
        return;
    }
    if ($action === 'uninstall') {
        require_confirmation('plugin.uninstall',$name,(string)$post['confirm_token']);
        if (!is_dir($directory)) plugin_set_enabled_state(db(),$name,false);
    }
    if ($name === 'zip-remote-attachments' && in_array($action,['install','upgrade','enable'],true)) foreach (builtin_plugin_schema($name) as $sql) h($sql);
    try { extension_plugin_lifecycle($name,$action,!empty($post['preserve_data']), approvedByAdmin: true); }
    catch(Throwable $error){extension_invalidate('plugins');fail(409,'插件操作失败：'.$error->getMessage());}
    extension_invalidate('plugins');
    redirect('/admin/plugins');
}

$actionForm = static function (string $name, string $action, string $label, string $class): string {
    return '<form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'">'
        .'<input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="'.e($action).'">'
        .'<button type="submit" class="'.e($class).'">'.e($label).'</button></form>';
};
$body = '<div class="extension-catalog">';
$invalidExtensions = [];
if ($section === 'themes') {
    $body .= '<div class="admin-list-toolbar catalog-toolbar"><p class="admin-intro">启用前会检查模板结构与兼容性，模板代码仍需人工审查。默认模板不可删除，删除正在使用的模板前请先切换模板。</p></div>';
    $active = (string)(load_config()['theme'] ?? 'default');
    $cards = [];
    foreach (is_dir($root.'/templates') ? new DirectoryIterator($root.'/templates') : [] as $dir) {
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dir->getFilename())) continue;
        $name = $dir->getFilename();
        $manifest = json_decode((string)@file_get_contents($dir->getPathname().'/theme.json'), true);
        try { extension_validate_directory($dir->getPathname(), 'theme', $name); } catch (Throwable $error) { $invalidExtensions[$name] = $error->getMessage(); continue; }
        $settings = is_array($manifest) ? theme_manifest_settings($manifest) : [];
        $isActive = $name === $active;
        $preview = '<span class="catalog-preview-placeholder" aria-hidden="true"><i></i><i></i><i></i></span>';
        // Only look at fixed raster filenames on the existing public theme asset path.
        $previewTypes = ['screenshot.webp'=>IMAGETYPE_WEBP, 'screenshot.png'=>IMAGETYPE_PNG, 'screenshot.jpg'=>IMAGETYPE_JPEG, 'screenshot.jpeg'=>IMAGETYPE_JPEG];
        if ($name === 'default') $previewTypes['banner.webp'] = IMAGETYPE_WEBP;
        foreach ($previewTypes as $asset => $type) {
            $file = extension_secure_runtime_file($root, 'templates/'.$name.'/assets/'.$asset);
            if ($file === null || (@getimagesize($file)[2] ?? null) !== $type) continue;
            $preview .= '<img src="/templates/'.rawurlencode($name).'/assets/'.rawurlencode($asset).'" alt="" loading="lazy" decoding="async">';
            break;
        }

        $card = '<article class="catalog-theme'.($isActive ? ' is-active' : '').'">'
            .'<div class="catalog-preview">'.$preview.'</div><div class="catalog-theme-details">'
            .($isActive ? '<span class="catalog-status is-enabled">当前使用</span>' : '')
            .'<h2>'.e($manifest['title'] ?? $name).'</h2><p class="catalog-meta">'.e($name).' · 版本 '.e($manifest['version'] ?? '未知').' · '.e($manifest['author'] ?? '未知作者').'</p>';
        $card .= '</div><div class="catalog-theme-actions">';
        if (!$isActive) $card .= $actionForm($name, 'enable', '启用', 'primary');
        if ($settings) $card .= '<a class="catalog-button" href="/admin/themes?theme='.rawurlencode($name).'">管理设置</a>';
        if ($name !== 'default' && !$isActive) $card .= $actionForm($name, 'delete', '删除模板', 'catalog-button is-danger');
        $cards[$name] = $card.'</div></article>';
    }
    if (!isset($cards[$active])) {
        $reason = isset($invalidExtensions[$active]) ? '未通过检查。' : '目录缺失或不可用。';
        $body .= '<section class="catalog-notice"><h2>当前模板不可用</h2><p>'.e($active).'：'.$reason.'</p></section>';
    }
    if ($cards) {
        $activeCard = $cards[$active] ?? '';
        unset($cards[$active]);
        $body .= '<div class="catalog-theme-grid">'.$activeCard.implode('', $cards).'</div>';
    } else {
        $body .= '<p class="catalog-empty">没有通过检查的模板。</p>';
    }
} else {
    $body .= '<div class="admin-list-toolbar catalog-toolbar"><p class="admin-intro">插件会执行 PHP 代码，请在启用前人工审查。未应用的插件迁移须由运维人员通过 CLI 执行。状态变更前会检查依赖、冲突和核心能力，卸载时可选择保留数据。</p></div>';
    $pluginRow = static function (string $title, string $name, string $actions, string $description, string $meta, string $state, string $stateClass): string {
        return '<tr role="row"><th scope="row" role="rowheader"><strong class="catalog-plugin-title">'.e($title).'</strong>'
            .($title !== $name ? '<span class="catalog-meta">'.e($name).'</span>' : '')
            .'<div class="catalog-row-actions">'.$actions.'</div></th>'
            .'<td role="cell" class="catalog-plugin-description"><p>'.e($description).'</p><p class="catalog-meta">'.e($meta).'</p></td>'
            .'<td role="cell" class="catalog-plugin-status"><span class="catalog-status '.e($stateClass).'">'.e($state).'</span></td></tr>';
    };
    $rows = '';
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
        $actions = '';
        $version = (string)($manifest['version'] ?? '未知');
        $meta = '版本 '.$version;
        if ($enabled && $adminPath !== null) $actions .= '<a class="catalog-row-action" href="'.e($adminPath).'">管理</a>';
        if (!$record) {
            $actions .= $actionForm($name, 'install', '安装', 'catalog-row-action');
        } else {
            if (version_compare((string)($manifest['version'] ?? '0.0.0'), (string)$record['version'], '>')) {
                $actions .= $actionForm($name, 'upgrade', '升级', 'catalog-row-action');
                $meta = '已安装 '.$record['version'].' · 可升级至 '.$version;
            }
            $actions .= $actionForm($name, $enabled ? 'disable' : 'enable', $enabled ? '停用' : '启用', 'catalog-row-action');
            if (!$enabled) $actions .= $actionForm($name, 'uninstall', '卸载', 'catalog-row-action is-danger');
        }
        $state = $record ? ($enabled ? '已启用' : '已停用') : '未安装';
        $rows .= $pluginRow((string)($manifest['title'] ?? $name), $name, $actions, (string)($manifest['description'] ?? '暂无说明'), $meta, $state, $enabled ? 'is-enabled' : '');
        unset($installed[$name]);
    }
    foreach ($installed as $name => $record) {
        $enabled = (int)$record['enabled'];
        $actions = $enabled ? $actionForm($name, 'disable', '停用', 'catalog-row-action') : '';
        $actions .= $actionForm($name, 'uninstall', '强制永久删除', 'catalog-row-action is-danger');
        $rows .= $pluginRow($name, $name, $actions, '插件目录缺失', '已登记版本 '.$record['version'], $enabled ? '已启用' : '已停用', 'is-warning');
    }
    $body .= '<p class="admin-intro">安装、升级或启用前，请审核插件的 PHP 代码。启用的插件拥有与应用相同的 PHP 进程权限。</p>'
        .'<div class="catalog-plugin-list"><table class="catalog-plugin-table" role="table" aria-label="插件列表">'
        .'<thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">插件</th><th scope="col" role="columnheader">说明</th><th scope="col" role="columnheader">状态</th></tr></thead><tbody role="rowgroup">'
        .($rows !== '' ? $rows : '<tr role="row"><td role="cell" colspan="3" class="catalog-empty">暂无可管理的插件。</td></tr>')
        .'</tbody></table></div>';
}
if ($invalidExtensions) {
    $body .= '<section class="catalog-notice"><h2>未通过检查的'.($section === 'themes' ? '模板' : '插件').'</h2><ul>';
    foreach ($invalidExtensions as $name => $reason) {
        $body .= '<li><strong>'.e($name).'</strong><p>'.e($reason).'</p>';
        if ($section === 'plugins') $body .= $actionForm($name, 'purge', '永久删除并清理数据', 'catalog-button is-danger');
        $body .= '</li>';
    }
    $body .= '</ul></section>';
}
if ($section === 'plugins') {
    $rendererDiagnostics = extension_markdown_renderer_diagnostics();
    if ($rendererDiagnostics['conflict']) {
        $body .= '<section class="catalog-notice"><h2>Markdown 主渲染器冲突</h2><p>当前选择：'.e($rendererDiagnostics['active']).'。候选项按优先级排列：</p><ul>';
        foreach ($rendererDiagnostics['renderers'] as $renderer) {
            $body .= '<li><strong>'.e($renderer['plugin']).'</strong><p>优先级 '.(int)$renderer['priority'].' · '.e($renderer['reason']).'</p></li>';
        }
        $body .= '</ul></section>';
    }
}
page($section === 'themes' ? '模板管理' : '插件管理', $body.'</div>');
return;
}
