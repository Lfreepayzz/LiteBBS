<?php
declare(strict_types=1);

namespace LiteBBS\Application;

use PDO;

final class PrivateMessageService
{
    public function __construct(private TransactionManager $transactions, private AuditLogger $audit)
    {
    }

    public function send(int $senderId, int $recipientId, string $body): int
    {
        if ($senderId === $recipientId) \fail(400, '不能给自己发送私信');

        $messageId = $this->transactions->run(function (PDO $db) use ($senderId, $recipientId, $body): int {
            $accounts = \h('SELECT id,status FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE',
                [min($senderId, $recipientId), max($senderId, $recipientId)])->fetchAll();
            if (count($accounts) !== 2 || array_column($accounts, 'status') !== ['active', 'active']) {
                \fail(404, '发件人或收件人不可用');
            }
            if (\h('SELECT 1 FROM user_blocks WHERE (user_id=? AND blocked_user_id=?) OR (user_id=? AND blocked_user_id=?) LIMIT 1',
                [$senderId, $recipientId, $recipientId, $senderId])->fetchColumn()) {
                \fail(403, '你与该用户之间无法发送私信');
            }
            \h('INSERT INTO private_messages(sender_id,recipient_id,body,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$senderId, $recipientId, $body]);
            $id = (int)$db->lastInsertId();
            \h('INSERT INTO notifications(user_id,actor_id,topic_id,post_id,kind,created_at) VALUES(?,?,NULL,NULL,"message",UTC_TIMESTAMP(6))', [$recipientId, $senderId]);
            $this->audit->record('message.sent', 'private_message', $id, ['recipient_id'=>$recipientId]);
            return $id;
        });
        \invalidate_user_cache($recipientId);
        return $messageId;
    }
}
