<?php
/** Read-only folder navigation; taxonomy providers still own media organization. */
defined('ABSPATH') || exit();

function pagenest_companion_media_library_taxonomy(): ?object
{
    $config = pagenest_companion_media_config();
    if (
        !$config['enabled'] ||
        empty($config['library_taxonomy']) ||
        !current_user_can('upload_files')
    ) {
        return null;
    }
    $taxonomy = get_taxonomy($config['library_taxonomy']);
    return $taxonomy &&
        $taxonomy->hierarchical &&
        in_array('attachment', $taxonomy->object_type, true) &&
        current_user_can($taxonomy->cap->assign_terms)
        ? $taxonomy
        : null;
}

function pagenest_companion_media_library_terms(object $taxonomy): array
{
    $terms = get_terms([
        'taxonomy' => $taxonomy->name,
        'hide_empty' => false,
        'orderby' => 'name',
        'number' => 5001,
    ]);
    if (is_wp_error($terms) || count($terms) > 5000) {
        throw new RuntimeException('Folder list unavailable.');
    }
    return array_map(
        static fn($term) => [
            'id' => (int) $term->term_id,
            'parent' => (int) $term->parent,
            'name' => $term->name,
            'slug' => $term->slug,
        ],
        $terms,
    );
}

add_action('admin_enqueue_scripts', static function ($hook): void {
    if ($hook !== 'upload.php' || (isset($_GET['mode']) && $_GET['mode'] === 'list')) {
        return;
    }
    $taxonomy = pagenest_companion_media_library_taxonomy();
    if (!$taxonomy) {
        return;
    }
    try {
        $terms = pagenest_companion_media_library_terms($taxonomy);
    } catch (RuntimeException $error) {
        return;
    }
    $root = dirname(__DIR__, 2);
    $manifest = require $root . '/assets/manifest.php';
    if (!isset($manifest['media_library_js'], $manifest['media_library_css'])) {
        return;
    }
    wp_enqueue_style(
        'pagenest-media-library',
        plugins_url(
            'assets/' . $manifest['media_library_css'],
            $root . '/pagenest-compatibility.php',
        ),
        [],
        null,
    );
    wp_enqueue_script(
        'pagenest-media-library',
        plugins_url(
            'assets/' . $manifest['media_library_js'],
            $root . '/pagenest-compatibility.php',
        ),
        ['media-grid', 'wp-util'],
        null,
        true,
    );
    wp_localize_script('pagenest-media-library', 'pagenestMediaLibrary', [
        'taxonomy' => $taxonomy->name,
        'terms' => $terms,
        'nonce' => wp_create_nonce('pagenest-media-folders'),
        'labels' => pagenest_companion_media_labels(),
    ]);
});

add_action('wp_ajax_pagenest_media_folders', static function (): void {
    $taxonomy = pagenest_companion_media_library_taxonomy();
    if (!$taxonomy) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
        return;
    }
    if (!check_ajax_referer('pagenest-media-folders', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid request'], 403);
        return;
    }
    try {
        wp_send_json_success(pagenest_companion_media_library_terms($taxonomy));
    } catch (RuntimeException $error) {
        wp_send_json_error(['message' => 'Folder list unavailable'], 503);
    }
});
