<?php
declare(strict_types=1);

require_once __DIR__.'/private-message-service.php';

function handle_messages(\LiteBBS\Framework\Request $request): void
{
$path = $request->path;
$method = $request->method;
$query = $request->query;
$post = $request->post;
if ($path !== '/messages' || !in_array($method, ['GET', 'POST'], true)) return;

$user = current_user();
if (!$user) redirect('/login');
$viewerId = (int)$user['id'];
$recipientName = trim((string)($query['to'] ?? $post['to'] ?? ''));

if ($method === 'POST') {
    require_post();
    $action = (string)($post['action'] ?? 'send');
    if ($action === 'mark_read') {
        if ($recipientName === '' || text_length($recipientName) > 32) fail(400, '私信会话无效');
        $recipient = h('SELECT id,username FROM users WHERE username=? AND status="active"', [$recipientName])->fetch();
        if (!$recipient || (int)$recipient['id'] === $viewerId) fail(404, '用户不存在');
        h('UPDATE private_messages SET read_at=UTC_TIMESTAMP(6) WHERE sender_id=? AND recipient_id=? AND read_at IS NULL', [(int)$recipient['id'], $viewerId]);
        invalidate_user_cache($viewerId);
        redirect('/messages?to='.rawurlencode((string)$recipient['username']));
    }
    if ($action !== 'send') fail(400, '私信操作无效');
    $body = trim((string)($post['body'] ?? ''));
    if ($recipientName === '' || text_length($recipientName) > 32 || text_length($body) < 1 || text_length($body) > 100) fail(400, '私信内容无效');
    limit_action('message-send', 10, 300, 'user:'.$viewerId);
    $recipient = h('SELECT id,username FROM users WHERE username=? AND status="active"', [$recipientName])->fetch();
    if (!$recipient) fail(404, '收件人不存在');
    $recipientId = (int)$recipient['id'];
    $service = new \LiteBBS\Application\PrivateMessageService(\LiteBBS\Application\transaction_manager(), \LiteBBS\Application\audit_logger());
    $service->send($viewerId, $recipientId, $body);
    redirect('/messages?to='.rawurlencode((string)$recipient['username']));
}

if ($recipientName !== '') {
    $recipient = h('SELECT id,username FROM users WHERE username=? AND status="active"', [$recipientName])->fetch();
    if (!$recipient) fail(404, '用户不存在');
    $recipientId = (int)$recipient['id'];
    if ($recipientId === $viewerId) fail(400, '不能查看与自己的私信');
    $messages = h('SELECT m.id message_id,m.sender_id,m.recipient_id,m.body,m.created_at,u.username sender_name FROM (SELECT id,sender_id,recipient_id,body,created_at FROM private_messages WHERE sender_id=? AND recipient_id=? ORDER BY id DESC LIMIT 100) m JOIN users u ON u.id=m.sender_id UNION ALL SELECT m.id message_id,m.sender_id,m.recipient_id,m.body,m.created_at,u.username sender_name FROM (SELECT id,sender_id,recipient_id,body,created_at FROM private_messages WHERE sender_id=? AND recipient_id=? ORDER BY id DESC LIMIT 100) m JOIN users u ON u.id=m.sender_id ORDER BY created_at DESC,message_id DESC LIMIT 100', [$viewerId, $recipientId, $recipientId, $viewerId])->fetchAll();
    $messages = array_reverse($messages);
    $body = '<section class="messages"><a class="messages-back" href="/messages">返回私信列表</a><header><h1>与 '.e($recipient['username']).' 的私信</h1></header><div class="message-thread">';
    foreach ($messages as $message) $body .= '<article class="message'.((int)$message['sender_id'] === $viewerId ? ' message-sent' : '').'"><strong>'.e($message['sender_name']).'</strong><p>'.nl2br(e($message['body'])).'</p><time>'.e(substr((string)$message['created_at'], 0, 16)).'</time></article>';
    if (!$messages) $body .= '<p class="empty-state">还没有私信，发一条消息开始对话。</p>';
    $body .= '</div><form method="post" action="/messages"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="to" value="'.e($recipient['username']).'"><button type="submit">标记会话已读</button></form><form class="message-compose" method="post"><input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="send"><input type="hidden" name="to" value="'.e($recipient['username']).'"><label>发送给 '.e($recipient['username']).'<textarea name="body" maxlength="100" rows="4" required></textarea><small>最多 100 字</small></label><button class="primary">发送私信</button></form></section>';
    page('私信', $body);
    return;
}

// Bound both directions with covering index reads before loading message bodies.
$rows = h('SELECT m.id message_id,m.sender_id,m.recipient_id,m.body,m.created_at,m.read_at,s.username sender_name,r.username recipient_name
    FROM (
        (SELECT id FROM private_messages WHERE sender_id=? ORDER BY id DESC LIMIT 100)
        UNION ALL
        (SELECT id FROM private_messages WHERE recipient_id=? ORDER BY id DESC LIMIT 100)
    ) recent
    JOIN private_messages m ON m.id=recent.id
    JOIN users s ON s.id=m.sender_id
    JOIN users r ON r.id=m.recipient_id
    ORDER BY m.created_at DESC,m.id DESC LIMIT 100', [$viewerId, $viewerId])->fetchAll();
$conversations = [];
foreach ($rows as $message) {
    $otherId = (int)$message['sender_id'] === $viewerId ? (int)$message['recipient_id'] : (int)$message['sender_id'];
    if (isset($conversations[$otherId])) continue;
    $name = (int)$message['sender_id'] === $viewerId ? (string)$message['recipient_name'] : (string)$message['sender_name'];
    $unread = (int)$message['recipient_id'] === $viewerId && $message['read_at'] === null;
    $conversations[$otherId] = ['name'=>$name, 'body'=>(string)$message['body'], 'created_at'=>(string)$message['created_at'], 'unread'=>$unread];
}
$body = '<section class="messages"><header><h1>私信</h1></header><div class="member-list">';
foreach ($conversations as $conversation) $body .= '<a class="member-row" href="/messages?to='.rawurlencode($conversation['name']).'"><span><strong>'.e($conversation['name']).'</strong>'.($conversation['unread'] ? ' <b class="message-unread">未读</b>' : '').'</span><span>'.e(text_slice($conversation['body'], 0, 100)).'</span><time>'.e(substr($conversation['created_at'], 0, 16)).'</time></a>';
if (!$conversations) $body .= '<p class="empty-state">暂无私信。可在用户主页选择“发送私信”。</p>';
$body .= '</div></section>';
page('私信', $body);
}
