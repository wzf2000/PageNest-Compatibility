<?php
/** Native privacy jobs remove only notification metadata, never original comments. */
defined('ABSPATH') || exit();
function pagenest_notifications_privacy(string $email, int $page = 1, bool $erase = false)
{
    $user = get_user_by('email', $email);
    if (!$user) {
        return $erase
            ? [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ]
            : ['data' => [], 'done' => true];
    }
    global $wpdb;
    // Indexed owner metadata includes pending seeds. Erasure always takes the first
    // remaining batch; export uses stable offsets without modifying the result set.
    $comments = get_comments([
        'status' => 'any',
        'type' => 'all',
        'number' => 51,
        'offset' => $erase ? 0 : (max(1, $page) - 1) * 50,
        'orderby' => 'comment_ID',
        'order' => 'ASC',
        'meta_key' => '_pagenest_notification_owner',
        'meta_value' => (int) $user->ID,
    ]);
    if ($wpdb->last_error !== '') {
        return pagenest_notifications_error('notifications_storage', 503);
    }
    $data = [];
    $removed = false;
    $retained = false;
    foreach (array_slice($comments, 0, 50) as $comment) {
        $event = get_comment_meta($comment->comment_ID, '_pagenest_notification_event', true);
        if (
            (int) get_comment_meta($comment->comment_ID, '_pagenest_notification_owner', true) !==
            (int) $user->ID
        ) {
            continue;
        }
        if ($erase) {
            $row_retained = false;
            foreach (
                [
                    '_pagenest_notification_recipient',
                    '_pagenest_notification_event',
                    '_pagenest_notification_read',
                ]
                as $key
            ) {
                $removed = delete_comment_meta($comment->comment_ID, $key) || $removed;
                $row_retained =
                    get_comment_meta($comment->comment_ID, $key, true) !== '' || $row_retained;
            }
            // Leave the owner index intact until all payload keys have been erased,
            // so a failed deletion can be retried even after its event disappeared.
            if (!$row_retained) {
                $removed =
                    delete_comment_meta($comment->comment_ID, '_pagenest_notification_owner') ||
                    $removed;
                $row_retained =
                    get_comment_meta($comment->comment_ID, '_pagenest_notification_owner', true) !==
                    '';
            }
            $retained = $retained || $row_retained;
        } else {
            if (!is_array($event) || (int) ($event['recipient'] ?? 0) !== (int) $user->ID) {
                continue;
            }
            $data[] = [
                'group_id' => 'pagenest-notifications',
                'group_label' => __('Notifications', 'pagenest-companion'),
                'item_id' => 'pagenest-notification-' . $comment->comment_ID,
                'data' => [
                    ['name' => 'Reply ID', 'value' => (string) $comment->comment_ID],
                    ['name' => 'Created', 'value' => esc_html($event['created'])],
                    [
                        'name' => 'Read',
                        'value' =>
                            get_comment_meta(
                                $comment->comment_ID,
                                '_pagenest_notification_read',
                                true,
                            ) === '1'
                                ? 'yes'
                                : 'no',
                    ],
                ],
            ];
        }
    }
    if ($erase) {
        update_user_meta($user->ID, '_pagenest_notifications_off', '1');
    }
    $paragraphs = pagenest_notifications_paragraph_privacy((int) $user->ID, $page, $erase);
    if ($paragraphs instanceof WP_Error) {
        return $paragraphs;
    }
    $data = array_merge($data, $paragraphs['data']);
    $removed = $removed || $paragraphs['removed'];
    if ($erase) {
        // Keep a nonidentifying one-way decision on comments; preferences cannot regenerate seeds.
        if (
            $wpdb->last_error !== '' ||
            get_user_meta($user->ID, '_pagenest_notifications_off', true) !== '1' ||
            $retained
        ) {
            return pagenest_notifications_error('notifications_storage', 503);
        }
        return [
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => [],
            'done' => count($comments) <= 50 && $paragraphs['done'],
        ];
    }
    if ($page === 1) {
        $data[] = [
            'group_id' => 'pagenest-notifications',
            'group_label' => __('Notifications', 'pagenest-companion'),
            'item_id' => 'pagenest-notification-preference',
            'data' => [
                [
                    'name' => __('Receive future comment notifications', 'pagenest-companion'),
                    'value' =>
                        get_user_meta($user->ID, '_pagenest_notifications_off', true) === '1'
                            ? 'no'
                            : 'yes',
                ],
            ],
        ];
    }
    return ['data' => $data, 'done' => count($comments) <= 50 && $paragraphs['done']];
}
add_filter('wp_privacy_personal_data_exporters', static function ($items) {
    $items['pagenest-notifications'] = [
        'exporter_friendly_name' => __('Notifications', 'pagenest-companion'),
        'callback' => 'pagenest_notifications_privacy',
    ];
    return $items;
});
add_filter('wp_privacy_personal_data_erasers', static function ($items) {
    $items['pagenest-notifications'] = [
        'eraser_friendly_name' => __('Notifications', 'pagenest-companion'),
        'callback' => static fn($email, $page) => pagenest_notifications_privacy(
            $email,
            $page,
            true,
        ),
    ];
    return $items;
});
// Core deletes usermeta; erase recipient metadata in bounded batches before the identity goes away.
add_action('delete_user', static function ($id) {
    $user = get_userdata($id);
    if ($user) {
        $page = 1;
        do {
            $result = pagenest_notifications_privacy($user->user_email, $page++, true);
            if (is_wp_error($result)) {
                break;
            }
        } while (!$result['done']);
    }
});

