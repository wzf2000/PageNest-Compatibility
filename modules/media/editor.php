<?php
/** Dynamic adapters preserve the original saved shortcode on unchanged blocks. */
defined('ABSPATH') || exit();

function pagenest_companion_media_block_attributes(string $tag): array
{
    $attributes = ['legacyContent' => ['type' => 'string', 'source' => 'html']];
    $numbers =
        $tag === 'wpmfpdf'
            ? ['id', 'embed']
            : [
                'galleryId',
                'row_height',
                'display_tree',
                'sub_galleries_listing',
                'display_tag',
                'disable_overlay',
                'download_all',
                'crop_image',
                'duration',
                'auto_animation',
                'number_lines',
                'show_buttons',
                'img_border_radius',
                'borderWidth',
                'hoverShadowH',
                'hoverShadowV',
                'hoverShadowBlur',
                'hoverShadowSpread',
                'gutterwidth',
                'tree_width',
                'hover_opacity',
                'hover_title_size',
                'hover_desc_size',
            ];
    $strings =
        $tag === 'wpmfpdf'
            ? ['target', 'width', 'height', 'url']
            : [
                'display',
                'layout',
                'aspect_ratio',
                'columns',
                'size',
                'targetsize',
                'link',
                'wpmf_orderby',
                'wpmf_order',
                'animation',
                'borderColor',
                'borderStyle',
                'hoverShadowColor',
                'theme',
                'html',
                'hover_color',
                'hover_title_position',
                'hover_title_color',
                'hover_desc_position',
                'hover_desc_color',
                'align',
            ];
    foreach ($numbers as $key) {
        $attributes[$key] = ['type' => 'number'];
    }
    foreach ($strings as $key) {
        $attributes[$key] = ['type' => 'string'];
    }
    return $attributes;
}

function pagenest_companion_media_block_render(string $tag, array $attrs, string $content): string
{
    // Saved inner shortcode is authoritative, including dimensions and display overrides.
    preg_match_all('/' . get_shortcode_regex([$tag]) . '/s', $content, $matches, PREG_SET_ORDER);
    $html = '';
    foreach ($matches as $match) {
        if ($match[1] === '[' && $match[6] === ']') {
            continue;
        }
        $params = shortcode_parse_atts($match[3]);
        $params = is_array($params) ? $params : [];
        $html .=
            $tag === 'wpmfpdf'
                ? pagenest_companion_media_pdf($params)
                : pagenest_companion_media_gallery($params);
    }
    if ($matches) {
        return $html;
    }
    if (isset($attrs['galleryId'])) {
        $attrs['gallery_id'] = $attrs['galleryId'];
    }
    // Old gallery's theme attribute mirrors display; only use it when display is omitted.
    if (empty($attrs['display']) && !empty($attrs['theme'])) {
        $attrs['display'] = $attrs['theme'];
    }
    if ($tag === 'wpmfpdf' && !isset($attrs['embed'])) {
        $attrs['embed'] = 1;
    }
    return $tag === 'wpmfpdf'
        ? pagenest_companion_media_pdf($attrs)
        : pagenest_companion_media_gallery($attrs);
}
