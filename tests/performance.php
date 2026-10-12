<?php
/** Synthetic memory WordPress and local private queue; no HTTP or real database. */
define('ABSPATH', __DIR__ . '/synthetic-web');
$disabled = ($argv[1] ?? '') === 'disabled';
$dir = sys_get_temp_dir() . '/pagenest-performance-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
define('PAGENEST_COMPANION_PERFORMANCE_ENABLED', !$disabled);
define('PAGENEST_COMPANION_PERFORMANCE_DIR', $dir);
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
    return 'https://example.test/wp-admin/' . $v;
}
function wp_nonce_field(...$args)
{
    echo '<input value="good">';
}
function add_management_page(...$args) {}
eval(
    'namespace PageNest\\Tools; final class Registry { public static bool $healthy=true; public static array $entries=[]; public static function healthy() {return self::$healthy;} public static function forPage($id) {return $id===8 ? ["kind"=>"internal"] : null;} public static function indexEntries() {return self::$entries;} }'
);
require __DIR__ . '/../modules/performance/bootstrap.php';
use PageNest\Performance\Pages;
use PageNest\Performance\Queue;
use PageNest\Performance\Reports;
$checks = 0;
function check($ok, $name)
{
    $GLOBALS['checks']++;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
}
function reject($fn, $name)
{
    try {
        $fn();
    } catch (Throwable $e) {
        check(true, $name);
        return;
    }
    check(false, $name);
}
try {
    check($reads === 0, 'bootstrap has zero option reads');
    if ($disabled) {
        check(
            !isset($hooks['admin_post_pagenest_performance']),
            'disabled has no submission endpoint',
        );
        check(scandir($dir) === ['.', '..'], 'disabled has no disk writes');
        ob_start();
        PageNest\Performance\screen();
        $html = ob_get_clean();
        check(
            $reads === 0 && str_contains($html, '尚未启用'),
            'disabled screen avoids choices and queue',
        );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_wpnonce'] = 'good';
        reject(
            fn() => PageNest\Performance\export_data(new Queue($dir), str_repeat('a', 32)),
            'disabled export guarded',
        );
        echo "Performance disabled: $checks checks\n";
    } else {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_wpnonce'] = 'good';
        $can = false;
        reject(fn() => PageNest\Performance\authorize(), 'permission');
        $can = true;
        $nonce = false;
        reject(fn() => PageNest\Performance\authorize(), 'nonce');
        $nonce = true;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        reject(fn() => PageNest\Performance\authorize(), 'post only');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        PageNest\Performance\authorize();
        check($no_store, 'no-store');
        foreach ([2, 3, 4] as $id) {
            reject(fn() => Pages::resolve('post:' . $id), 'private draft password');
        }
        foreach (
            [
                'http://example.test/a',
                'https://evil.test/a',
                'https://u@example.test/a',
                'https://example.test:8443/a',
                'https://example.test/a?q=1',
                'https://example.test/a#x',
                'https://example.test/wp-admin/a',
                'https://example.test/wp-json/a',
                'https://example.test/wp-login.php',
                'https://example.test/xmlrpc.php',
                'https://example.test/%6fauth/a',
                'https://example.test/a/../b',
                'https://example.test/a%2fb%3fx',
            ]
            as $url
        ) {
            reject(fn() => Pages::url($url), 'illegal URL ' . $url);
        }
        check(
            count(Pages::suggested()) >= 6 && count(Pages::suggested()) <= 8,
            'representative bounded samples',
        );
        set_error_handler(static function () {
            throw new RuntimeException('unexpected_warning');
        });
        try {
            try {
                Pages::selection([['post:1']]);
            } catch (RuntimeException $e) {
                check(
                    $e->getMessage() === 'invalid_selection',
                    'nested selection rejects before PHP warning',
                );
            }
        } finally {
            restore_error_handler();
        }
        reject(fn() => Pages::resolve('post:8'), 'unlisted tool excluded');
        \PageNest\Tools\Registry::$entries = [
            ['kind' => 'internal', 'binding' => ['post_id' => 8]],
        ];
        check(Pages::resolve('post:8')['post_id'] === 8, 'healthy public registry tool');
        \PageNest\Tools\Registry::$healthy = false;
        reject(fn() => Pages::resolve('post:8'), 'unhealthy tool excluded');
        \PageNest\Tools\Registry::$healthy = true;
        check(Queue::directory($dir) === $dir, 'private existing dir');
        chmod($dir, 0777);
        reject(fn() => Queue::directory($dir), 'public directory');
        chmod($dir, 0700);
        $link = $dir . '-link';
        symlink($dir, $link);
        reject(fn() => Queue::directory($link), 'directory symlink');
        unlink($link);
        symlink(__FILE__, $dir . '/unexpected');
        reject(fn() => Queue::directory($dir), 'existing symlink');
        unlink($dir . '/unexpected');
        reject(fn() => Queue::directory(ABSPATH), 'web root directory');
        $q = new Queue($dir);
        $queueLock = fopen($dir . '/queue.lock', 'c+b');
        chmod($dir . '/queue.lock', 0600);
        flock($queueLock, LOCK_EX | LOCK_NB);
        reject(fn() => $q->start(['post:1'], 'desktop', 1), 'parallel submit lock');
        fclose($queueLock);
        $j = $q->start(['post:1'], 'desktop', 3);
        check(Queue::valid($j), 'queued protocol');
        check((fileperms($dir . '/job-' . $j['id'] . '.json') & 0777) === 0600, 'private job');
        reject(fn() => $q->start(['post:5'], 'mobile', 1), 'queued prevents start');
        $q->cancel($j['id']);
        check($q->reports()[0]['status'] === 'cancelled', 'queued cancellation');
        $worker = fopen($dir . '/worker.lock', 'c+b');
        flock($worker, LOCK_EX | LOCK_NB);
        reject(fn() => $q->start(['post:5'], 'mobile', 1), 'worker prevents start');
        fclose($worker);
        $j = $q->start(['post:5'], 'mobile', 1);
        $path = $dir . '/job-' . $j['id'] . '.json';
        $j['status'] = 'running';
        file_put_contents($path, json_encode($j));
        $q->cancel($j['id']);
        check(is_file($dir . '/cancel-' . $j['id']), 'running cancel marker');
        $j['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
        file_put_contents($path, json_encode($j));
        $next = $q->start(['post:6'], 'desktop', 1);
        $old = json_decode(file_get_contents($path), true);
        check(
            $old['status'] === 'failed' && $old['error'] === 'worker_expired',
            'expired recovery',
        );
        $q->cancel($next['id']);
        $newPair = $q->start(['post:1'], 'desktop', 1, 'paired');
        check(
            $newPair['schema_version'] === 2 &&
                $newPair['profile']['cache_mode'] === 'paired' &&
                Queue::valid($newPair),
            'paired job creation',
        );
        $q->cancel($newPair['id']);
        reject(fn() => $q->start(['post:1'], 'desktop', 1, 'arbitrary'), 'invalid cache mode');
        $j['status'] = 'completed';
        $j['error'] = null;
        $j['pages'][0]['title'] = '<script>alert(1)</script>';
        $j['results'] = [
            [
                'page_key' => 'post:5',
                'cache_phase' => 'fresh',
                'browser_cache_requests' => 0,
                'run' => 1,
                'status' => 'ok',
                'http_status' => 200,
                'ttfb_ms' => 10,
                'fcp_ms' => 20,
                'lcp_ms' => 30,
                'cls' => 0,
                'long_task_blocking_ms' => 0,
                'request_count' => 4,
                'transfer_bytes' => 100,
                'failed_requests' => 0,
                'blocked_requests' => 1,
                'cache_header' => 'HIT',
                'duration_ms' => 1500,
                'error' => null,
            ],
        ];
        check(Queue::valid($j), 'result protocol');
        ob_start();
        PageNest\Performance\report($j);
        $html = ob_get_clean();
        check(
            !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'),
            'safe display',
        );
        $duplicate = $j;
        $duplicate['results'][] = $duplicate['results'][0];
        check(!Queue::valid($duplicate), 'duplicate run rejected');
        $foreign = $j;
        $foreign['origin'] = 'https://evil.test';
        check(!Queue::valid($foreign), 'foreign origin rejected');
        $mismatch = $j;
        $mismatch['pages'][0]['post_id'] = 1;
        check(!Queue::valid($mismatch), 'page identity mismatch rejected');
        $bad = $j;
        $bad['results'][0]['fcp_ms'] = '<script>';
        check(!Queue::valid($bad), 'invalid metric rejected');
        $legacy = $j;
        $legacy['schema_version'] = 1;
        unset(
            $legacy['results'][0]['cache_phase'],
            $legacy['results'][0]['browser_cache_requests'],
        );
        check(Queue::valid($legacy), 'legacy report readable');
        $legacy['profile']['cache_mode'] = 'paired';
        check(!Queue::valid($legacy), 'legacy paired rejected');
        $pair = $j;
        $pair['profile']['cache_mode'] = 'paired';
        $pair['profile']['repeats'] = 3;
        $pair['environment'] = [
            'runner' => 'playwright-navigation-v3',
            'node_version' => 'v24',
            'chromium_version' => 'synthetic',
            'network' => 'server-local',
            'cache_mode' => 'paired',
            'sample_window_ms' => 1500,
            'policy_hash' => str_repeat('a', 64),
            'limits_hash' => str_repeat('b', 64),
        ];
        $row = $pair['results'][0];
        $warm = $row;
        $warm['cache_phase'] = 'warm';
        $warm['ttfb_ms'] = 5;
        $warm['browser_cache_requests'] = 2;
        $pair['results'][] = $warm;
        $fresh2 = $row;
        $fresh2['run'] = 2;
        $fresh2['ttfb_ms'] = 100;
        $warm2 = $warm;
        $warm2['run'] = 2;
        $warm2['status'] = 'failed';
        $warm2['error'] = 'navigation_failed';
        $pair['results'][] = $fresh2;
        $pair['results'][] = $warm2;
        check(Queue::valid($pair), 'paired phases valid');
        $stats = Reports::paired($pair, 'post:5', 'ttfb_ms');
        check(
            $stats['fresh'] === 10.0 &&
                $stats['warm'] === 5.0 &&
                $stats['difference'] === -5.0 &&
                $stats['n'] === 1 &&
                $stats['total'] === 3,
            'paired only both successful',
        );
        check(
            Reports::stats($pair, 'post:5', 'fresh', 'ttfb_ms')['median'] === 55.0,
            'fresh median independent',
        );
        check(
            Reports::stats($pair, 'post:5', 'warm', 'ttfb_ms')['median'] === 5.0,
            'warm median independent',
        );
        $missing = $pair;
        unset($missing['results'][0]['cache_phase']);
        check(!Queue::valid($missing), 'v2 requires phase');
        $invalid = $pair;
        $invalid['results'][1]['browser_cache_requests'] = 5;
        check(!Queue::valid($invalid), 'cache requests bounded');
        $invalid = $pair;
        $invalid['results'][] = $invalid['results'][1];
        check(!Queue::valid($invalid), 'duplicate phase rejected');
        $invalid = $pair;
        $invalid['results'][0]['status'] = 'failed';
        $invalid['results'][0]['error'] = 'navigation_failed';
        check(!Queue::valid($invalid), 'warm success cannot follow failed fresh');
        $orphan = $pair;
        array_shift($orphan['results']);
        check(!Queue::valid($orphan), 'warm requires fresh same run');
        $invalid['results'][1]['status'] = 'failed';
        $invalid['results'][1]['error'] = 'warmup_failed';
        check(Queue::valid($invalid), 'warmup failure accepted');
        $null = $pair;
        $null['results'][1]['ttfb_ms'] = null;
        check(
            Reports::paired($null, 'post:5', 'ttfb_ms')['n'] === 0 &&
                Reports::paired($null, 'post:5', 'ttfb_ms')['fresh'] === null,
            'paired null stays unavailable',
        );
        check(
            Reports::delta(0.0, 5.0) === ['absolute' => 5.0, 'percent' => null],
            'zero baseline no percentage',
        );
        check(
            Reports::delta(null, 5.0) === ['absolute' => null, 'percent' => null],
            'null baseline no delta',
        );
        $after = $pair;
        $after['id'] = str_repeat('b', 32);
        $after['versions']['theme'] = 'updated';
        $after['results'][1]['ttfb_ms'] = 3;
        $comparison = Reports::compare($pair, $after);
        check(
            $comparison['comparable'] &&
                $comparison['versions_changed'] &&
                count($comparison['pages']) === 2,
            'strict historical comparison allows versions changes',
        );
        foreach (
            [
                'runner',
                'node_version',
                'chromium_version',
                'network',
                'cache_mode',
                'sample_window_ms',
                'policy_hash',
                'limits_hash',
            ]
            as $field
        ) {
            $different = $after;
            $different['environment'][$field] = 'different';
            check(
                !Reports::compare($pair, $different)['comparable'],
                'environment difference ' . $field,
            );
        }
        $different = $after;
        $different['environment']['runner'] = 'playwright-navigation-v2';
        unset($different['environment']['limits_hash']);
        check(Queue::valid($different), 'previous runner remains readable');
        check(
            !Reports::compare($different, $different)['comparable'],
            'unknown limits cannot compare',
        );
        $different['environment']['runner'] = 'playwright-navigation-v3';
        check(!Queue::valid($different), 'new runner requires limits hash');
        $different = $after;
        $different['profile']['device'] = 'desktop';
        check(!Reports::compare($pair, $different)['comparable'], 'device difference');
        $different = $after;
        $different['pages'][0]['url'] = 'https://example.test/different/';
        check(!Reports::compare($pair, $different)['comparable'], 'URL difference');
        $different = $after;
        $different['status'] = 'failed';
        check(!Reports::compare($pair, $different)['comparable'], 'completed required');
        $different = $after;
        unset($different['environment']);
        check(!Reports::compare($pair, $different)['comparable'], 'environment required');
        check(!Reports::compare($j, $j)['comparable'], 'legacy environment insufficient');
        $noData = $pair;
        foreach ($noData['results'] as &$r) {
            $r['status'] = 'failed';
            $r['error'] = 'navigation_failed';
        }
        unset($r);
        check(
            !Reports::compare($noData, $noData)['comparable'],
            'failed samples cannot form comparison',
        );
        $poison = $pair;
        $poison['cookie'] = 'secret';
        $poison['profile']['password'] = 'secret';
        $poison['versions']['token'] = 'secret';
        $poison['pages'][0]['private_note'] = 'secret';
        $poison['results'][0]['raw_headers'] = 'secret';
        $poison['environment']['authorization'] = 'secret';
        $projection = Reports::export($poison);
        check(
            !str_contains(json_encode($projection), 'secret') && Queue::valid($projection),
            'export whitelist ignores nested injected secrets',
        );
        reject(
            fn() => Reports::export(array_replace($pair, ['status' => 'queued'])),
            'export terminal only',
        );
        file_put_contents($dir . '/job-' . $j['id'] . '.json', json_encode($j));
        $no_store = false;
        check(
            PageNest\Performance\export_data($q, $j['id'])['id'] === $j['id'] && $no_store,
            'private export no-store',
        );
        $can = false;
        reject(fn() => PageNest\Performance\export_data($q, $j['id']), 'export permission');
        $can = true;
        $nonce = false;
        reject(fn() => PageNest\Performance\export_data($q, $j['id']), 'export nonce');
        $nonce = true;
        reject(fn() => PageNest\Performance\export_data($q, '../../secret'), 'export strict id');
        ob_start();
        PageNest\Performance\comparison($pair, $after);
        $html = ob_get_clean();
        check(
            !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'),
            'comparison XSS escaped',
        );
        for ($i = count($q->reports()); $i < 10; $i++) {
            $copy = $j;
            $copy['id'] = bin2hex(random_bytes(16));
            $copy['status'] = 'cancelled';
            $p = $dir . '/job-' . $copy['id'] . '.json';
            file_put_contents($p, json_encode($copy));
            chmod($p, 0600);
        }
        reject(fn() => $q->start(['post:1'], 'desktop', 1), 'count budget');
        $files = glob($dir . '/job-*.json');
        unlink($files[0]);
        file_put_contents($dir . '/large', str_repeat('x', 5 * 1024 * 1024));
        chmod($dir . '/large', 0600);
        reject(fn() => $q->start(['post:1'], 'desktop', 1), 'byte budget');
        unlink($dir . '/large');
        $path = $files[1];
        file_put_contents($path, str_repeat('x', Queue::LIMIT + 1));
        reject(fn() => $q->reports(), 'oversized report');
        echo "Performance contracts: $checks checks\n";
    }
} finally {
    foreach (glob($dir . '/*') as $p) {
        unlink($p);
    }
    rmdir($dir);
}
