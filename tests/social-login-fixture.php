<?php
/** In-memory login markup only: no WordPress connection, cookies, network or email. */
define('ABSPATH', __DIR__);
$GLOBALS['fixture_user'] = ($argv[1] ?? '') === 'authenticated' ? 42 : 0;
function pagenest_social_active()
{
    return $GLOBALS['fixture_enabled'] ?? true;
}
function pagenest_social_provider_config($provider)
{
    return in_array($provider, ['qq', 'baidu', 'sina'], true) ||
        ($GLOBALS['argv'][1] ?? '') === 'all-providers'
        ? ['client_id' => 'synthetic']
        : null;
}
function is_user_logged_in()
{
    return (bool) get_current_user_id();
}
function get_current_user_id()
{
    return $GLOBALS['fixture_user'];
}
function plugin_dir_url($file)
{
    return 'https://example.test/wp-content/plugins/pagenest-compatibility/';
}
function esc_html($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function esc_attr($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function esc_url($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function pagenest_social_return($url)
{
    return $url ?: 'https://example.test/';
}
function pagenest_social_query_url($params, $url)
{
    return $url . '?' . http_build_query($params);
}
function admin_url($path)
{
    return 'https://example.test/wp-admin/' . $path;
}
function get_edit_profile_url($id)
{
    return admin_url('profile.php');
}
function wp_login_url($return = '')
{
    return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode($return);
}
function wp_logout_url($return = '')
{
    return 'https://example.test/wp-login.php?action=logout&_wpnonce=synthetic&redirect_to=' .
        rawurlencode($return);
}
require __DIR__ . '/../modules/social/ui.php';
if (($argv[1] ?? '') === 'check') {
    $GLOBALS['fixture_user'] = 0;
    $anonymous = pagenest_social_login_panel();
    if (
        substr_count($anonymous, 'pagenest_social_start') !== 3 ||
        str_contains($anonymous, 'action=logout')
    ) {
        throw new RuntimeException('Anonymous provider buttons missing.');
    }
    $GLOBALS['fixture_user'] = 42;
    $authenticated = pagenest_social_login_panel();
    if (
        !str_contains($authenticated, '你已登录') ||
        !str_contains($authenticated, 'profile.php') ||
        !str_contains($authenticated, 'action=logout') ||
        str_contains($authenticated, 'pagenest_social_start')
    ) {
        throw new RuntimeException('Authenticated account notice missing.');
    }
    if (pagenest_social_buttons() !== '') {
        throw new RuntimeException('Authenticated comment login prompt changed.');
    }
    $GLOBALS['fixture_enabled'] = false;
    if (pagenest_social_login_panel() !== '') {
        throw new RuntimeException('Disabled service displayed a panel.');
    }
    echo "Social login markup: anonymous, authenticated, comment and disabled branches passed.\n";
    return;
}
echo pagenest_social_login_panel();
