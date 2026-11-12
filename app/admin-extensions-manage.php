<?php
declare(strict_types=1);

if (!in_array($path, ['/admin/themes', '/admin/plugins'], true) || !in_array($method, ['GET', 'POST'], true)) return;

$actor = admin();
$section = $path === '/admin/themes' ? 'themes' : 'plugins';
$root = dirname(__DIR__);

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
        header('Clear-Site-Data: "cache"');
        redirect('/');
    }

    $directory = $root.'/plugins/'.$name;
    $manifest = is_file($directory.'/manifest.json') ? json_decode((string)file_get_contents($directory.'/manifest.json'), true) : null;
    try { extension_validate_directory($directory, 'plugin', $name); } catch (Throwable $error) { fail(400, '插件预检失败：'.$error->getMessage()); }

    if ($action === 'delete') {
        if (empty($_POST['confirm_token'])) confirmation_page('确认删除插件','将永久删除插件目录及登记信息。数据库表和数据会保留，需由运维另行清理。','plugin.delete',['name'=>$name,'action'=>'delete'],$name);
        require_confirmation('plugin.delete',$name,(string)$_POST['confirm_token']);
        h('UPDATE plugins SET enabled=0 WHERE name=?',[$name]);
        h('DELETE FROM plugins WHERE name=?',[$name]);
        audit('plugin.deleted','plugin',null,['name'=>$name,'database_tables_retained'=>$manifest['database_tables']??[]]);
        remove_tree($directory);
        redirect('/admin/plugins');
    }

    if (!in_array($action, ['enable', 'disable'], true)) fail(400, '插件操作无效');
    $enabled = $action === 'enable' ? 1 : 0;
    h('INSERT INTO plugins(name,version,enabled,config_json,installed_at) VALUES(?,?,?,JSON_OBJECT(),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),enabled=VALUES(enabled)', [$name, (string)($manifest['version'] ?? '0.0.0'), $enabled]);
    audit('plugin.toggled', 'plugin', null, ['name' => $name, 'enabled' => $enabled]);
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
    $body .= '<p class="admin-intro">当前版本仅登记插件包，不会执行 plugin.php。这里的启用状态不代表插件功能已运行。删除插件会移除文件和登记信息，但保留数据库表与数据。</p>';
    $installed = [];
    foreach (h('SELECT name,version,enabled FROM plugins')->fetchAll() as $row) $installed[$row['name']] = $row;
    foreach (is_dir($root.'/plugins') ? new DirectoryIterator($root.'/plugins') : [] as $dir) {
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dir->getFilename())) continue;
        $name = $dir->getFilename();
        $manifest = json_decode((string)@file_get_contents($dir->getPathname().'/manifest.json'), true);
        try { extension_validate_directory($dir->getPathname(), 'plugin', $name); } catch (Throwable $error) { $invalidExtensions[$name] = $error->getMessage(); continue; }
        $enabled = (int)($installed[$name]['enabled'] ?? 0);
        $body .= '<section class="extension-row"><div><h2>'.e($manifest['title'] ?? $name).'</h2><p>'.e($name).' · '.e($manifest['version'] ?? '未知版本').' · '.e($manifest['description'] ?? '暂无说明').' · '.($enabled ? '已启用' : '已停用').'</p></div><div class="extension-actions"><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="'.($enabled ? 'disable' : 'enable').'"><button class="primary">'.($enabled ? '停用' : '启用').'</button></form><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="delete"><label>输入 ID 确认<input name="confirm_name" required autocomplete="off"></label><button>删除插件</button></form></div></section>';
    }
    $body = str_replace('<label>输入 ID 确认<input name="confirm_name" required autocomplete="off"></label>', '', $body);
    if ($invalidExtensions) { $body .= '<section class="extension-errors"><h2>未通过检查的插件</h2>'; foreach ($invalidExtensions as $name => $reason) $body .= '<article><strong>'.e($name).'</strong><p>'.e($reason).'</p></article>'; $body .= '</section>'; }
    if (!$installed && !isset($name)) $body .= '<p class="admin-intro">尚未安装插件。</p>';
}
page($section === 'themes' ? '模板管理' : '插件管理', $body);
exit;
