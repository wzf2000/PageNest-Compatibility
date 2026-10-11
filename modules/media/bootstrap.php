<?php
/** Optional media owner. Independent of theme and old media-library storage. */
defined('ABSPATH') || exit();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/editor.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/folders.php';

function pagenest_companion_media_bootstrap(): void
{
    if (!pagenest_companion_media_config()['enabled']) {
        return;
    }
    $registry = WP_Block_Type_Registry::get_instance();
    foreach (
        [
            'wpmfgallery' => 'pagenest_companion_media_gallery',
            'wpmfpdf' => 'pagenest_companion_media_pdf',
        ]
        as $tag => $callback
    ) {
        $block = $tag === 'wpmfpdf' ? 'wpmf/pdfembed' : 'wpmf/block-gallery';
        if (shortcode_exists($tag) || $registry->is_registered($block)) {
            continue;
        }
        add_shortcode($tag, $callback);
        register_block_type($block, [
            'api_version' => 3,
            'attributes' => pagenest_companion_media_block_attributes($tag),
            'render_callback' => static function ($attrs, $content) use ($tag) {
                return pagenest_companion_media_block_render($tag, $attrs, $content);
            },
        ]);
        $GLOBALS['pagenest_companion_media_owned'][$block] = true;
    }
}
add_action('init', 'pagenest_companion_media_bootstrap', 1000);
add_action('enqueue_block_editor_assets', static function (): void {
    if (
        !empty($GLOBALS['pagenest_companion_media_owned']) &&
        pagenest_companion_media_config()['enabled']
    ) {
        wp_enqueue_media();
        pagenest_companion_media_assets(true);
    }
});
