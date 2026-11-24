<?php
declare(strict_types=1);

namespace LiteBBS\Application;

final class TopicCursor
{
    public function __construct(private string $secret)
    {
    }

    public function signature(int $topicId, int $afterId, int $floor, int $revision, int $replyCount): string
    {
        if ($this->secret === '') return '';

        return hash_hmac('sha256', "topic-floor:v1:$topicId:$afterId:$floor:$revision:$replyCount", $this->secret);
    }

    public function accepts(mixed $signature, int $topicId, int $afterId, int $floor, int $revision, int $replyCount): bool
    {
        return $this->secret !== '' && $floor > 0 && is_string($signature) && strlen($signature) === 64
            && hash_equals($this->signature($topicId, $afterId, $floor, $revision, $replyCount), $signature);
    }
}
