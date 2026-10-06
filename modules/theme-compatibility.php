<?php
/** Explicit aliases act only on rendered HTML; switching is the only migration trigger. */
defined('ABSPATH') || exit();
function pagenest_companion_migrate_theme($old_name = '', $old_theme = null)
{
    $config = pagenest_companion_config('theme');
    if (
        $config['legacy_stylesheet'] === '' ||
        get_option('stylesheet') !== 'pagenest' ||
        !$old_theme ||
        $old_theme->get_stylesheet() !== $config['legacy_stylesheet']
    ) {
        return;
    }
    $old = get_option('theme_mods_' . $config['legacy_stylesheet'], []);
    if (!is_array($old)) {
        return;
    }
    $existing = get_theme_mods();
    $existing = is_array($existing) ? $existing : [];
    foreach ($old as $key => $value) {
        if ($config['setting_prefix'] !== '' && str_starts_with($key, $config['setting_prefix'])) {
            $key = 'pagenest_' . substr($key, strlen($config['setting_prefix']));
        }
        if ($key === 'nav_menu_locations' && is_array($value)) {
            foreach ($config['menu_locations'] as $from => $to) {
                if (array_key_exists($from, $value)) {
                    if (!array_key_exists($to, $value)) {
                        $value[$to] = $value[$from];
                    }
                    unset($value[$from]);
                }
            }
            if (array_key_exists($key, $existing)) {
                if (!is_array($existing[$key])) {
                    continue;
                }
                $value = $existing[$key] + $value;
            }
        } elseif (array_key_exists($key, $existing)) {
            continue;
        }
        if (!array_key_exists($key, $existing) || $existing[$key] !== $value) {
            set_theme_mod($key, $value);
            $existing[$key] = $value;
        }
    }
}
add_action('after_switch_theme', 'pagenest_companion_migrate_theme', 100, 2);
add_filter(
    'the_content',
    static function ($html) {
        $aliases = pagenest_companion_config('theme', 'class_aliases');
        if (!$aliases || !class_exists('WP_HTML_Tag_Processor')) {
            return $html;
        }
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag()) {
            foreach ($aliases as $from => $to) {
                if ($tags->has_class($from)) {
                    $tags->add_class($to);
                }
            }
        }
        return $tags->get_updated_html();
    },
    30,
);
add_action('wp_enqueue_scripts', static function () {
    $aliases = pagenest_companion_config('theme', 'anchor_prefixes');
    if (!$aliases) {
        return;
    }
    wp_enqueue_script(
        'pagenest-anchor-aliases',
        plugins_url('../assets/anchor-aliases.js', __FILE__),
        [],
        '0.6.0-rc.1',
        true,
    );
    wp_localize_script('pagenest-anchor-aliases', 'PageNestAnchorAliases', $aliases);
});

/** The editor preview bypasses the_content; share aliases only with active theme assets. */
function pagenest_companion_content_aliases()
{
    $aliases = pagenest_companion_config('theme', 'class_aliases');
    if (!$aliases) {
        return;
    }
    foreach (['pagenest-content', 'pagenest-content-preview'] as $handle) {
        if (wp_script_is($handle, 'enqueued')) {
            wp_localize_script($handle, 'PageNestContentAliases', $aliases);
        }
    }
}
add_action('wp_enqueue_scripts', 'pagenest_companion_content_aliases', 1000);
add_action('admin_enqueue_scripts', 'pagenest_companion_content_aliases', 1000);
