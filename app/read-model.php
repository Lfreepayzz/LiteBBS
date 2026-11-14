<?php
declare(strict_types=1);

function topic_page_viewer_state(array $posts, int $topicId, ?array $viewer): array
{
    $state = ['bookmarked'=>false, 'subscribed'=>false, 'likes'=>[], 'liked'=>[], 'blocked'=>[]];
    if (!$viewer || !$posts) return $state;
    $viewerId = (int)$viewer['id'];
    $postIds = array_values(array_unique(array_map(static fn(array $post): int => (int)$post['id'], $posts)));
    $authorIds = array_values(array_unique(array_map(static fn(array $post): int => (int)$post['user_id'], $posts)));
    $postMarks = implode(',', array_fill(0, count($postIds), '?'));
    $authorMarks = implode(',', array_fill(0, count($authorIds), '?'));
    $state['bookmarked'] = (bool)h('SELECT 1 FROM bookmarks WHERE user_id=? AND topic_id=?', [$viewerId, $topicId])->fetchColumn();
    $state['subscribed'] = (bool)h('SELECT 1 FROM topic_subscriptions WHERE user_id=? AND topic_id=?', [$viewerId, $topicId])->fetchColumn();
    foreach (h('SELECT post_id,COUNT(*) total FROM reactions WHERE reaction="like" AND post_id IN ('.$postMarks.') GROUP BY post_id', $postIds)->fetchAll() as $row) $state['likes'][(int)$row['post_id']] = (int)$row['total'];
    foreach (h('SELECT post_id FROM reactions WHERE user_id=? AND reaction="like" AND post_id IN ('.$postMarks.')', array_merge([$viewerId], $postIds))->fetchAll(PDO::FETCH_COLUMN) as $postId) $state['liked'][(int)$postId] = true;
    foreach (h('SELECT blocked_user_id FROM user_blocks WHERE user_id=? AND blocked_user_id IN ('.$authorMarks.')', array_merge([$viewerId], $authorIds))->fetchAll(PDO::FETCH_COLUMN) as $authorId) $state['blocked'][(int)$authorId] = true;
    return $state;
}
