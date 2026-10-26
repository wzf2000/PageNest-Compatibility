<?php
/** Settings navigation keeps registered routes and administrator-only access. */
define('ABSPATH', __DIR__);
$hooks = $enqueued = [];
$authorized = true;
function __($text, ...$args)
{
    return $text;
}
function add_action($name, $callback, $priority = 10, $accepted = 1)
{
    $GLOBALS['hooks'][$name][$priority][] = $callback;
}
function add_filter(...$args)
{
    add_action(...$args);
}
function add_shortcode(...$args) {}
class AdminAccessDenied extends RuntimeException {}
function wp_die(...$args)
{
    throw new AdminAccessDenied();
}
function run_hooks($name, ...$args)
{
    $callbacks = $GLOBALS['hooks'][$name] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $callback) {
            $callback(...$args);
        }
    }
}
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function esc_html($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_attr__($text, ...$args)
{
    return esc_html($text);
}
function esc_html__($text, ...$args)
{
    return esc_html($text);
}
function esc_url($text)
{
    return esc_html($text);
}
function admin_url($path)
{
    return '/wp-admin/' . $path;
}
// Model WP's separate submenu, page-hook and capability-denial registries.
function add_options_page($title, $label, $capability, $slug, $callback)
{
    if (!current_user_can($capability)) {
        $GLOBALS['_wp_submenu_nopriv']['options-general.php'][$slug] = true;
        return false;
    }
    $hook = 'settings_page_' . $slug;
    $GLOBALS['submenu']['options-general.php'][] = [$label, $capability, $slug, $title];
    $GLOBALS['_registered_pages'][$hook] = true;
    $GLOBALS['page_callbacks'][$hook] = $callback;
    return $hook;
}
function remove_submenu_page($parent, $slug)
{
    foreach ($GLOBALS['submenu'][$parent] ?? [] as $index => $entry) {
        if ($entry[2] === $slug) {
            unset($GLOBALS['submenu'][$parent][$index]);
            return $entry;
        }
    }
    return false;
}
function current_user_can($capability)
{
    return $capability === 'manage_options' && $GLOBALS['authorized'];
}
function plugins_url($path, $file)
{
    return $path;
}
function wp_enqueue_style($handle, $source, ...$args)
{
    $GLOBALS['enqueued'][] = $source;
}
function wp_localize_script(...$args) {}
function wp_enqueue_script($handle, $source, ...$args)
{
    $GLOBALS['enqueued'][] = $source;
}
require dirname(__DIR__) . '/admin-design.php';
foreach (
    [
        'index.php',
        'upload.php',
        'settings_page_other',
        'settings_page_pagenest-companion',
        'settings_page_pagenest-discovery',
        'settings_page_pagenest-media',
        'settings_page_pagenest-social',
        'settings_page_pagenest-reading',
        'settings_page_pagenest-notifications',
        'settings_page_pagenest-guide',
        'settings_page_pagenest-diagnostics',
    ]
    as $screen
) {
    foreach ([true, false] as $authorized) {
        $enqueued = [];
        run_hooks('admin_enqueue_scripts', $screen);
        $expected = $authorized && str_starts_with($screen, 'settings_page_pagenest-') ? 2 : 0;
        if (count($enqueued) !== $expected) {
            throw new RuntimeException('Unexpected screen asset scope: ' . $screen);
        }
        foreach ($enqueued as $source) {
            if (!is_file(dirname(__DIR__) . '/' . $source)) {
                throw new RuntimeException('Missing content-hashed asset');
            }
        }
    }
}
echo "Admin role/screen isolation and generated assets passed.\n";

// An unloaded module must not acquire a navigation link just because its slug is known.
ob_start();
pagenest_companion_admin_header('Settings', 'Description', 'pagenest-companion');
$header = ob_get_clean();
check(!str_contains($header, '<a '), 'Unloaded modules leaked into navigation');
foreach (
    [
        'modules/settings.php',
        'social-settings.php',
        'modules/media/settings.php',
        'modules/discovery/bootstrap.php',
        'modules/reading/bootstrap.php',
        'modules/notifications/presentation.php',
        'modules/content/editor.php',
        'modules/diagnostics.php',
    ]
    as $file
) {
    require dirname(__DIR__) . '/' . $file;
}
$filter = $hooks['submenu_file'][10][0];
$parent_filter = $hooks['parent_file'][10][0];
$pagenow = 'options-general.php';
foreach ([true, false] as $authorized) {
    $unrelated = ['Other settings', 'manage_options', 'other-plugin', 'Other settings'];
    $submenu = [
        'options-general.php' => [$unrelated],
        'upload.php' => [['Media folders', 'upload_files', 'pagenest-media-folders']],
        'tools.php' => [['Performance', 'manage_options', 'pagenest-performance']],
        'themes.php' => [['Appearance', 'edit_theme_options', 'pagenest-appearance']],
    ];
    $original_other_menus = array_slice($submenu, 1, null, true);
    $_registered_pages = $_wp_submenu_nopriv = $page_callbacks = [];
    run_hooks('admin_menu');
    $entries = array_values($submenu['options-general.php']);
    check($entries[0] === $unrelated, 'Unrelated settings changed');
    check(array_slice($submenu, 1, null, true) === $original_other_menus, 'Other menus changed');
    check(count($entries) === ($authorized ? 2 : 1), 'Settings menu was not consolidated');
    if ($authorized) {
        check(
            $entries[1] === [
                'PageNest settings',
                'manage_options',
                'pagenest-companion',
                'PageNest settings',
            ],
            'Unified menu label or capability changed',
        );
    }
    if ($authorized) {
        $registered_callbacks = $page_callbacks;
    }
    foreach (pagenest_companion_admin_pages() as $slug => [$label, $callback]) {
        $hook = 'settings_page_' . $slug;
        check(
            isset($_registered_pages[$hook]) === $authorized,
            'Legacy page registration changed: ' . $slug,
        );
        check(
            isset($_wp_submenu_nopriv['options-general.php'][$slug]) !== $authorized,
            'Capability denial changed: ' . $slug,
        );
        $plugin_page = $slug;
        check(
            $parent_filter('') === ($authorized ? 'options-general.php' : ''),
            'Hidden page parent inference failed: ' . $slug,
        );
        check(
            $filter($slug, 'options-general.php') === ($authorized ? 'pagenest-companion' : $slug),
            'Unified menu highlight failed: ' . $slug,
        );
        check($filter('other', 'tools.php') === 'other', 'Highlight escaped Settings');
        if (!$authorized) {
            ob_start();
            try {
                $registered_callbacks[$hook]();
            } catch (AdminAccessDenied $error) {
                // Some pages use WordPress' explicit access-denied response.
            }
            check(ob_get_clean() === '', 'Page rendered without manage_options: ' . $slug);
        }
    }
    $pagenow = 'tools.php';
    $plugin_page = 'pagenest-companion';
    check($parent_filter('tools.php') === 'tools.php', 'Parent highlight escaped Settings');
    check($filter('other', 'options-general.php') === 'other', 'Actual screen context was ignored');
    $pagenow = 'options-general.php';
    $plugin_page = 'other-plugin';
    check($parent_filter('') === '', 'Unrelated parent changed');
    check($filter(null, 'options-general.php') === null, 'Unrelated null highlight changed');
    $plugin_page = 'pagenest-performance';
    check(
        $filter('pagenest-performance', 'tools.php') === 'pagenest-performance',
        'Tool highlight changed',
    );
}
$authorized = true;
foreach (array_keys(pagenest_companion_admin_pages()) as $active) {
    ob_start();
    pagenest_companion_admin_header('Settings', 'Description', $active);
    $header = ob_get_clean();
    check(substr_count($header, '<a ') === 8, 'Missing settings navigation link');
    check(substr_count($header, 'aria-current="page"') === 1, 'Current page is ambiguous');
    check(
        str_contains($header, 'page=' . $active . '" aria-current="page"'),
        'Wrong current navigation link',
    );
    check(
        str_contains($header, 'aria-label="PageNest settings"'),
        'Navigation lacks a localized label',
    );
    check(!str_contains($header, 'role="tab'), 'Page navigation must use links, not ARIA tabs');
}
echo "Settings menu, legacy registrations, permission denials and navigation passed.\n";
