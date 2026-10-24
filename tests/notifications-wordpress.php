<?php
/** Run only with wp eval-file in a fresh, disposable synthetic WordPress lab. */
if (
    PHP_SAPI !== 'cli' ||
    !defined('ABSPATH') ||
    !defined('PAGENEST_NOTIFICATIONS_TEST_LAB') ||
    !PAGENEST_NOTIFICATIONS_TEST_LAB
) {
    throw new RuntimeException('An explicitly marked disposable notification lab is required.');
}
function pn_notification_assert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function pn_notification_request(
    string $method,
    string $route,
    array $body = [],
    ?string $nonce = null,
) {
    $request = new WP_REST_Request($method, '/pagenest/v1/notifications' . $route);
    $request->set_header('X-WP-Nonce', $nonce ?? wp_create_nonce('wp_rest'));
    if ($method === 'GET') {
        $request->set_query_params($body);
    } else {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
    }
    return rest_get_server()->dispatch($request);
}
$token = 'pn-notifications-' . wp_generate_password(8, false);
$reader = wp_create_user(
    $token . '-reader',
    wp_generate_password(),
    $token . '-reader@example.test',
);
$writer = wp_create_user(
    $token . '-writer',
    wp_generate_password(),
    $token . '-writer@example.test',
);
$post = wp_insert_post([
    'post_title' => 'Synthetic notification article',
    'post_content' => 'Synthetic public content.',
    'post_status' => 'publish',
    'post_author' => $writer,
]);
$comment_ids = [];
$old_option = get_option('pagenest_companion_notifications', '0');
// Suppress all mail in this synthetic test, including unrelated WordPress hooks.
$mail_guard = static fn() => true;
add_filter('pre_wp_mail', $mail_guard);
$approval_guard = static fn() => 1;
$flood_guard = static fn() => false;
add_filter('pre_comment_approved', $approval_guard, 99);
add_filter('comment_flood_filter', $flood_guard, 99);
try {
    update_option('pagenest_companion_notifications', '1');
    $parent = wp_new_comment(
        [
            'comment_post_ID' => $post,
            'user_id' => $reader,
            'comment_author' => 'Synthetic Reader',
            'comment_content' => 'Parent',
            'comment_approved' => 1,
        ],
        true,
    );
    pn_notification_assert(!is_wp_error($parent), 'Synthetic parent submission');
    $comment_ids[] = $parent;
    for ($i = 0; $i < 12; $i++) {
        $comment_ids[] = wp_new_comment(
            [
                'comment_post_ID' => $post,
                'comment_parent' => $parent,
                'user_id' => $writer,
                'comment_author' => 'Synthetic Writer',
                'comment_content' => 'Reply ' . $i . ' ' . $token,
                'comment_approved' => 1,
            ],
            true,
        );
    }
    foreach ($comment_ids as $id) {
        pn_notification_assert(!is_wp_error($id), 'Synthetic comment submission');
    }
    wp_set_current_user($reader);
    $list = pn_notification_request('GET', '');
    pn_notification_assert(
        $list->get_status() === 200 &&
            count($list->get_data()['items']) === 10 &&
            $list->get_data()['recentUnread'] === 12,
        'Real WordPress event creation/pagination',
    );
    $page = pn_notification_request('GET', '', ['page' => 2]);
    pn_notification_assert(count($page->get_data()['items']) === 2, 'Second page');
    $id = $list->get_data()['items'][0]['id'];
    pn_notification_assert(
        pn_notification_request('POST', '/read', ['id' => $id])->get_status() === 200,
        'Read mutation',
    );
    wp_set_comment_status($id, 'hold');
    pn_notification_assert(
        pn_notification_request('POST', '/read', ['id' => $id])->get_status() === 404,
        'Approval revocation',
    );
    wp_set_comment_status($id, 'approve');
    pn_notification_assert(
        get_comment_meta($id, '_pagenest_notification_read', true) === '1',
        'Reapproval preserves read flag',
    );
    wp_set_current_user($writer);
    pn_notification_assert(
        pn_notification_request('POST', '/read', ['id' => $id])->get_status() === 404,
        'Ownership mutation',
    );
    wp_set_current_user($reader);
    pn_notification_assert(
        pn_notification_request('GET', '', [], 'invalid')->get_status() === 401,
        'Nonce required',
    );
    $request = new WP_REST_Request('GET', '/pagenest/v1/notifications');
    $response = apply_filters(
        'rest_post_dispatch',
        pn_notification_request('GET', '', [], 'invalid'),
        rest_get_server(),
        $request,
    );
    pn_notification_assert(
        str_contains($response->get_headers()['Cache-Control'] ?? '', 'no-store'),
        'Error no-store filter',
    );
    wp_update_post(['ID' => $post, 'post_password' => 'synthetic']);
    pn_notification_assert(
        count(pn_notification_request('GET', '')->get_data()['items']) === 0,
        'Password-protected post hidden',
    );
    wp_update_post(['ID' => $post, 'post_password' => '']);
    pn_notification_assert(
        pn_notification_request('POST', '/preference', ['enabled' => false])->get_data()[
            'enabled'
        ] === false,
        'Account opt-out',
    );
    $muted = wp_new_comment(
        [
            'comment_post_ID' => $post,
            'comment_parent' => $parent,
            'user_id' => $writer,
            'comment_author' => 'Synthetic Writer',
            'comment_content' => 'Muted ' . $token,
            'comment_approved' => 1,
        ],
        true,
    );
    $comment_ids[] = $muted;
    pn_notification_assert(
        get_comment_meta($muted, '_pagenest_notification_recipient', true) === '',
        'No future event while opted out',
    );
    wp_set_comment_status($comment_ids[1], 'spam');
    wp_set_comment_status($comment_ids[2], 'trash');
    $export = pagenest_notifications_privacy(get_userdata($reader)->user_email);
    pn_notification_assert(
        count($export['data']) === 13,
        'Native export includes preference and events',
    );
    $erase = pagenest_notifications_privacy(get_userdata($reader)->user_email, 1, true);
    pn_notification_assert(
        !is_wp_error($erase) && $erase['items_removed'] && get_comment($id),
        'Erase preserves original comments',
    );
    pn_notification_assert(
        get_comment_meta($comment_ids[1], '_pagenest_notification_event', true) === '' &&
            get_comment_meta($comment_ids[2], '_pagenest_notification_event', true) === '' &&
            get_comment($comment_ids[1]) &&
            get_comment($comment_ids[2]),
        'Erase includes spam/trash and preserves comments',
    );
    wp_set_comment_status($id, 'hold');
    wp_set_comment_status($id, 'approve');
    pn_notification_assert(
        get_comment_meta($id, '_pagenest_notification_recipient', true) === '',
        'Erase cannot regenerate historic event',
    );
    update_user_meta($reader, '_pagenest_notifications_off', '0');
    $deletion_reply = wp_new_comment(
        [
            'comment_post_ID' => $post,
            'comment_parent' => $parent,
            'user_id' => $writer,
            'comment_author' => 'Synthetic Writer',
            'comment_content' => 'Deletion ' . $token,
            'comment_approved' => 1,
        ],
        true,
    );
    pn_notification_assert(!is_wp_error($deletion_reply), 'User deletion seed');
    $comment_ids[] = $deletion_reply;
    wp_set_comment_status($deletion_reply, 'trash');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_set_current_user($writer);
    wp_delete_user($reader);
    pn_notification_assert(
        get_comment($deletion_reply) &&
            get_comment_meta($deletion_reply, '_pagenest_notification_event', true) === '' &&
            get_comment_meta($deletion_reply, '_pagenest_notification_owner', true) === '',
        'User deletion erases trashed notification metadata without deleting comments',
    );
    update_option('pagenest_companion_notifications', '0');
    pn_notification_assert(pn_notification_request('GET', '')->get_status() === 403, 'Module off');
    echo "Native WordPress notification integration passed.\n";
} finally {
    foreach ($comment_ids as $id) {
        if (!is_wp_error($id)) {
            wp_delete_comment($id, true);
        }
    }
    wp_delete_post($post, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($reader);
    wp_delete_user($writer);
    update_option('pagenest_companion_notifications', $old_option);
    remove_filter('pre_wp_mail', $mail_guard);
    remove_filter('pre_comment_approved', $approval_guard, 99);
    remove_filter('comment_flood_filter', $flood_guard, 99);
    wp_set_current_user(0);
}
