<?php
/** Account notifications are independent of comment email and paragraph records. */
defined('ABSPATH') || exit();
function pagenest_notifications_enabled(): bool
{
    return get_option('pagenest_companion_notifications', '0') === '1';
}
function pagenest_notifications_public($comment): bool
{
    if (
        !$comment ||
        (string) $comment->comment_approved !== '1' ||
        !in_array($comment->comment_type, ['', 'comment'], true)
    ) {
        return false;
    }
    $post = get_post($comment->comment_post_ID);
    return $post &&
        in_array($post->post_type, ['post', 'page'], true) &&
        $post->post_status === 'publish' &&
        $post->post_password === '' &&
        is_post_publicly_viewable($post);
}
/** Only comments submitted while enabled get a seed; approval never backfills old comments. */
function pagenest_notifications_submitted($id, $approved): void
{
    if (!pagenest_notifications_enabled()) {
        return;
    }
    $reply = get_comment($id);
    $parent = $reply ? get_comment($reply->comment_parent) : null;
    if (
        !$reply ||
        !$parent ||
        !$parent->user_id ||
        !get_userdata($parent->user_id) ||
        (int) $reply->user_id === (int) $parent->user_id ||
        get_user_meta($parent->user_id, '_pagenest_notifications_off', true) === '1'
    ) {
        return;
    }
    $seed = [
        'recipient' => (int) $parent->user_id,
        'parent' => (int) $parent->comment_ID,
        'author' => (int) $reply->user_id,
        'post' => (int) $reply->comment_post_ID,
        'created' => gmdate('c'),
    ];
    // Index the owner first so a partial write remains reachable by privacy jobs.
    if (!add_comment_meta($id, '_pagenest_notification_owner', (int) $parent->user_id, true)) {
        return;
    }
    if (!add_comment_meta($id, '_pagenest_notification_event', $seed, true)) {
        return;
    }
    if ((string) $approved === '1') {
        pagenest_notifications_approved('approved', '', $reply);
    }
}
function pagenest_notifications_approved($new, $old, $reply): void
{
    if ($new !== 'approved') {
        return;
    }
    $event = get_comment_meta($reply->comment_ID, '_pagenest_notification_event', true);
    if (
        !is_array($event) ||
        get_comment_meta($reply->comment_ID, '_pagenest_notification_decided', true) !== ''
    ) {
        return;
    }
    // A one-way decision prevents reapproval, preferences or moderation from rewinding an event.
    if (!add_comment_meta($reply->comment_ID, '_pagenest_notification_decided', '1', true)) {
        return;
    }
    if (
        pagenest_notifications_enabled() &&
        get_user_meta($event['recipient'], '_pagenest_notifications_off', true) !== '1' &&
        pagenest_notifications_visible($reply, $event, (int) $event['recipient'])
    ) {
        add_comment_meta(
            $reply->comment_ID,
            '_pagenest_notification_recipient',
            (int) $event['recipient'],
            true,
        );
    }
}
function pagenest_notifications_visible($reply, $event, int $user): bool
{
    if (
        !is_array($event) ||
        (int) ($event['recipient'] ?? 0) !== $user ||
        !get_userdata($user) ||
        !pagenest_notifications_public($reply)
    ) {
        return false;
    }
    $parent = get_comment($reply->comment_parent);
    return $parent &&
        pagenest_notifications_public($parent) &&
        (int) $parent->user_id === $user &&
        (int) $parent->comment_ID === (int) ($event['parent'] ?? 0) &&
        (int) $reply->user_id === (int) ($event['author'] ?? -1) &&
        (int) $reply->user_id !== $user &&
        (int) $reply->comment_post_ID === (int) ($event['post'] ?? 0) &&
        (int) $parent->comment_post_ID === (int) $reply->comment_post_ID;
}
add_action('comment_post', 'pagenest_notifications_submitted', 20, 2);
add_action('transition_comment_status', 'pagenest_notifications_approved', 20, 3);
function pagenest_notifications_error(string $code, int $status): WP_Error
{
    return new WP_Error(
        $code,
        __('Notifications could not be loaded. Try again.', 'pagenest-companion'),
        ['status' => $status],
    );
}
function pagenest_notifications_permission($request)
{
    if (!get_current_user_id() || !wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
        return pagenest_notifications_error('notifications_session', 401);
    }
    return pagenest_notifications_enabled()
        ? true
        : pagenest_notifications_error('notifications_disabled', 403);
}
/** A fixed recent window bounds both storage queries and access rechecks. */
function pagenest_notifications_projection(bool $count_only = false)
{
    global $wpdb;
    $user = get_current_user_id();
    $comments = get_comments([
        'status' => 'all',
        'type' => 'comment',
        'number' => 100,
        'orderby' => 'comment_ID',
        'order' => 'DESC',
        'meta_key' => '_pagenest_notification_recipient',
        'meta_value' => $user,
    ]);
    if ($wpdb->last_error !== '') {
        return pagenest_notifications_error('notifications_storage', 503);
    }
    $items = [];
    foreach ($comments as $reply) {
        $event = get_comment_meta($reply->comment_ID, '_pagenest_notification_event', true);
        if (!pagenest_notifications_visible($reply, $event, $user)) {
            continue;
        }
        $read = get_comment_meta($reply->comment_ID, '_pagenest_notification_read', true) === '1';
        $items[] = [
            'id' => (int) $reply->comment_ID,
            'kind' => 'comment_reply',
            'title' => wp_strip_all_tags(get_the_title($reply->comment_post_ID)),
            'author' => wp_strip_all_tags($reply->comment_author),
            'url' => get_comment_link($reply),
            'created' => $event['created'],
            'read' => $read,
        ];
    }
    $paragraphs = pagenest_notifications_paragraph_items($user);
    if ($paragraphs instanceof WP_Error) {
        return $paragraphs;
    }
    $items = array_merge($items, $paragraphs);
    usort(
        $items,
        static fn($a, $b) => strcmp($b['created'], $a['created']) ?:
        strcmp($a['kind'], $b['kind']) ?:
        $b['id'] <=> $a['id'],
    );
    $items = array_slice($items, 0, 100);
    $unread = count(array_filter($items, static fn($item) => !$item['read']));
    return ['items' => $count_only ? [] : $items, 'recentUnread' => $unread];
}
function pagenest_notifications_count($request)
{
    $projection = pagenest_notifications_projection(true);
    return $projection instanceof WP_Error
        ? $projection
        : new WP_REST_Response(['recentUnread' => $projection['recentUnread']]);
}
function pagenest_notifications_list($request)
{
    $projection = pagenest_notifications_projection();
    if ($projection instanceof WP_Error) {
        return $projection;
    }
    $filter = $request->get_param('filter') ?? 'all';
    if (!in_array($filter, ['all', 'unread'], true)) {
        return pagenest_notifications_error('notifications_input', 400);
    }
    $items =
        $filter === 'unread'
            ? array_values(array_filter($projection['items'], static fn($item) => !$item['read']))
            : $projection['items'];
    $user = get_current_user_id();
    $pages = max(1, (int) ceil(count($items) / 10));
    $page = max(1, min($pages, (int) $request->get_param('page')));
    return new WP_REST_Response([
        'items' => array_slice($items, ($page - 1) * 10, 10),
        'filter' => $filter,
        'page' => $page,
        'pages' => $pages,
        'recentUnread' => $projection['recentUnread'],
        'enabled' => get_user_meta($user, '_pagenest_notifications_off', true) !== '1',
    ]);
}
function pagenest_notifications_positive_id($value): bool
{
    return (is_int($value) && $value > 0) ||
        (is_string($value) &&
            preg_match('/^[1-9][0-9]*$/D', $value) === 1 &&
            filter_var($value, FILTER_VALIDATE_INT) !== false);
}
function pagenest_notifications_validate_item($item)
{
    if (
        !is_array($item) ||
        !pagenest_notifications_positive_id($item['id'] ?? null) ||
        !in_array($item['kind'] ?? '', ['comment_reply', 'paragraph_comment'], true)
    ) {
        return pagenest_notifications_error('notifications_input', 400);
    }
    $id = (int) $item['id'];
    $user = get_current_user_id();
    if ($item['kind'] === 'paragraph_comment') {
        $valid =
            (int) get_post_meta($id, '_pagenest_notification_recipient', true) === $user &&
            pagenest_notifications_paragraph_visible(
                get_post($id),
                get_post_meta($id, '_pagenest_notification_event', true),
                $user,
            );
    } else {
        $valid =
            (int) get_comment_meta($id, '_pagenest_notification_recipient', true) === $user &&
            pagenest_notifications_visible(
                get_comment($id),
                get_comment_meta($id, '_pagenest_notification_event', true),
                $user,
            );
    }
    return $valid
        ? ['kind' => $item['kind'], 'id' => $id]
        : pagenest_notifications_error('notifications_missing', 404);
}
function pagenest_notifications_write_read(array $item): bool
{
    $id = $item['id'];
    if ($item['kind'] === 'paragraph_comment') {
        update_post_meta($id, '_pagenest_notification_read', '1');
        return get_post_meta($id, '_pagenest_notification_read', true) === '1';
    }
    update_comment_meta($id, '_pagenest_notification_read', '1');
    return get_comment_meta($id, '_pagenest_notification_read', true) === '1';
}
function pagenest_notifications_read($request)
{
    $item = pagenest_notifications_validate_item([
        'id' => $request->get_param('id'),
        'kind' => $request->get_param('kind') ?? 'comment_reply',
    ]);
    if ($item instanceof WP_Error) {
        return $item;
    }
    return pagenest_notifications_write_read($item)
        ? new WP_REST_Response(['read' => true])
        : pagenest_notifications_error('notifications_storage', 503);
}
function pagenest_notifications_read_page($request)
{
    $input = $request->get_json_params();
    $items = $input['items'] ?? null;
    if (
        !is_array($items) ||
        array_keys($items) !== range(0, count($items) - 1) ||
        count($items) < 1 ||
        count($items) > 10
    ) {
        return pagenest_notifications_error('notifications_input', 400);
    }
    $validated = [];
    foreach ($items as $item) {
        $valid = pagenest_notifications_validate_item($item);
        if ($valid instanceof WP_Error) {
            return $valid;
        }
        $key = $valid['kind'] . ':' . $valid['id'];
        if (isset($validated[$key])) {
            return pagenest_notifications_error('notifications_input', 400);
        }
        $validated[$key] = $valid;
    }
    $written = [];
    foreach ($validated as $item) {
        if (!pagenest_notifications_write_read($item)) {
            return new WP_Error(
                'notifications_partial',
                __(
                    'Some notifications could not be marked as read. The list has been refreshed.',
                    'pagenest-companion',
                ),
                ['status' => 503, 'written' => $written],
            );
        }
        $written[] = $item;
    }
    return new WP_REST_Response(['read' => true, 'written' => $written]);
}
function pagenest_notifications_preference($request)
{
    $input = $request->get_json_params();
    if (!is_bool($input['enabled'] ?? null)) {
        return pagenest_notifications_error('notifications_input', 400);
    }
    $value = $input['enabled'] ? '0' : '1';
    update_user_meta(get_current_user_id(), '_pagenest_notifications_off', $value);
    return get_user_meta(get_current_user_id(), '_pagenest_notifications_off', true) === $value
        ? new WP_REST_Response(['enabled' => $input['enabled']])
        : pagenest_notifications_error('notifications_storage', 503);
}
add_action('rest_api_init', static function () {
    foreach (
        [
            '' => ['GET', 'pagenest_notifications_list'],
            '/count' => ['GET', 'pagenest_notifications_count'],
            '/read-page' => ['POST', 'pagenest_notifications_read_page'],
            '/read' => ['POST', 'pagenest_notifications_read'],
            '/preference' => ['POST', 'pagenest_notifications_preference'],
        ]
        as $suffix => [$method, $callback]
    ) {
        register_rest_route('pagenest/v1', '/notifications' . $suffix, [
            'methods' => $method,
            'permission_callback' => 'pagenest_notifications_permission',
            'callback' => $callback,
            'args' =>
                $suffix === '/read'
                    ? [
                        'id' => [
                            'required' => true,
                            'validate_callback' => static fn(
                                $value,
                            ) => pagenest_notifications_positive_id($value),
                        ],
                    ]
                    : [],
        ]);
    }
});
// Runs after REST converts errors too, including cookie-authentication nonce errors.
add_filter(
    'rest_post_dispatch',
    static function ($response, $server, $request) {
        if (str_starts_with($request->get_route(), '/pagenest/v1/notifications')) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Vary', 'Cookie');
        }
        return $response;
    },
    20,
    3,
);
require_once __DIR__ . '/paragraph.php';
require_once __DIR__ . '/presentation.php';
require_once __DIR__ . '/privacy.php';
