<?php
/** Memory fixtures cover decisions and raw metadata conflicts without production data. */
define('ABSPATH', __DIR__);
define('DB_NAME', 'synthetic');
define('USER_COOKIE', 'synthetic_user');
define('ARRAY_A', 'ARRAY_A');
define('WP_CACHE', ($argv[1] ?? '') === 'cache');
$options = [];
$rows = [];
$user = 0;
$preview = false;
$admin = false;
$posts = [
    1 => (object) [
        'ID' => 1,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
    ],
];
class WP_Error
{
    public function __construct(public $code, public $message) {}
    public function get_error_code()
    {
        return $this->code;
    }
}
function is_wp_error($v)
{
    return $v instanceof WP_Error;
}
function add_action(...$args) {}
function get_option($key, $default = null)
{
    return $GLOBALS['options'][$key] ?? $default;
}
function sanitize_text_field($v)
{
    return strip_tags($v);
}
function get_current_user_id()
{
    return $GLOBALS['user'];
}
function is_admin()
{
    return $GLOBALS['admin'];
}
function is_preview()
{
    return $GLOBALS['preview'];
}
function is_feed()
{
    return false;
}
function is_singular($types)
{
    return true;
}
function get_queried_object_id()
{
    return 1;
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function is_post_publicly_viewable($p)
{
    return $p->post_status === 'publish';
}
function wp_is_post_revision($id)
{
    return false;
}
function post_password_required($p)
{
    return $p->post_password !== '';
}
function wp_cache_delete(...$args) {}
function number_format_i18n($n)
{
    return number_format($n);
}
function esc_html($v)
{
    return htmlspecialchars($v, ENT_QUOTES);
}
function fixture_owner() {}
function add_post_meta($id, $key, $value, $unique)
{
    if ($GLOBALS['wpdb']->write_fail || !empty($GLOBALS['rows'][$id][$key])) {
        return false;
    }
    $GLOBALS['rows'][$id][$key] = [
        ['meta_id' => ++$GLOBALS['wpdb']->serial, 'meta_value' => (string) $value],
    ];
    return $GLOBALS['wpdb']->serial;
}
class ViewsDatabase
{
    public $prefix = 'fixture_',
        $postmeta = 'fixture_postmeta',
        $last_error = '',
        $serial = 100;
    public $lock_fail = false,
        $write_fail = false;
    public function prepare($sql, ...$args)
    {
        return [$sql, $args];
    }
    public function get_var($query)
    {
        return $this->lock_fail ? '0' : '1';
    }
    public function get_results($query, $mode)
    {
        return $GLOBALS['rows'][$query[1][0]][$query[1][1]] ?? [];
    }
    public function query($query)
    {
        if ($this->write_fail) {
            return false;
        }
        foreach ($GLOBALS['rows'] as &$post) {
            foreach ($post as &$items) {
                foreach ($items as &$row) {
                    if ($row['meta_id'] === $query[1][0] && $row['meta_value'] === $query[1][1]) {
                        $row['meta_value'] = (string) ((int) $row['meta_value'] + 1);
                        return 1;
                    }
                }
            }
        }
        return 0;
    }
}
$wpdb = new ViewsDatabase();
require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/modules/views.php';
$checks = 0;
function verify($label, $condition)
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}
verify(
    'disabled defaults do not write',
    is_wp_error(pagenest_companion_views_increment(1)) && !$rows,
);
$options['pagenest_companion_views_settings'] = [
    'enabled' => 1,
    'count' => 0,
    'exclude_bots' => 0,
    'template' => '%VIEW_COUNT% 次浏览',
];
if (WP_CACHE) {
    verify(
        'page caches refuse counting',
        is_wp_error(pagenest_companion_views_increment(1)) && !$rows,
    );
    echo "Views cache guard: $checks checks\n";
    exit();
}
verify(
    'older profiles accept omitted views section',
    pagenest_companion_validate(['schema_version' => 1])['views']['legacy_meta'] === '',
);
try {
    pagenest_companion_validate([
        'schema_version' => 1,
        'views' => ['legacy_owner_function' => 'invalid()'],
    ]);
    verify('invalid owner rejected', false);
} catch (InvalidArgumentException $e) {
    verify('invalid owner rejected', true);
}
$GLOBALS['pagenest_companion_profile'] = pagenest_companion_validate([
    'schema_version' => 1,
    'views' => ['legacy_meta' => '_fixture_old'],
]);
$rows[1]['_fixture_old'] = [['meta_id' => 1, 'meta_value' => '0009']];
verify(
    'fallback reads preserve raw source',
    pagenest_companion_views_get(1) === 9 && $rows[1]['_fixture_old'][0]['meta_value'] === '0009',
);
verify('first increment seeds old count', pagenest_companion_views_increment(1) === 10);
verify(
    'next increment is canonical',
    pagenest_companion_views_increment(1) === 11 &&
        $rows[1]['_fixture_old'][0]['meta_value'] === '0009',
);
ob_start();
pagenest_companion_views_footer(1);
$output = ob_get_clean();
verify(
    'render only reads',
    $output === '<span>11 次浏览</span>' && pagenest_companion_views_get(1) === 11,
);
$options['pagenest_companion_views_settings']['template'] = '&lt;script&gt;%VIEW_COUNT%';
ob_start();
pagenest_companion_views_footer(1);
$output = ob_get_clean();
verify('template text escaped', str_contains($output, '&amp;lt;script&amp;gt;11'));
$options['pagenest_companion_views_settings']['count'] = 1;
$user = 7;
verify('guests exclude signed-in readers', is_wp_error(pagenest_companion_views_increment(1)));
$user = 0;
$_COOKIE[USER_COOKIE] = 'live';
verify('guest cookie exclusion', is_wp_error(pagenest_companion_views_increment(1)));
unset($_COOKIE[USER_COOKIE]);
$options['pagenest_companion_views_settings']['count'] = 2;
verify('registered audience excludes guests', is_wp_error(pagenest_companion_views_increment(1)));
$user = 7;
verify('registered audience counts readers', pagenest_companion_views_increment(1) === 12);
$options['pagenest_companion_views_settings']['exclude_bots'] = 1;
$_SERVER['HTTP_USER_AGENT'] = 'Googlebot';
verify('robot excluded', is_wp_error(pagenest_companion_views_increment(1)));
$_SERVER['HTTP_USER_AGENT'] = '';
verify('empty agent retains legacy behavior', pagenest_companion_views_increment(1) === 13);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 PageNestPerformance/1.0';
$options['pagenest_companion_views_settings']['exclude_bots'] = 0;
$before = $rows;
verify(
    'performance token never writes count with bot exclusion disabled',
    is_wp_error(pagenest_companion_views_increment(1)) && $rows === $before,
);
$_SERVER['HTTP_USER_AGENT'] = '';
$preview = true;
verify('previews excluded', is_wp_error(pagenest_companion_views_increment(1)));
$preview = false;
$admin = true;
verify('admin excluded', is_wp_error(pagenest_companion_views_increment(1)));
$admin = false;
$posts[1]->post_status = 'private';
verify('private content excluded', is_wp_error(pagenest_companion_views_increment(1)));
$posts[1]->post_status = 'publish';
$posts[1]->post_password = 'secret';
verify('locked content excluded', is_wp_error(pagenest_companion_views_increment(1)));
$posts[1]->post_password = '';
$GLOBALS['pagenest_companion_profile']['views']['legacy_owner_function'] = 'fixture_owner';
verify('legacy owner prevents counting', is_wp_error(pagenest_companion_views_increment(1)));
ob_start();
pagenest_companion_views_footer(1);
verify('legacy owner prevents duplicate display', ob_get_clean() === '');
$GLOBALS['pagenest_companion_profile']['views']['legacy_owner_function'] = '';
$wpdb->lock_fail = true;
verify(
    'lock failure explicit',
    pagenest_companion_views_increment(1)->code === 'pagenest_views_unavailable',
);
$wpdb->lock_fail = false;
$wpdb->write_fail = true;
verify(
    'write failure explicit',
    pagenest_companion_views_increment(1)->code === 'pagenest_views_unavailable',
);
$wpdb->write_fail = false;
$key = '_pagenest_companion_views';
$saved = $rows[1][$key];
$rows[1][$key][] = ['meta_id' => 999, 'meta_value' => '13'];
verify('duplicates rejected', is_wp_error(pagenest_companion_views_increment(1)));
$rows[1][$key] = $saved;
foreach (['-1', '1.5', 'garbage', (string) PHP_INT_MAX, (string) PHP_INT_MAX . '0', '8'] as $raw) {
    $rows[1][$key][0]['meta_value'] = $raw;
    verify(
        'invalid overflow and baseline conflicts rejected: ' . $raw,
        is_wp_error(pagenest_companion_views_increment(1)),
    );
}
$rows[1][$key] = $saved;
pagenest_companion_views_process();
pagenest_companion_views_process();
verify('wp_head once per request', pagenest_companion_views_get(1) === 14);
echo "Views contracts: $checks checks\n";
