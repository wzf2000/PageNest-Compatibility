<?php
/** Presentation shared only by Companion's existing settings pages. */
defined('ABSPATH') || exit();
function pagenest_companion_admin_pages(): array
{
    return [
        'pagenest-companion' => ['阅读互动', 'pagenest_companion_settings_page'],
        'pagenest-discovery' => ['搜索与分享', 'pagenest_discovery_settings_page'],
        'pagenest-media' => ['媒体与文件夹', 'pagenest_companion_media_settings_page'],
        'pagenest-social' => ['社交登录', 'pagenest_social_settings_page'],
    ];
}
function pagenest_companion_admin_header(string $title, string $description, string $active): void
{
    echo '<div class="wrap pn-admin"><header class="pn-admin-header"><p class="pn-admin-eyebrow">PAGENEST / COMPANION</p><h1>' .
        esc_html($title) .
        '</h1><p class="pn-admin-intro">' .
        esc_html($description) .
        '</p></header><nav class="pn-admin-nav" aria-label="Companion 设置">';
    foreach (pagenest_companion_admin_pages() as $slug => [$label, $callback]) {
        if (!function_exists($callback)) {
            continue;
        }
        echo '<a href="' .
            esc_url(admin_url('options-general.php?page=' . $slug)) .
            '"' .
            ($active === $slug ? ' aria-current="page"' : '') .
            '>' .
            esc_html($label) .
            '</a>';
    }
    echo '</nav>';
}
add_action('admin_enqueue_scripts', static function ($hook): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $screens = array_map(
        static fn($slug) => 'settings_page_' . $slug,
        array_keys(pagenest_companion_admin_pages()),
    );
    if (!in_array($hook, $screens, true)) {
        return;
    }
    $manifest = require __DIR__ . '/assets/manifest.php';
    wp_enqueue_style(
        'pagenest-companion-admin',
        plugins_url('assets/' . $manifest['admin_design_css'], __FILE__),
        [],
        null,
    );
    wp_enqueue_script(
        'pagenest-companion-admin',
        plugins_url('assets/' . $manifest['admin_design_js'], __FILE__),
        [],
        null,
        true,
    );
});
