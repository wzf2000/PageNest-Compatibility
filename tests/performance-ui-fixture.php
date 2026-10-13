<?php
/** Static synthetic UI pages. No WordPress database, credentials or website requests. */
$scenario = $argv[1] ?? 'completed';
define('ABSPATH', __DIR__ . '/synthetic-web');
$dir = sys_get_temp_dir() . '/pn-performance-ui-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
define('PAGENEST_COMPANION_PERFORMANCE_ENABLED', $scenario !== 'disabled');
define(
    'PAGENEST_COMPANION_PERFORMANCE_DIR',
    $scenario === 'config-error' ? $dir . '/missing' : $dir,
);
$hooks = [];
$options = [];
$can = true;
$nonce = true;
$reads = 0;
$wp_version = 'synthetic';
$posts = [];
for ($i = 1; $i <= 12; $i++) {
    $posts[$i] = (object) [
        'ID' => $i,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
        'post_title' => 'Page ' . $i,
        'post_content' => str_repeat('x', $i * 10),
        'url' => 'https://example.test/page-' . $i . '/',
    ];
}
$posts[2]->post_status = 'private';
$posts[3]->post_status = 'draft';
$posts[4]->post_password = 'secret';
$posts[5]->post_content = '<pre>code</pre>';
$posts[6]->post_content = '<img src="">';
$posts[7]->post_content = '<ul>目录</ul>';
function add_action($name, $callback, ...$rest)
{
    $GLOBALS['hooks'][$name][] = $callback;
}
function home_url($path)
{
    return 'https://example.test' . $path;
}
function get_option($key, $default = null)
{
    $GLOBALS['reads']++;
    return $GLOBALS['options'][$key] ?? $default;
}
function update_option($key, $value, $autoload)
{
    if ($autoload !== false) {
        throw new RuntimeException('autoload');
    }
    $GLOBALS['options'][$key] = $value;
}
function get_post($id)
{
    return $GLOBALS['posts'][$id] ?? null;
}
function get_permalink($id)
{
    return $GLOBALS['posts'][$id]->url ?? '';
}
function is_post_publicly_viewable($p)
{
    return $p->post_status === 'publish';
}
function get_posts($args)
{
    if ($args['numberposts'] > 100) {
        throw new RuntimeException('unbounded');
    }
    return array_values($GLOBALS['posts']);
}
function get_file_data($path, $headers)
{
    return ['Version' => 'synthetic'];
}
function wp_get_theme()
{
    return new class {
        function get($k)
        {
            return 'synthetic';
        }
    };
}
function current_user_can($cap)
{
    return $GLOBALS['can'] && $cap === 'manage_options';
}
function wp_verify_nonce($v, $action)
{
    return $GLOBALS['nonce'] && $v === 'good' && $action === 'pagenest_performance';
}
function wp_unslash($v)
{
    return $v;
}
function nocache_headers()
{
    $GLOBALS['no_store'] = true;
}
function wp_die(...$args)
{
    throw new RuntimeException('denied');
}
function esc_html($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}
function esc_attr($v)
{
    return esc_html($v);
}
function esc_url($v)
{
    return esc_html($v);
}
function admin_url($v)
{
    return '/fixture/' . $v;
}
function wp_nonce_field(...$args)
{
    echo '<input type="hidden" name="_wpnonce" value="good">';
}
function add_management_page(...$args) {}
eval(
    'namespace PageNest\\Tools; final class Registry { public static bool $healthy=true; public static array $entries=[]; public static function healthy() {return self::$healthy;} public static function forPage($id) {return $id===8 ? ["kind"=>"internal"] : null;} public static function indexEntries() {return self::$entries;} }'
);
require __DIR__ . '/../modules/performance/bootstrap.php';

