<?php
declare(strict_types=1);

namespace LiteBBS\Application;

final class FeedCursor
{
    /** @return array{p: int, d: string, i: int} */
    public static function decode(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 256) {
            throw new \InvalidArgumentException('Invalid feed cursor.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;
        if (!is_array($data) || !in_array($data['p'] ?? null, [0, 1], true)
            || !is_string($data['d'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $data['d'])
            || !is_int($data['i'] ?? null) || $data['i'] < 1) {
            throw new \InvalidArgumentException('Invalid feed cursor.');
        }

        return ['p' => $data['p'], 'd' => $data['d'], 'i' => $data['i']];
    }

    public static function fromTopic(array $topic): string
    {
        $data = json_encode([
            'p' => (int)$topic['is_pinned'],
            'd' => (string)$topic['last_post_at'],
            'i' => (int)$topic['id'],
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
