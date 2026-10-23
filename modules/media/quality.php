<?php
/** Independent attachment text maintenance. Never moves, removes or reassigns files. */
defined('ABSPATH') || exit();
function pagenest_media_quality_attachment(int $id)
{
    $post = get_post($id);
    return $post &&
        $post->post_type === 'attachment' &&
        wp_attachment_is_image($id) &&
        current_user_can('upload_files') &&
        current_user_can('read_post', $id) &&
        current_user_can('edit_post', $id)
        ? $post
        : null;
}
/** Reject a stale form as a whole before writing either field. */
function pagenest_media_quality_save(int $id, array $input): string
{
    if (!pagenest_media_quality_attachment($id)) {
        return 'forbidden';
    }
    global $wpdb;
    foreach ([$wpdb->posts, $wpdb->postmeta] as $table) {
        $engine = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table,
            ),
        );
        if (strtoupper((string) $engine) !== 'INNODB') {
            return 'failed';
        }
    }
    if ($wpdb->query('START TRANSACTION') === false) {
        return 'failed';
    }
    try {
        // Serialize this page's saves per attachment, then re-read uncached old values.
        $locked = $wpdb->get_var(
            $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id),
        );
        if ((int) $locked !== $id) {
            $wpdb->query('ROLLBACK');
            return 'failed';
        }
        $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_image_alt' FOR UPDATE",
                $id,
            ),
        );
        if ($wpdb->last_error !== '') {
            $wpdb->query('ROLLBACK');
            return 'failed';
        }
        clean_post_cache($id);
        $result = pagenest_media_quality_update($id, $input);
        if ($wpdb->query($result === 'saved' ? 'COMMIT' : 'ROLLBACK') === false) {
            $wpdb->query('ROLLBACK');
            $result = 'failed';
        }
        clean_post_cache($id);
        return $result;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        clean_post_cache($id);
        return 'failed';
    }
}
function pagenest_media_quality_update(int $id, array $input): string
{
    $post = pagenest_media_quality_attachment($id);
    if (!$post) {
        return 'forbidden';
    }
    foreach (['alt', 'caption', 'expected_alt', 'expected_caption'] as $key) {
        if (!is_string($input[$key] ?? null) || strlen($input[$key]) > 20000) {
            return 'invalid';
        }
    }
    if (
        (string) get_post_meta($id, '_wp_attachment_image_alt', true) !== $input['expected_alt'] ||
        $post->post_excerpt !== $input['expected_caption']
    ) {
        return 'conflict';
    }
    $caption =
        $input['caption'] === $input['expected_caption']
            ? $post->post_excerpt
            : wp_kses_post($input['caption']);
    $alt =
        $input['alt'] === $input['expected_alt']
            ? $input['expected_alt']
            : sanitize_text_field($input['alt']);
    if ($caption !== $post->post_excerpt) {
        $saved = wp_update_post(wp_slash(['ID' => $id, 'post_excerpt' => $caption]), true);
        if (
            is_wp_error($saved) ||
            (int) $saved !== $id ||
            get_post($id)->post_excerpt !== $caption
        ) {
            return 'failed';
        }
    }
    if ($alt !== (string) get_post_meta($id, '_wp_attachment_image_alt', true)) {
        $saved = update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
        if ($saved === false) {
            return 'failed';
        }
        if ((string) get_post_meta($id, '_wp_attachment_image_alt', true) !== $alt) {
            return 'failed';
        }
    }
    return 'saved';
}
/** A bounded explicit submit; permissions, nonce, transaction and result remain per item. */
function pagenest_media_quality_submit(array $items, int $single = 0): array
{
    if (count($items) > 20) {
        return [];
    }
    $results = [];
    foreach ($items as $id => $input) {
        $id = absint($id);
        if (!$id || ($single && $single !== $id)) {
            continue;
        }
        if (
            !is_array($input) ||
            !is_string($input['nonce'] ?? null) ||
            !wp_verify_nonce($input['nonce'], 'pagenest-media-quality-' . $id)
        ) {
            $results[$id] = 'invalid';
            continue;
        }
        $results[$id] = pagenest_media_quality_save($id, $input);
    }
    return $results;
}
add_action('admin_post_pagenest_media_quality_save', static function (): void {
    $id = is_scalar($_POST['attachment'] ?? null) ? absint($_POST['attachment']) : 0;
    if (!pagenest_media_quality_attachment($id)) {
        wp_die(esc_html__('You cannot edit this attachment.', 'pagenest-companion'), '', [
            'response' => 403,
        ]);
    }
    check_admin_referer('pagenest-media-quality-' . $id);
    $status = pagenest_media_quality_save($id, wp_unslash($_POST));
    wp_safe_redirect(
        add_query_arg(
            ['page' => 'pagenest-media-quality', 'pn_result' => $status, 'pn_changed' => $id],
            admin_url('upload.php'),
        ),
    );
    exit();
});
/** Static references only; no rendering, callbacks, filesystem scans or URL requests. */
function pagenest_media_quality_references(string $content, int $id, array $urls): bool
{
    foreach ($urls as $url) {
        if (
            $url !== '' &&
            str_contains(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url)
        ) {
            return true;
        }
    }
    if (preg_match('/\bwp-image-' . $id . '(?![0-9])/', $content)) {
        return true;
    }
    $remaining = 500;
    $blocks = static function (array $items, int $depth = 0) use (
        &$blocks,
        &$remaining,
        $id,
    ): bool {
        if ($depth >= 30) {
            return false;
        }
        foreach ($items as $block) {
            if (--$remaining < 0) {
                return false;
            }
            $attrs = $block['attrs'] ?? [];
            if (
                in_array(
                    $block['blockName'],
                    [
                        'core/image',
                        'core/gallery',
                        'core/file',
                        'core/audio',
                        'core/video',
                        'core/cover',
                        'core/media-text',
                        'pagenest/gallery',
                        'pagenest/pdf',
                    ],
                    true,
                )
            ) {
                $ids = array_merge(is_array($attrs['ids'] ?? null) ? $attrs['ids'] : [], [
                    $attrs['id'] ?? 0,
                    $attrs['mediaId'] ?? 0,
                ]);
                if (in_array($id, array_map('intval', $ids), true)) {
                    return true;
                }
            }
            if ($blocks($block['innerBlocks'] ?? [], $depth + 1)) {
                return true;
            }
        }
        return false;
    };
    if ($blocks(parse_blocks($content))) {
        return true;
    }
    preg_match_all(
        '/' .
            get_shortcode_regex([
                'gallery',
                'pagenest_gallery',
                'pagenest_pdf',
                'wpmfpdf',
                'wpmfgallery',
            ]) .
            '/s',
        $content,
        $matches,
        PREG_SET_ORDER,
    );
    foreach (array_slice($matches, 0, 500) as $match) {
        if ($match[1] === '[' && $match[6] === ']') {
            continue;
        }
        $attrs = shortcode_parse_atts($match[3]);
        if (!is_array($attrs)) {
            continue;
        }
        if ($match[2] === 'wpmfgallery') {
            $gallery = pagenest_companion_media_gallery_settings($attrs);
            if ($gallery && in_array($id, $gallery['attachment_ids'], true)) {
                return true;
            }
        }
        $ids = preg_split(
            '/\s*,\s*/',
            (string) ($attrs['ids'] ?? ($attrs['include'] ?? ($attrs['id'] ?? ''))),
        );
        if (in_array($id, array_map('intval', $ids), true)) {
            return true;
        }
    }
    return false;
}
function pagenest_media_quality_reference_page(int $id, int $page): array
{
    $urls = array_filter([wp_get_attachment_url($id)]);
    foreach (array_slice(get_intermediate_image_sizes(), 0, 30) as $size) {
        $url = wp_get_attachment_image_url($id, $size);
        if ($url) {
            $urls[] = $url;
        }
    }
    $full_scope =
        current_user_can('read_private_posts') &&
        current_user_can('read_private_pages') &&
        current_user_can('edit_others_posts') &&
        current_user_can('edit_others_pages');
    $args = [
        'post_type' => ['post', 'page'],
        'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => 21,
        'offset' => ($page - 1) * 20,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
    ];
    if (!$full_scope) {
        $args['author'] = get_current_user_id();
    }
    $query = new WP_Query($args);
    $result = [
        'scope' => $full_scope ? 'site' : 'own',
        'matches' => [],
        'checked' => 0,
        'skipped' => 0,
        'more' => count($query->posts) > 20,
    ];
    foreach (array_slice($query->posts, 0, 20) as $post) {
        if (!current_user_can('read_post', $post->ID) || strlen($post->post_content) > 1000000) {
            $result['skipped']++;
            continue;
        }
        $result['checked']++;
        if (pagenest_media_quality_references($post->post_content, $id, $urls)) {
            $result['matches'][] = $post;
        }
    }
    return $result;
}
add_filter(
    'posts_where',
    static function ($where, $query) {
        if ($query->get('pagenest_quality_missing_caption') === true) {
            global $wpdb;
            $where .= " AND {$wpdb->posts}.post_excerpt = ''";
        }
        return $where;
    },
    10,
    2,
);
function pagenest_media_quality_page(): void
{
    if (!current_user_can('upload_files')) {
        return;
    }
    $submitted = $results = [];
    if (isset($_POST['pn_quality_nonce'])) {
        check_admin_referer('pagenest-media-quality-batch', 'pn_quality_nonce');
        $submitted =
            isset($_POST['items']) && is_array($_POST['items']) && count($_POST['items']) <= 20
                ? wp_unslash($_POST['items'])
                : [];
        $single = is_scalar($_POST['pn_save_one'] ?? null) ? absint($_POST['pn_save_one']) : 0;
        if ($single || ($_POST['pn_save_all'] ?? '') === '1') {
            $results = pagenest_media_quality_submit($submitted, $single);
        }
    }
    $filter = is_string($_GET['pn_missing'] ?? null) ? $_GET['pn_missing'] : '';
    $page = max(1, min(10000, absint($_GET['pn_quality_page'] ?? 1)));
    $args = [
        'post_type' => 'attachment',
        'post_status' => ['inherit', 'publish', 'private', 'draft', 'pending'],
        'post_mime_type' => 'image',
        'posts_per_page' => 21,
        'offset' => ($page - 1) * 20,
        'orderby' => 'ID',
        'order' => 'DESC',
        'no_found_rows' => true,
    ];
    if (in_array($filter, ['alt', 'both'], true)) {
        $args['meta_query'] = [
            'relation' => 'OR',
            ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
            ['key' => '_wp_attachment_image_alt', 'value' => ''],
        ];
    }
    if (in_array($filter, ['caption', 'both'], true)) {
        $args['pagenest_quality_missing_caption'] = true;
    }
    if ($submitted) {
        unset($args['meta_query'], $args['pagenest_quality_missing_caption']);
        $args['post__in'] = array_map('absint', array_keys($submitted));
        $args['orderby'] = 'post__in';
        $args['offset'] = 0;
        $args['posts_per_page'] = 20;
    }
    $query = new WP_Query($args);
    echo '<div class="wrap pn-admin"><header class="pn-admin-header"><h1>' .
        esc_html__('Media quality', 'pagenest-companion') .
        '</h1><p>' .
        esc_html__(
            'Review each image separately, then save one attachment or submit this page. File data, ownership and existing content stay in place.',
            'pagenest-companion',
        ) .
        '</p></header>';
    $messages = [
        'saved' => __('Attachment text saved.', 'pagenest-companion'),
        'conflict' => __(
            'This attachment changed after you opened the form. Reload and review its current text before saving.',
            'pagenest-companion',
        ),
        'invalid' => __('Text is invalid or too long.', 'pagenest-companion'),
        'failed' => __('Attachment text could not be saved.', 'pagenest-companion'),
        'forbidden' => __('You cannot edit this attachment.', 'pagenest-companion'),
    ];
    $result = is_string($_GET['pn_result'] ?? null) ? $_GET['pn_result'] : '';
    if (isset($messages[$result])) {
        echo '<div class="notice notice-' .
            ($result === 'saved' ? 'success' : 'error') .
            '"><p>' .
            esc_html($messages[$result]) .
            '</p></div>';
    }
    echo '<form method="get" class="pn-diagnostics-filters"><input type="hidden" name="page" value="pagenest-media-quality"><label for="pn-quality-filter">' .
        esc_html__('Missing text', 'pagenest-companion') .
        '<select id="pn-quality-filter" name="pn_missing">';
    foreach (
        [
            '' => __('All images', 'pagenest-companion'),
            'alt' => __('Missing alt text', 'pagenest-companion'),
            'caption' => __('Missing caption', 'pagenest-companion'),
            'both' => __('Missing both', 'pagenest-companion'),
        ]
        as $key => $label
    ) {
        echo '<option value="' .
            esc_attr($key) .
            '"' .
            selected($filter, $key, false) .
            '>' .
            esc_html($label) .
            '</option>';
    }
    echo '</select></label><button class="button">' .
        esc_html__('Filter', 'pagenest-companion') .
        '</button></form>';
    $reference = absint($_GET['pn_reference'] ?? 0);
    if ($reference && pagenest_media_quality_attachment($reference)) {
        $scan_page = max(1, min(10000, absint($_GET['pn_scan_page'] ?? 1)));
        $scan = pagenest_media_quality_reference_page($reference, $scan_page);
        echo '<section class="pn-admin-section"><h2>' .
            esc_html(
                sprintf(__('References to attachment #%d', 'pagenest-companion'), $reference),
            ) .
            '</h2><p>' .
            esc_html(
                $scan['scope'] === 'site'
                    ? __('Scope: saved site posts and pages you can read.', 'pagenest-companion')
                    : __('Scope: only your own saved posts and pages.', 'pagenest-companion'),
            ) .
            '</p><p>' .
            esc_html(
                sprintf(
                    __(
                        'This page checked %1$d saved posts/pages and skipped %2$d inaccessible or oversized entries.',
                        'pagenest-companion',
                    ),
                    $scan['checked'],
                    $scan['skipped'],
                ),
            ) .
            '</p><p>' .
            esc_html__(
                'Only this scan page was checked: up to 1 MB, 500 blocks and 30 nested levels per readable post/page. Other content, templates and external uses remain unchecked. No matches does not mean unused.',
                'pagenest-companion',
            ) .
            '</p><ul>';
        foreach ($scan['matches'] as $post) {
            echo '<li><a href="' .
                esc_url(get_permalink($post)) .
                '">' .
                esc_html(get_the_title($post)) .
                '</a></li>';
        }
        echo '</ul>';
        if (!$scan['matches']) {
            echo '<p>' .
                esc_html__('No matches in the checked content.', 'pagenest-companion') .
                '</p>';
        }
        if ($scan['more']) {
            echo '<a class="button" href="' .
                esc_url(
                    add_query_arg(['pn_reference' => $reference, 'pn_scan_page' => $scan_page + 1]),
                ) .
                '">' .
                esc_html__('Check next page', 'pagenest-companion') .
                '</a>';
        }
        echo '</section>';
    }
    $shown = 0;
    echo '<form method="post">';
    wp_nonce_field('pagenest-media-quality-batch', 'pn_quality_nonce');
    foreach (array_slice($query->posts, 0, 20) as $post) {
        if (!pagenest_media_quality_attachment($post->ID)) {
            continue;
        }
        $shown++;
        $id = (int) $post->ID;
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $form = [
            'alt' => $alt,
            'caption' => $post->post_excerpt,
            'expected_alt' => $alt,
            'expected_caption' => $post->post_excerpt,
        ];
        if (
            isset($submitted[$id]) &&
            ($results[$id] ?? '') !== 'saved' &&
            is_array($submitted[$id])
        ) {
            foreach (array_keys($form) as $key) {
                if (
                    is_string($submitted[$id][$key] ?? null) &&
                    strlen($submitted[$id][$key]) <= 20000
                ) {
                    $form[$key] = $submitted[$id][$key];
                }
            }
        }
        echo '<section class="pn-admin-section pn-quality-item"><div>' .
            wp_get_attachment_image($id, 'thumbnail') .
            '<h2>' .
            esc_html(get_the_title($post)) .
            ' <small>#' .
            $id .
            '</small></h2><a href="' .
            esc_url(
                add_query_arg(
                    [
                        'page' => 'pagenest-media-quality',
                        'pn_reference' => $id,
                        'pn_scan_page' => 1,
                    ],
                    admin_url('upload.php'),
                ),
            ) .
            '">' .
            esc_html__('Check saved content references', 'pagenest-companion') .
            '</a></div><div>';
        if (isset($messages[$results[$id] ?? ''])) {
            echo '<p class="notice notice-' .
                ($results[$id] === 'saved' ? 'success' : 'error') .
                '" role="status">' .
                esc_html($messages[$results[$id]]) .
                '</p>';
        }
        foreach (['expected_alt', 'expected_caption'] as $key) {
            echo '<input type="hidden" name="items[' .
                $id .
                '][' .
                $key .
                ']" value="' .
                esc_attr($form[$key]) .
                '">';
        }
        echo '<input type="hidden" name="items[' .
            $id .
            '][nonce]" value="' .
            esc_attr(wp_create_nonce('pagenest-media-quality-' . $id)) .
            '"><label for="pn-alt-' .
            $id .
            '">' .
            esc_html__('Alternative text', 'pagenest-companion') .
            '</label><input class="widefat" id="pn-alt-' .
            $id .
            '" name="items[' .
            $id .
            '][alt]" type="text" maxlength="2000" value="' .
            esc_attr($form['alt']) .
            '"><label for="pn-caption-' .
            $id .
            '">' .
            esc_html__('Caption', 'pagenest-companion') .
            '</label><textarea id="pn-caption-' .
            $id .
            '" name="items[' .
            $id .
            '][caption]" maxlength="20000" rows="3">' .
            esc_textarea($form['caption']) .
            '</textarea><button class="button" type="submit" name="pn_save_one" value="' .
            $id .
            '">' .
            esc_html__('Save this attachment', 'pagenest-companion') .
            '</button></div></section>';
    }
    if ($shown) {
        echo '<p data-pn-savebar><button class="button button-primary" type="submit" name="pn_save_all" value="1">' .
            esc_html__('Save this page, with a result for each attachment', 'pagenest-companion') .
            '</button></p>';
    }
    echo '</form>';
    if (!$shown) {
        echo '<p>' . esc_html__('No editable images on this page.', 'pagenest-companion') . '</p>';
    }
    echo '<nav class="pn-diagnostics-filters" aria-label="' .
        esc_attr__('Media quality pages', 'pagenest-companion') .
        '">';
    foreach (
        [
            $page - 1 => __('Previous page', 'pagenest-companion'),
            $page + 1 => __('Next page', 'pagenest-companion'),
        ]
        as $target => $label
    ) {
        if ($target < 1 || ($target > $page && count($query->posts) <= 20)) {
            continue;
        }
        echo '<a class="button" href="' .
            esc_url(
                add_query_arg(
                    [
                        'page' => 'pagenest-media-quality',
                        'pn_missing' => $filter,
                        'pn_quality_page' => $target,
                    ],
                    admin_url('upload.php'),
                ),
            ) .
            '">' .
            esc_html($label) .
            '</a>';
    }
    echo '</nav></div>';
}
add_action('admin_menu', static function (): void {
    add_media_page(
        __('Media quality', 'pagenest-companion'),
        __('Media quality', 'pagenest-companion'),
        'upload_files',
        'pagenest-media-quality',
        'pagenest_media_quality_page',
    );
});
