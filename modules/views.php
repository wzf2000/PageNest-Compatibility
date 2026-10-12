<?php
/** Optional page-render counters. No rewards, migrations, cookies or deferred endpoints. */
defined('ABSPATH') || exit();
function pagenest_companion_views_settings()
{
    $defaults = [
        'enabled' => 0,
        'count' => 0,
        'exclude_bots' => 0,
        'template' => '%VIEW_COUNT% 次浏览',
    ];
    $stored = get_option('pagenest_companion_views_settings', null);
    if ($stored === null) {
        return $defaults;
    }
    return pagenest_companion_views_sanitize($stored);
}
function pagenest_companion_views_sanitize($input)
{
    if (
        !is_array($input) ||
        !in_array($input['enabled'] ?? null, [0, 1, '0', '1'], true) ||
        !in_array($input['count'] ?? null, [0, 1, 2, '0', '1', '2'], true) ||
        !in_array($input['exclude_bots'] ?? null, [0, 1, '0', '1'], true) ||
        !is_string($input['template'] ?? null) ||
        strlen($input['template']) > 500
    ) {
        return [
            'enabled' => 0,
            'count' => 0,
            'exclude_bots' => 0,
            'template' => '%VIEW_COUNT% 次浏览',
        ];
    }
    return [
        'enabled' => (int) $input['enabled'],
        'count' => (int) $input['count'],
        'exclude_bots' => (int) $input['exclude_bots'],
        'template' => sanitize_text_field($input['template']),
    ];
}
function pagenest_companion_views_legacy_active()
{
    $owner = pagenest_companion_config('views', 'legacy_owner_function');
    return $owner !== '' && function_exists($owner);
}
function pagenest_companion_views_decimal($raw)
{
    if (!is_string($raw) || !preg_match('/^[0-9]+$/D', $raw)) {
        throw new RuntimeException('Invalid view counter.');
    }
    $normalized = ltrim($raw, '0');
    $normalized = $normalized === '' ? '0' : $normalized;
    $limit = (string) PHP_INT_MAX;
    if (
        strlen($normalized) > strlen($limit) ||
        (strlen($normalized) === strlen($limit) && strcmp($normalized, $limit) > 0)
    ) {
        throw new RuntimeException('View counter exceeds integer limit.');
    }
    return (int) $normalized;
}
function pagenest_companion_views_rows($id, $key)
{
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_id,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id",
            $id,
            $key,
        ),
        ARRAY_A,
    );
    if ($wpdb->last_error !== '' || !is_array($rows) || count($rows) > 1) {
        throw new RuntimeException('View metadata is unavailable or duplicated.');
    }
    if ($rows) {
        pagenest_companion_views_decimal($rows[0]['meta_value']);
    }
    return $rows;
}
function pagenest_companion_views_lock($id, $callback)
{
    $id = (int) $id;
    if ($id < 1) {
        throw new RuntimeException('Invalid view identity.');
    }
    global $wpdb;
    $name =
        'pagenest_views_' .
        substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $id), 0, 48);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $name)) !== '1') {
        throw new RuntimeException('View counter is busy.');
    }
    try {
        return $callback();
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}
function pagenest_companion_views_get($id)
{
    $rows = pagenest_companion_views_rows($id, '_pagenest_companion_views');
    $legacy = pagenest_companion_config('views', 'legacy_meta');
    if (!$rows && $legacy !== '') {
        $rows = pagenest_companion_views_rows($id, $legacy);
    }
    return $rows ? pagenest_companion_views_decimal($rows[0]['meta_value']) : 0;
}
function pagenest_companion_views_readable($id)
{
    $post = get_post($id);
    return $post &&
        in_array($post->post_type, ['post', 'page'], true) &&
        $post->post_status === 'publish' &&
        is_post_publicly_viewable($post) &&
        !wp_is_post_revision($id) &&
        !post_password_required($post);
}
function pagenest_companion_views_audience($settings)
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (is_string($agent) && preg_match('/(?:^|\s)PageNestPerformance\/1\.0(?:$|\s)/D', $agent)) {
        return false;
    }
    $user = get_current_user_id();
    if ($settings['count'] === 1 && ($user > 0 || !empty($_COOKIE[USER_COOKIE]))) {
        return false;
    }
    if ($settings['count'] === 2 && $user === 0) {
        return false;
    }
    if ($settings['exclude_bots']) {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        foreach (
            [
                'google',
                'msnbot',
                'ia_archiver',
                'lycos',
                'jeeves',
                'scooter',
                'fast-webcrawler',
                'slurp@inktomi',
                'turnitinbot',
                'technorati',
                'yahoo',
                'findexa',
                'findlinks',
                'gaisbo',
                'zyborg',
                'surveybot',
                'bloglines',
                'blogsearch',
                'pubsub',
                'syndic8',
                'userland',
                'gigabot',
                'become.com',
                'baiduspider',
                '360spider',
                'spider',
                'sosospider',
                'yandex',
                'AhrefsBot',
                'bingbot',
                'applebot',
                'GitCrawlerBot',
                'Bytespider',
                'BLEXBot',
            ]
            as $fragment
        ) {
            if (is_string($agent) && stripos($agent, $fragment) !== false) {
                return false;
            }
        }
    }
    return true;
}
function pagenest_companion_views_increment($id)
{
    $id = (int) $id;
    $settings = pagenest_companion_views_settings();
    if (
        !$settings['enabled'] ||
        pagenest_companion_views_legacy_active() ||
        (defined('WP_CACHE') && WP_CACHE) ||
        is_admin() ||
        is_preview() ||
        !pagenest_companion_views_readable($id) ||
        !pagenest_companion_views_audience($settings)
    ) {
        return new WP_Error('pagenest_views_excluded', 'This request is not counted.');
    }
    try {
        return pagenest_companion_views_lock($id, static function () use ($id) {
            global $wpdb;
            $key = '_pagenest_companion_views';
            $rows = pagenest_companion_views_rows($id, $key);
            $legacy = pagenest_companion_config('views', 'legacy_meta');
            $old = $legacy !== '' ? pagenest_companion_views_rows($id, $legacy) : [];
            $baseline = $old ? pagenest_companion_views_decimal($old[0]['meta_value']) : 0;
            $value = $rows ? pagenest_companion_views_decimal($rows[0]['meta_value']) : $baseline;
            if ($value < $baseline || $value === PHP_INT_MAX) {
                throw new RuntimeException('View counter baseline conflict or overflow.');
            }
            if (!$rows) {
                if (!add_post_meta($id, $key, $value + 1, true)) {
                    throw new RuntimeException('Could not create view counter.');
                }
            } elseif (
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->postmeta} SET meta_value=CAST(meta_value AS UNSIGNED)+1 WHERE meta_id=%d AND meta_value=%s",
                        (int) $rows[0]['meta_id'],
                        $rows[0]['meta_value'],
                    ),
                ) !== 1
            ) {
                throw new RuntimeException('Could not increment view counter.');
            }
            wp_cache_delete($id, 'post_meta');
            return $value + 1;
        });
    } catch (Throwable $error) {
        return new WP_Error('pagenest_views_unavailable', $error->getMessage());
    }
}
function pagenest_companion_views_process()
{
    static $seen = [];
    if (!is_singular(['post', 'page']) || is_feed()) {
        return;
    }
    $id = (int) get_queried_object_id();
    if (isset($seen[$id])) {
        return;
    }
    $seen[$id] = true;
    $result = pagenest_companion_views_increment($id);
    if (is_wp_error($result) && $result->get_error_code() === 'pagenest_views_unavailable') {
        error_log('PageNest Companion view counter unavailable.');
    }
}
function pagenest_companion_views_footer($id)
{
    $settings = pagenest_companion_views_settings();
    if (
        !$settings['enabled'] ||
        pagenest_companion_views_legacy_active() ||
        is_admin() ||
        is_preview() ||
        !pagenest_companion_views_readable($id)
    ) {
        return;
    }
    try {
        $text = str_replace(
            '%VIEW_COUNT%',
            number_format_i18n(pagenest_companion_views_get($id)),
            $settings['template'],
        );
        echo '<span>' . esc_html($text) . '</span>';
    } catch (Throwable $error) {
        error_log('PageNest Companion view display unavailable.');
    }
}
add_action('wp_head', 'pagenest_companion_views_process');
add_action('pagenest_article_actions', 'pagenest_companion_views_footer', 5);
add_action('admin_init', static function () {
    register_setting('pagenest_companion', 'pagenest_companion_views_settings', [
        'type' => 'array',
        'sanitize_callback' => 'pagenest_companion_views_sanitize',
    ]);
});
add_action('admin_notices', static function () {
    if (
        current_user_can('manage_options') &&
        pagenest_companion_views_settings()['enabled'] &&
        defined('WP_CACHE') &&
        WP_CACHE
    ) {
        echo '<div class="notice notice-warning"><p>PageNest Companion 浏览计数不支持页面缓存；WP_CACHE 已启用，计数已停止，已有记录保留。</p></div>';
    }
});
