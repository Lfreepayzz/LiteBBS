<?php
declare(strict_types=1);
function handle_admin_categories(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$post = $request->post;
if ($path !== '/admin/categories' || !in_array($method, ['GET','POST'], true)) return;
$actor=admin();
if ($method==='POST') {
 require_post();
    $id = (int)($post['id'] ?? 0);
    $action = $post['action'] ?? 'save';
    if (!in_array($action, ['save', 'delete', 'restore'], true)) fail(400, '操作无效');
    $title = trim((string)($post['title'] ?? ''));
    $description = trim((string)($post['description'] ?? ''));
    if ($action === 'save' && (text_length($title) < 1 || text_length($title) > 100 || text_length($description) > 500)) {
        fail(400, '分区名称或说明长度无效');
    }
    $position = (int)($post['position'] ?? 0);
    $visible = $action === 'save' ? isset($post['is_visible']) : $action === 'restore';
    try {
        [$id, $countChanged] = \LiteBBS\Application\transaction_manager()->run(static function (PDO $pdo) use ($id, $action, $actor, $title, $description, $position, $visible): array {
            $existing = null;
            if ($id || $action !== 'save') {
                $existing = h('SELECT is_visible FROM categories WHERE id=? FOR UPDATE', [$id])->fetch();
                if (!$existing) fail(404, '分区不存在');
            }
            if ($action !== 'save') {
                h('UPDATE categories SET is_visible=? WHERE id=?', [(int)$visible, $id]);
                audit('category.'.$action, 'category', $id);
                extension_enqueue_async_event('category.visibility.updated', ['category_id'=>$id, 'visible'=>$visible, 'actor_id'=>(int)$actor['id']]);
            } else {
                $values = [slug($title), $title, $description, $position, (int)$visible];
                if ($existing) {
                    h('UPDATE categories SET slug=?,title=?,description=?,position=?,is_visible=? WHERE id=?', [...$values, $id]);
                } else {
                    h('INSERT INTO categories(slug,title,description,position,is_visible) VALUES(?,?,?,?,?)', $values);
                    $id = (int)$pdo->lastInsertId();
                }
                audit('category.saved', 'category', $id);
                extension_enqueue_async_event($existing ? 'category.updated' : 'category.created', ['category_id'=>$id, 'actor_id'=>(int)$actor['id']]);
            }
            return [$id, !$existing || (bool)$existing['is_visible'] !== $visible];
        });
    } catch (PDOException $error) {
        if ($action === 'save' && (int)($error->errorInfo[1] ?? 0) === 1062) fail(409, '分区名称生成的 slug 已存在，请使用不同名称');
        throw $error;
    }
    invalidate('categories');
    invalidate_topic_listing_cache($id, $countChanged);
    redirect('/admin/categories');
}
$rows=h('SELECT id,title,description,position,is_visible FROM categories ORDER BY position,id')->fetchAll();
$body='<p class="admin-intro">创建分区、修改名称与说明、调整顺序，或隐藏不再使用的分区。隐藏不会删除已有主题。</p>';
$body.='<form class="form category-create" method="post"><h2>添加分区</h2><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="save"><label>名称<input name="title" maxlength="100" required></label><label>说明<input name="description" maxlength="500"></label><label>排序<input name="position" type="number" value="0"></label><label><input type="checkbox" name="is_visible" checked> 前台显示</label><button class="primary">创建分区</button></form><h2>现有分区 <small>（'.count($rows).'）</small></h2>';
foreach($rows as$r){$id=(int)$r['id'];$body.='<section class="category-admin"><h3>分区 #'.$id.' · '.e($r['title']).($r['is_visible']?'':' <span>已隐藏</span>').'</h3><form method="post" class="category-edit"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.$id.'"><label>名称<input name="title" maxlength="100" value="'.e($r['title']).'" required></label><label>说明<input name="description" maxlength="500" value="'.e($r['description']).'"></label><label>排序<input name="position" type="number" value="'.(int)$r['position'].'"></label><label class="check-label"><input type="checkbox" name="is_visible"'.($r['is_visible']?' checked':'').'> 前台显示</label><button class="primary">保存修改</button></form><form method="post" class="category-delete"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.($r['is_visible']?'delete':'restore').'"><button>'.($r['is_visible']?'删除分区（隐藏）':'恢复分区').'</button></form></section>';}
 page('分区管理',$body); return;
}
