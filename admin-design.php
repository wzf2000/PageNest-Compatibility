<?php
/** Navigation and presentation shared by Companion settings pages. */
defined('ABSPATH') || exit();
function pagenest_companion_admin_pages(): array
{
    return [
        'pagenest-companion' => [
            __('Reading and interaction', 'pagenest-companion'),
            'pagenest_companion_settings_page',
        ],
        'pagenest-discovery' => [
            __('Search and sharing', 'pagenest-companion'),
            'pagenest_discovery_settings_page',
        ],
        'pagenest-notifications' => [
            __('Notifications', 'pagenest-companion'),
            'pagenest_notifications_settings',
        ],
        'pagenest-reading' => [__('My reading', 'pagenest-companion'), 'pagenest_reading_markup'],
        'pagenest-guide' => [__('Content guide', 'pagenest-companion'), 'pagenest_guide_page'],
        'pagenest-diagnostics' => [
            __('Module diagnostics', 'pagenest-companion'),
            'pagenest_companion_diagnostics_page',
        ],
        'pagenest-media' => [
            __('Media and folders', 'pagenest-companion'),
            'pagenest_companion_media_settings_page',
        ],
        'pagenest-social' => [
            __('Social sign-in', 'pagenest-companion'),
            'pagenest_social_settings_page',
        ],
    ];
}
// Keep page registrations, load hooks and old URLs; hide only their duplicate menu links.
add_action(
    'admin_menu',
    static function (): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        foreach (array_keys(pagenest_companion_admin_pages()) as $slug) {
            if ($slug !== 'pagenest-companion') {
                remove_submenu_page('options-general.php', $slug);
            }
        }
    },
    99,
);
function pagenest_companion_is_settings_page(): bool
{
    global $pagenow, $plugin_page;
    $page = pagenest_companion_admin_pages()[$plugin_page ?? ''] ?? null;
    return $pagenow === 'options-general.php' &&
        $page &&
        function_exists($page[1]) &&
        current_user_can('manage_options');
}
// Hidden submenu links no longer let core infer their parent from the visible menu.
add_filter('parent_file', static function ($parent_file) {
    return pagenest_companion_is_settings_page() ? 'options-general.php' : $parent_file;
});
add_filter(
    'submenu_file',
    static function ($submenu_file, $parent_file) {
        return $parent_file === 'options-general.php' && pagenest_companion_is_settings_page()
            ? 'pagenest-companion'
            : $submenu_file;
    },
    10,
    2,
);
function pagenest_companion_admin_header(string $title, string $description, string $active): void
{
    echo '<div class="wrap pn-admin"><header class="pn-admin-header"><p class="pn-admin-eyebrow">' .
        esc_html__('PageNest settings', 'pagenest-companion') .
        '</p><h1>' .
        esc_html($title) .
        '</h1><p class="pn-admin-intro">' .
        esc_html($description) .
        '</p></header><nav class="pn-admin-nav" aria-label="' .
        esc_attr__('PageNest settings', 'pagenest-companion') .
        '">';
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
    $quality = $hook === 'media_page_pagenest-media-quality' && current_user_can('upload_files');
    if (!current_user_can('manage_options') && !$quality) {
        return;
    }
    $screens = array_map(
        static fn($slug) => 'settings_page_' . $slug,
        array_keys(pagenest_companion_admin_pages()),
    );
    if (!in_array($hook, $screens, true) && !$quality) {
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
    wp_localize_script('pagenest-companion-admin', 'PageNestAdmin', [
        'unsaved' => __('Unsaved changes.', 'pagenest-companion'),
    ]);
});
