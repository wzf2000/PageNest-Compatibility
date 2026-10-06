<?php
// Execute the actual enqueue hook and capture WordPress's inline script output.
define('ABSPATH', __DIR__);
$actions = $inline = $registered = $queue = [];
$scripts = (object) [
    'registered' => [
        'prism-core-js' => (object) ['src' => '/prism/components/prism-core.js', 'deps' => []],
        'prism-plugin-autoloader' => (object) [
            'src' => '/prism/plugins/autoloader/prism-autoloader.js',
            'deps' => ['prism-core-js'],
        ],
    ],
    'queue' => ['prism-plugin-autoloader'],
];
function add_action($name, $fn, ...$args)
{
    global $actions;
    $actions[$name][] = $fn;
}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function apply_filters($tag, $value, ...$args)
{
    return $value;
}
function current_theme_supports(...$args)
{
    return true;
}
function is_singular(...$args)
{
    return true;
}
function wp_scripts()
{
    global $scripts;
    return $scripts;
}
function wp_script_is($handle, ...$args)
{
    global $queue;
    return in_array($handle, $queue, true);
}
function wp_register_script($handle, $src, ...$args)
{
    global $registered;
    $registered[$handle] = $src;
}
function wp_enqueue_script($handle)
{
    global $queue;
    $queue[] = $handle;
}
function wp_add_inline_script($handle, $code, $position)
{
    global $inline;
    $inline[$handle][$position][] = $code;
}
function wp_json_encode($value)
{
    return json_encode($value);
}
function get_queried_object_id()
{
    return 0;
}
function get_post($id)
{
    return false;
}
function plugins_url($path, $file)
{
    return $path;
}
function wp_localize_script(...$args) {}
function is_user_logged_in()
{
    return false;
}
function rest_url($path)
{
    return '/api/' . $path;
}
function wp_login_url($url)
{
    return '/login';
}
function get_permalink()
{
    return '/article';
}
require dirname(__DIR__) . '/pagenest-compatibility.php';
$out = dirname(__DIR__) . '/.runtime/frontend';
@mkdir($out . '/prism', 0777, true);
function copy_tree($from, $to)
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_dir($from . '/' . $name)) {
            copy_tree($from . '/' . $name, $to . '/' . $name);
        } else {
            copy($from . '/' . $name, $to . '/' . $name);
        }
    }
}
copy_tree(dirname(__DIR__) . '/node_modules/prismjs/components', $out . '/prism/components');
copy_tree(
    dirname(__DIR__) . '/node_modules/prismjs/plugins/autoloader',
    $out . '/prism/plugins/autoloader',
);
foreach ([false, true] as $existing) {
    $queue = $existing ? ['mbb-math'] : [];
    $registered = $inline = [];
    foreach ($actions['wp_enqueue_scripts'] as $fn) {
        $fn();
    }
    if (in_array('pagenest-mathjax', $queue, true) === $existing) {
        throw new RuntimeException('MathJax deduplication failed');
    }
    $html =
        '<!doctype html><meta charset="utf-8"><pre><code class="language-python">def greet():\n    return "hello"</code></pre>';
    foreach (['prism-core-js', 'prism-plugin-autoloader'] as $handle) {
        $html .= '<script src="' . $scripts->registered[$handle]->src . '"></script>';
        foreach ($inline[$handle]['after'] ?? [] as $code) {
            $html .= '<script>' . $code . '</script>';
        }
    }
    $html .=
        '<script>window.fixture=' .
        json_encode([
            'math_handles' => $queue,
            'math_sources' => $registered,
            'math_inline' => $inline['pagenest-mathjax'] ?? [],
        ]) .
        ';</script>';
    file_put_contents($out . '/' . ($existing ? 'existing' : 'standalone') . '.html', $html);
}
echo "Browser fixture: MathJax ownership checks passed\n";
$manifest = require dirname(__DIR__) . '/assets/manifest.php';
copy_tree(dirname(__DIR__) . '/assets', $out . '/assets');
$paragraph_html =
    '<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/' .
    $manifest['comments_css'] .
    '"><body><main class="pagenest-comments-body"><h1>Synthetic ordinary article</h1><p data-pagenest-block="b-neutral" id="b-neutral">A stable synthetic paragraph.</p></main><script>window.PageNestComments={post:10,api:"/api/",nonce:"",user:0,loginUrl:"/login",displayName:"",avatarUrl:""};</script><script src="/assets/' .
    $manifest['comments_js'] .
    '"></script></body></html>';
file_put_contents($out . '/paragraph.html', $paragraph_html);
