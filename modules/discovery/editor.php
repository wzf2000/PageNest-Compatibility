<?php
/** Per-object share presentation. Never changes canonical URLs or publishing visibility. */
defined('ABSPATH') || exit();
function pagenest_seo_overrides($id): array
{
    $value = get_post_meta($id, '_pagenest_seo', true);
    $value = is_array($value) ? $value : [];
    return [
        'title' => is_string($value['title'] ?? null)
            ? pagenest_discovery_text($value['title'], 180)
            : '',
        'description' => is_string($value['description'] ?? null)
            ? pagenest_discovery_text($value['description'], 160)
            : '',
        'image' => absint($value['image'] ?? 0),
    ];
}
function pagenest_seo_public_image(int $id): bool
{
    if (!$id || !wp_attachment_is_image($id)) {
        return false;
    }
    $seen = [];
    $post = get_post($id);
    while ($post) {
        if (isset($seen[$post->ID]) || count($seen) >= 30 || $post->post_password !== '') {
            return false;
        }
        $seen[$post->ID] = true;
        if ($post->post_type !== 'attachment') {
            return pagenest_discovery_public_post($post);
        }
        if (!in_array($post->post_status, ['inherit', 'publish'], true)) {
            return false;
        }
        if (!$post->post_parent) {
            return true;
        }
        $post = get_post($post->post_parent);
    }
    return false;
}
/** The editor uses the same fallback chain, gated by the editor's current attachment access. */
function pagenest_seo_defaults($post = null, bool $public = true): array
{
    $options = pagenest_discovery_options();
    $description = $post ? pagenest_discovery_text($post->post_excerpt, 160) : '';
    if ($description === '') {
        $description = pagenest_discovery_text(
            $options['description'] ?: get_bloginfo('description'),
            160,
        );
    }
    $image = 0;
    foreach (
        [
            $post ? (int) get_post_thumbnail_id($post) : 0,
            $options['image'],
            (int) get_theme_mod('custom_logo', 0),
        ]
        as $candidate
    ) {
        if (
            $candidate &&
            wp_get_attachment_image_url($candidate, 'full') &&
            ($public
                ? pagenest_seo_public_image($candidate)
                : wp_attachment_is_image($candidate) &&
                    current_user_can('read_post', $candidate) &&
                    pagenest_companion_media_readable($candidate))
        ) {
            $image = $candidate;
            break;
        }
    }
    return [
        'title' => pagenest_discovery_text($post ? $post->post_title : get_bloginfo('name'), 180),
        'description' => $description,
        'image' => (int) $image,
    ];
}
/** Public output and editor hints use exactly the same eligible image. */
function pagenest_seo_image($post, int $override): int
{
    if (
        $override &&
        pagenest_seo_public_image($override) &&
        wp_get_attachment_image_url($override, 'full')
    ) {
        return $override;
    }
    return pagenest_seo_defaults($post)['image'];
}
function pagenest_seo_site_image($post, int $override): bool
{
    $id = pagenest_seo_image($post, $override);
    return $id > 0 && $id !== $override && $id !== (int) get_post_thumbnail_id($post);
}
function pagenest_seo_preview_image($post, int $override): string
{
    $id = pagenest_seo_image($post, $override);
    return $id
        ? (string) (wp_get_attachment_image_url($id, 'medium') ?:
        wp_get_attachment_image_url($id, 'full'))
        : '';
}
function pagenest_seo_editor_image($post, int $override): int
{
    if (
        $override &&
        wp_attachment_is_image($override) &&
        current_user_can('read_post', $override) &&
        pagenest_companion_media_readable($override) &&
        wp_get_attachment_image_url($override, 'full')
    ) {
        return $override;
    }
    return pagenest_seo_defaults($post, false)['image'];
}
function pagenest_seo_editor_preview($post, int $override): array
{
    $id = pagenest_seo_editor_image($post, $override);
    $public = pagenest_seo_image($post, $override);
    return [
        'image' => $id
            ? (string) (wp_get_attachment_image_url($id, 'medium') ?:
            wp_get_attachment_image_url($id, 'full'))
            : '',
        'public_image' => $public > 0,
        'inherited' => pagenest_seo_site_image($post, $override),
        'private_preview' => $id > 0 && $id !== $public,
    ];
}
add_action('wp_ajax_pagenest_seo_preview_image', static function (): void {
    $id = is_scalar($_POST['post'] ?? null) ? absint($_POST['post']) : 0;
    if (!$id || !current_user_can('edit_post', $id)) {
        wp_send_json_error(null, 403);
    }
    check_ajax_referer('pagenest-seo-preview-' . $id, 'nonce');
    $post = get_post($id);
    if (!$post || !in_array($post->post_type, ['post', 'page'], true)) {
        wp_send_json_error(null, 400);
    }
    $image = is_scalar($_POST['image'] ?? null) ? absint($_POST['image']) : 0;
    if ($image && !current_user_can('read_post', $image)) {
        wp_send_json_error(null, 403);
    }
    wp_send_json_success(pagenest_seo_editor_preview($post, $image));
});
add_action('add_meta_boxes', static function () {
    foreach (['post', 'page'] as $type) {
        add_meta_box(
            'pagenest-seo',
            __('Search and sharing preview', 'pagenest-companion'),
            'pagenest_seo_editor',
            $type,
            'normal',
        );
    }
});
function pagenest_seo_editor($post): void
{
    if (!current_user_can('edit_post', $post->ID)) {
        return;
    }
    $value = pagenest_seo_overrides($post->ID);
    wp_nonce_field('pagenest-seo-' . $post->ID, 'pagenest-seo-nonce');
    $defaults = pagenest_seo_defaults($post);
    $preview = pagenest_seo_editor_preview($post, $value['image']);
    $default_preview = pagenest_seo_editor_preview($post, 0);
    $image = $preview['image'];
    $default_image = $default_preview['image'];
    $fallback = $defaults['description'];
    echo '<div data-pn-seo-editor data-pn-seo-post="' .
        (int) $post->ID .
        '" data-pn-seo-nonce="' .
        esc_attr(wp_create_nonce('pagenest-seo-preview-' . $post->ID)) .
        '" data-pn-seo-site-description="' .
        (pagenest_discovery_text($post->post_excerpt, 160) === '' && $defaults['description'] !== ''
            ? '1'
            : '0') .
        '"><p>' .
        esc_html__(
            'Overrides apply only when Companion SEO is enabled and another SEO plugin has not taken ownership. Blank fields inherit the article and site defaults.',
            'pagenest-companion',
        ) .
        '</p><p><label>' .
        esc_html__('Share title', 'pagenest-companion') .
        '<input type="text" class="widefat" name="pagenest_seo[title]" data-pn-seo-title maxlength="180" value="' .
        esc_attr($value['title']) .
        '" placeholder="' .
        esc_attr($post->post_title) .
        '"></label></p><p><label>' .
        esc_html__('Share description', 'pagenest-companion') .
        '<textarea class="widefat" name="pagenest_seo[description]" data-pn-seo-description maxlength="160" rows="3" placeholder="' .
        esc_attr($fallback) .
        '">' .
        esc_textarea($value['description']) .
        '</textarea></label></p><input type="hidden" name="pagenest_seo[image]" data-pn-seo-image value="' .
        (int) $value['image'] .
        '"><p><button type="button" class="button" data-pn-seo-select>' .
        esc_html__('Choose share image', 'pagenest-companion') .
        '</button> <button type="button" class="button" data-pn-seo-clear>' .
        esc_html__('Use default image', 'pagenest-companion') .
        '</button></p><section class="pn-seo-preview" aria-label="' .
        esc_attr__('Sharing preview', 'pagenest-companion') .
        '"><img data-pn-seo-preview-image alt="" src="' .
        esc_url($image ?: '') .
        '" ' .
        ($image ? '' : 'hidden') .
        ' data-pn-seo-default-image="' .
        esc_url($default_image ?: '') .
        '" data-pn-seo-default-public="' .
        ($default_preview['public_image'] ? '1' : '0') .
        '" data-pn-seo-default-private="' .
        ($default_preview['private_preview'] ? '1' : '0') .
        '" data-pn-seo-default-inherited="' .
        (pagenest_seo_site_image($post, 0) ? '1' : '0') .
        '"><strong data-pn-seo-preview-title>' .
        esc_html($value['title'] ?: $defaults['title']) .
        '</strong><p data-pn-seo-preview-description>' .
        esc_html($value['description'] ?: $fallback) .
        '</p><small>' .
        esc_html(get_permalink($post)) .
        '</small></section><p data-pn-seo-missing-description role="status"' .
        (($value['description'] ?: $fallback) !== '' ? ' hidden' : '') .
        '>' .
        esc_html__(
            'No share description. Add a description, article excerpt or site description.',
            'pagenest-companion',
        ) .
        '</p><p data-pn-seo-missing-image role="status"' .
        ($preview['public_image'] ? ' hidden' : '') .
        '>' .
        esc_html__(
            'No public share image. Choose a public image, featured image, site image or logo.',
            'pagenest-companion',
        ) .
        '</p><p data-pn-seo-inherited-description' .
        ($value['description'] === '' &&
        pagenest_discovery_text($post->post_excerpt, 160) === '' &&
        $fallback !== ''
            ? ''
            : ' hidden') .
        '>' .
        esc_html__('Using the site description.', 'pagenest-companion') .
        '</p><p data-pn-seo-inherited-image' .
        (pagenest_seo_site_image($post, $value['image']) ? '' : ' hidden') .
        '>' .
        esc_html__('Using the site image or logo.', 'pagenest-companion') .
        '</p><p data-pn-seo-private-preview' .
        ($preview['private_preview'] ? '' : ' hidden') .
        '>' .
        esc_html__(
            'This editor image is not currently public. Public sharing uses an eligible default or no image. Check again after publishing.',
            'pagenest-companion',
        ) .
        '</p><p data-pn-seo-image-status role="status"></p><p>' .
        esc_html__(
            'Preview uses saved article defaults and your current overrides. Save the article to apply. Draft images can be selected here; only publicly readable images appear in public sharing metadata.',
            'pagenest-companion',
        ) .
        '</p></div>';
}
add_action('save_post', static function ($id) {
    if (
        wp_is_post_revision($id) ||
        wp_is_post_autosave($id) ||
        !in_array(get_post_type($id), ['post', 'page'], true) ||
        !current_user_can('edit_post', $id) ||
        !isset($_POST['pagenest-seo-nonce']) ||
        !is_string($_POST['pagenest-seo-nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['pagenest-seo-nonce'])),
            'pagenest-seo-' . $id,
        )
    ) {
        return;
    }
    $input =
        isset($_POST['pagenest_seo']) && is_array($_POST['pagenest_seo'])
            ? wp_unslash($_POST['pagenest_seo'])
            : [];
    $image = is_scalar($input['image'] ?? null) ? absint($input['image']) : 0;
    $value = [
        'title' => pagenest_discovery_text(
            is_string($input['title'] ?? null) ? $input['title'] : '',
            180,
        ),
        'description' => pagenest_discovery_text(
            is_string($input['description'] ?? null) ? $input['description'] : '',
            160,
        ),
        'image' =>
            $image &&
            wp_attachment_is_image($image) &&
            current_user_can('read_post', $image) &&
            pagenest_companion_media_readable($image)
                ? $image
                : 0,
    ];
    if (!array_filter($value)) {
        delete_post_meta($id, '_pagenest_seo');
    } else {
        update_post_meta($id, '_pagenest_seo', wp_slash($value));
    }
});
add_action('admin_enqueue_scripts', static function ($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['post', 'page'], true)) {
        return;
    }
    wp_enqueue_media();
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_script(
        'pagenest-seo-editor',
        plugins_url(
            'assets/' . $manifest['seo_editor_js'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        ['media-views'],
        null,
        true,
    );
    wp_localize_script('pagenest-seo-editor', 'PageNestSEOEditor', [
        'choose' => __('Choose share image', 'pagenest-companion'),
        'url' => admin_url('admin-ajax.php'),
        'checking' => __('Checking public image availability…', 'pagenest-companion'),
        'failed' => __(
            'Image availability could not be checked. Save and reopen to refresh the preview.',
            'pagenest-companion',
        ),
    ]);
    wp_enqueue_style(
        'pagenest-seo-editor',
        plugins_url(
            'assets/' . $manifest['seo_editor_css'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        [],
        null,
    );
});
