<?php
namespace PageNest\Tools;
defined('ABSPATH') || exit();
if (!defined(__NAMESPACE__ . '\\VERSION')) {
    define(__NAMESPACE__ . '\\VERSION', '1.2.0');
}

/** The explicit module switch is independent of the legacy profile constant. */
function enabled(): bool
{
    return defined('PAGENEST_COMPANION_TOOLS_ENABLED') && PAGENEST_COMPANION_TOOLS_ENABLED === true;
}

function legacyOwner(): bool
{
    if (class_exists('SiteTools\\Profile', false) || class_exists('SiteTools\\Host', false)) {
        return true;
    }
    $plugins = array_merge(
        (array) get_option('active_plugins', []),
        array_keys((array) get_site_option('active_sitewide_plugins', [])),
    );
    foreach ($plugins as $plugin) {
        if (is_string($plugin) && basename($plugin) === 'wp-tool-integration.php') {
            return true;
        }
    }
    return false;
}

function bootstrap(): void
{
    static $started = false;
    if ($started || !enabled() || legacyOwner()) {
        return;
    }
    $started = true;
    foreach (
        [
            'providers',
            'profile',
            'registry',
            'asset-loader',
            'host',
            'local-isolation',
            'diagnostics',
        ]
        as $file
    ) {
        require_once __DIR__ . '/' . $file . '.php';
    }
    foreach (
        ['Profile', 'Registry', 'Assets', 'Host', 'Providers', 'Diagnostics', 'LocalIsolation']
        as $class
    ) {
        class_alias(__NAMESPACE__ . '\\' . $class, 'SiteTools\\' . $class);
    }
    if (!defined('SiteTools\\VERSION')) {
        define('SiteTools\\VERSION', VERSION);
    }
    do_action('pagenest_tools_register_providers');
    if (
        !Profile::ready() ||
        !in_array(wp_parse_url(home_url(), PHP_URL_HOST), Profile::get('allowed_hosts', []), true)
    ) {
        return;
    }
    Providers::start();
    require_once __DIR__ . '/classic-preview.php';
    add_action('init', [Host::class, 'init']);
    add_filter(
        'rest_post_dispatch',
        static function ($response, $server, $request) {
            foreach (Profile::get('rest_namespaces', []) as $namespace) {
                if (str_starts_with($request->get_route(), '/' . $namespace . '/')) {
                    $response->header('Cache-Control', 'private, no-store');
                    $response->header('Vary', 'Cookie');
                    $response->header('X-Content-Type-Options', 'nosniff');
                    break;
                }
            }
            return $response;
        },
        10,
        3,
    );
    add_action('template_redirect', static function () {
        if (Registry::forPage((int) get_queried_object_id()) && is_user_logged_in()) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
            header('Cache-Control: private, no-store');
        }
    });
}
// All normal plugins have loaded before facade aliases are created.
add_action('plugins_loaded', __NAMESPACE__ . '\\bootstrap', 30);