/** Owner-indexed postmeta jobs include partial events, independent of module switches. */
function pagenest_notifications_paragraph_privacy(int $user, int $page, bool $erase)
{
    global $wpdb;
    $notes = get_posts([
        'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
        'post_status' => array_values(get_post_stati()),
        'numberposts' => 51,
        'offset' => $erase ? 0 : (max(1, $page) - 1) * 50,
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_key' => '_pagenest_notification_owner',
        'meta_value' => $user,
    ]);
    if ($wpdb->last_error !== '') {
        return pagenest_notifications_error('notifications_storage', 503);
    }
    $data = [];
    $removed = false;
    $retained = false;
    foreach (array_slice($notes, 0, 50) as $note) {
        if ((int) get_post_meta($note->ID, '_pagenest_notification_owner', true) !== $user) {
            continue;
        }
        $event = get_post_meta($note->ID, '_pagenest_notification_event', true);
        if ($erase) {
            $remaining = false;
            foreach (
                [
                    '_pagenest_notification_recipient',
                    '_pagenest_notification_event',
                    '_pagenest_notification_read',
                ]
                as $key
            ) {
                $removed = delete_post_meta($note->ID, $key) || $removed;
                $remaining = get_post_meta($note->ID, $key, true) !== '' || $remaining;
            }
            if (!$remaining) {
                $removed = delete_post_meta($note->ID, '_pagenest_notification_owner') || $removed;
                $remaining = get_post_meta($note->ID, '_pagenest_notification_owner', true) !== '';
            }
            $retained = $retained || $remaining;
        } elseif (is_array($event) && (int) ($event['recipient'] ?? 0) === $user) {
            $data[] = [
                'group_id' => 'pagenest-notifications',
                'group_label' => __('Notifications', 'pagenest-companion'),
                'item_id' => 'pagenest-paragraph-notification-' . $note->ID,
                'data' => [
                    ['name' => 'Paragraph comment ID', 'value' => (string) $note->ID],
                    ['name' => 'Created', 'value' => esc_html($event['created'])],
                    [
                        'name' => 'Read',
                        'value' =>
                            get_post_meta($note->ID, '_pagenest_notification_read', true) === '1'
                                ? 'yes'
                                : 'no',
                    ],
                ],
            ];
        }
    }
    return $retained
        ? pagenest_notifications_error('notifications_storage', 503)
        : ['data' => $data, 'removed' => $removed, 'done' => count($notes) <= 50];
}
