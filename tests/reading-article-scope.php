<?php
/** Exercise the actual reading hooks with article/page query and loop boundaries. */
define('ABSPATH', __DIR__);
$hooks = $localized = $enqueued = [];
$enabled = $main = $loop = true;
$singular = 'post';
$post = $queried = (object) [
    'ID' => 1,
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_password' => '',
];
function add_action($name, $callback, ...$args)
{
    $GLOBALS['hooks'][$name][] = $callback;
}
function add_filter(...$args)
{
    add_action(...$args);
}
function add_shortcode($name, $callback)
{
    $GLOBALS['shortcodes'][$name] = $callback;
}
function get_option($key, $default = false)
{
    return $key === 'pagenest_companion_reading' ? ($GLOBALS['enabled'] ? '1' : '0') : $default;
}
function is_multisite()
{
    return false;
}
function get_current_user_id()
{
    return 0;
}
function is_user_logged_in()
{
    return false;
}
function is_singular($types = [])
{
    return $GLOBALS['singular'] &&
        (!$types || in_array($GLOBALS['singular'], (array) $types, true));
}
function in_the_loop()
{
    return $GLOBALS['loop'];
}
function is_main_query()
{
    return $GLOBALS['main'];
}
function get_post()
{
    return $GLOBALS['post'];
}
function get_queried_object()
{
    return $GLOBALS['queried'];
}
function pagenest_discovery_public_post($post)
{
    return $post &&
        $post->post_status === 'publish' &&
        $post->post_password === '' &&
        in_array($post->post_type, ['post', 'page'], true);
}
function pagenest_discovery_sections($post)
{
    return [['id' => 'stable']];
}
function pagenest_companion_config(...$args)
{
    return 'pagenest/v1';
}
function __($text, $domain)
{
    return $text;
}
function esc_html__($text, $domain)
{
    return htmlspecialchars($text, ENT_QUOTES);
}
function plugins_url($path, $base)
{
    return '/' . $path;
}
function rest_url($path)
{
    return '/' . $path;
}
function get_permalink()
{
    return '/article';
}
function wp_login_url($url)
{
    return '/login';
}
function current_theme_supports($feature)
{
    return true;
}
function wp_enqueue_script($handle, ...$args)
{
    $GLOBALS['enqueued'][] = $handle;
}
function wp_enqueue_style(...$args) {}
function wp_localize_script($handle, $name, $data)
{
    $GLOBALS['localized'] = $data;
}
require dirname(__DIR__) . '/modules/reading/bootstrap.php';
function reading_output()
{
    global $hooks;
    $html = '<h2 id="stable">公开内容</h2><p>合成阅读内容。</p>';
    foreach ($hooks['the_content'] as $callback) {
        $html = $callback($html);
    }
    foreach ($hooks['wp_enqueue_scripts'] as $callback) {
        $callback();
    }
    ob_start();
    pagenest_reading_header_entry();
    foreach ($hooks['wp_footer'] as $callback) {
        $callback();
    }
    return ['html' => $html, 'global' => ob_get_clean(), 'config' => $GLOBALS['localized']];
}
if (in_array('--fixture', $argv, true)) {
    if (in_array('page', $argv, true)) {
        $singular = $post->post_type = 'page';
        $post->ID = 2;
    }
    echo json_encode(reading_output(), JSON_UNESCAPED_UNICODE);
    exit();
}
$checks = 0;
function check_scope($name, $condition)
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
    $GLOBALS['checks']++;
}
$out = reading_output();
check_scope(
    'public article keeps button and current',
    str_contains($out['html'], 'data-pn-save-later') && $out['config']['current']['id'] === 1,
);
foreach (['page', 'attachment', 'custom', ''] as $type) {
    $singular = $post->post_type = $type;
    $out = reading_output();
    check_scope(
        "$type does not collect reading",
        !str_contains($out['html'], 'data-pn-save-later') && $out['config']['current'] === null,
    );
    check_scope(
        "$type retains global reading and shortcode",
        str_contains($out['global'], 'data-pn-open-reading') &&
            str_contains($shortcodes['pagenest_my_reading'](), 'data-pn-reading'),
    );
}
$singular = 'post';
$post->post_type = 'page';
$out = reading_output();
check_scope(
    'actual page type rejected despite article query flag',
    !str_contains($out['html'], 'data-pn-save-later') && $out['config']['current'] === null,
);
$post->post_type = 'post';
foreach (['private', 'draft'] as $status) {
    $post->post_status = $status;
    $out = reading_output();
    check_scope(
        "$status article excluded",
        !str_contains($out['html'], 'data-pn-save-later') && $out['config']['current'] === null,
    );
}
$post->post_status = 'publish';
$post->post_password = 'protected';
$out = reading_output();
check_scope(
    'password article excluded',
    !str_contains($out['html'], 'data-pn-save-later') && $out['config']['current'] === null,
);
$post->post_password = '';
foreach (['main', 'loop'] as $boundary) {
    $GLOBALS[$boundary] = false;
    $out = reading_output();
    check_scope(
        "outside $boundary content unchanged",
        !str_contains($out['html'], 'data-pn-save-later'),
    );
    $GLOBALS[$boundary] = true;
}
$queried = clone $post;
$queried->post_type = 'page';
$out = reading_output();
check_scope('queried page never becomes current article', $out['config']['current'] === null);
$post = $queried = null;
$out = reading_output();
check_scope(
    'missing article object is excluded',
    !str_contains($out['html'], 'data-pn-save-later') && $out['config']['current'] === null,
);
$enabled = false;
$localized = $enqueued = [];
$out = reading_output();
check_scope(
    'disabled module adds no controls or script',
    !str_contains($out['html'], 'data-pn-save-later') &&
        !$out['global'] &&
        !$localized &&
        !$enqueued,
);
echo "$checks article reading scope checks passed\n";
