<?php
/** Exact identities, public ancestry and complete optimistic settings updates. */
define('ABSPATH', __DIR__);
$options = $meta = $posts = $errors = [];
$allowed = true;
function add_action(...$args) {}
function add_filter(...$args) {}
function get_queried_object()
{
    return (object) ['ID' => 10];
}
function is_singular($type)
{
    return $type === 'post';
}
function is_post_publicly_viewable($post)
{
    return $GLOBALS['publicly_viewable'] ?? true;
}
function is_category()
{
    return false;
}
function get_the_terms(...$args)
{
    return [];
}
function pagenest_discovery_text($text, ...$args)
{
    return strip_tags($text);
}
function get_option($key, $default = false)
{
    return $GLOBALS['options'][$key] ?? $default;
}
function get_post_meta($id, $key, $single = true)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function get_permalink($post)
{
    return 'https://fixture.example/' . $post->ID;
}
function current_user_can(...$args)
{
    return $GLOBALS['allowed'];
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'valid';
}
function add_settings_error(...$args)
{
    $GLOBALS['errors'][] = $args;
}
function pagenest_companion_feature($feature)
{
    return $GLOBALS['enabled'] ?? true;
}
function pagenest_companion_post_setting($id, $key)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/modules/chapters.php';
require dirname(__DIR__) . '/modules/discovery/seo.php';
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_defaults();
$posts[1] = (object) [
    'ID' => 1,
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_password' => '',
    'post_parent' => 0,
    'post_title' => 'Public root',
];
$posts[2] = clone $posts[1];
$posts[2]->ID = 2;
$posts[2]->post_parent = 1;
$meta[10]['series'] = ' Exact 系列 ';
$options['pagenest_companion_series_directories'] = [' Exact 系列 ' => 2];
check(pagenest_companion_series_directory(10)['page_id'] === 2, 'Exact identity resolves');
check(
    array_column(pagenest_companion_series_directory(10)['parents'], 'id') === [1, 2],
    'Complete public ancestry',
);
$meta[10]['series'] = 'Exact 系列';
check(pagenest_companion_series_directory(10) === [], 'Whitespace is identity, never guessed');
$meta[10]['series'] = ' Exact 系列 ';
foreach (['private', 'draft', 'future'] as $status) {
    $posts[1]->post_status = $status;
    check(
        pagenest_seo_breadcrumb_parents() === [],
        'SEO never reveals partial inaccessible branch',
    );
    check(
        pagenest_companion_series_directory(10) === [],
        'Non-public ancestor hidden from administrators',
    );
}
$posts[1]->post_status = 'publish';
$posts[2]->post_password = 'secret';
check(
    pagenest_companion_series_directory(10) === [],
    'Password page hidden without cookie shortcuts',
);
$posts[2]->post_password = '';
$posts[1]->post_parent = 2;
check(pagenest_companion_series_directory(10) === [], 'Cycle rejects whole branch');
$posts[1]->post_parent = 99;
check(pagenest_companion_series_directory(10) === [], 'Missing ancestor rejects whole branch');
$posts[1]->post_parent = 0;
$publicly_viewable = false;
check(pagenest_companion_series_directory(10) === [], 'WordPress public visibility is required');
$publicly_viewable = true;
$GLOBALS['pagenest_companion_profile']['chapters']['series_directories'] = [' Exact 系列 ' => 1];
check(pagenest_companion_series_directory(10)['page_id'] === 1, 'Server relationship wins');
check(pagenest_companion_series_directory(1) === [], 'No directory inference from page metadata');
$enabled = false;
check(pagenest_companion_series_directory(10) === [], 'Feature off hides relationship');
$enabled = true;
foreach (
    [
        ['A' => 0],
        ['A' => '2'],
        ['' => 2],
        [str_repeat('a', 101) => 2],
        array_fill_keys(range(1, 201), 2),
    ]
    as $map
) {
    try {
        pagenest_companion_validate([
            'schema_version' => 1,
            'chapters' => ['series_directories' => $map],
        ]);
        throw new RuntimeException('Accepted invalid map');
    } catch (InvalidArgumentException $e) {
    }
}
$meta[11] = ['series' => '', '_pagenest_document_id' => 'D11'];
$GLOBALS['pagenest_companion_profile']['chapters']['document_series'] = ['D11' => ' Exact 系列 '];
check(
    pagenest_companion_series_directory(11)['page_id'] === 1,
    'Explicit legacy document binding resolves',
);
$meta[11]['series'] = 'Different';
check(pagenest_companion_series_directory(11) === [], 'Article series overrides document binding');
$meta[11]['series'] = '';
$meta[11]['_pagenest_document_id'] = 'D12';
check(pagenest_companion_series_directory(11) === [], 'Unbound document never guessed');
unset($meta[11]);
$old = $options['pagenest_companion_series_directories'];
$form = [
    'revision' => pagenest_companion_series_directory_revision(),
    'nonce' => 'valid',
    'rows' => [['series' => 'New series', 'page' => '2'], ['series' => '', 'page' => '']],
];
$denied = $form;
$denied['nonce'] = 'bad';
check(pagenest_companion_series_directory_sanitize($denied) === $old, 'Nonce required');
$allowed = false;
check(pagenest_companion_series_directory_sanitize($form) === $old, 'Administrator required');
$allowed = true;
$stale = $form;
$stale['revision'] = str_repeat('0', 64);
check(
    pagenest_companion_series_directory_sanitize($stale) === $old,
    'Stale form preserves all mappings',
);
$bad = $form;
$bad['rows'][0]['page'] = '99';
check(
    pagenest_companion_series_directory_sanitize($bad) === $old,
    'Invalid page rejects complete save',
);
$good = pagenest_companion_series_directory_sanitize($form);
check(
    $good === ['New series' => 2, ' Exact 系列 ' => 2],
    'Shadowed admin values retained during server ownership',
);
check(
    pagenest_companion_series_directory_sanitize($good) === $good,
    'First option add double sanitize safe',
);
$options['pagenest_companion_series_directories'] = ['Existing' => 2];
$form['revision'] = pagenest_companion_series_directory_revision();
check(
    pagenest_companion_series_directory_sanitize($form) === ['Existing' => 2],
    'Omitted existing row rejects complete save',
);
check($meta === [10 => ['series' => ' Exact 系列 ']], 'No metadata or identity writes');
echo "Series relationship, privacy, identity and settings contracts passed\n";
