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
    $preferences = h('SELECT EXISTS(SELECT 1 FROM bookmarks WHERE user_id=? AND topic_id=?) AS bookmarked,EXISTS(SELECT 1 FROM topic_subscriptions WHERE user_id=? AND topic_id=?) AS subscribed', [$viewerId, $topicId, $viewerId, $topicId])->fetch();
    $state['bookmarked'] = !empty($preferences['bookmarked']);
    $state['subscribed'] = !empty($preferences['subscribed']);
    foreach (h('SELECT post_id,COUNT(*) total,MAX(user_id=?) liked FROM reactions WHERE reaction="like" AND post_id IN ('.$postMarks.') GROUP BY post_id', array_merge([$viewerId], $postIds))->fetchAll() as $row) { $postId=(int)$row['post_id'];$state['likes'][$postId]=(int)$row['total'];if(!empty($row['liked']))$state['liked'][$postId]=true; }
    foreach (h('SELECT blocked_user_id FROM user_blocks WHERE user_id=? AND blocked_user_id IN ('.$authorMarks.')', array_merge([$viewerId], $authorIds))->fetchAll(PDO::FETCH_COLUMN) as $authorId) $state['blocked'][(int)$authorId] = true;
    return $state;
}
