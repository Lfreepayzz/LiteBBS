<?php
declare(strict_types=1);
if ($path !== '/admin/categories' || !in_array($method, ['GET','POST'], true)) return;
$actor=admin();
if ($method==='POST') {
 require_post();
 $id=(int)($_POST['id']??0); $action=(string)($_POST['action']??'save');
 if ($action==='delete'||$action==='restore') {
  if (!$id||!h('SELECT id FROM categories WHERE id=?',[$id])->fetchColumn()) fail(404,'分区不存在');
  h('UPDATE categories SET is_visible=? WHERE id=?',[$action==='restore'?1:0,$id]);
  audit('category.'.$action,'category',$id); invalidate('categories'); invalidate('topics'); redirect('/admin/categories');
 }
 if ($action!=='save') fail(400,'操作无效');
 $title=trim((string)($_POST['title']??'')); $desc=trim((string)($_POST['description']??''));
 $position=(int)($_POST['position']??0); $visible=isset($_POST['is_visible'])?1:0;
 if (text_length($title)<1||text_length($title)>100||text_length($desc)>500) fail(400,'分区名称或说明长度无效');
  try { if ($id) {
  if (!h('SELECT id FROM categories WHERE id=?',[$id])->fetchColumn()) fail(404,'分区不存在');
  h('UPDATE categories SET slug=?,title=?,description=?,position=?,is_visible=? WHERE id=?',[slug($title),$title,$desc,$position,$visible,$id]);
 } else {
  h('INSERT INTO categories(slug,title,description,position,is_visible) VALUES(?,?,?,?,?)',[slug($title),$title,$desc,$position,$visible]);
  $id=(int)db()->lastInsertId();
  }} catch (PDOException $error) { if ($error->getCode()==='23000') fail(409,'分区名称生成的 slug 已存在，请使用不同名称'); throw $error; }
 audit('category.saved','category',$id); invalidate('categories'); invalidate('topics'); redirect('/admin/categories');
}
$rows=h('SELECT id,title,description,position,is_visible FROM categories ORDER BY position,id')->fetchAll();
$body='<p class="admin-intro">创建分区、修改名称与说明、调整顺序，或隐藏不再使用的分区。隐藏不会删除已有主题。</p>';
$body.='<form class="form category-create" method="post"><h2>添加分区</h2><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="save"><label>名称<input name="title" maxlength="100" required></label><label>说明<input name="description" maxlength="500"></label><label>排序<input name="position" type="number" value="0"></label><label><input type="checkbox" name="is_visible" checked> 前台显示</label><button class="primary">创建分区</button></form><h2>现有分区 <small>（'.count($rows).'）</small></h2>';
foreach($rows as$r){$id=(int)$r['id'];$body.='<section class="category-admin"><h3>分区 #'.$id.' · '.e($r['title']).($r['is_visible']?'':' <span>已隐藏</span>').'</h3><form method="post" class="category-edit"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="'.$id.'"><label>名称<input name="title" maxlength="100" value="'.e($r['title']).'" required></label><label>说明<input name="description" maxlength="500" value="'.e($r['description']).'"></label><label>排序<input name="position" type="number" value="'.(int)$r['position'].'"></label><label class="check-label"><input type="checkbox" name="is_visible"'.($r['is_visible']?' checked':'').'> 前台显示</label><button class="primary">保存修改</button></form><form method="post" class="category-delete"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="'.($r['is_visible']?'delete':'restore').'"><button>'.($r['is_visible']?'删除分区（隐藏）':'恢复分区').'</button></form></section>';}
page('分区管理',$body); exit;
