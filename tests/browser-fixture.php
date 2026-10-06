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
function get_option($key, $default = false)
{
    return $default;
}
function metadata_exists(...$args)
{
    return false;
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
// Use real sibling assets locally; the minimum reading-layout contract keeps CI independent.
$theme_assets = dirname(__DIR__, 2) . '/PageNest/assets';
@mkdir($out . '/theme', 0777, true);
$real_theme =
    !getenv('PAGENEST_TEST_MINIMAL_LAYOUT') &&
    is_file($theme_assets . '/layout.css') &&
    is_file($theme_assets . '/reading.js');
if ($real_theme) {
    foreach (['layout.css', 'reading.js'] as $asset) {
        copy($theme_assets . '/' . $asset, $out . '/theme/' . $asset);
    }
} else {
    file_put_contents(
        $out . '/theme/layout.css',
        <<<'CSS'
        body { margin: 0; font: 16px/1.7 sans-serif; }
        .pagenest-main { max-width: 1240px; margin: auto; padding: 36px 24px 72px; }
        .pagenest-main * { box-sizing: border-box; }
        .pagenest-reading-grid { display: grid; grid-template-columns: minmax(0, 1fr) 240px; gap: 30px; align-items: start; }
        .pagenest-article-column { min-width: 0; }
        .pagenest-sidebar { align-self: stretch; min-width: 0; }
        .pagenest-reading-rail { position: sticky; top: 94px; max-height: calc(100dvh - 110px); overflow: auto; display: flex; flex-direction: column; gap: 18px; }
        .pagenest-reading-rail > * { flex: none; }
        @media (max-width: 1023px) {
          .pagenest-reading-grid { display: flex; flex-direction: column; }
          .pagenest-article-column, .pagenest-sidebar { width: 100%; }
          .pagenest-reading-rail { position: static; max-height: none; overflow: visible; display: contents; }
        }
        CSS
        ,
    );
    file_put_contents(
        $out . '/theme/reading.js',
        <<<'JS'
        const toc = document.querySelector('.pagenest-toc');
        toc.hidden = false;
        document.querySelectorAll('.pagenest-article-body :is(h2,h3,h4)').forEach((heading) => {
          const link = document.createElement('a');
          link.href = '#' + heading.id;
          link.textContent = heading.textContent;
          const row = document.createElement('div');
          row.append(link);
          toc.querySelector('nav').append(row);
        });
        JS
        ,
    );
}
$sections = '';
for ($section = 0; $section < 36; $section++) {
    $level = ($section % 3) + 2;
    $sections .=
        '<h' .
        $level .
        ' id="section-' .
        $section .
        '">Synthetic reading section ' .
        $section .
        '</h' .
        $level .
        '><p>Neutral article text for stable reading geometry. ' .
        str_repeat('This paragraph provides enough content to scroll the reading page. ', 6) .
        '</p>';
}
$reading_html =
    '<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
    '<link rel="stylesheet" href="/theme/layout.css"><link rel="stylesheet" href="/assets/' .
    $manifest['comments_css'] .
    '"><body class="pagenest-site">' .
    '<header class="pagenest-header"><div class="pagenest-nav-inner">Synthetic reading fixture</div></header>' .
    '<main class="pagenest-main"><div class="pagenest-reading-grid"><div class="pagenest-article-column">' .
    '<article class="pagenest-article"><h1 class="pagenest-entry-title">A deliberately long neutral article title to reveal reading column wrapping changes</h1>' .
    '<div class="pagenest-article-body pagenest-comments-body"><p data-pagenest-block="b-neutral" id="b-neutral">A stable synthetic paragraph.</p>' .
    $sections .
    '</div></article></div><aside class="pagenest-sidebar"><div class="pagenest-reading-rail">' .
    '<div class="pagenest-reading-actions" data-pagenest-reading-actions></div>' .
    '<div class="pagenest-reading-panel" data-pagenest-reading-panel hidden></div>' .
    '<details class="pagenest-toc" hidden open><summary>本文目录</summary><nav aria-label="本文目录"></nav></details>' .
    '<section class="pagenest-widget"><h2>相关阅读</h2><p>Neutral related article</p></section>' .
    '<section class="pagenest-widget"><h2>交流与更多</h2><p>Neutral community link</p></section>' .
    '</div></aside></div></main><script src="/theme/reading.js"></script>' .
    '<script>window.fixtureRealTheme=' .
    ($real_theme ? 'true' : 'false') .
    ';window.PageNestComments={post:10,api:"/api/",nonce:"synthetic-nonce",user:1,loginUrl:"/login",displayName:"Synthetic reader",avatarUrl:""};</script>' .
    '<script src="/assets/' .
    $manifest['comments_js'] .
    '"></script></body></html>';
file_put_contents($out . '/reading.html', $reading_html);
