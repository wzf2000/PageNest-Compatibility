<?php
/** One owner for permission checks, historical deduplication, records and counts. */
defined('ABSPATH') || exit();
function pagenest_companion_legacy_like($user_id, $post_id)
{
    global $wpdb;
    $config = pagenest_companion_config('likes', 'legacy_provider');
    if ($config['table_suffix'] === '') {
        return false;
    }
    // Identifiers were validated when the profile loaded; values remain placeholders.
    $table = $wpdb->prefix . $config['table_suffix'];
    $where = ['`' . $config['user_column'] . '` = %d'];
    $values = [(int) $user_id];
    if ($config['post_column'] !== '') {
        $where[] = '`' . $config['post_column'] . '` = %d';
        $values[] = (int) $post_id;
    }
    if ($config['event_column'] !== '') {
        $where[] = '`' . $config['event_column'] . '` = %s';
        $values[] = $config['event_value'];
    }
    if ($config['record_column'] !== '') {
        $where[] = '`' . $config['record_column'] . '` = %s';
        $values[] = $config['record_prefix'] . $user_id . ':' . $post_id;
    }
    $found = $wpdb->get_var(
        $wpdb->prepare(
            'SELECT 1 FROM `' . $table . '` WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            ...$values,
        ),
    );
    if ($wpdb->last_error !== '') {
        return new WP_Error('like_history_unavailable', '历史点赞记录暂不可读取。', [
            'status' => 503,
        ]);
    }
    return (string) $found === '1';
}
function pagenest_companion_like($user_id, $post_id)
{
    global $wpdb;
    $user_id = (int) $user_id;
    $post_id = (int) $post_id;
    $post = get_post($post_id);
    if ($user_id < 1 || $user_id !== get_current_user_id()) {
        return new WP_Error('login_required', '请先登录。', ['status' => 401]);
    }
    if (
        !$post ||
        $post->post_type !== 'post' ||
        $post->post_status !== 'publish' ||
        !pagenest_companion_can_read($post)
    ) {
        return new WP_Error('like_denied', '无法点赞这篇文章。', ['status' => 403]);
    }
    if ((int) $post->post_author === $user_id) {
        return new WP_Error('like_self', '不能给自己的文章点赞。', ['status' => 403]);
    }
    $lock =
        'pagenest-like:' .
        substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $post_id), 0, 45);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $lock)) !== '1') {
        return new WP_Error('like_busy', '请稍后重试。', ['status' => 503]);
    }
    $config = pagenest_companion_config('likes');
    $result = null;
    $transaction = false;
    try {
        clean_post_cache($post_id);
        // Recheck visibility/author inside the serialized write boundary.
        $post = get_post($post_id);
        if (
            !$post ||
            $post->post_status !== 'publish' ||
            !pagenest_companion_can_read($post) ||
            (int) $post->post_author === $user_id
        ) {
            return new WP_Error('like_denied', '无法点赞这篇文章。', ['status' => 403]);
        }
        $history = pagenest_companion_legacy_like($user_id, $post_id);
        if (is_wp_error($history)) {
            return $history;
        }
        $stored = get_post_meta($post_id, $config['record_meta'], true);
        if ($stored !== '' && !is_array($stored)) {
            return new WP_Error('like_storage_invalid', '点赞记录暂不可读取。', ['status' => 503]);
        }
        $raw_count = get_post_meta($post_id, $config['counter_meta'], true);
        if (
            $raw_count !== '' &&
            (!is_scalar($raw_count) || !preg_match('/^[0-9]+$/D', (string) $raw_count))
        ) {
            return new WP_Error('like_storage_invalid', '点赞计数暂不可读取。', ['status' => 503]);
        }
        $records = is_array($stored) ? $stored : [];
        $count = max(0, (int) get_post_meta($post_id, $config['counter_meta'], true));
        if ($history || isset($records[$user_id])) {
            $result = ['post' => $post_id, 'count' => $count, 'already_liked' => true];
        } else {
            // Crash-safe updates require a transactional metadata table. Reject unsupported engines.
            $engine = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $wpdb->postmeta,
                ),
            );
            if (strtoupper((string) $engine) !== 'INNODB') {
                return new WP_Error('like_storage_unavailable', '点赞存储暂不可写。', [
                    'status' => 503,
                ]);
            }
            if ($wpdb->query('START TRANSACTION') === false) {
                return new WP_Error('like_storage_unavailable', '点赞存储暂不可写。', [
                    'status' => 503,
                ]);
            }
            $transaction = true;
            $records[$user_id] = gmdate('c');
            $record_saved =
                $stored === ''
                    ? add_post_meta($post_id, $config['record_meta'], $records, true)
                    : update_post_meta($post_id, $config['record_meta'], $records, $stored);
            $count_saved = $record_saved
                ? update_post_meta($post_id, $config['counter_meta'], $count + 1)
                : false;
            if (!$record_saved || !$count_saved || $wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                clean_post_cache($post_id);
                return new WP_Error('like_save_failed', '点赞保存失败，请重试。', [
                    'status' => 503,
                ]);
            }
            $transaction = false;
            $result = ['post' => $post_id, 'count' => $count + 1, 'already_liked' => false];
        }
    } finally {
        if ($transaction) {
            $wpdb->query('ROLLBACK');
            clean_post_cache($post_id);
        }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
    // Experience/account extensions subscribe independently after the durable record commits.
    do_action('pagenest_like_recorded', $user_id, $post_id, $result['count']);
    return $result;
}
function pagenest_companion_like_button($id)
{
    $post = get_post($id);
    if (
        !$post ||
        $post->post_type !== 'post' ||
        $post->post_status !== 'publish' ||
        !pagenest_companion_can_read($post)
    ) {
        return;
    }
    echo '<button type="button" class="pagenest-like" data-pagenest-like="' .
        absint($id) .
        '">点赞 <span>' .
        absint(get_post_meta($id, pagenest_companion_config('likes', 'counter_meta'), true)) .
        '</span></button>';
}
add_action('pagenest_article_actions', 'pagenest_companion_like_button');
add_action('rest_api_init', static function () {
    register_rest_route('pagenest-companion/v1', '/likes/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => static fn() => is_user_logged_in()
            ? true
            : new WP_Error('login_required', '请先登录。', ['status' => 401]),
        'callback' => static fn($request) => pagenest_companion_like(
            get_current_user_id(),
            absint($request['id']),
        ),
    ]);
});
add_action('wp_enqueue_scripts', static function () {
    if (!is_singular('post') || !pagenest_companion_can_read(get_post(get_queried_object_id()))) {
        return;
    }
    $assets = require dirname(__DIR__) . '/assets/manifest.php';
    wp_enqueue_script(
        'pagenest-likes',
        plugins_url('../assets/' . $assets['likes_js'], __FILE__),
        [],
        null,
        true,
    );
    wp_localize_script('pagenest-likes', 'PageNestLikes', [
        'api' => rest_url('pagenest-companion/v1/likes/'),
        'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'loginUrl' => wp_login_url(get_permalink()),
    ]);
});
