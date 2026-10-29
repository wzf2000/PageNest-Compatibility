<?php
/** New public paragraph comments notify only the current signed-in source author. */
defined('ABSPATH') || exit();
function pagenest_notifications_paragraph_enabled(): bool
{
    return pagenest_notifications_enabled() &&
        get_option('pagenest_companion_paragraph_notifications', '0') === '1' &&
        function_exists('pagenest_pc_enabled') &&
        pagenest_companion_feature('comments');
}
function pagenest_notifications_paragraph_visible($note, $event, int $user): bool
{
    if (
        !pagenest_notifications_paragraph_enabled() ||
        !$note ||
        $note->post_type !== pagenest_companion_config('paragraphs', 'post_type') ||
        $note->post_status !== 'private' ||
        !is_array($event) ||
        (int) ($event['recipient'] ?? 0) !== $user ||
        !get_userdata($user)
    ) {
        return false;
    }
    $data = get_post_meta($note->ID, pagenest_companion_config('paragraphs', 'data_meta'), true);
    if (
        !is_array($data) ||
        ($data['_entry_kind'] ?? '') !== 'comment' ||
        ($data['visibility'] ?? '') !== 'public' ||
        (int) ($data['post_id'] ?? 0) !== (int) ($event['post'] ?? 0) ||
        ($data['block_id'] ?? '') !== ($event['block'] ?? '') ||
        (int) $note->post_author !== (int) ($event['author'] ?? 0) ||
        (int) $note->post_author === $user ||
        !get_userdata($note->post_author)
    ) {
        return false;
    }
    $source = get_post($event['post']);
    return $source &&
        in_array($source->post_type, ['post', 'page'], true) &&
        $source->post_status === 'publish' &&
        $source->post_password === '' &&
        is_post_publicly_viewable($source) &&
        (int) $source->post_author === $user &&
        pagenest_pc_enabled($source->ID) &&
        (bool) pagenest_pc_live_block($source->ID, $event['block']);
}
function pagenest_notifications_paragraph_created(int $id): void
{
    if (
        !pagenest_notifications_paragraph_enabled() ||
        get_post_meta($id, '_pagenest_notification_decided', true) !== ''
    ) {
        return;
    }
    $note = get_post($id);
    $data = get_post_meta($id, pagenest_companion_config('paragraphs', 'data_meta'), true);
    $source = is_array($data) ? get_post($data['post_id'] ?? 0) : null;
    if (!$note || !$source) {
        return;
    }
    $user = (int) $source->post_author;
    $event = [
        'recipient' => $user,
        'author' => (int) $note->post_author,
        'post' => (int) $source->ID,
        'block' => $data['block_id'] ?? '',
        'created' => gmdate('c'),
    ];
    // Even an ineligible new submission cannot later be replayed as a fresh event.
    if (
        !add_post_meta($id, '_pagenest_notification_decided', '1', true) ||
        get_user_meta($user, '_pagenest_notifications_off', true) === '1' ||
        !pagenest_notifications_paragraph_visible($note, $event, $user)
    ) {
        return;
    }
    // Owner first: privacy jobs can retry erasing a partially written event.
    if (
        !add_post_meta($id, '_pagenest_notification_owner', $user, true) ||
        !add_post_meta($id, '_pagenest_notification_event', $event, true)
    ) {
        return;
    }
    add_post_meta($id, '_pagenest_notification_recipient', $user, true);
}
add_action('pagenest_pc_public_comment_created', 'pagenest_notifications_paragraph_created');
function pagenest_notifications_paragraph_items(int $user)
{
    if (!pagenest_notifications_paragraph_enabled()) {
        return [];
    }
    global $wpdb;
    $notes = get_posts([
        'post_type' => pagenest_companion_config('paragraphs', 'post_type'),
        'post_status' => 'any',
        'numberposts' => 100,
        'orderby' => 'ID',
        'order' => 'DESC',
        'meta_key' => '_pagenest_notification_recipient',
        'meta_value' => $user,
    ]);
    if ($wpdb->last_error !== '') {
        return pagenest_notifications_error('notifications_storage', 503);
    }
    $items = [];
    foreach ($notes as $note) {
        $event = get_post_meta($note->ID, '_pagenest_notification_event', true);
        if (!pagenest_notifications_paragraph_visible($note, $event, $user)) {
            continue;
        }
        $author = get_userdata($note->post_author);
        $items[] = [
            'kind' => 'paragraph_comment',
            'id' => (int) $note->ID,
            'title' => wp_strip_all_tags(get_the_title($event['post'])),
            'author' => wp_strip_all_tags($author->display_name),
            'url' => get_permalink($event['post']) . '#' . rawurlencode($event['block']),
            'created' => $event['created'],
            'read' => get_post_meta($note->ID, '_pagenest_notification_read', true) === '1',
        ];
    }
    return $items;
}
