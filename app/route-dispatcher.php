<?php
declare(strict_types=1);

/**
 * Transitional route adapter. Every request still enters the Kernel; the
 * legacy modules are invoked behind one Router boundary while they are being
 * migrated to controllers. No web request may include a module directly.
 */
function dispatch_application_route(\LiteBBS\Framework\Request $request, \LiteBBS\Framework\Container $container, array $params = []): ?\LiteBBS\Framework\Response
{
    $path = $request->path;
    $method = $request->method;
    ob_start();
    try {
        if (plugin_route_dispatch(['method'=>$method, 'path'=>$path])) {
            return new \LiteBBS\Framework\Response((string)ob_get_clean(), http_response_code() ?: 200);
        }
        $legacyResponse = require __DIR__.'/legacy-dispatch.php';
        $body = (string)ob_get_clean();
        if ($legacyResponse instanceof \LiteBBS\Framework\Response) return $legacyResponse;
        return $body !== '' ? new \LiteBBS\Framework\Response($body, http_response_code() ?: 200) : null;
    } catch (\LiteBBS\Framework\ResponseHalt $halt) {
        if (ob_get_level() > 0) ob_end_clean();
        return $halt->response;
    } catch (Throwable $error) {
        if (ob_get_level() > 0) ob_end_clean();
        throw $error;
    }
}
