<?php
/** Front-end login destinations and an explicit way back from administration. */
defined('ABSPATH') || exit();
function pagenest_social_frontend_return()
{
    if (is_admin() || ($GLOBALS['pagenow'] ?? '') === 'wp-login.php' || wp_doing_ajax()) {
        return '';
    }
    $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '');
    if (!is_string($uri) || !str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
        return '';
    }
    $home = wp_parse_url(home_url('/'));
    $origin = $home['scheme'] . '://' . $home['host'];
    if (isset($home['port'])) {
        $origin .= ':' . $home['port'];
    }
    return pagenest_social_return($origin . $uri);
}
function pagenest_social_login_url_return($url, $redirect, $force_reauth)
{
    // Keep explicit targets, including admin and account-security reauthentication.
    if ($redirect !== '' || $force_reauth) {
        return $url;
    }
    $return = pagenest_social_frontend_return();
    return $return ? add_query_arg('redirect_to', rawurlencode($return), $url) : $url;
}
function pagenest_social_native_login_return($redirect, $requested, $user)
{
    if (is_wp_error($user) || !($user instanceof WP_User)) {
        return $redirect;
    }
    // No target: retain WordPress role-aware defaults, not a forced home redirect.
    // An explicit external target never becomes a login destination, even if allowlisted elsewhere.
    if (
        is_string($requested) &&
        str_starts_with($requested, '/') &&
        !str_starts_with($requested, '//')
    ) {
        $home = wp_parse_url(home_url('/'));
        $requested =
            $home['scheme'] .
            '://' .
            $home['host'] .
            (isset($home['port']) ? ':' . $home['port'] : '') .
            $requested;
    }
    return $requested !== '' ? pagenest_social_return($requested) : $redirect;
}
function pagenest_social_admin_back_to_site($bar)
{
    if (!is_admin() || !is_user_logged_in()) {
        return;
    }
    $bar->add_node([
        'id' => 'pagenest-back-to-site',
        'title' =>
            '<span class="ab-icon dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><span class="ab-label">返回网站</span>',
        'href' => home_url('/'),
        'meta' => ['title' => '返回网站'],
    ]);
}
function pagenest_social_admin_return_styles()
{
    // The core site-name menu is hidden at phone widths; keep the direct exit visible.
    echo '<style>#wpadminbar #wp-admin-bar-pagenest-back-to-site{display:block}#wpadminbar #wp-admin-bar-pagenest-back-to-site .ab-label{display:inline;position:static;clip-path:none;width:auto;height:auto;margin:0;overflow:visible;white-space:nowrap}#wpadminbar #wp-admin-bar-pagenest-back-to-site .ab-icon{font:20px/1 dashicons!important;width:20px;height:20px;padding:0;margin:0}#wpadminbar #wp-admin-bar-pagenest-back-to-site>.ab-item{display:flex;align-items:center;gap:4px}@media(max-width:782px){#wpadminbar #wp-admin-bar-pagenest-back-to-site>.ab-item{min-height:46px;padding:0 10px}}</style>';
}
add_filter('login_url', 'pagenest_social_login_url_return', 20, 3);
add_filter('login_redirect', 'pagenest_social_native_login_return', 20, 3);
add_action('admin_bar_menu', 'pagenest_social_admin_back_to_site', 40);
add_action('admin_head', 'pagenest_social_admin_return_styles');
