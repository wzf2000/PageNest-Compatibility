<?php
/** New notification regressions; run only in the explicitly marked disposable lab. */
if (
    PHP_SAPI !== 'cli' ||
    !defined('ABSPATH') ||
    !defined('PAGENEST_NOTIFICATIONS_TEST_LAB') ||
    !PAGENEST_NOTIFICATIONS_TEST_LAB
) {
    throw new RuntimeException('An explicitly marked disposable notification lab is required.');
}
function pn_followup_assert($condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
}
function pn_followup_request(string $method, string $suffix = '', array $input = [])
{
    $request = new WP_REST_Request($method, '/pagenest/v1/notifications' . $suffix);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    if ($method === 'GET') {
        $request->set_query_params($input);
    } else {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($input));
    }
    return rest_get_server()->dispatch($request);
}
$guard = static fn() => true;
add_filter('pre_wp_mail', $guard);
$token = 'pn-followups-' . wp_generate_password(8, false);
$owner = wp_create_user($token . '-owner', wp_generate_password(), $token . '-owner@example.test');
$writer = wp_create_user(
    $token . '-writer',
    wp_generate_password(),
    $token . '-writer@example.test',
);
$old = get_option('pagenest_companion_notifications', '0');
$old_paragraph = get_option('pagenest_companion_paragraph_notifications', '0');
$post = wp_insert_post([
    'post_title' => 'Synthetic paragraph notifications',
    'post_content' => '<p>Unique public notification paragraph.</p>',
    'post_status' => 'publish',
    'post_author' => $owner,
]);
$notes = [];
$comments = [];
try {
    update_option('pagenest_companion_notifications', '1');
    update_option('pagenest_companion_paragraph_notifications', '0');
    update_post_meta($post, '_pagenest_comments_enabled', '1');
    pagenest_pc_refresh($post);
    $registry = get_post_meta(
        $post,
        pagenest_companion_config('paragraphs', 'registry_meta'),
        true,
    );
    pn_followup_assert(!empty($registry[0]['id']), 'Live source paragraph exists');
    $block = $registry[0]['id'];
    $create = static function (bool $public = true, bool $import = false) use (
        $post,
        $block,
        &$notes,
    ) {
        $input = [
            'post_id' => $post,
            'block_id' => $block,
            'text' => 'Synthetic notification comment ' . wp_generate_uuid4(),
            'uuid' => wp_generate_uuid4(),
        ];
        if ($public) {
            $input['public_confirmed'] = true;
        }
        $saved = pagenest_pc_save($input, $import, $public);
        pn_followup_assert(!is_wp_error($saved), 'New synthetic paragraph record saved');
        $notes[] = (int) $saved['id'];
        return [$saved, $input];
    };
    wp_set_current_user($writer);
    [$off] = $create();
    pn_followup_assert(
        get_post_meta($off['id'], '_pagenest_notification_owner', true) === '',
        'Separate switch defaults off',
    );
    update_option('pagenest_companion_paragraph_notifications', '1');
    [$private] = $create(false);
    $private_data = get_post_meta(
        $private['id'],
        pagenest_companion_config('paragraphs', 'data_meta'),
        true,
    );
    $private_data['visibility'] = 'public';
    update_post_meta(
        $private['id'],
        pagenest_companion_config('paragraphs', 'data_meta'),
        $private_data,
    );
    pn_followup_assert(
        get_post_meta($private['id'], '_pagenest_notification_owner', true) === '',
        'Publishing old note does not create notification',
    );
    [$first, $input] = $create();
    $event = get_post_meta($first['id'], '_pagenest_notification_event', true);
    pn_followup_assert(
        is_array($event) && $event['recipient'] === $owner && !isset($event['text']),
        'New public comment creates minimal event',
    );
    $retry = pagenest_pc_save($input, false, true);
    pn_followup_assert(
        !is_wp_error($retry) &&
            $retry['id'] === $first['id'] &&
            count(get_post_meta($first['id'], '_pagenest_notification_event')) === 1,
        'UUID retry creates no extra event',
    );
    wp_set_current_user($owner);
    [$self] = $create();
    pn_followup_assert(
        get_post_meta($self['id'], '_pagenest_notification_owner', true) === '',
        'Authors do not notify themselves',
    );
    update_user_meta($owner, '_pagenest_notifications_off', '1');
    wp_set_current_user($writer);
    [$muted] = $create();
    pn_followup_assert(
        get_post_meta($muted['id'], '_pagenest_notification_owner', true) === '',
        'Recipient preference blocks paragraph events',
    );
    update_user_meta($owner, '_pagenest_notifications_off', '0');
    wp_set_current_user($owner);
    $list = pn_followup_request('GET')->get_data();
    pn_followup_assert(
        count($list['items']) === 1 &&
            $list['items'][0]['kind'] === 'paragraph_comment' &&
            str_ends_with($list['items'][0]['url'], '#' . rawurlencode($block)),
        'Typed paragraph notification uses stable existing fragment',
    );
    $typed = ['kind' => 'paragraph_comment', 'id' => $first['id']];
    pn_followup_assert(
        pn_followup_request('POST', '/read', ['id' => $first['id']])->get_status() === 404,
        'Numeric compatibility cannot cross into post IDs',
    );
    pn_followup_assert(
        pn_followup_request('POST', '/read-page', [
            'items' => [$typed, ['kind' => 'paragraph_comment', 'id' => $private['id']]],
        ])->get_status() === 404 &&
            get_post_meta($first['id'], '_pagenest_notification_read', true) === '',
        'Batch validates every item before any write',
    );
    foreach (
        [
            [],
            array_fill(0, 11, $typed),
            [$typed, $typed],
            [['id' => $first['id']]],
            [['kind' => 'bad', 'id' => 1]],
        ]
        as $bad
    ) {
        pn_followup_assert(
            pn_followup_request('POST', '/read-page', ['items' => $bad])->get_status() === 400,
            'Malformed batch rejected',
        );
    }
    pn_followup_assert(
        pn_followup_request('POST', '/read-page', ['items' => [$typed]])->get_status() === 200,
        'Typed page read succeeds',
    );
    $unread = pn_followup_request('GET', '', ['filter' => 'unread'])->get_data();
    pn_followup_assert(
        $unread['items'] === [] && $unread['recentUnread'] === 0,
        'Unread filter retains whole-window count',
    );
    pn_followup_assert(
        pn_followup_request('GET', '', ['filter' => 'bad'])->get_status() === 400,
        'Unknown filter rejected',
    );
    delete_post_meta($first['id'], '_pagenest_notification_read');
    $data_key = pagenest_companion_config('paragraphs', 'data_meta');
    $original = get_post_meta($first['id'], $data_key, true);
    foreach (
        [
            'visibility' => 'author_only',
            '_entry_kind' => 'note',
            'block_id' => 'missing',
            'post_id' => 0,
        ]
        as $key => $value
    ) {
        update_post_meta($first['id'], $data_key, array_merge($original, [$key => $value]));
        pn_followup_assert(
            pn_followup_request('GET')->get_data()['items'] === [] &&
                pn_followup_request('POST', '/read', $typed)->get_status() === 404,
            'Changed paragraph binding/visibility retracts list and mutation',
        );
    }
    update_post_meta($first['id'], $data_key, $original);
    foreach (
        [['post_password' => 'synthetic'], ['post_status' => 'private'], ['post_author' => $writer]]
        as $change
    ) {
        wp_update_post(array_merge(['ID' => $post], $change));
        pn_followup_assert(
            pn_followup_request('GET', '/count')->get_data()['recentUnread'] === 0,
            'Source access/identity change retracts count',
        );
        wp_update_post([
            'ID' => $post,
            'post_password' => '',
            'post_status' => 'publish',
            'post_author' => $owner,
        ]);
    }
    update_option('pagenest_companion_paragraph_notifications', '0');
    pn_followup_assert(
        pn_followup_request('GET')->get_data()['items'] === [] &&
            pn_followup_request('POST', '/read', $typed)->get_status() === 404,
        'Paragraph switch disables historical projection',
    );
    update_option('pagenest_companion_paragraph_notifications', '1');
    // Seed a genuine ordinary reply and copy its event onto a comment sharing the note ID.
    $parent = wp_insert_comment([
        'comment_post_ID' => $post,
        'user_id' => $owner,
        'comment_approved' => 1,
    ]);
    $comments[] = $parent;
    $collision = wp_insert_comment([
        'comment_post_ID' => $post,
        'comment_parent' => $parent,
        'user_id' => $writer,
        'comment_author' => 'Synthetic Writer',
        'comment_approved' => 1,
    ]);
    $comments[] = $collision;
    pagenest_notifications_submitted($collision, 1);
    $ordinary = ['kind' => 'comment_reply', 'id' => $collision];
    pn_followup_assert(
        pn_followup_request('POST', '/read-page', [
            'items' => [$typed, $ordinary],
        ])->get_status() === 200 &&
            get_comment_meta($collision, '_pagenest_notification_read', true) === '1',
        'Mixed storage batch writes both kinds',
    );
    // Allocate a note with exactly the ordinary comment's numeric ID.
    $shared = $collision;
    while (get_post($shared)) {
        $shared = wp_insert_comment([
            'comment_post_ID' => $post,
            'comment_parent' => $parent,
            'user_id' => $writer,
            'comment_approved' => 1,
        ]);
        $comments[] = $shared;
    }
    pagenest_notifications_submitted($shared, 1);
    $shared_note = wp_insert_post([
        'import_id' => $shared,
        'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
        'post_status' => 'private',
        'post_author' => $writer,
        'meta_input' => [$data_key => $original],
    ]);
    $notes[] = $shared_note;
    pn_followup_assert($shared_note === $shared, 'Synthetic storage IDs collide');
    pagenest_notifications_paragraph_created($shared_note);
    pn_followup_assert(
        pn_followup_request('POST', '/read-page', [
            'items' => [
                ['kind' => 'comment_reply', 'id' => $shared],
                ['kind' => 'paragraph_comment', 'id' => $shared],
            ],
        ])->get_status() === 200 &&
            get_comment_meta($shared, '_pagenest_notification_read', true) === '1' &&
            get_post_meta($shared, '_pagenest_notification_read', true) === '1',
        'Same numeric ID keeps typed read storage distinct',
    );
    delete_post_meta($first['id'], '_pagenest_notification_read');
    delete_comment_meta($collision, '_pagenest_notification_read');
    $failure = static function ($check, $object, $key) use ($collision) {
        return $object === $collision && $key === '_pagenest_notification_read' ? false : $check;
    };
    add_filter('update_comment_metadata', $failure, 10, 3);
    $partial = pn_followup_request('POST', '/read-page', ['items' => [$typed, $ordinary]]);
    remove_filter('update_comment_metadata', $failure, 10);
    pn_followup_assert(
        $partial->get_status() === 503 &&
            count($partial->get_data()['data']['written']) === 1 &&
            get_post_meta($first['id'], '_pagenest_notification_read', true) === '1' &&
            get_comment_meta($collision, '_pagenest_notification_read', true) === '',
        'Partial batch failure reports exact committed subset',
    );
    wp_trash_post($first['id']);
    $export = pagenest_notifications_privacy(get_userdata($owner)->user_email);
    pn_followup_assert(
        !is_wp_error($export) &&
            count(
                array_filter(
                    $export['data'],
                    static fn($row) => $row['item_id'] ===
                        'pagenest-paragraph-notification-' . $first['id'],
                ),
            ) === 1,
        'Native export includes trashed paragraph metadata',
    );
    $delete_failure = static function ($check, $object, $key) use ($first) {
        return $object === $first['id'] && $key === '_pagenest_notification_event' ? false : $check;
    };
    add_filter('delete_post_metadata', $delete_failure, 10, 3);
    $erased = pagenest_notifications_privacy(get_userdata($owner)->user_email, 1, true);
    remove_filter('delete_post_metadata', $delete_failure, 10);
    pn_followup_assert(
        is_wp_error($erased) &&
            get_post_meta($first['id'], '_pagenest_notification_owner', true) !== '' &&
            get_user_meta($owner, '_pagenest_notifications_off', true) === '1',
        'Failed privacy erase retains owner for retry and opts out of future events',
    );
    $erased = pagenest_notifications_privacy(get_userdata($owner)->user_email, 1, true);
    pn_followup_assert(
        !is_wp_error($erased) &&
            $erased['done'] &&
            get_post($first['id']) &&
            get_post_meta($first['id'], $data_key, true) === $original &&
            get_post_meta($first['id'], '_pagenest_notification_owner', true) === '' &&
            get_post_meta($first['id'], '_pagenest_notification_decided', true) === '1',
        'Privacy retry removes notification payload only, preserving record and decision',
    );
    update_user_meta($owner, '_pagenest_notifications_off', '0');
    $window = [];
    for ($index = 0; $index < 105; $index++) {
        $id = wp_insert_post([
            'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
            'post_status' => 'private',
            'post_author' => $writer,
            'meta_input' => [$data_key => $original],
        ]);
        $notes[] = $id;
        $window[] = $id;
        $seed = array_merge($event, ['created' => gmdate('c', time() + $index + 1)]);
        add_post_meta($id, '_pagenest_notification_owner', $owner, true);
        add_post_meta($id, '_pagenest_notification_event', $seed, true);
        add_post_meta($id, '_pagenest_notification_recipient', $owner, true);
        add_post_meta($id, '_pagenest_notification_decided', '1', true);
        if ($index >= 5) {
            update_post_meta($id, '_pagenest_notification_read', '1');
        }
    }
    $projection = pn_followup_request('GET')->get_data();
    pn_followup_assert(
        $projection['pages'] === 10 &&
            $projection['recentUnread'] === 0 &&
            $projection['items'][0]['id'] === $window[104] &&
            pn_followup_request('GET', '', ['filter' => 'unread'])->get_data()['items'] === [],
        'Merged recent window truncates before unread filtering',
    );
    delete_post_meta($window[103], '_pagenest_notification_read');
    $filtered = pn_followup_request('GET', '', ['filter' => 'unread', 'page' => 10])->get_data();
    pn_followup_assert(
        count($filtered['items']) === 1 &&
            $filtered['items'][0]['id'] === $window[103] &&
            $filtered['page'] === 1 &&
            $filtered['recentUnread'] === 1,
        'Unread pagination clamps after recent-window filtering',
    );
    $exported = 0;
    $export_page = 1;
    do {
        $batch_export = pagenest_notifications_privacy(
            get_userdata($owner)->user_email,
            $export_page++,
        );
        pn_followup_assert(!is_wp_error($batch_export), 'Paged native privacy export succeeds');
        $exported += count(
            array_filter(
                $batch_export['data'],
                static fn($row) => str_starts_with(
                    $row['item_id'],
                    'pagenest-paragraph-notification-',
                ),
            ),
        );
    } while (!$batch_export['done'] && $export_page < 10);
    pn_followup_assert(
        $exported === 105 && $batch_export['done'],
        'Native privacy export traverses every postmeta page',
    );
    $erase_page = 1;
    do {
        $batch_erase = pagenest_notifications_privacy(
            get_userdata($owner)->user_email,
            $erase_page++,
            true,
        );
        pn_followup_assert(!is_wp_error($batch_erase), 'Paged native privacy erase succeeds');
    } while (!$batch_erase['done'] && $erase_page < 10);
    pn_followup_assert(
        $batch_erase['done'] &&
            get_post_meta($window[104], '_pagenest_notification_owner', true) === '' &&
            get_post_meta($window[0], '_pagenest_notification_owner', true) === '' &&
            get_post_meta($window[0], $data_key, true) === $original,
        'Native privacy erase drains all pages and preserves original notes',
    );
    echo "Native WordPress notification followups passed.\n";
} finally {
    foreach ($notes as $id) {
        wp_delete_post($id, true);
    }
    foreach ($comments as $id) {
        wp_delete_comment($id, true);
    }
    wp_delete_post($post, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($owner);
    wp_delete_user($writer);
    update_option('pagenest_companion_notifications', $old);
    update_option('pagenest_companion_paragraph_notifications', $old_paragraph);
    remove_filter('pre_wp_mail', $guard);
    wp_set_current_user(0);
}