function plugins_url($path, $file)
{
    return '../../' . $path;
}
function wp_enqueue_style($handle, $source, ...$args)
{
    $GLOBALS['styles'][$handle] = $source;
}
function wp_enqueue_script($handle, $source, ...$args)
{
    $GLOBALS['scripts'][$handle] = $source;
}
function wp_date($format, $timestamp)
{
    return gmdate($format, $timestamp + 8 * 3600);
}
$styles = $scripts = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$options['show_on_front'] = 'page';
$options['page_on_front'] = 1;
$options['page_for_posts'] = 5;
$posts[1]->post_title = '站点首页';
$posts[5]->post_title = '博客 · 公式与代码示例';
$posts[6]->post_title = '媒体与 PDF：一篇比较长的公开文章标题，用于验证窄屏换行';
$posts[7]->post_title = '内容导览';
$styles = $scripts = [];
foreach ($hooks['admin_enqueue_scripts'] as $hook) {
    $hook('tools_page_pagenest-performance');
}
$j = [
    'schema_version' => 2,
    'id' => str_repeat('a', 32),
    'status' => 'completed',
    'created_at' => '2026-10-13T04:20:00Z',
    'updated_at' => '2026-10-13T04:21:00Z',
    'expires_at' => '2026-10-13T04:50:00Z',
    'profile' => ['device' => 'desktop', 'cache_mode' => 'paired', 'repeats' => 3],
    'origin' => 'https://example.test',
    'pages' => [
        PageNest\Performance\Pages::resolve('post:5'),
        PageNest\Performance\Pages::resolve('post:6'),
    ],
    'versions' => ['wordpress' => '7.1.3', 'companion' => '1.5.0', 'theme' => '1.1.0'],
    'environment' => [
        'runner' => 'playwright-navigation-v3',
        'node_version' => 'v20.13.1',
        'chromium_version' => '140.0.7339.186',
        'network' => 'server-local',
        'cache_mode' => 'paired',
        'sample_window_ms' => 1500,
        'policy_hash' => str_repeat('a', 64),
        'limits_hash' => str_repeat('b', 64),
    ],
    'results' => [],
    'error' => null,
];
foreach ($j['pages'] as $p) {
    foreach ([1, 2, 3] as $run) {
        foreach (['fresh', 'warm'] as $phase) {
            $r = [
                'page_key' => $p['key'],
                'run' => $run,
                'cache_phase' => $phase,
                'status' => 'ok',
                'http_status' => 200,
                'ttfb_ms' => $phase === 'fresh' ? 2850.1 : 542.6,
                'fcp_ms' => $phase === 'fresh' ? 4596 : 1600,
                'lcp_ms' => $phase === 'fresh' ? 5572 : 1888,
                'cls' => 0,
                'long_task_blocking_ms' => 477,
                'request_count' => 34,
                'transfer_bytes' => $phase === 'fresh' ? 749903 : 15544,
                'failed_requests' => 2,
                'blocked_requests' => 2,
                'browser_cache_requests' => $phase === 'fresh' ? 0 : 31,
                'cache_header' => $phase === 'fresh' ? 'MISS' : 'HIT',
                'duration_ms' => 9153.176,
                'error' => null,
            ];
            if ($p['key'] === 'post:6') {
                $r['lcp_ms'] = null;
            }
            if ($p['key'] === 'post:6' && $run === 3) {
                $r['status'] = 'failed';
                $r['error'] = $phase === 'warm' ? 'warmup_failed' : 'navigation_failed';
                $r['http_status'] = null;
                $r['cache_header'] = 'unknown';
            }
            $j['results'][] = $r;
        }
    }
}
try {
    if (!in_array($scenario, ['disabled', 'config-error', 'empty'], true)) {
        if (in_array($scenario, ['queued', 'running', 'cancel-notice', 'expired-running'], true)) {
            $j['status'] = $scenario === 'queued' ? 'queued' : 'running';
            $j['results'] = $j['status'] === 'queued' ? [] : array_slice($j['results'], 0, 3);
            if (!$j['results']) {
                unset($j['environment']);
            }
        }
        if ($scenario === 'expired-running') {
            $j['expires_at'] = '2026-10-12T04:50:00Z';
        }
        if ($scenario === 'failed') {
            $j['status'] = 'failed';
            $j['error'] = 'resource_limit';
        }
        if ($scenario === 'cancel-notice') {
            $_GET['performance_notice'] = 'cancel_requested';
        }
        $jobs = [$j];
        if (in_array($scenario, ['history', 'incomparable', 'budget'], true)) {
            $before = $j;
            $before['id'] = str_repeat('b', 32);
            $before['created_at'] = '2026-10-12T04:20:00Z';
            $before['versions']['companion'] = '1.4.0';
            if ($scenario === 'incomparable') {
                $before['schema_version'] = 1;
                $before['profile']['cache_mode'] = 'fresh-context';
                unset($before['environment']);
                $before['results'] = array_values(
                    array_filter(
                        $before['results'],
                        static fn($r) => $r['cache_phase'] === 'fresh',
                    ),
                );
                foreach ($before['results'] as &$r) {
                    unset($r['cache_phase'], $r['browser_cache_requests']);
                }
                unset($r);
            }
            $jobs[] = $before;
            if ($scenario !== 'budget') {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_POST = [
                    'operation' => 'compare',
                    '_wpnonce' => 'good',
                    'before' => $before['id'],
                    'after' => $j['id'],
                ];
            } else {
                $_GET['performance_notice'] = 'budget_cleanup_required';
                for ($i = 2; $i < 10; $i++) {
                    $copy = $j;
                    $copy['id'] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
                    $copy['created_at'] = sprintf('2026-10-%02dT04:20:00Z', 12 - $i);
                    $jobs[] = $copy;
                }
            }
        }
        foreach ($jobs as $job) {
            if (!PageNest\Performance\Queue::valid($job)) {
                throw new RuntimeException('Invalid UI fixture ' . $scenario);
            }
            $path = $dir . '/job-' . $job['id'] . '.json';
            file_put_contents($path, json_encode($job));
            chmod($path, 0600);
        }
    }
    ob_start();
    PageNest\Performance\screen();
    $body = ob_get_clean();
    $head =
        '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>页面测速合成预览</title><style>body{margin:0;background:#f0f0f1;font-family:system-ui,sans-serif}#wpcontent{margin:32px 40px}.wrap{margin:10px 0}button,input,select{font:inherit}.button{display:inline-block;text-decoration:none;cursor:pointer}p{margin:12px 0}@media(max-width:600px){#wpcontent{margin:18px 12px}}</style>';
    foreach ($styles as $src) {
        $head .= '<link rel="stylesheet" href="' . esc_attr($src) . '">';
    }
    $tail = '';
    foreach ($scripts as $src) {
        $tail .= '<script src="' . esc_attr($src) . '"></script>';
    }
    $out = dirname(__DIR__) . '/.runtime/performance';
    @mkdir($out, 0777, true);
    file_put_contents(
        $out . '/' . $scenario . '.html',
        $head . '</head><body><main id="wpcontent">' . $body . '</main>' . $tail . '</body></html>',
    );
} finally {
    foreach (glob($dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}
