<?php
declare(strict_types=1);

/** Administrative extension-share package and permanent draft service. */
final class ExtensionShareService
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{48}$/D';
    private const FORUM_ORIGIN = 'https://bolgk.eu.org';

    public static function forumOrigin(): string
    {
        return self::FORUM_ORIGIN;
    }

    public static function create(
        string $kind,
        string $name
    ): array {
        if (!in_array($kind, ['plugin', 'theme'], true) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $name)) fail(400, '扩展信息无效');
        if (!class_exists('ZipArchive')) fail(503, '服务器未启用 ZIP 打包支持');
        $forumOrigin = self::forumOrigin();
        $directory = dirname(__DIR__).'/'.($kind === 'plugin' ? 'plugins' : 'templates').'/'.$name;
        extension_validate_directory($directory, $kind, $name);
        $manifest = $kind === 'plugin' ? extension_plugin_manifest($name) : theme_runtime_package($name)['manifest'] ?? null;
        if (!is_array($manifest)) fail(404, '扩展清单不可用');
        $storage = dirname(__DIR__).'/share-packages';
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) fail(500, '分享包目录不可写');
        if (!is_writable($storage)) fail(500, '分享包目录不可写');
        $token = bin2hex(random_bytes(24));
        $file = $storage.'/'.$token.'.zip';
        $archive = new ZipArchive();
        if ($archive->open($file, ZipArchive::CREATE | ZipArchive::EXCL) !== true) fail(500, '无法创建分享包');
        try {
            $root = realpath($directory);
            if ($root === false) throw new RuntimeException('扩展目录不可用');
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            $count = 0;
            foreach ($entries as $entry) {
                if (++$count > 300 || !$entry->isFile() || $entry->isLink()) throw new RuntimeException('扩展文件不符合打包要求');
                $path = $entry->getPathname();
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                if (extension_secure_runtime_file($root, $relative) !== $path || !$archive->addFile($path, $name.'/'.$relative)) throw new RuntimeException('扩展文件无法加入分享包');
            }
            if ($count === 0) throw new RuntimeException('扩展没有可打包的文件');
        } catch (Throwable $error) {
            $archive->close();
            @unlink($file);
            throw $error;
        }
        $archive->close();
        $siteOrigin = rtrim((string)(load_config()['url'] ?? install_request_origin() ?? ''), '/');
        if (request_origin_parts($siteOrigin) === null) { @unlink($file); fail(500, '无法确定当前站点下载地址'); }
        $label = $kind === 'theme' ? '模板' : '插件';
        $title = '【'.$label.'分享】'.trim((string)($manifest['title'] ?? $name));
        $body = '## '.trim((string)($manifest['title'] ?? $name))."\n\n".trim((string)($manifest['description'] ?? '暂无介绍。'))."\n\n---\n\n- **类型**：{$label}\n- **版本**：`".(string)($manifest['version'] ?? '未知')."`\n- **下载**：[点击下载{$label} ZIP 包]({$siteOrigin}/extension-download/{$token})";
        return ['forum_url'=>$forumOrigin.'/new?share_source='.rawurlencode($siteOrigin).'&share_token='.self::storeDraft($title, $body)];
    }

    public static function draftResponse(string $token): \LiteBBS\Framework\Response
    {
        if (!preg_match(self::TOKEN_PATTERN, $token) || !same_request_origin((string)($_SERVER['HTTP_ORIGIN'] ?? ''), self::forumOrigin())) fail(403, '无权读取分享草稿');
        $file = dirname(__DIR__).'/cache/share-drafts/'.$token.'.json';
        if (!is_file($file)) fail(404, '分享草稿不存在');
        $payload = file_get_contents($file);
        if (!is_string($payload)) fail(404, '分享草稿不存在');
        return (new \LiteBBS\Framework\Response($payload))->header('Content-Type', 'application/json; charset=utf-8')->header('Access-Control-Allow-Origin', self::forumOrigin())->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public static function downloadResponse(string $token): \LiteBBS\Framework\Response
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) fail(404, '分享包不存在');
        $file = dirname(__DIR__).'/share-packages/'.$token.'.zip';
        if (!is_file($file)) fail(404, '分享包不存在');
        return (new \LiteBBS\Framework\Response())->header('Content-Type', 'application/zip')->header('Content-Disposition', 'attachment; filename="extension-'.$token.'.zip"')->header('Content-Length', (string)filesize($file))->header('Cache-Control', 'public, max-age=86400')->header('X-Content-Type-Options', 'nosniff')->stream(static function () use ($file): void { readfile($file); });
    }

    private static function storeDraft(string $title, string $body): string
    {
        $directory = dirname(__DIR__).'/cache/share-drafts';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) fail(500, '分享草稿目录不可写');
        if (!is_writable($directory)) fail(500, '分享草稿目录不可写');
        $token = bin2hex(random_bytes(24));
        $payload = json_encode(['title'=>$title, 'body'=>$body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($directory.'/'.$token.'.json', $payload, LOCK_EX) === false) fail(500, '无法保存分享草稿');
        return $token;
    }
}

function handle_extension_share_create(\LiteBBS\Framework\Request $request): void
{
    if ($request->path !== '/admin/extension-share' || $request->method !== 'POST') return;
    admin();
    require_post();
    try {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(ExtensionShareService::create((string)($request->post['kind'] ?? ''), (string)($request->post['name'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\LiteBBS\Framework\ResponseHalt $halt) {
        throw $halt;
    } catch (Throwable $error) {
        error_log('extension share package failed: '.$error->getMessage());
        fail(500, '分享包创建失败');
    }
}
