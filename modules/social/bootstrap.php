<?php
/** Optional social service. Loading this file does not enable it or migrate users. */
defined('ABSPATH') || exit();
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/identities.php';
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/migration.php';
require_once __DIR__ . '/ui.php';
add_action('admin_post_nopriv_pagenest_social_start', 'pagenest_social_start');
add_action('admin_post_pagenest_social_start', 'pagenest_social_start');
add_action('admin_post_pagenest_social_unbind', 'pagenest_social_unbind_route');
add_action('admin_post_nopriv_pagenest_social_callback', 'pagenest_social_callback');
add_action('admin_post_pagenest_social_callback', 'pagenest_social_callback');
// The same state handles the historical homepage/provider callback without changing its URI.
add_action('init', 'pagenest_social_callback', 5);
add_action('login_form', static function () {
    echo pagenest_social_login_panel(wp_unslash($_REQUEST['redirect_to'] ?? ''));
});
add_action('register_form', static function () {
    echo pagenest_social_login_panel();
});
add_action('comment_form_after', static function () {
    echo pagenest_social_buttons(get_permalink());
});
add_action('show_user_profile', 'pagenest_social_profile');
add_action('admin_footer', 'pagenest_social_profile_forms');
add_filter('get_avatar_data', 'pagenest_social_avatar', 100, 2);

require_once __DIR__ . '/share.php';
add_filter('the_content', 'pagenest_social_share', 30);
add_action('wp_enqueue_scripts', 'pagenest_social_share_assets');
require_once __DIR__ . '/placeholder-mail.php';

add_action('login_enqueue_scripts', 'pagenest_social_ui_assets');
add_action('wp_enqueue_scripts', 'pagenest_social_ui_assets');
