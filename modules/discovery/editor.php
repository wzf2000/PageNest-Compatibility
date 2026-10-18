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
    $image = $value['image'] ? wp_get_attachment_image_url($value['image'], 'medium') : '';
    $fallback = pagenest_discovery_text(
        $post->post_excerpt ?: pagenest_discovery_options()['description'],
        160,
    );
    echo '<div data-pn-seo-editor><p>' .
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
        '><strong data-pn-seo-preview-title></strong><p data-pn-seo-preview-description></p><small>' .
        esc_html(get_permalink($post)) .
        '</small></section><p>' .
        esc_html__(
            'Preview uses saved article defaults and your current overrides. Save the article to apply. It does not publish a draft or change access permissions.',
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
            $image && pagenest_seo_public_image($image) && current_user_can('read_post', $image)
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
