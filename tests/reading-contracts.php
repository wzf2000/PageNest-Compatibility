<?php
/** Database candidate filters are deliberately weaker than decoded authorization. */
define('ABSPATH', __DIR__);
$hooks = $routes = $posts = $meta = $options = $queries = [];
$user = 1;
class WP_Error
{
    public function __construct(public $code, public $message, public $data = []) {}
}
class WP_REST_Response
{
    public $headers = [];
    public function __construct(public $data) {}
    public function header($key, $value)
    {
        $this->headers[$key] = $value;
    }
}
function __($text, $domain)
{
    return $text;
}
function esc_html__($text, $domain)
{
    return $text;
}
function esc_attr__($text, $domain)
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars($text, ENT_QUOTES);
}
function esc_attr($text)
{
    return esc_html($text);
}
function esc_url($text)
{
    return $text;
}
function add_action($name, $callback, ...$args)
{
    $GLOBALS['hooks'][$name][] = $callback;
}
function add_filter(...$args)
{
    add_action(...$args);
}
function add_shortcode(...$args) {}
function get_option($key, $default = false)
{
    return $GLOBALS['options'][$key] ?? $default;
}
function get_current_user_id()
{
    return $GLOBALS['user'];
}
function is_user_logged_in()
{
    return get_current_user_id() > 0;
}
function current_user_can($cap, $id = 0)
{
    return $cap === 'manage_options' || in_array($id, [1], true);
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function get_post_meta($id, $key, $single = true)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function metadata_exists($type, $id, $key)
{
    return isset($GLOBALS['meta'][$id][$key]);
}
function post_password_required($post)
{
    return $post->post_password !== '';
}
function absint($value)
{
    return abs((int) $value);
}
function sanitize_text_field($text)
{
    return strip_tags($text);
}
function wp_strip_all_tags($text, $breaks = false)
{
    return strip_tags($text);
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}
function get_userdata($id)
{
    return (object) ['ID' => $id, 'display_name' => 'Reader'];
}
function get_permalink($post)
{
    return 'http://127.0.0.1/article/' . (is_object($post) ? $post->ID : $post);
}
function register_rest_route($ns, $path, $args)
{
    $GLOBALS['routes'][$path] = $args;
}
function pagenest_companion_feature($name)
{
    return true;
}
function pagenest_companion_post_setting($id, $field)
{
    return get_post_meta($id, '_pagenest_' . $field, true);
}
function pagenest_pc_avatar_url_fixture()
{
    return '';
}
function candidate($query, $id)
{
    if (!$query) {
        return true;
    }
    if (isset($query['key'])) {
        $value = get_post_meta($id, $query['key']);
        return ($query['compare'] ?? '') === 'LIKE'
            ? str_contains(serialize($value), $query['value'])
            : $value === $query['value'];
    }
    $matches = [];
    foreach ($query as $key => $part) {
        if (is_array($part)) {
            $matches[] = candidate($part, $id);
        }
    }
    return ($query['relation'] ?? 'AND') === 'OR'
        ? in_array(true, $matches, true)
        : !in_array(false, $matches, true);
}
function get_posts($args)
{
    $GLOBALS['queries'][] = $args;
    $out = array_filter(
        $GLOBALS['posts'],
        static fn($post) => $post->post_type === $args['post_type'] &&
            (!isset($args['author']) || $post->post_author === $args['author']) &&
            (empty($args['pagenest_notes_before']) || $post->ID < $args['pagenest_notes_before']) &&
            candidate($args['meta_query'] ?? [], $post->ID),
    );
    usort($out, static fn($a, $b) => $b->ID <=> $a->ID);
    return ($args['posts_per_page'] ?? -1) < 0
        ? $out
        : array_slice($out, 0, $args['posts_per_page']);
}
require dirname(__DIR__) . '/config.php';
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_defaults();
require dirname(__DIR__) . '/modules/paragraph-comments.php';
require dirname(__DIR__) . '/modules/discovery/bootstrap.php';
require dirname(__DIR__) . '/modules/reading/bootstrap.php';
require dirname(__DIR__) . '/modules/content/editor.php';
$checks = 0;
function check($name, $ok)
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $checks++;
}
foreach ([1 => 'publish', 2 => 'private'] as $id => $status) {
    $posts[$id] = (object) [
        'ID' => $id,
        'post_status' => $status,
        'post_type' => 'post',
        'post_password' => '',
        'post_title' => 'Article',
        'post_content' => '<h2 id="stable">Search heading</h2><p>Saved content</p>',
        'post_content_filtered' => '# Search heading',
        'post_author' => 1,
    ];
    $meta[$id]['_pagenest_comments_enabled'] = '1';
    $meta[$id]['_pagenest_block_registry'] = [['id' => 'block', 'active' => true]];
}
for ($id = 100; $id < 205; $id++) {
    $posts[$id] = (object) [
        'ID' => $id,
        'post_type' => 'pagenest_note',
        'post_status' => 'private',
        'post_author' => $id % 7 === 0 ? 2 : 1,
    ];
    $meta[$id]['_pagenest_note_data'] = [
        'post_id' => $id % 11 === 0 ? 2 : ($id % 2 === 0 ? 1 : '1'),
        'block_id' => 'block',
        'quote' => 'reference',
        'text' => 'saved note',
        'visibility' => $id % 3 === 0 ? 'public' : 'author_only',
    ];
}
// These private candidates deliberately spoof database LIKE patterns in their text.
for ($id = 300; $id < 385; $id++) {
    $posts[$id] = (object) [
        'ID' => $id,
        'post_type' => 'pagenest_note',
        'post_status' => 'private',
        'post_author' => 1,
    ];
    $meta[$id]['_pagenest_note_data'] = [
        'post_id' => 2,
        'block_id' => 'block',
        'quote' => 'reference',
        'text' => 's:7:"post_id";i:1;s:10:"visibility";s:6:"public";',
        'visibility' => 'author_only',
    ];
}
$request = ['post' => 1, 'q' => '', 'kind' => 'all', 'cursor' => null];
$page = pagenest_pc_page($request, false);
check(
    'spoofed private metadata never projects',
    count($page['items']) > 0 &&
        !array_filter($page['items'], static fn($item) => $item['id'] >= 300),
);
check(
    'public last page has no private cursor',
    $page['next_cursor'] === null && !$page['has_more'],
);
check(
    'every public scan bounded',
    !array_filter($queries, static fn($args) => $args['posts_per_page'] > 80),
);
$own = pagenest_pc_page($request + [], true);
check(
    'own sparse empty page advances last scanned cursor',
    $own['items'] === [] && $own['has_more'] && $own['next_cursor'] === 345,
);
$next = pagenest_pc_page(array_replace($request, ['cursor' => $own['next_cursor']]), true);
check(
    'own next cursor continues despite invisible candidates',
    $next['next_cursor'] < $own['next_cursor'],
);
$all = pagenest_pc_page(array_replace($request, ['post' => 0]), true);
check(
    'cross article permissions rechecked',
    !array_filter($all['items'], static fn($item) => (int) $item['post_id'] !== 1),
);
$deny = pagenest_pc_page(array_replace($request, ['post' => 2]), false);
check('unreadable chapter rejected', is_wp_error($deny));
check(
    'paired saved heading remains usable',
    pagenest_discovery_sections($posts[1], 'Search')[0]['id'] === 'stable',
);
$copy = clone $posts[1];
$copy->post_content .= '<h3 id="stable">Duplicate</h3>';
check('duplicate heading safely falls back', pagenest_discovery_sections($copy) === []);
$copy->post_content =
    '<div data-mbb-generated><h2 id="generated">Search</h2></div><div hidden><h2 id="hidden">Hidden</h2></div>';
