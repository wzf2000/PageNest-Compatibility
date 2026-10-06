<?php
/** Scope known Prism resources only on code-free theme listing templates. */
defined('ABSPATH') || exit();
function pagenest_prism_has_external_dependency($registry, $targets)
{
    foreach ($registry->queue as $handle) {
        if (in_array($handle, $targets, true)) {
            continue;
        }
        $pending = [$handle];
        $seen = [];
        while ($pending) {
            $current = array_pop($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if (in_array($current, $targets, true)) {
                return true;
            }
            foreach ($registry->registered[$current]->deps ?? [] as $dependency) {
                $pending[] = $dependency;
            }
        }
    }
    return false;
}
function pagenest_scope_prism_assets()
{
    if (
        is_singular() ||
        is_admin() ||
        is_feed() ||
        is_customize_preview() ||
        !current_theme_supports('pagenest-independent-layout')
    ) {
        return;
    }
    if (
        !(
            is_front_page() ||
            is_home() ||
            is_category() ||
            is_tag() ||
            is_author() ||
            is_date() ||
            is_search()
        )
    ) {
        return;
    }
    if (apply_filters('pagenest_keep_listing_prism', false)) {
        return;
    }
    $scripts = [
        'copy-clipboard',
        'prism-core-js',
        'prism-plugin-autoloader',
        'prism-plugin-toolbar',
        'prism-plugin-line-numbers',
        'prism-plugin-show-language',
        'prism-plugin-copy-to-clipboard',
    ];
    $styles = [
        'prism-theme-default',
        'prism-theme-style',
        'prism-plugin-toolbar',
        'prism-plugin-line-numbers',
    ];
    $registry = wp_scripts();
    $components = pagenest_prism_components_path($registry, 'prism-plugin-autoloader');
    if ($components === '' || !str_ends_with($components, '/components/')) {
        return;
    }
    $base = substr($components, 0, -strlen('components/'));
    foreach (
        [
            ['registry' => $registry, 'handles' => $scripts],
            ['registry' => wp_styles(), 'handles' => $styles],
        ]
        as $group
    ) {
        foreach ($group['handles'] as $handle) {
            if (!isset($group['registry']->registered[$handle])) {
                continue;
            }
            $url = pagenest_prism_resource_url($group['registry'], $handle);
            $clipboard_base = preg_replace('~/[^/]+/$~', '/clipboard/', $base);
            if (
                $url === '' ||
                (!str_starts_with($url, $base) &&
                    !($handle === 'copy-clipboard' && str_starts_with($url, $clipboard_base)))
            ) {
                return;
            }
            if (
                in_array(
                    $handle,
                    array_merge($group['registry']->done ?? [], $group['registry']->to_do ?? []),
                    true,
                )
            ) {
                return;
            }
        }
        // Keep the full bundle when any other queued handle needs it, directly or indirectly.
        if (pagenest_prism_has_external_dependency($group['registry'], $group['handles'])) {
            return;
        }
    }
    foreach ($scripts as $handle) {
        wp_dequeue_script($handle);
    }
    foreach ($styles as $handle) {
        wp_dequeue_style($handle);
    }
}
add_action('wp_enqueue_scripts', 'pagenest_scope_prism_assets', 1000);
