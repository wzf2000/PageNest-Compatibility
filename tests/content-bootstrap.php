<?php
/** Separate process tests for optional startup and single-owner handling. */
define('ABSPATH', __DIR__);
$mode = $argv[1] ?? 'disabled';
$hooks = [];
function add_action($name, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['hooks'][$name][$priority][] = $callback;
}
function add_filter(...$args)
{
    add_action(...$args);
}
function esc_html($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function did_action($hook)
{
    return $GLOBALS['mode'] === 'late';
}
if ($mode !== 'disabled') {
    $file = tempnam(sys_get_temp_dir(), 'pagenest-bootstrap-');
    copy(__DIR__ . '/content-example.json', $file);
    define('PAGENEST_COMPANION_CONTENT_FILE', $file);
}
if ($mode === 'legacy') {
    function site_extensions_config($key, $default = null)
    {
        return $default;
    }
}
if ($mode === 'legacy-class') {
    eval('namespace SiteExtensions; final class Profile {}');
}
require dirname(__DIR__) . '/modules/content/bootstrap.php';
if ($mode !== 'late' && isset($hooks['the_content'])) {
    throw new RuntimeException('Content registered before all plugins loaded');
}
foreach ($hooks['plugins_loaded'][20] ?? [] as $callback) {
    $callback();
}
if (in_array($mode, ['disabled', 'legacy', 'legacy-class'], true)) {
    if (
        isset($hooks['the_content'], $hooks['wp_enqueue_scripts']) ||
        pagenest_companion_content_config('modules') !== []
    ) {
        throw new RuntimeException('Disabled owner registered content or kept enabled modules');
    }
} elseif (!isset($hooks['the_content'][20], $hooks['the_content'][30])) {
    throw new RuntimeException('Content owner missing hooks');
}
$count = count($hooks['the_content'][20] ?? []);
pagenest_companion_content_bootstrap();
if ($count !== count($hooks['the_content'][20] ?? [])) {
    throw new RuntimeException('Bootstrap registered duplicate hooks');
}
if (in_array($mode, ['legacy', 'legacy-class'], true)) {
    ob_start();
    foreach ($hooks['admin_notices'][10] ?? [] as $callback) {
        $callback();
    }
    $notice = ob_get_clean();
    if (!str_contains($notice, 'another content display owner')) {
        throw new RuntimeException('Missing sanitized owner notice');
    }
}
if (isset($file)) {
    unlink($file);
}
echo "Content bootstrap $mode passed\n";
