<?php
/** Chapter display precedes relative links (32) and paragraph identities (35). */
defined('ABSPATH') || exit();
function pagenest_companion_content_chapter(int $id): bool
{
    return pagenest_companion_content_config('modules.chapter_display.enabled', false) &&
        (bool) get_post_meta(
            $id,
            pagenest_companion_content_config('modules.chapter_display.document_meta'),
            true,
        );
}
function pagenest_companion_content_chapter_render(string $html, int $id): string
{
    if (!pagenest_companion_content_chapter($id) || !pagenest_companion_can_read(get_post($id))) {
        return $html;
    }
    if (str_contains($html, 'data-pagenest-chapter="1"')) {
        return $html;
    }
    $config = pagenest_companion_content_config('modules.chapter_display');
    $aliases = get_post_meta($id, $config['heading_aliases_meta'], true);
    $aliases = is_array($aliases) ? $aliases : [];
    [$dom, $xp, $root] = pagenest_companion_content_dom($html);
    if (!$root) {
        return $html;
    }
    $used = [];
    foreach ($xp->query('.//*[@id]', $root) as $element) {
        $used[$element->getAttribute('id')] = true;
    }
    $seen = [];
    foreach ($xp->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $root) as $heading) {
        $slug = mb_strtolower(html_entity_decode(trim($heading->textContent), ENT_QUOTES, 'UTF-8'));
        $slug = preg_replace('/[^\p{L}\p{N}_\s-]/u', '', $slug);
        $slug = str_replace(' ', '-', $slug);
        $count = $seen[$slug] ?? 0;
        $seen[$slug] = $count + 1;
        $base = $slug . ($count ? '-' . $count : '');
        $anchor = $heading->getAttribute('id');
        if ($anchor === '' && $base !== '') {
            $anchor = $base;
            $suffix = $count;
            while (isset($used[$anchor])) {
                $anchor = $slug . '-' . ++$suffix;
            }
            $heading->setAttribute('id', $anchor);
            $used[$anchor] = true;
        }
        foreach (is_array($aliases[$base] ?? null) ? $aliases[$base] : [] as $old) {
            // Metadata is not an executable template, and duplicate IDs are never added.
            if (
                !is_string($old) ||
                $old === '' ||
                strlen($old) > 500 ||
                preg_match('/[\s<>\x00-\x1f\x7f]/u', $old) ||
                isset($used[$old])
            ) {
                continue;
            }
            $span = $dom->createElement('span');
            $span->setAttribute('id', $old);
            $heading->insertBefore($span, $heading->firstChild);
            $used[$old] = true;
        }
    }
    $navigation = '';
    $libraries = get_posts([
        'post_type' => 'page',
        'post_status' => ['private', 'publish'],
        'meta_key' => $config['library_meta'],
        'meta_value' => '1',
        'posts_per_page' => 1,
    ]);
    if ($libraries && pagenest_companion_can_read($libraries[0])) {
        $navigation =
            '<nav class="pagenest-content-nav" aria-label="' .
            esc_attr($config['labels']['navigation'] ?? 'Chapter navigation') .
            '"><a href="' .
            esc_url(get_permalink($libraries[0])) .
            '">' .
            esc_html($config['labels']['return'] ?? 'Return to contents') .
            '</a></nav>';
    }
    return $navigation .
        '<div class="pagenest-content-chapter' .
        (!empty($config['hide_first_title']) ? ' pagenest-content-hide-title' : '') .
        '" data-pagenest-chapter="1">' .
        pagenest_companion_content_inner($dom, $root) .
        '</div>';
}
add_filter(
    'the_content',
    static fn($html) => pagenest_companion_content_chapter_render($html, (int) get_the_ID()),
    30,
);
add_action('wp_enqueue_scripts', static function (): void {
    $id = (int) get_queried_object_id();
    if (
        !is_singular('post') ||
        !pagenest_companion_content_chapter($id) ||
        !pagenest_companion_can_read(get_post($id))
    ) {
        return;
    }
    pagenest_companion_content_assets(false);
    $manifest = require dirname(__DIR__, 2) . '/assets/manifest.php';
    wp_enqueue_script(
        'pagenest-content-spacing',
        plugins_url(
            'assets/' . $manifest['content_spacing_js'],
            dirname(__DIR__, 2) . '/pagenest-compatibility.php',
        ),
        [],
        null,
        true,
    );
});
