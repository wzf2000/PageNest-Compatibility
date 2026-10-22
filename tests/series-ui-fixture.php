<?php
/** Synthetic settings view using the real section and admin stylesheet. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function get_option($key, $default = false)
{
    return $key === 'pagenest_companion_series_directories'
        ? ['阅读与实践系列' => 2, '旧目录仍保留的系列' => 99]
        : $default;
}
function pagenest_companion_config($section, $key = null)
{
    return $key === 'series_directories' ? ['服务器系列' => 8] : ['D01' => '服务器系列'];
}
function wp_create_nonce($action)
{
    return 'synthetic-nonce';
}
function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function esc_html($value)
{
    return esc_attr($value);
}
function get_posts($query)
{
    return [(object) ['ID' => 2, 'post_title' => '阅读与实践目录']];
}
require dirname(__DIR__) . '/modules/series-settings.php';
?>
<!doctype html>
<html lang="zh-CN">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>阅读设置</title>
<style><?php readfile(dirname(__DIR__) . '/assets/admin-design.css'); ?></style>
<body>
    <main class="wrap pn-admin">
        <header class="pn-admin-header">
            <h1>阅读与互动</h1>
        </header>
        <div class="pn-admin-layout">
            <form class="pn-admin-main"><?php pagenest_companion_series_settings_fields(); ?><button type="submit" class="button button-primary">保存阅读设置</button></form>
            <aside class="pn-admin-help">
                <h2>先配置，再保存</h2>
                <p>关联公开目录，继续使用文章现有系列。</p>
            </aside>
        </div>
    </main>
</body>
</html>