check('generated and hidden headings excluded', pagenest_discovery_sections($copy) === []);
check(
    'unknown shortcode arguments excluded',
    pagenest_discovery_text('[unknown secret="PRIVATE"]text[/unknown]') === '',
);
check(
    'new reading default disabled',
    !pagenest_reading_enabled() && pagenest_reading_markup() === '',
);
check('new guide default disabled', pagenest_guide_render() === '');
$guide = pagenest_guide_sanitize([
    'enabled' => '1',
    'groups' => [['title' => 'Links', 'items' => [1, 1, 2, 999]]],
]);
check('guide only accepts public sources once', $guide['groups'][0]['items'] === [1]);
check(
    'guide sanitizer is idempotent for first option creation',
    pagenest_guide_sanitize($guide) === $guide,
);
$options['pagenest_companion_guide'] = [
    'enabled' => true,
    'groups' => [['title' => 'Links', 'items' => [1, 2]]],
];
check(
    'guide never renders invalid source IDs',
    !str_contains(pagenest_guide_render(), '/article/2'),
);
$options['pagenest_companion_guide']['groups'][0]['items'][] = 999;
$guide = pagenest_guide_sanitize([
    'enabled' => '1',
    'groups' => [['title' => 'Links', 'items' => [999]]],
]);
check('existing invalid IDs remain manageable', $guide['groups'][0]['items'] === [999]);
// Exceed the public scan budget using private spoof candidates, never a private ID cursor.
for ($id = 400; $id < 820; $id++) {
    $posts[$id] = clone $posts[300];
    $posts[$id]->ID = $id;
    $meta[$id] = $meta[300];
}
$page = pagenest_pc_page($request, false);
check(
    'scan budget exhaustion exposes only a generic error',
    is_wp_error($page) && $page->code === 'query_limited',
);
echo "$checks reading/discovery contract checks passed\n";
