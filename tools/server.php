<?php

if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || ! preg_match('/^(?:127\.0\.0\.1|localhost):801[345]$/i', $_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"ok":false,"code":"forbidden","message":"Local isolated access only."}';

    return true;
}
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$public = realpath(__DIR__.'/../public');
$file = realpath($public.$uri);
if ($file && is_file($file) && str_starts_with(strtolower(str_replace('\\', '/', $file)), strtolower(str_replace('\\', '/', $public)).'/') && preg_match('/\.(?:css|js|svg|png|jpe?g|webp|gif|ico|woff2?|txt)$/i', $file) && ! preg_match('~(?:^|/)\.~', $uri)) {
    if (preg_match('/\.(css|js)$/i', $file)) {
        header('Cache-Control: no-cache');
    }

    return false;
}
require $public.'/index.php';
