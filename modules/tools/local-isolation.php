<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
/** Exact synthetic-site ownership proof shared by optional services and CLI adapters. */
final class LocalIsolation
{
    public static function isLocal(): bool
    {
        if (!defined('SITE_TOOLS_SANDBOX_ROOT') || wp_get_environment_type() !== 'local') {
            return false;
        }
        $configured = SITE_TOOLS_SANDBOX_ROOT;
        if (
            !is_string($configured) ||
            !str_starts_with($configured, '/') ||
            str_contains($configured, '://')
        ) {
            return false;
        }
        $root = realpath($configured);
        $web = realpath(ABSPATH);
        if (
            !$root ||
            !$web ||
            $root !== rtrim($configured, '/') ||
            dirname($root) !== '/tmp' ||
            $web !== rtrim(ABSPATH, '/') ||
            !str_starts_with($web, $root . '/') ||
            str_starts_with($root, '/www/') ||
            str_starts_with($root, '/var/lib/')
        ) {
            return false;
        }
        foreach ([$root, $web, $root . '/ownership.json'] as $path) {
            $parts = explode('/', ltrim($path, '/'));
            $current = '';
            foreach ($parts as $part) {
                $current .= '/' . $part;
                if (is_link($current)) {
                    return false;
                }
            }
        }
        $marker = $root . '/ownership.json';
        if (!is_file($marker) || filesize($marker) > 4096) {
            return false;
        }
        $ownership = json_decode(file_get_contents($marker), true);
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return ($ownership['synthetic'] ?? false) === true &&
            ($ownership['owner'] ?? '') === 'direction-10-sandbox' &&
            is_string($host) &&
            (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) ||
                str_ends_with($host, '.test') ||
                str_ends_with($host, '.invalid'));
    }
    public static function local(string $confirmation): void
    {
        if (!defined('WP_CLI') || !WP_CLI || $confirmation !== 'local-only' || !self::isLocal()) {
            throw new \RuntimeException(
                'Requires CLI, local-only confirmation and marked isolated local WordPress',
            );
        }
    }
}
