<?php
/** Loopback-only router for an explicitly configured synthetic site. */
$site = realpath(getenv('COMPONENT_LAB_SITE') ?: '');
$host = getenv('COMPONENT_LAB_HTTP_HOST');
if (
    !$site ||
    !str_starts_with($site, '/tmp/') ||
    basename($site) !== 'site' ||
    ($_SERVER['HTTP_HOST'] ?? '') !== $host ||
    !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
) {
    http_response_code(403);
    exit();
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (
    str_contains($path, '..') ||
    preg_match('~(?:^|/)(?:\.|wp-config|readme|license)|\.(?:sql|log|jsonl|md)$~i', $path)
) {
    http_response_code(404);
    exit();
}
$file = realpath($site . '/' . ltrim($path, '/'));
if ($file && str_starts_with($file, $site . '/') && is_file($file)) {
    return false;
}
require $site . '/index.php';
