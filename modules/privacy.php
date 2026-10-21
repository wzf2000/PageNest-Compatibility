<?php
/** Native privacy exports remain registered when optional feature switches are off. */
defined('ABSPATH') || exit();
function pagenest_privacy_user(string $email): int
{
    $email = sanitize_email($email);
    $user = $email !== '' ? get_user_by('email', $email) : false;
    return $user ? (int) $user->ID : 0;
}
function pagenest_privacy_item(string $group, int $id, array $values): array
{
    $labels = [
        'notes' => __('Companion paragraph comments and private notes', 'pagenest-companion'),
        'likes' => __('Companion likes', 'pagenest-companion'),
        'social' => __('Companion social connections', 'pagenest-companion'),
    ];
    $data = [];
    foreach ($values as $name => $value) {
        $data[] = [
            'name' => $name,
            'value' => htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
                true,
            ),
        ];
    }
    return [
        'group_id' => 'pagenest-' . $group,
        'group_label' => $labels[$group],
        'item_id' => 'pagenest-' . $group . '-' . $id,
        'data' => $data,
    ];
}
function pagenest_privacy_export_notes(string $email, int $page = 1)
{
    $user = pagenest_privacy_user($email);
    if (!$user) {
        return ['data' => [], 'done' => true];
    }
    if (!empty($GLOBALS['pagenest_companion_profile_invalid'])) {
        return new WP_Error(
            'pagenest_privacy_configuration',
            __(
                'Companion configuration is unavailable. Restore the valid configuration before exporting.',
                'pagenest-companion',
            ),
        );
    }
    $config = pagenest_companion_config('paragraphs');
    $query = new WP_Query([
        'post_type' => $config['post_type'],
        'post_status' => array_keys(get_post_stati()),
        'author' => $user,
        'orderby' => 'ID',
        'order' => 'ASC',
        'posts_per_page' => 21,
        'offset' => (max(1, $page) - 1) * 20,
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
    ]);
    $data = [];
    foreach (array_slice($query->posts, 0, 20) as $note) {
        if ((int) $note->post_author !== $user || $note->post_type !== $config['post_type']) {
            continue;
        }
        $stored = get_post_meta($note->ID, $config['data_meta'], true);
        $stored = is_array($stored) ? $stored : [];
        $values = [
            __('Record ID', 'pagenest-companion') => $note->ID,
            __('Visibility', 'pagenest-companion') =>
                ($stored['visibility'] ?? '') === 'public' ? 'public' : 'author_only',
        ];
        foreach (
            [
                'uuid' => __('Record UUID', 'pagenest-companion'),
                'post_id' => __('Target post ID', 'pagenest-companion'),
                'document_id' => __('Document identity', 'pagenest-companion'),
                'block_id' => __('Paragraph anchor', 'pagenest-companion'),
                'quote' => __('Saved quotation', 'pagenest-companion'),
                'text' => __('Record text', 'pagenest-companion'),
                'created_at' => __('Created at', 'pagenest-companion'),
                'previous_quote' => __('Previous saved quotation', 'pagenest-companion'),
                'previous_block_id' => __('Previous paragraph anchor', 'pagenest-companion'),
                'updated_at' => __('Updated at', 'pagenest-companion'),
            ]
            as $key => $label
        ) {
            if (is_string($stored[$key] ?? null) || is_int($stored[$key] ?? null)) {
                $values[$label] = $stored[$key];
            }
        }
        // Stored author-owned snapshots remain exportable even when their target is lost/private.
        $data[] = pagenest_privacy_item('notes', (int) $note->ID, $values);
    }
    return ['data' => $data, 'done' => count($query->posts) <= 20];
}
function pagenest_privacy_export_likes(string $email, int $page = 1)
{
    $user = pagenest_privacy_user($email);
    if (!$user) {
        return ['data' => [], 'done' => true];
    }
    if (!empty($GLOBALS['pagenest_companion_profile_invalid'])) {
        return new WP_Error(
            'pagenest_privacy_configuration',
            __(
                'Companion configuration is unavailable. Restore the valid configuration before exporting.',
                'pagenest-companion',
            ),
        );
    }
    global $wpdb;
    $key = pagenest_companion_config('likes', 'record_meta');
    // Fixed metadata pages; never export other users' entries or query the external legacy ledger.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT 21 OFFSET %d",
            $key,
            (max(1, $page) - 1) * 20,
        ),
    );
    if ($wpdb->last_error !== '') {
        return new WP_Error(
            'pagenest_privacy_storage',
            __(
                'Companion records could not be read. Retry the export after storage is available.',
                'pagenest-companion',
            ),
        );
    }
    $data = [];
    foreach (array_slice($rows, 0, 20) as $row) {
        $records = maybe_unserialize($row->meta_value);
        if (
            !is_array($records) ||
            !is_string($records[$user] ?? null) ||
            strlen($records[$user]) > 64
        ) {
            continue;
        }
        $data[] = pagenest_privacy_item('likes', (int) $row->meta_id, [
            __('Target post ID', 'pagenest-companion') => (int) $row->post_id,
            __('Liked at', 'pagenest-companion') => $records[$user],
        ]);
    }
    return ['data' => $data, 'done' => count($rows) <= 20];
}
function pagenest_privacy_export_social(string $email, int $page = 1)
{
    $user = pagenest_privacy_user($email);
    if (!$user) {
        return ['data' => [], 'done' => true];
    }
    $bindings = get_user_meta($user, '_pagenest_social_bindings', true);
    $bindings = is_array($bindings) ? $bindings : [];
    ksort($bindings, SORT_STRING);
    $offset = (max(1, $page) - 1) * 20;
    $data = [];
    $index = $offset;
    foreach (array_slice($bindings, $offset, 20, true) as $binding) {
        $index++;
        if (
            !is_array($binding) ||
            !in_array(
                $binding['provider'] ?? null,
                ['qq', 'baidu', 'sina', 'github', 'google', 'wechat'],
                true,
            ) ||
            !is_string($binding['subject'] ?? null) ||
            $binding['subject'] === ''
        ) {
            continue;
        }
        $data[] = pagenest_privacy_item('social', $index, [
            __('Provider', 'pagenest-companion') => $binding['provider'],
            __('Account subject SHA-256', 'pagenest-companion') => hash(
                'sha256',
                $binding['subject'],
            ),
        ]);
    }
    return ['data' => $data, 'done' => count($bindings) <= $offset + 20];
}
add_filter('wp_privacy_personal_data_exporters', static function (array $exporters): array {
    foreach (
        [
            'notes' => __('Companion paragraph comments and private notes', 'pagenest-companion'),
            'likes' => __('Companion likes', 'pagenest-companion'),
            'social' => __('Companion social connections', 'pagenest-companion'),
        ]
        as $name => $label
    ) {
        $exporters['pagenest-' . $name] = [
            'exporter_friendly_name' => $label,
            'callback' => 'pagenest_privacy_export_' . $name,
        ];
    }
    return $exporters;
});
