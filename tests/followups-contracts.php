<?php
/** Public hierarchy and image decisions without administrator visibility shortcuts. */
define('ABSPATH', __DIR__);
$hooks = $posts = $terms = $images = $thumbnail = [];
$kind = 'page';
$object = null;
function add_action($key, $fn, ...$args)
{
    $GLOBALS['hooks'][$key][] = $fn;
}
function __($text, ...$args)
{
    return $text;
}
function esc_html__($text, ...$args)
{
    return $text;
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function get_term($id, ...$args)
{
    return $GLOBALS['terms'][$id] ?? null;
}
function get_the_terms(...$args)
{
    return array_values($GLOBALS['terms']);
}
function get_queried_object()
{
    return $GLOBALS['object'];
}
function is_singular($kind)
{
    return $GLOBALS['kind'] === $kind;
}
function is_category()
{
    return $GLOBALS['kind'] === 'category';
}
function is_wp_error($value)
{
    return false;
}
function is_term_publicly_viewable($term)
{
    return $term->public;
}
function pagenest_discovery_public_post($post)
{
    return $post->post_status === 'publish' && $post->post_password === '';
}
function pagenest_discovery_text($text, ...$args)
{
    return $text;
}
function get_permalink($post)
{
    return '/page/' . $post->ID;
}
function get_term_link($term)
{
    return '/category/' . $term->term_id;
}
function pagenest_discovery_options()
{
    return ['image' => 8, 'description' => '', 'search' => false, 'seo' => false];
}
function get_bloginfo($key)
{
    return $key === 'version' ? '7.1.3' : '';
}
function get_theme_mod(...$args)
{
    return 9;
}
function get_post_thumbnail_id($post)
{
    return $GLOBALS['thumbnail'][$post->ID] ?? 0;
}
function wp_attachment_is_image($id)
{
    return isset($GLOBALS['images'][$id]);
}
function wp_get_attachment_image_url($id, $size)
{
    return $GLOBALS['images'][$id] ?? false;
}
function current_user_can(...$args)
{
    return $GLOBALS['authorized'] ?? true;
}
function pagenest_companion_media_readable($id)
{
    return true;
}
function wp_die(...$args)
{
    throw new RuntimeException('forbidden');
}
function check_admin_referer(...$args)
{
    throw new RuntimeException('nonce-required');
}
function get_option(...$args)
{
    return ['secret' => '/private/account/password'];
}
function get_file_data(...$args)
{
    return ['version' => '1.6.0'];
}
function pagenest_companion_feature($key)
{
    return false;
}
function pagenest_seo_external_owner_stub()
{
    return false;
}
function apply_filters($key, $value)
{
    return $value;
}
function pagenest_reading_enabled()
{
    return false;
}
function pagenest_guide_options()
{
    return ['enabled' => false];
}
function pagenest_companion_media_config()
{
    return ['enabled' => false];
}
function pagenest_media_authoring_enabled()
{
    return false;
}
function pagenest_companion_content_config(...$args)
{
    return [];
}
require dirname(__DIR__) . '/modules/discovery/editor.php';
require dirname(__DIR__) . '/modules/discovery/seo.php';
require dirname(__DIR__) . '/modules/diagnostics.php';
function check($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$make = static fn($id, $parent = 0, $status = 'publish', $password = '') => (object) [
    'ID' => $id,
    'post_parent' => $parent,
    'post_type' => 'page',
    'post_status' => $status,
    'post_password' => $password,
    'post_title' => 'Page ' . $id,
    'post_excerpt' => '',
];
$posts[1] = $make(1);
$posts[2] = $make(2, 1);
$object = $make(3, 2);
check(
    array_column(pagenest_seo_breadcrumb_parents(), 'item') === ['/page/1', '/page/2'],
    'Actual ordered page ancestry',
);
$posts[1]->post_password = 'private';
check(
    pagenest_seo_breadcrumb_parents() === [],
    'Protected ancestor omitted even for administrator',
);
$posts[1]->post_password = '';
$posts[1]->post_parent = 2;
check(
    pagenest_seo_breadcrumb_parents() === [],
    'Page cycle terminates without partial fake ancestry',
);
$kind = 'post';
$term = static fn($id, $parent = 0, $public = true) => (object) [
    'term_id' => $id,
    'parent' => $parent,
    'public' => $public,
    'taxonomy' => 'category',
    'name' => 'Term ' . $id,
];
$terms = [20 => $term(20), 10 => $term(10), 21 => $term(21, 20), 11 => $term(11, 10)];
check(
    array_column(pagenest_seo_breadcrumb_parents(), 'item') === ['/category/10', '/category/11'],
    'Deepest branch, deterministic ID tie',
);
$terms[10]->public = false;
check(
    array_column(pagenest_seo_breadcrumb_parents(), 'item') === ['/category/20', '/category/21'],
    'Nonpublic category branch excluded',
);
$terms[20]->parent = 21;
check(pagenest_seo_breadcrumb_parents() === [], 'Category cycle terminates');
for ($i = 1; $i <= 32; $i++) {
    $terms[$i] = $term($i, $i + 1);
}
check(pagenest_seo_category_branch($terms[1]) === [], 'Category traversal bounded');
foreach ([7, 8, 9] as $id) {
    $posts[$id] = $make($id);
    $posts[$id]->post_type = 'attachment';
    $posts[$id]->post_status = 'inherit';
    $images[$id] = '/image/' . $id;
}
$posts[7]->post_password = 'private';
check(pagenest_seo_image($object, 7) === 8, 'Private override inherits public site image');
$images[8] = false;
check(pagenest_seo_image($object, 7) === 9, 'Unresolvable site image inherits logo');
unset($images[9]);
check(pagenest_seo_preview_image($object, 7) === '', 'No public fallback produces missing image');
$images[7] = '/image/7';
$preview = pagenest_seo_editor_preview($object, 7);
check(
    $preview['image'] === '/image/7' && !$preview['public_image'] && $preview['private_preview'],
    'Readable draft preview remains available with truthful public status',
);
$images[8] = '/image/8';
$preview = pagenest_seo_editor_preview($object, 7);
check(
    $preview['public_image'] && $preview['inherited'] && $preview['private_preview'],
    'Private editor image retains public site fallback state',
);
$authorized = false;
try {
    $hooks['admin_post_pagenest_diagnostics_export'][0]();
    throw new RuntimeException('unguarded export');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'forbidden', 'Export requires administrator');
}
$authorized = true;
try {
    $hooks['admin_post_pagenest_diagnostics_export'][0]();
    throw new RuntimeException('unguarded export');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'nonce-required', 'Export requires nonce before output');
}
$export = pagenest_companion_diagnostics_export();
check(
    count($export['modules']) >= 10 &&
        !str_contains(json_encode($export), '/private/account/password'),
    'Export whitelist does not dump stored configuration',
);
check(
    array_keys($export) === ['schema_version', 'companion_version', 'wordpress_version', 'modules'],
    'Export has fixed keys',
);
echo "Hierarchy, privacy, bounded traversal, image fallback and safe export contracts passed\n";
