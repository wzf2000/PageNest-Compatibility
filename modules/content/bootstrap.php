<?php
/** Optional content owner; never competes with the previous display owner. */
defined('ABSPATH') || exit();
require_once dirname(__DIR__, 2) . '/content-config.php';
$GLOBALS['pagenest_companion_content_profile'] = ['schema_version' => 1, 'modules' => []];
if (defined('PAGENEST_COMPANION_CONTENT_FILE')) {
    try {
        $GLOBALS['pagenest_companion_content_profile'] = pagenest_companion_load_content(
            PAGENEST_COMPANION_CONTENT_FILE,
            ABSPATH,
        );
    } catch (Throwable $error) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>PageNest Companion content profile is invalid; optional content modules are disabled.</p></div>';
        });
    }
}
foreach (pagenest_companion_content_config('invalid_modules', []) as $name) {
    add_action('admin_notices', static function () use ($name) {
        echo '<div class="notice notice-error"><p>PageNest Companion content module ' .
            esc_html($name) .
            ' has invalid configuration and is disabled.</p></div>';
    });
}
function pagenest_companion_content_bootstrap(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    if (
        !array_filter(
            pagenest_companion_content_config('modules', []),
            static fn($module) => !empty($module['enabled']),
        )
    ) {
        return;
    }
    if (
        class_exists('SiteExtensions\\Profile', false) ||
        function_exists('site_extensions_config')
    ) {
        $GLOBALS['pagenest_companion_content_profile']['modules'] = [];
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>PageNest Companion content modules are disabled while another content display owner is loaded.</p></div>';
        });
        return;
    }
    require_once __DIR__ . '/pages.php';
    require_once __DIR__ . '/archives.php';
    require_once __DIR__ . '/chapters.php';
}
if (function_exists('did_action') && did_action('plugins_loaded')) {
    pagenest_companion_content_bootstrap();
} else {
    add_action('plugins_loaded', 'pagenest_companion_content_bootstrap', 20);
}
