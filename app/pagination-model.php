<?php
declare(strict_types=1);

namespace LiteBBS\Application;

final class Pagination
{
    /** @return array{current_page: int, page_count: int, pages: list<array{number: int, url: string, current: bool}>} */
    public static function forView(int $currentPage, int $itemCount, int $pageSize, string $firstUrl, string $numberedPath): array
    {
        $pageCount = max(1, (int)ceil(max(0, $itemCount) / max(1, $pageSize)));
        $currentPage = min(max(1, $currentPage), $pageCount);
        $numbers = $pageCount <= 7
            ? range(1, $pageCount)
            : array_values(array_unique(array_filter([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $pageCount - 1, $pageCount], static fn(int $number): bool => $number >= 1 && $number <= $pageCount)));
        sort($numbers, SORT_NUMERIC);
        $pages = [];
        foreach ($numbers as $number) {
            $pages[] = ['number' => $number, 'url' => $number === 1 ? $firstUrl : $numberedPath.'-'.$number, 'current' => $number === $currentPage];
        }

        return ['current_page' => $currentPage, 'page_count' => $pageCount, 'pages' => $pages];
    }
}

final class NumericPageCursor
{
    public static function feed(int $page, int $pageSize, ?int $categoryId = null): string
    {
        if ($page < 2) return '';
        $scope = $categoryId === null ? 'home' : 'category:'.$categoryId;
        $versions = $categoryId === null
            ? \cache_versions(['topics', 'categories'])
            : \cache_versions(['category-topics:'.$categoryId]);
        $version = $categoryId === null
            ? (int)$versions['topics'].':'.(int)$versions['categories']
            : (int)$versions['category-topics:'.$categoryId];
        $cached = \cached('numeric-page:v1:'.$scope.':'.$pageSize.':'.$page.':'.$version, 300, static function () use ($page, $pageSize, $categoryId): array {
            $cursor = null;
            for ($number = 2; $number <= $page; $number++) {
                $args = [];
                $where = $categoryId === null ? 't.status="normal" AND c.is_visible=1' : 't.category_id=? AND t.status="normal"';
                if ($categoryId !== null) $args[] = $categoryId;
                if ($cursor !== null) {
                    ['p'=>$pinned, 'd'=>$date, 'i'=>$id] = $cursor;
                    $where .= ' AND (t.is_pinned<? OR (t.is_pinned=? AND (t.last_post_at<? OR (t.last_post_at=? AND t.id<?))))';
                    array_push($args, $pinned, $pinned, $date, $date, $id);
                }
                $join = $categoryId === null ? ' JOIN categories c ON c.id=t.category_id' : '';
                $rows = \h('SELECT t.id,t.is_pinned,t.last_post_at FROM topics t'.$join.' WHERE '.$where.' ORDER BY t.is_pinned DESC,t.last_post_at DESC,t.id DESC LIMIT '.($pageSize + 1), $args)->fetchAll();
                if (count($rows) <= $pageSize) return [''];
                $cursor = $rows[$pageSize - 1];
            }
            return [FeedCursor::fromTopic($cursor, $page)];
        });
        return (string)($cached[0] ?? '');
    }

    public static function topicAfter(int $topicId, int $page, int $pageSize): int
    {
        if ($page < 2) return 0;
        $version = \cache_version('topic:'.$topicId);
        $cached = \cached('numeric-topic-page:v1:'.$topicId.':'.$pageSize.':'.$page.':'.$version, 300, static function () use ($topicId, $page, $pageSize): array {
            $after = 0;
            for ($number = 2; $number <= $page; $number++) {
                $rows = \h('SELECT id FROM posts WHERE topic_id=? AND status="normal" AND id>? ORDER BY id LIMIT '.($pageSize + 1), [$topicId, $after])->fetchAll();
                if (count($rows) <= $pageSize) return [0];
                $after = (int)$rows[$pageSize - 1]['id'];
            }
            return [$after];
        });
        return (int)($cached[0] ?? 0);
    }
}
