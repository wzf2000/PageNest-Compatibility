<?php
/** Chapter ordering and file links use WordPress metadata and explicit mappings. */
defined('ABSPATH') || exit();
function pagenest_companion_can_read($post)
{
    return $post &&
        !post_password_required($post) &&
        ($post->post_status === 'publish' ||
            ($post->post_status === 'private' && current_user_can('read_post', $post->ID)));
}
function pagenest_companion_series($id)
{
    $config = pagenest_companion_config('chapters');
    $series = get_post_meta($id, $config['series_meta'], true);
    $document = get_post_meta($id, $config['document_meta'], true);
    if ($series === '' && $document === '') {
        return [];
    }
    $all = get_posts([
        'post_type' => 'post',
        'post_status' => ['publish', 'private'],
        'posts_per_page' => -1,
        'meta_key' => $series !== '' ? $config['series_meta'] : $config['document_meta'],
        'orderby' => 'meta_value',
        'order' => 'ASC',
    ]);
    $all = array_values(
        array_filter(
            $all,
            static fn($p) => pagenest_companion_can_read($p) &&
                ($series === '' || get_post_meta($p->ID, $config['series_meta'], true) === $series),
        ),
    );
    $rank = array_flip($config['series_order']);
    usort($all, static function ($a, $b) use ($config, $rank) {
        $ad = (string) get_post_meta($a->ID, $config['document_meta'], true);
        $bd = (string) get_post_meta($b->ID, $config['document_meta'], true);
        $ar = $rank[$ad] ?? PHP_INT_MAX;
        $br = $rank[$bd] ?? PHP_INT_MAX;
        if ($ar !== $br) {
            return $ar <=> $br;
        }
        $ao = get_post_meta($a->ID, $config['order_meta'], true);
        $bo = get_post_meta($b->ID, $config['order_meta'], true);
        if (is_numeric($ao) && is_numeric($bo) && $ao != $bo) {
            return (float) $ao <=> (float) $bo;
        }
        return strcmp($ad, $bd) ?: $a->ID <=> $b->ID;
    });
    return $all;
}
add_filter(
    'pagenest_related_posts',
    static function ($posts, $id) {
        $all = pagenest_companion_series($id);
        if (!$all) {
            return $posts;
        }
        $index = array_search((int) $id, array_map(static fn($p) => (int) $p->ID, $all), true);
        if ($index === false) {
            return [];
        }
        $out = [];
        foreach ([1, -1, 2, -2] as $offset) {
            if (isset($all[$index + $offset])) {
                $out[] = $all[$index + $offset];
            }
        }
        return array_slice($out, 0, 3);
    },
    10,
    2,
);
add_filter(
    'pagenest_related_title',
    static fn($title, $id) => pagenest_companion_series($id) ? '同系列章节' : $title,
    10,
    2,
);
add_filter(
    'the_content',
    static function ($html) {
        $map = pagenest_companion_config('chapters', 'link_map');
        if (!$map || !class_exists('WP_HTML_Tag_Processor')) {
            return $html;
        }
        $tags = new WP_HTML_Tag_Processor($html);
        while ($tags->next_tag('A')) {
            $href = $tags->get_attribute('href');
            if (!is_string($href)) {
                continue;
            }
            $parts = wp_parse_url($href);
            if (
                !is_array($parts) ||
                isset($parts['host']) ||
                isset($parts['scheme']) ||
                isset($parts['query'])
            ) {
                continue;
            }
            $path = $parts['path'] ?? '';
            $file = basename($path);
            if (!isset($map[$file])) {
                continue;
            }
            $post = get_post($map[$file]);
            if (!pagenest_companion_can_read($post)) {
                continue;
            }
            $url = get_permalink($post);
            if (isset($parts['fragment'])) {
                $url .= '#' . $parts['fragment'];
            }
            $tags->set_attribute('href', esc_url($url));
        }
        return $tags->get_updated_html();
    },
    32,
);
