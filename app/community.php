<?php
declare(strict_types=1);

if ($path === '/avatar/upload' && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login');
    $file = $_FILES['avatar'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] > 2097152) fail(400, '头像无效或超过 2 MiB');
    $info = @getimagesize((string)$file['tmp_name']); $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
    if (!$info || !in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true) || $info[0] > 2048 || $info[1] > 2048) fail(400, '头像必须是 2048px 内的 JPG、PNG、GIF 或 WebP 图片');
    $dir = dirname(__DIR__).'/uploads/avatars'; if (!is_dir($dir) && !mkdir($dir, 0750, true)) fail(500, '头像目录不可用');
    $name = bin2hex(random_bytes(24)); $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime]; $target = $dir.'/'.$name.'.'.$ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) fail(500, '头像保存失败');
    $old = (string)h('SELECT avatar_path FROM users WHERE id=?', [$user['id']])->fetchColumn(); h('UPDATE users SET avatar_path=? WHERE id=?', [$name.'.'.$ext, $user['id']]); if ($old !== '') @unlink($dir.'/'.basename($old)); redirect('/me?tab=profile');
}
if (preg_match('~^/avatar/(\d+)$~', $path, $match) && $method === 'GET') {
    $avatar = h('SELECT avatar_path FROM users WHERE id=? AND status="active"', [(int)$match[1]])->fetchColumn(); $file = $avatar ? dirname(__DIR__).'/uploads/avatars/'.basename((string)$avatar) : ''; if (!$file || !is_file($file)) fail(404, '头像不存在');
    header('Content-Type: '.((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'image/png')); header('Cache-Control: public, max-age=86400'); readfile($file); return;
}
if (preg_match('~^/topic/(\d+)/(bookmark|subscribe)$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $topicId = (int)$match[1];
    if (!h('SELECT id FROM topics WHERE id=? AND status="normal"', [$topicId])->fetch()) fail(404, '主题不存在');
    $table = $match[2] === 'bookmark' ? 'bookmarks' : 'topic_subscriptions'; $db=db(); $db->beginTransaction();
    try { h('SELECT id FROM users WHERE id=? FOR UPDATE', [$user['id']]); $exists = (bool)h('SELECT 1 FROM '.$table.' WHERE user_id=? AND topic_id=?', [$user['id'], $topicId])->fetchColumn(); if ($exists) h('DELETE FROM '.$table.' WHERE user_id=? AND topic_id=?', [$user['id'], $topicId]); else h('INSERT INTO '.$table.'(user_id,topic_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))', [$user['id'], $topicId]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    redirect('/t/'.$topicId);
}
if (preg_match('~^/post/(\d+)/like$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $postId = (int)$match[1];
    $post = h('SELECT p.id,p.topic_id FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal" AND t.status="normal"', [$postId])->fetch();
    if (!$post) fail(404, '回复不存在');
    $db=db(); $db->beginTransaction();
    try { h('SELECT id FROM users WHERE id=? FOR UPDATE', [$user['id']]); $exists = (bool)h('SELECT 1 FROM reactions WHERE user_id=? AND post_id=? AND reaction="like"', [$user['id'], $postId])->fetchColumn(); if ($exists) h('DELETE FROM reactions WHERE user_id=? AND post_id=? AND reaction="like"', [$user['id'], $postId]); else h('INSERT INTO reactions(user_id,post_id,reaction,created_at) VALUES(?, ?, "like", UTC_TIMESTAMP(6))', [$user['id'], $postId]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    redirect('/t/'.(int)$post['topic_id'].'#p'.$postId);
}
if ($path === '/report' && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); limit_action('report', 10, 300, 'user:'.$user['id']); $type = (string)($_POST['target_type'] ?? ''); $target = (int)($_POST['target_id'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
    if (!in_array($type, ['topic','post','user'], true) || $target < 1 || text_length($reason) < 5 || text_length($reason) > 1000) fail(400, '举报信息无效'); $table = ['topic'=>'topics','post'=>'posts','user'=>'users'][$type]; if (!h('SELECT id FROM '.$table.' WHERE id=?', [$target])->fetch()) fail(404, '举报目标不存在');
    h('INSERT INTO reports(reporter_id,target_type,target_id,reason,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))', [$user['id'],$type,$target,$reason]); $reportId = (int)db()->lastInsertId(); audit('report.created', 'report', $reportId, ['target_type'=>$type,'target_id'=>$target]); page('举报已提交', '<section class="community-message"><h1>举报已提交</h1><p>举报编号 #'. $reportId .'，管理员会审核这条举报。</p><a href="/">返回首页</a></section>'); return;
}
if (preg_match('~^/user/(\d+)/(block|unblock)$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $blocked = (int)$match[1];
    if ($match[2] === 'block') { if ($blocked === (int)$user['id']) fail(400, '不能屏蔽自己'); if (!h('SELECT id FROM users WHERE id=? AND status="active"', [$blocked])->fetch()) fail(404, '用户不存在'); h('INSERT IGNORE INTO user_blocks(user_id,blocked_user_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))', [$user['id'],$blocked]); } else h('DELETE FROM user_blocks WHERE user_id=? AND blocked_user_id=?', [$user['id'],$blocked]); redirect('/me?tab=blocks');
}
