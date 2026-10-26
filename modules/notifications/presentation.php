<?php
/** Notifications settings and an explicitly opened, memory-only account view. */
defined('ABSPATH') || exit();
add_action('admin_init', static function () {
    register_setting('pagenest_notifications', 'pagenest_companion_notifications', [
        'type' => 'string',
        'default' => '0',
        'sanitize_callback' => static fn($value) => current_user_can('manage_options') &&
        $value === '1'
            ? '1'
            : '0',
    ]);
});
function pagenest_notifications_settings(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    pagenest_companion_admin_header(
        __('Notifications', 'pagenest-companion'),
        __(
            'Replies to signed-in readers’ ordinary comments. Email settings are separate.',
            'pagenest-companion',
        ),
        'pagenest-notifications',
    );
    echo '<form method="post" action="options.php" class="pn-admin-section">';
    settings_fields('pagenest_notifications');
    echo '<input type="hidden" name="pagenest_companion_notifications" value="0"><label><input type="checkbox" name="pagenest_companion_notifications" value="1" ' .
        checked(pagenest_notifications_enabled(), true, false) .
        '> ' .
        esc_html__('Enable notifications', 'pagenest-companion') .
        '</label><p>' .
        esc_html__(
            'Disabled by default. Only new comment submissions are eligible; existing comments are not backfilled. Add [pagenest_notifications] to an existing page for other themes.',
            'pagenest-companion',
        ) .
        '</p>';
    submit_button();
    echo '</form></div>';
}
add_action('admin_menu', static function () {
    add_options_page(
        __('Notifications', 'pagenest-companion'),
        __('Notifications', 'pagenest-companion'),
        'manage_options',
        'pagenest-notifications',
        'pagenest_notifications_settings',
    );
});
function pagenest_notifications_markup(): string
{
    if (!pagenest_notifications_enabled()) {
        return '';
    }
    return '<section class="pn-notifications" data-pn-notifications><h2>' .
        esc_html__('Notifications', 'pagenest-companion') .
        '</h2><p>' .
        esc_html__('Recent replies to your comments.', 'pagenest-companion') .
        '</p><button type="button" data-pn-notifications-load hidden>' .
        esc_html__('Load notifications', 'pagenest-companion') .
        '</button><noscript>' .
        esc_html__('Enable JavaScript to load notifications.', 'pagenest-companion') .
        '</noscript><div data-pn-notifications-status role="status" aria-live="polite"></div><div data-pn-notifications-result></div></section>';
}
add_shortcode('pagenest_notifications', 'pagenest_notifications_markup');
add_action('pagenest_header_actions', static function () {
    if (pagenest_notifications_enabled() && is_user_logged_in()) {
        echo '<button type="button" class="pn-header-notifications" data-pn-open-notifications hidden aria-label="' .
            esc_attr__('Notifications', 'pagenest-companion') .
            '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="pn-notifications-label">' .
            esc_html__('Notifications', 'pagenest-companion') .
            '</span><span data-pn-notifications-badge hidden aria-hidden="true"></span></button>';
    }
});
add_action('wp_footer', static function () {
    if (
        pagenest_notifications_enabled() &&
        is_user_logged_in() &&
        current_theme_supports('pagenest-independent-layout')
    ) {
        echo '<dialog class="pn-notifications-dialog" aria-labelledby="pn-notifications-title"><div class="pn-notifications-toolbar"><h2 id="pn-notifications-title">' .
            esc_html__('Notifications', 'pagenest-companion') .
            '</h2><button type="button" data-pn-close-notifications>' .
            esc_html__('Close', 'pagenest-companion') .
            '</button></div>' .
            pagenest_notifications_markup() .
            '</dialog>';
    }
});
add_action('template_redirect', static function () {
    if (pagenest_notifications_enabled() && is_user_logged_in()) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }
});
add_action('wp_enqueue_scripts', static function () {
    if (!pagenest_notifications_enabled()) {
        return;
    }
    $post = get_queried_object();
    if (
        !(is_user_logged_in() && current_theme_supports('pagenest-independent-layout')) &&
        !($post && is_singular() && has_shortcode($post->post_content, 'pagenest_notifications'))
    ) {
        return;
    }
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    $base = dirname(__DIR__, 2) . '/pagenest-compatibility.php';
    wp_enqueue_script(
        'pagenest-notifications',
        plugins_url('assets/' . $manifest['notifications_js'], $base),
        [],
        null,
        true,
    );
    wp_enqueue_style(
        'pagenest-notifications',
        plugins_url('assets/' . $manifest['notifications_css'], $base),
        [],
        null,
    );
    wp_localize_script('pagenest-notifications', 'PageNestNotifications', [
        'api' => rest_url('pagenest/v1/notifications'),
        'countApi' => rest_url('pagenest/v1/notifications/count'),
        'readApi' => rest_url('pagenest/v1/notifications/read'),
        'preferenceApi' => rest_url('pagenest/v1/notifications/preference'),
        'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'login' => wp_login_url(get_permalink()),
        'text' => [
            'load' => __('Load notifications', 'pagenest-companion'),
            'refresh' => __('Refresh notifications', 'pagenest-companion'),
            'window' => __(
                'Only the latest 100 eligible replies are checked.',
                'pagenest-companion',
            ),
            'loading' => __('Loading notifications…', 'pagenest-companion'),
            'empty' => __('No recent replies to show.', 'pagenest-companion'),
            'error' => __('Notifications could not be loaded. Try again.', 'pagenest-companion'),
            'expired' => __('Your session expired. Sign in again.', 'pagenest-companion'),
            'off' => __('Notifications are currently unavailable.', 'pagenest-companion'),
            'login' => __('Sign in to view your notifications.', 'pagenest-companion'),
            'read' => __('Read', 'pagenest-companion'),
            'unread' => __('Unread', 'pagenest-companion'),
            'mark' => __('Mark as read', 'pagenest-companion'),
            'previous' => __('Previous', 'pagenest-companion'),
            'next' => __('Next', 'pagenest-companion'),
            'preference' => __('Receive future comment reply notifications', 'pagenest-companion'),
            'independent' => __(
                'This preference does not change comment email settings.',
                'pagenest-companion',
            ),
            'recent' => __('Unread in recent replies:', 'pagenest-companion'),
            'settings' => __('Notification preferences', 'pagenest-companion'),
            'saved' => __('Notification preference saved.', 'pagenest-companion'),
        ],
    ]);
});
