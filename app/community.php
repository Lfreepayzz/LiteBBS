<?php
declare(strict_types=1);

if ($path === '/avatar/upload' && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login');
    limit_action('avatar-upload', 5, 3600, 'user:'.$user['id']);
    $file = $_FILES['avatar'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] > 2097152) fail(400, '头像无效或超过 2 MiB');
    $info = @getimagesize((string)$file['tmp_name']); $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: '';
    if (!$info || !in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048 || $info[0] * $info[1] > 4194304) fail(400, '头像必须是 2048px 内的 JPG、PNG、GIF 或 WebP 图片');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) fail(503, '头像图片处理组件不可用');
    $image = @imagecreatefromstring((string)file_get_contents((string)$file['tmp_name']));
    if (!$image) fail(400, '头像图片无法解码');
    $width = imagesx($image); $height = imagesy($image); $scale = min(1, 512 / max($width, $height));
    $resized = imagecreatetruecolor(max(1, (int)floor($width * $scale)), max(1, (int)floor($height * $scale)));
    if (!$resized) { imagedestroy($image); fail(500, '头像图片处理失败'); }
    imagealphablending($resized, false); imagesavealpha($resized, true);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
    $dir = dirname(__DIR__).'/uploads/avatars'; if (!is_dir($dir) && !mkdir($dir, 0750, true)) { imagedestroy($resized); imagedestroy($image); fail(500, '头像目录不可用'); }
    $name = bin2hex(random_bytes(24)); $target = $dir.'/'.$name.'.png';
    $saved = imagepng($resized, $target, 6); imagedestroy($resized); imagedestroy($image);
    if (!$saved) fail(500, '头像保存失败');
    try { $old = (string)h('SELECT avatar_path FROM users WHERE id=?', [$user['id']])->fetchColumn(); h('UPDATE users SET avatar_path=? WHERE id=?', [$name.'.png', $user['id']]); }
    catch (Throwable $error) { @unlink($target); throw $error; }
    if ($old !== '') @unlink($dir.'/'.basename($old)); extension_event_async('user.avatar.updated',['user_id'=>(int)$user['id']]); redirect('/me?tab=profile');
}
if (preg_match('~^/avatar/(\d+)$~', $path, $match) && $method === 'GET') {
    $avatar = h('SELECT avatar_path FROM users WHERE id=? AND status="active"', [(int)$match[1]])->fetchColumn(); $file = $avatar ? dirname(__DIR__).'/uploads/avatars/'.basename((string)$avatar) : ''; if (!$file || !is_file($file)) fail(404, '头像不存在');
    $type=(new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'image/png';header('Content-Type: '.$type); header('X-Content-Type-Options: nosniff'); header('Cache-Control: public, max-age=86400');if(getenv('LITEBBS_X_ACCEL_REDIRECT')==='1'){header('X-Accel-Redirect: /__litebbs-uploads/avatars/'.rawurlencode(basename((string)$avatar)));return;}readfile($file); return;
}
if (preg_match('~^/topic/(\d+)/(bookmark|subscribe)$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $topicId = (int)$match[1];
    if (!h('SELECT id FROM topics WHERE id=? AND status="normal"', [$topicId])->fetch()) fail(404, '主题不存在');
    $table = $match[2] === 'bookmark' ? 'bookmarks' : 'topic_subscriptions'; $db=db(); $db->beginTransaction();
    try { $exists = (bool)h('SELECT 1 FROM '.$table.' WHERE user_id=? AND topic_id=? FOR UPDATE', [$user['id'], $topicId])->fetchColumn(); if ($exists) h('DELETE FROM '.$table.' WHERE user_id=? AND topic_id=?', [$user['id'], $topicId]); else h('INSERT INTO '.$table.'(user_id,topic_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))', [$user['id'], $topicId]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    extension_event_async($match[2] === 'bookmark' ? ($exists ? 'bookmark.deleted' : 'bookmark.created') : ($exists ? 'subscription.deleted' : 'subscription.created'), ['topic_id'=>$topicId,'actor_id'=>(int)$user['id']]);
    redirect('/t/'.$topicId);
}
if (preg_match('~^/post/(\d+)/like$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $postId = (int)$match[1];
    $post = h('SELECT p.id,p.topic_id FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal" AND t.status="normal"', [$postId])->fetch();
    if (!$post) fail(404, '回复不存在');
    $db=db(); $db->beginTransaction();
    try { $exists = (bool)h('SELECT 1 FROM reactions WHERE user_id=? AND post_id=? AND reaction="like" FOR UPDATE', [$user['id'], $postId])->fetchColumn(); if ($exists) h('DELETE FROM reactions WHERE user_id=? AND post_id=? AND reaction="like"', [$user['id'], $postId]); else h('INSERT INTO reactions(user_id,post_id,reaction,created_at) VALUES(?, ?, "like", UTC_TIMESTAMP(6))', [$user['id'], $postId]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    extension_event_async($exists ? 'reaction.deleted' : 'reaction.created', ['post_id'=>$postId,'topic_id'=>(int)$post['topic_id'],'actor_id'=>(int)$user['id'],'reaction'=>'like']);
    redirect('/t/'.(int)$post['topic_id'].'#p'.$postId);
}
if (preg_match('~^/post/(\d+)/delete$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $postId = (int)$match[1]; $db = db(); $db->beginTransaction();
    try {
        $post = h('SELECT p.id,p.topic_id,p.user_id,t.category_id,(SELECT MIN(fp.id) FROM posts fp WHERE fp.topic_id=p.topic_id AND fp.status="normal") AS original_post_id FROM posts p JOIN topics t ON t.id=p.topic_id WHERE p.id=? AND p.status="normal" AND t.status="normal" FOR UPDATE', [$postId])->fetch();
        if (!$post) fail(404, '回复不存在');
        if ((int)$post['user_id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin') fail(403, '无权删除此回复');
        if ((int)$post['id'] === (int)$post['original_post_id']) fail(400, '主题首帖不能在此删除');
        if (h('UPDATE posts SET status="deleted" WHERE id=? AND status="normal"', [$postId])->rowCount() !== 1) fail(409, '回复状态已变化，请刷新后重试');
        if (topic_last_post_pointer_available()) h('UPDATE topics SET reply_count=GREATEST(reply_count-1,0),last_post_id=(SELECT id FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),last_post_at=COALESCE((SELECT created_at FROM posts WHERE topic_id=? AND status="normal" ORDER BY id DESC LIMIT 1),created_at) WHERE id=?', [(int)$post['topic_id'],(int)$post['topic_id'],(int)$post['topic_id']]);
        else h('UPDATE topics SET reply_count=GREATEST(reply_count-1,0) WHERE id=?', [(int)$post['topic_id']]);
        h('DELETE FROM notifications WHERE post_id=?', [$postId]);
        audit('post.deleted', 'post', $postId, ['topic_id'=>(int)$post['topic_id']]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    extension_event_async('post.deleted', ['post_id'=>$postId,'topic_id'=>(int)$post['topic_id'],'actor_id'=>(int)$user['id'],'reason'=>'user']);
    invalidate_topic_read_cache((int)$post['topic_id'], (int)$post['category_id'], false, false);
    redirect('/t/'.(int)$post['topic_id'].'#replies');
}
if ($path === '/report' && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); limit_action('report', 10, 300, 'user:'.$user['id']); limit_action('report-ip', 30, 3600, hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''))); $type = (string)($_POST['target_type'] ?? ''); $target = (int)($_POST['target_id'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
    if (!in_array($type, ['topic','post','user'], true) || $target < 1 || text_length($reason) < 5 || text_length($reason) > 1000) fail(400, '举报信息无效'); $table = ['topic'=>'topics','post'=>'posts','user'=>'users'][$type]; if (!h('SELECT id FROM '.$table.' WHERE id=?', [$target])->fetch()) fail(404, '举报目标不存在');
    h('INSERT INTO reports(reporter_id,target_type,target_id,reason,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6))', [$user['id'],$type,$target,$reason]); $reportId = (int)db()->lastInsertId(); audit('report.created', 'report', $reportId, ['target_type'=>$type,'target_id'=>$target]); extension_event_async('report.created',['report_id'=>$reportId,'target_type'=>$type,'target_id'=>$target,'actor_id'=>(int)$user['id']]); page('举报已提交', '<section class="community-message"><h1>举报已提交</h1><p>举报编号 #'. $reportId .'，管理员会审核这条举报。</p><a href="/">返回首页</a></section>'); return;
}
if (preg_match('~^/user/(\d+)/(block|unblock)$~', $path, $match) && $method === 'POST') {
    require_post(); $user = current_user(); if (!$user) redirect('/login'); $blocked = (int)$match[1];
    if ($match[2] === 'block') { if ($blocked === (int)$user['id']) fail(400, '不能屏蔽自己'); if (!h('SELECT id FROM users WHERE id=? AND status="active"', [$blocked])->fetch()) fail(404,'用户不存在'); $changed=h('INSERT IGNORE INTO user_blocks(user_id,blocked_user_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))',[$user['id'],$blocked])->rowCount()===1; if($changed)extension_event_async('user.blocked',['actor_id'=>(int)$user['id'],'user_id'=>$blocked]); } else { $changed=h('DELETE FROM user_blocks WHERE user_id=? AND blocked_user_id=?',[$user['id'],$blocked])->rowCount()===1; if($changed)extension_event_async('user.unblocked',['actor_id'=>(int)$user['id'],'user_id'=>$blocked]); } redirect('/me?tab=blocks');
}
