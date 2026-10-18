<?php
/** New neutral media authoring never rewrites compatible blocks or imported mappings. */
defined('ABSPATH') || exit();
function pagenest_media_authoring_enabled(): bool
{
    return get_option('pagenest_companion_media_authoring', '0') === '1' &&
        pagenest_companion_media_config()['enabled'];
}
function pagenest_media_native_attributes(bool $pdf = false): array
{
    return $pdf
        ? [
            'id' => ['type' => 'integer', 'default' => 0],
            'width' => ['type' => 'string', 'default' => '100%'],
            'height' => ['type' => 'string', 'default' => '800'],
        ]
        : [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => []],
            'layout' => ['type' => 'string', 'default' => 'grid'],
            'columns' => ['type' => 'number', 'default' => 3],
            'gap' => ['type' => 'number', 'default' => 15],
            'lightbox' => ['type' => 'boolean', 'default' => true],
            'autoplay' => ['type' => 'boolean', 'default' => false],
            'interval' => ['type' => 'number', 'default' => 3000],
        ];
}
function pagenest_media_native_gallery($attrs): string
{
    if (!pagenest_media_authoring_enabled() || !is_array($attrs)) {
        return '';
    }
    $ids = $attrs['ids'] ?? [];
    if (is_string($ids)) {
        $ids = preg_split('/\s*,\s*/', $ids);
    }
    if (!is_array($ids) || count($ids) > 100) {
        return '';
    }
    $config = pagenest_companion_media_defaults();
    $config['attachment_ids'] = array_values(
        array_unique(array_filter(array_map('pagenest_companion_media_id', $ids))),
    );
    foreach (['columns', 'gap', 'interval'] as $key) {
        if (isset($attrs[$key]) && is_scalar($attrs[$key])) {
            $config[$key] = (int) $attrs[$key];
        }
    }
    foreach (['lightbox', 'autoplay'] as $key) {
        if (isset($attrs[$key])) {
            $config[$key] = in_array($attrs[$key], [true, 1, '1'], true);
        }
    }
    $config['autoplay'] =
        isset($attrs['autoplay']) && in_array($attrs['autoplay'], [true, 1, '1'], true);
    $config['layout'] = is_string($attrs['layout'] ?? null) ? $attrs['layout'] : 'grid';
    $config['orderby'] = 'ids';
    try {
        $config = pagenest_companion_media_validate([
            'schema_version' => 1,
            'enabled' => true,
            'galleries' => [1 => $config],
        ])['galleries'][1];
    } catch (InvalidArgumentException $error) {
        return '';
    }
    return pagenest_companion_media_gallery_render($config);
}
function pagenest_media_native_pdf($attrs): string
{
    return pagenest_media_authoring_enabled()
        ? pagenest_companion_media_pdf(is_array($attrs) ? array_merge($attrs, ['embed' => 1]) : [])
        : '';
}
add_action(
    'init',
    static function () {
        if (!pagenest_media_authoring_enabled()) {
            return;
        }
        foreach (['gallery', 'pdf'] as $type) {
            register_block_type('pagenest/' . $type, [
                'api_version' => 3,
                'attributes' => pagenest_media_native_attributes($type === 'pdf'),
                'render_callback' =>
                    $type === 'pdf' ? 'pagenest_media_native_pdf' : 'pagenest_media_native_gallery',
            ]);
            add_shortcode(
                'pagenest_' . $type,
                $type === 'pdf' ? 'pagenest_media_native_pdf' : 'pagenest_media_native_gallery',
            );
        }
    },
    1010,
);
add_action('enqueue_block_editor_assets', static function () {
    if (!pagenest_media_authoring_enabled()) {
        return;
    }
    wp_enqueue_media();
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_script(
        'pagenest-native-media-editor',
        plugins_url(
            'assets/' . $manifest['native_media_js'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'],
        null,
        true,
    );
    wp_localize_script('pagenest-native-media-editor', 'PageNestNativeMedia', [
        'text' => [
            'gallery' => __('PageNest gallery', 'pagenest-companion'),
            'pdf' => __('PageNest PDF', 'pagenest-companion'),
            'images' => __('Select or reorder images', 'pagenest-companion'),
            'document' => __('Select PDF', 'pagenest-companion'),
            'layout' => __('Gallery layout', 'pagenest-companion'),
            'grid' => __('Grid', 'pagenest-companion'),
            'slider' => __('Slider', 'pagenest-companion'),
            'columns' => __('Columns', 'pagenest-companion'),
            'gap' => __('Image gap', 'pagenest-companion'),
            'lightbox' => __('Open images in a viewer', 'pagenest-companion'),
            'autoplay' => __('Play slideshow automatically', 'pagenest-companion'),
            'interval' => __('Slide interval in milliseconds', 'pagenest-companion'),
            'width' => __('Viewer width', 'pagenest-companion'),
            'height' => __('Viewer height', 'pagenest-companion'),
            'empty' => __(
                'Choose files from the media library to preview. Files and existing gallery mappings stay in place.',
                'pagenest-companion',
            ),
        ],
    ]);
});
add_action('admin_init', static function () {
    register_setting('pagenest_companion_media', 'pagenest_companion_media_authoring', [
        'type' => 'string',
        'default' => '0',
        'sanitize_callback' => static fn($value) => current_user_can('manage_options') &&
        $value === '1'
            ? '1'
            : '0',
    ]);
});
