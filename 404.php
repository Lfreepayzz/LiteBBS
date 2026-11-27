<?php
declare(strict_types=1);

final class LiteBBSNotFound
{
    public const PAGE = 'page';
    public const ATTACHMENT = 'attachment';

    public static function message(string $kind): string
    {
        return match ($kind) {
            self::ATTACHMENT => '附件不存在或已删除',
            default => '页面不存在',
        };
    }
}

/** Central 404 response factory used by all application routes. */
function litebbs_not_found_response(string $kind = LiteBBSNotFound::PAGE): \LiteBBS\Framework\Response
{
    return litebbs_error_response(404, LiteBBSNotFound::message($kind));
}

/** Central HTTP error response factory shared by the entire application. */
function litebbs_error_response(int $status, string $message): \LiteBBS\Framework\Response
{
    if ($status < 400 || $status > 599) throw new InvalidArgumentException('Invalid error response status.');
    $level = ob_get_level();
    ob_start();
    try {
        page($status === 404 ? '页面不存在' : '请求失败', '<section class="message"><h1>'.e($message).'</h1><a href="/">返回首页</a></section>');
        return new \LiteBBS\Framework\Response((string)ob_get_clean(), $status);
    } finally {
        while (ob_get_level() > $level) ob_end_clean();
    }
}

function litebbs_not_found(string $kind = LiteBBSNotFound::PAGE): never
{
    throw new \LiteBBS\Framework\ResponseHalt(litebbs_not_found_response($kind));
}
