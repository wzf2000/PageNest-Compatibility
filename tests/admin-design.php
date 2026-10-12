<?php
/** Prevent presentation assets from leaking into unrelated screens or roles. */
define('ABSPATH', __DIR__);
$hooks = $enqueued = [];
$authorized = true;
function add_action($name, $callback)
{
    $GLOBALS['hooks'][$name][] = $callback;
}
function current_user_can($capability)
{
    return $capability === 'manage_options' && $GLOBALS['authorized'];
}
function plugins_url($path, $file)
{
    return $path;
}
function wp_enqueue_style($handle, $source, ...$args)
{
    $GLOBALS['enqueued'][] = $source;
}
function wp_enqueue_script($handle, $source, ...$args)
{
    $GLOBALS['enqueued'][] = $source;
}
require dirname(__DIR__) . '/admin-design.php';
foreach (
    [
        'index.php',
        'upload.php',
        'settings_page_other',
        'settings_page_pagenest-companion',
        'settings_page_pagenest-discovery',
        'settings_page_pagenest-media',
        'settings_page_pagenest-social',
    ]
    as $screen
) {
    foreach ([true, false] as $authorized) {
        $enqueued = [];
        foreach ($hooks['admin_enqueue_scripts'] as $callback) {
            $callback($screen);
        }
        $expected = $authorized && str_starts_with($screen, 'settings_page_pagenest-') ? 2 : 0;
        if (count($enqueued) !== $expected) {
            throw new RuntimeException('Unexpected screen asset scope: ' . $screen);
        }
        foreach ($enqueued as $source) {
            if (!is_file(dirname(__DIR__) . '/' . $source)) {
                throw new RuntimeException('Missing content-hashed asset');
            }
        }
    }
}
echo "Admin role/screen isolation and generated assets passed.\n";
