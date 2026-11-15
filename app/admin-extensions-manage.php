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
        // Match plugin management: return to this management page after a
        // successful update instead of navigating the administrator to home.
        redirect('/admin/themes');
    }

    $directory = $root.'/plugins/'.$name;
    $manifestFile = is_file($directory.'/plugin.json') ? $directory.'/plugin.json' : $directory.'/manifest.json';
    $manifest = is_file($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
    try { extension_validate_directory($directory, 'plugin', $name); } catch (Throwable $error) { fail(400, '插件预检失败：'.$error->getMessage()); }

    if ($action === 'delete') {
        if (empty($_POST['confirm_token'])) confirmation_page('确认删除插件','将永久删除插件目录、登记信息，以及该插件专属前缀下的全部数据表和残留数据，此操作不可恢复。','plugin.delete',['name'=>$name,'action'=>'delete'],$name);
        require_confirmation('plugin.delete',$name,(string)$_POST['confirm_token']);
        $tables = $manifest['database_tables'] ?? [];
        if (!is_array($tables)) fail(400, '插件数据表清单无效');
        $tablePrefix = isset($manifest['schema']) ? 'plg_'.str_replace('-', '_', $name).'_' : 'plugin_'.str_replace('-', '_', $name).'_';
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^'.preg_quote($tablePrefix, '/').'[a-zA-Z0-9_]{1,64}$/', $table)) fail(400, '插件数据表清单无效');
        }
        $residualTables = h('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE ?', [$tablePrefix.'%'])->fetchAll(PDO::FETCH_COLUMN);
        h('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($residualTables as $table) {
                if (!is_string($table) || !preg_match('/^'.preg_quote($tablePrefix, '/').'[a-zA-Z0-9_]{1,64}$/', $table)) continue;
                h('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
            }
        } finally {
            h('SET FOREIGN_KEY_CHECKS=1');
        }
        h('UPDATE plugins SET enabled=0 WHERE name=?',[$name]);
        h('DELETE FROM plugins WHERE name=?',[$name]);
        plugin_runtime_invalidate();
        audit('plugin.deleted','plugin',null,['name'=>$name,'database_tables_dropped'=>$residualTables]);
        remove_tree($directory);
        redirect('/admin/plugins');
    }

    if (!in_array($action, ['enable', 'disable'], true)) fail(400, '插件操作无效');
    $enabled = $action === 'enable' ? 1 : 0;
    if ($enabled && isset($manifest['schema'])) {
        $migrationDirectory = $directory.'/migrations';
        $files = is_dir($migrationDirectory) ? glob($migrationDirectory.'/*.php') : [];
        $applied = (int)h('SELECT COUNT(*) FROM plugin_migrations WHERE plugin_name=?', [$name])->fetchColumn();
        if ($files && $applied !== count($files)) fail(409, '插件迁移尚未完成。请先在发布环境执行：php scripts/plugin-migrate.php '.$name);
        if ($name === 'zip-remote-attachments') {
            h('CREATE TABLE IF NOT EXISTS plg_zip_remote_attachments_files(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,post_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,source_kind ENUM("upload","remote") NOT NULL,storage_name CHAR(64) NULL,remote_url VARCHAR(2048) NULL,original_name VARCHAR(255) NOT NULL,file_size BIGINT UNSIGNED NULL,sha256 CHAR(64) NULL,access_mode ENUM("login","reply","points") NOT NULL DEFAULT "login",point_cost INT UNSIGNED NOT NULL DEFAULT 0,status ENUM("active","removed") NOT NULL DEFAULT "active",created_at DATETIME(6) NOT NULL,FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id),KEY ix_plg_zip_remote_attachments_post(post_id,status,id),KEY ix_plg_zip_remote_attachments_user_storage(user_id,source_kind,status,file_size)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            h('CREATE TABLE IF NOT EXISTS plg_zip_remote_attachments_grants(user_id BIGINT UNSIGNED NOT NULL,attachment_id BIGINT UNSIGNED NOT NULL,grant_kind ENUM("reply","purchase") NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(user_id,attachment_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(attachment_id) REFERENCES plg_zip_remote_attachments_files(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        $tables = $manifest['database_tables'] ?? [];
        if (is_array($tables)) foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^plg_[a-z0-9_]+_[a-z][a-z0-9_]*$/', $table)) fail(409, '插件数据表清单无效');
            if (!h('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table])->fetchColumn()) fail(409, '插件数据表尚未创建。请先执行：php scripts/migrate.php');
        }
    }
    h('INSERT INTO plugins(name,version,enabled,config_json,installed_at) VALUES(?,?,?,JSON_OBJECT(),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),enabled=VALUES(enabled)', [$name, (string)($manifest['version'] ?? '0.0.0'), $enabled]);
    plugin_runtime_invalidate();
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
    $body .= '<p class="admin-intro">已启用插件会加载并执行其受支持的 Hook；停用后不会加载。插件是受信任的 PHP 代码，启用前请完成人工审查。删除插件会永久删除插件目录、登记信息，以及该插件专属前缀下的数据表和残留数据，请先完成备份。</p>';
    $installed = [];
    foreach (h('SELECT name,version,enabled FROM plugins')->fetchAll() as $row) $installed[$row['name']] = $row;
    foreach (is_dir($root.'/plugins') ? new DirectoryIterator($root.'/plugins') : [] as $dir) {
        if (!$dir->isDir() || $dir->isDot() || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $dir->getFilename())) continue;
        $name = $dir->getFilename();
        $manifestFile = is_file($dir->getPathname().'/plugin.json') ? $dir->getPathname().'/plugin.json' : $dir->getPathname().'/manifest.json';
        $manifest = json_decode((string)@file_get_contents($manifestFile), true);
        try { extension_validate_directory($dir->getPathname(), 'plugin', $name); } catch (Throwable $error) { $invalidExtensions[$name] = $error->getMessage(); continue; }
        $enabled = (int)($installed[$name]['enabled'] ?? 0);
         $body .= '<section class="extension-row"><div><h2>'.e($manifest['title'] ?? $name).'</h2><p>'.e($name).' · '.e($manifest['version'] ?? '未知版本').' · '.e($manifest['description'] ?? '暂无说明').' · '.($enabled ? '已启用' : '已停用').'</p></div><div class="extension-actions"><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="'.($enabled ? 'disable' : 'enable').'"><button class="primary">'.($enabled ? '停用' : '启用').'</button></form><form method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="name" value="'.e($name).'"><input type="hidden" name="action" value="delete"><button>删除插件</button></form></div></section>';
    }
    if ($invalidExtensions) { $body .= '<section class="extension-errors"><h2>未通过检查的插件</h2>'; foreach ($invalidExtensions as $name => $reason) $body .= '<article><strong>'.e($name).'</strong><p>'.e($reason).'</p></article>'; $body .= '</section>'; }
    if (!$installed && !isset($name)) $body .= '<p class="admin-intro">尚未安装插件。</p>';
}
page($section === 'themes' ? '模板管理' : '插件管理', $body);
return;
