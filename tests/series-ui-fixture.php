<?php
/** Real settings sections and assets, with synthetic public pages and chapter metadata. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function __($text, $domain = '')
{
    static $messages;
    if ($messages === null) {
        $messages = [];
        preg_match_all(
            '/^msgid (".*")\nmsgstr (".*")$/m',
            file_get_contents(dirname(__DIR__) . '/languages/zh_CN.po'),
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $messages[json_decode($match[1])] = json_decode($match[2]);
        }
    }
    return $messages[$text] ?? $text;
}
function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function esc_html($value)
{
    return esc_attr($value);
}
function esc_url($value)
{
    return esc_attr($value);
}
function esc_html__($text, $domain = '')
{
    return esc_html(__($text, $domain));
}
function esc_attr__($text, $domain = '')
{
    return esc_attr(__($text, $domain));
}
function current_user_can(...$args)
{
    return true;
}
function wp_create_nonce($action)
{
    return 'synthetic-nonce';
}
function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'synthetic-nonce';
}
function wp_unslash($value)
{
    return $value;
}
function wp_salt($scheme)
{
    return 'synthetic-salt';
}
function admin_url($path)
{
    return 'https://fixture.example/wp-admin/' . $path;
}
function get_option($key, $default = false)
{
    return $key === 'pagenest_companion_series_directories'
        ? ['阅读与实践系列' => 2, '旧目录仍保留的系列' => 99]
        : $default;
}
function get_post($id)
{
    if (!in_array((int) $id, [2, 8], true)) {
        return null;
    }
    return (object) [
        'ID' => (int) $id,
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_password' => '',
        'post_parent' => 0,
        'post_title' => (int) $id === 2 ? '阅读与实践目录' : '服务器目录',
    ];
}
function get_permalink($post)
{
    return 'https://fixture.example/' . $post->ID;
}
function is_post_publicly_viewable($post)
{
    return true;
}
function post_password_required($post)
{
    return false;
}
function get_posts($query)
{
    if ($query['post_type'] === 'page') {
        return [get_post(2), get_post(8)];
    }
    return array_map(
        static fn($id) => (object) [
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_password' => '',
            'post_title' => [
                '第一章：阅读与实践',
                '第二章：长标题与重复顺序的实际状态',
                '第三章：未填写顺序时的回退',
            ][$id - 10],
        ],
        [10, 11, 12],
    );
}
function get_post_meta($id, $key, $single = true)
{
    $value =
        [
            '_pagenest_series' => '阅读与实践系列',
            '_pagenest_document_id' => 'D' . $id,
            '_pagenest_chapter_order' => $id === 12 ? '' : '2',
        ][$key] ?? '';
    return $single ? $value : [$value];
}
function pagenest_companion_post_setting($id, $field)
{
    return get_post_meta(
        $id,
        $field === 'series' ? '_pagenest_series' : '_pagenest_chapter_order',
        true,
    );
}
function pagenest_companion_feature($feature)
{
    return true;
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}
class WP_Error
{
    public function __construct(public $code, public $message) {}
    public function get_error_message()
    {
        return $this->message;
    }
}
$wpdb = new class {
    public $postmeta = 'synthetic_postmeta';
    public $last_error = '';
    public function prepare($query, ...$args)
    {
        return $query;
    }
    public function get_col($query)
    {
        return [];
    }
};
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/modules/chapters.php';
require dirname(__DIR__) . '/admin-design.php';
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_defaults();
$GLOBALS['pagenest_companion_profile']['chapters']['series_directories'] = ['服务器系列' => 8];
$GLOBALS['pagenest_companion_profile']['chapters']['document_series'] = ['D01' => '服务器系列'];
$_GET['pn_series'] = '阅读与实践系列';
if (getenv('PAGENEST_SERIES_FIXTURE_POST')) {
    parse_str(getenv('PAGENEST_SERIES_FIXTURE_POST'), $_POST);
}
function pagenest_companion_settings_page() {}
function pagenest_discovery_settings_page() {}
function pagenest_notifications_settings() {}
function pagenest_reading_markup() {}
function pagenest_guide_page() {}
function pagenest_companion_diagnostics_page() {}
function pagenest_companion_media_settings_page() {}
function pagenest_social_settings_page() {}
?>
<!doctype html>
<html lang="zh-CN">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>阅读设置</title>
<style><?php readfile(dirname(__DIR__) . '/assets/admin-design.css'); ?></style>
<body>
    <?php pagenest_companion_admin_header(
        '阅读与互动',
        '为读者开启合适的互动方式。',
        'pagenest-companion',
    ); ?>
    <div class="pn-admin-layout">
        <div class="pn-admin-main">
            <form method="post"><?php pagenest_companion_series_settings_fields(); ?><p><button type="submit" class="button button-primary">保存阅读设置</button></p>
            </form>
            <?php pagenest_companion_series_maintenance(); ?>
        </div>
        <aside class="pn-admin-help">
            <h2>先配置，再保存</h2>
            <p>关联公开目录，继续使用文章现有系列。</p>
        </aside>
    </div>
    </div>
    <script>window.PageNestAdmin = <?php echo json_encode([
        'seriesChanged' => __('Order changed. Preview again before saving.', 'pagenest-companion'),
        'directoryPending' => __(
            'Selected directory (save reading settings to apply):',
            'pagenest-companion',
        ),
    ]); ?>;</script>
    <script><?php readfile(dirname(__DIR__) . '/assets/admin-design.js'); ?></script>
</body>
</html>
