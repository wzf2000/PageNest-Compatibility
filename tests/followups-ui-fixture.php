<?php
/** CLI-only synthetic UI renderer for the small cross-engine regression suite. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ABSPATH', __DIR__);
parse_str($argv[1] ?? '', $_GET);
parse_str($argv[2] ?? '', $_POST);
$hooks = $meta = [];
$posts = [];
foreach ([1, 4, 5] as $id) {
    $posts[$id] = (object) [
        'ID' => $id,
        'post_type' => $id === 1 ? 'post' : 'attachment',
        'post_status' => $id === 1 ? 'publish' : 'inherit',
        'post_parent' => 0,
        'post_password' => '',
        'post_excerpt' => '',
        'post_title' => 'Synthetic image ' . $id,
        'post_content' => '',
        'post_author' => 7,
    ];
}
function add_action($key, $fn, ...$args)
{
    $GLOBALS['hooks'][$key][] = $fn;
}
function add_filter(...$args)
{
    add_action(...$args);
}
function __($text, ...$args)
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_attr($text)
{
    return esc_html($text);
}
function esc_textarea($text)
{
    return esc_html($text);
}
function esc_url($text)
{
    return esc_html($text);
}
function esc_html__($text, ...$args)
{
    return esc_html($text);
}
function esc_attr__($text, ...$args)
{
    return esc_attr($text);
}
function current_user_can(...$args)
{
    return true;
}
function get_current_user_id()
{
    return 7;
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function get_the_title($post)
{
    return $post->post_title;
}
function get_permalink($post)
{
    return 'http://companion.fixture.test/post/' . $post->ID;
}
function get_post_meta($id, $key, $single)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value)
{
    $GLOBALS['meta'][$id][$key] = $value;
    return true;
}
function wp_update_post($args, ...$extra)
{
    $GLOBALS['posts'][$args['ID']]->post_excerpt = $args['post_excerpt'];
    return $args['ID'];
}
function wp_slash($value)
{
    return $value;
}
function wp_unslash($value)
{
    return $value;
}
function sanitize_text_field($text)
{
    return strip_tags($text);
}
function wp_kses_post($text)
{
    return $text;
}
function clean_post_cache($id) {}
function is_wp_error($value)
{
    return false;
}
function absint($value)
{
    return abs((int) $value);
}
function wp_attachment_is_image($id)
{
    return in_array($id, [4, 5], true);
}
function wp_get_attachment_image_url($id, ...$args)
{
    return wp_attachment_is_image($id)
        ? 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="120" height="80"%3E%3Crect width="120" height="80" fill="%23426354"/%3E%3C/svg%3E'
        : false;
}
function wp_get_attachment_image($id, ...$args)
{
    return '<img alt="" width="120" height="80" src="' .
        esc_attr(wp_get_attachment_image_url($id)) .
        '">';
}
function wp_create_nonce(...$args)
{
    return 'valid';
}
function wp_verify_nonce($nonce, ...$args)
{
    return $nonce === 'valid';
}
function wp_nonce_field($action, $name = '_wpnonce')
{
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="valid">';
}
function check_admin_referer($action, $name = '_wpnonce')
{
    if (($_POST[$name] ?? '') !== 'valid') {
        throw new RuntimeException('Bad fixture nonce');
    }
}
function wp_nonce_url($url, ...$args)
{
    return $url . '&_wpnonce=valid';
}
function admin_url($url)
{
    return 'http://companion.fixture.test/' . $url;
}
function add_query_arg($args, $url = '')
{
    $base = $url ?: 'http://companion.fixture.test/?' . http_build_query($_GET);
    $parts = parse_url($base);
    parse_str($parts['query'] ?? '', $query);
    return ($parts['scheme'] ?? 'http') .
        '://' .
        ($parts['host'] ?? 'companion.fixture.test') .
        ($parts['path'] ?? '/') .
        '?' .
        http_build_query(array_merge($query, $args));
}
function selected($value, $expected, $echo = true)
{
    return $value === $expected ? ' selected' : '';
}
function get_file_data(...$args)
{
    return ['version' => '1.6.0'];
}
function get_bloginfo($key)
{
    return $key === 'version' ? '6.8.3' : '';
}
function get_option($key, $default = null)
{
    return $default;
}
function get_theme_mod(...$args)
{
    return 0;
}
function get_post_thumbnail_id($post)
{
    return 0;
}
function pagenest_companion_feature($key)
{
    return $key !== 'likes';
}
function pagenest_discovery_options()
{
    return [
        'search' => true,
        'seo' => true,
        'description' => isset($_GET['empty']) ? '' : 'Site description',
        'image' => 0,
    ];
}
function pagenest_seo_external_owner()
{
    return false;
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
    return ['enabled' => true];
}
function pagenest_media_authoring_enabled()
{
    return true;
}
function pagenest_companion_content_config(...$args)
{
    return [];
}
function pagenest_companion_media_readable($id)
{
    return true;
}
function pagenest_discovery_public_post($post)
{
    return $post->post_status === 'publish' && $post->post_password === '';
}
function pagenest_discovery_text($text, ...$args)
{
    return strip_tags($text);
}
class WP_Query
{
    public $posts;
    public function __construct($args)
    {
        $this->posts = array_map(
            static fn($id) => $GLOBALS['posts'][$id],
            $args['post__in'] ?? [4, 5],
        );
    }
}
$wpdb = new class {
    public $posts = 'posts';
    public $postmeta = 'postmeta';
    public $last_error = '';
    public function prepare($sql, $id)
    {
        return str_contains($sql, 'ENGINE') ? 'INNODB' : $id;
    }
    public function query($sql)
    {
        return true;
    }
    public function get_var($value)
    {
        return $value;
    }
    public function get_results($sql)
    {
        return [];
    }
};
require dirname(__DIR__) . '/admin-design.php';
require dirname(__DIR__) . '/modules/diagnostics.php';
require dirname(__DIR__) . '/modules/discovery/editor.php';
require dirname(__DIR__) . '/modules/media/quality.php';
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic Companion UI</title><style>body{margin:20px;background:#f0f0f1;font:14px/1.6 system-ui}input,textarea,select,button{font:inherit}.widefat{width:100%}table{border-collapse:collapse}th,td{text-align:left;padding:10px}a{color:#426354}button{cursor:pointer}';
echo file_get_contents(dirname(__DIR__) . '/assets/admin-design.css');
echo file_get_contents(dirname(__DIR__) . '/assets/seo-editor.css');
echo '</style>';
if (($_GET['screen'] ?? '') === 'quality') {
    pagenest_media_quality_page();
} elseif (($_GET['screen'] ?? '') === 'seo') {
    pagenest_seo_editor($posts[1]);
} else {
    pagenest_companion_diagnostics_page();
}
echo '</html>';
